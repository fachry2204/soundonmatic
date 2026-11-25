<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Enums\ReleaseCheckpoint;
use App\Enums\ReleaseJobStatus;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Recheck a matching SoundOn release before changing Soundfresh status. */
final class ReviewManuallyVerifiedDraft implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public readonly string $releaseJobId)
    {
        $this->onQueue('release-automation');
    }

    public function handle(PlaywrightClient $worker, SessionManager $sessions): void
    {
        $job = ReleaseJob::findOrFail($this->releaseJobId);
        if ($job->error_code !== 'DRAFT_MANUAL_REVIEW_PENDING') return;

        try {
            if (! in_array($job->soundfresh_workflow_status, [null, 'pending'], true)) {
                throw new \RuntimeException('Rilisan Soundfresh tidak lagi berstatus Pending.');
            }
            $soundon = $sessions->ensure(Platform::SoundOn);
            if ($soundon->status === 'manual_auth_required') throw new \RuntimeException('Sesi SoundOn memerlukan login ulang.');
            $draft = $worker->findDraft((string) $job->release_title, (string) $job->artist_name, $soundon->session_state_encrypted);
            $release = null;
            if ($draft === null) {
                $matches = $worker->releaseDuplicates([[
                    'key' => (string) $job->id, 'title' => (string) $job->release_title, 'artist' => (string) $job->artist_name,
                ]], $soundon->fresh()->session_state_encrypted);
                $release = $matches[(string) $job->id] ?? null;
                if (is_array($release) && ($release['found'] ?? false) === true) {
                    $query = [];
                    parse_str((string) parse_url((string) ($release['release_url'] ?? ''), PHP_URL_QUERY), $query);
                    $reference = $release['release_id'] ?? $query['id'] ?? $query['release_id'] ?? null;
                    $draft = ['draft_id' => is_scalar($reference) ? (string) $reference : '', 'draft_url' => $release['release_url'] ?? null];
                }
            }
            $draftId = (string) ($draft['draft_id'] ?? '');
            if (! preg_match('/^\d{10,}$/', $draftId)) {
                throw new \RuntimeException('Rilisan dengan judul dan artis tersebut tidak ditemukan dengan ID SoundOn yang dapat dikenali.');
            }
            $soundfresh = $sessions->ensure(Platform::Soundfresh);
            if ($soundfresh->status === 'manual_auth_required') throw new \RuntimeException('Sesi Soundfresh memerlukan login ulang.');
            $review = $worker->review((string) $job->id, (string) $job->soundfresh_release_url, $draftId, $soundfresh->session_state_encrypted);
            if (($review['reviewed'] ?? false) !== true) throw new \RuntimeException('Soundfresh tidak mengonfirmasi status Under Review.');
            $job->update([
                'status' => ReleaseJobStatus::Completed,
                'checkpoint' => ReleaseCheckpoint::Completed,
                'soundon_draft_id' => $draftId,
                'soundon_draft_url' => $draft['draft_url'] ?? null,
                'soundon_draft_status' => 'already_exists',
                'soundon_release_status' => $release['status'] ?? $job->soundon_release_status,
                'soundfresh_workflow_status' => 'under_review',
                'error_code' => null, 'error_message' => null,
                'progress_label' => 'Rilisan ditemukan di SoundOn — Soundfresh Under Review',
                'progress_percent' => 100, 'finished_at' => now(), 'progress_updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $job->update([
                'status' => ReleaseJobStatus::NeedsAttention,
                'error_code' => 'DUPLICATE_RELEASE_FOUND',
                'error_message' => 'Status belum dikonfirmasi. Periksa Soundfresh sebelum mencoba lagi: '.$exception->getMessage(),
                'progress_label' => 'Pemeriksaan atau perubahan status gagal',
                'finished_at' => now(), 'progress_updated_at' => now(),
            ]);
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReleaseJob::whereKey($this->releaseJobId)->where('error_code', 'DRAFT_MANUAL_REVIEW_PENDING')->update([
            'status' => ReleaseJobStatus::NeedsAttention,
            'error_code' => 'DUPLICATE_RELEASE_FOUND',
            'error_message' => 'Proses pemeriksaan terhenti. Periksa status Soundfresh sebelum mencoba lagi.',
            'progress_label' => 'Pemeriksaan atau perubahan status gagal',
            'finished_at' => now(), 'progress_updated_at' => now(),
        ]);
    }
}

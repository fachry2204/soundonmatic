<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class VerifySoundfreshReleaseIdentifiers implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $releaseJobId)
    {
        $this->onQueue('status-checks');
    }

    public function handle(PlaywrightClient $worker, SessionManager $sessions): void
    {
        $job = ReleaseJob::query()->findOrFail($this->releaseJobId);
        $isrcs = array_values(array_filter((array) $job->soundon_isrcs_json));
        if ($job->soundfresh_workflow_status !== 'uploading' || ! filled($job->soundon_upc) || count($isrcs) < max(1, (int) $job->track_count)) {
            $job->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Rilisan harus berada di tab Uploading dan memiliki UPC serta seluruh ISRC.']);
            return;
        }

        $job->update(['soundfresh_verify_status' => 'sending', 'soundfresh_verify_error' => null]);
        try {
            $soundfresh = $sessions->ensure(Platform::Soundfresh);
            if ($soundfresh->status === 'manual_auth_required') {
                throw new RuntimeException('Sesi Soundfresh perlu login ulang dari Pengaturan Platform.');
            }
            $result = $worker->verifySoundfreshIdentifiers(
                $job->soundfresh_release_url,
                (string) $job->soundon_upc,
                $isrcs,
                $soundfresh->session_state_encrypted,
            );
            if (($result['verified'] ?? false) !== true) {
                throw new RuntimeException('Soundfresh tidak mengonfirmasi Verify Release.');
            }
            // The operator may have pressed Stop while the browser request was
            // still in flight. Do not resurrect a job that was explicitly
            // cancelled from the status page.
            $job->refresh();
            if ($job->soundfresh_verify_status !== 'sending') {
                return;
            }
            $job->update([
                'soundfresh_workflow_status' => 'verified',
                'soundfresh_verify_status' => 'success',
                'soundfresh_verify_error' => null,
                'soundfresh_verified_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $job->refresh();
            if ($job->soundfresh_verify_status !== 'sending') {
                return;
            }
            $job->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => $exception->getMessage()]);
        }
    }
}

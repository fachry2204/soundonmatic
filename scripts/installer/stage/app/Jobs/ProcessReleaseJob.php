<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Automation\PrepareReleaseAssets;
use App\Actions\Automation\CleanupTemporaryAssets;
use App\Actions\Automation\ValidateReleaseMetadata;
use App\Enums\AutomationRunStatus;
use App\Enums\Platform;
use App\Enums\ReleaseCheckpoint;
use App\Enums\ReleaseJobStatus;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\ReleaseJob as Release;
use App\Services\Automation\AttentionNotifier;
use App\Services\Automation\AutomationErrorClassifier;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use App\Services\SoundOn\MetadataFieldSynchronizer;
use App\Services\SoundOn\SoundOnMetadataMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProcessReleaseJob implements ShouldQueue
{
    use Queueable;

    // Queue attempts also increase while WithoutOverlapping is waiting for the
    // single SoundOn slot. Keep that infrastructure allowance high; real
    // processing failures are limited separately by release_jobs.attempts.
    public int $tries = 100;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $releaseJobId)
    {
        $this->onQueue('release-automation');
    }

    public function middleware(): array
    {
        // Two queue workers may process different releases in isolated
        // Chromium contexts. Lock only the same release to block duplicate
        // queue messages from uploading it twice.
        return [(new WithoutOverlapping('soundon-release-'.$this->releaseJobId))->shared()->releaseAfter(10)->expireAfter(7200)];
    }

    public function handle(PlaywrightClient $worker, PrepareReleaseAssets $prepareAssets, CleanupTemporaryAssets $cleanupAssets, SessionManager $sessions, SoundOnMetadataMapper $mapper, MetadataFieldSynchronizer $fieldSynchronizer, ValidateReleaseMetadata $validator, AutomationErrorClassifier $classifier, AttentionNotifier $notifier): void
    {
        $job = Release::with('automationRun')->findOrFail($this->releaseJobId);
        // Retries and operator re-dispatches can leave an older queue message
        // behind. Never re-run a terminal release or increment completion twice.
        if (in_array($job->status, [ReleaseJobStatus::Completed, ReleaseJobStatus::Skipped], true)) {
            return;
        }
        if ($job->automationRun->stop_requested_at || $job->automationRun->status === AutomationRunStatus::Stopped) {
            $job->update(['status' => ReleaseJobStatus::Stopped, 'finished_at' => now()]);

            return;
        }
        if ($job->automationRun->status === AutomationRunStatus::Paused) {
            // Finish this queue message without consuming retry attempts. Queued
            // database records are dispatched again explicitly by ResumeAutomationRun.
            return;
        }

        // A stopped run can leave its original queue messages behind while
        // ResumeAutomationRun dispatches replacements. Only one message may
        // atomically claim a queued release, so duplicates exit harmlessly.
        $claimed = Release::query()
            ->whereKey($job->id)
            ->where('status', ReleaseJobStatus::Queued)
            ->update([
                'status' => ReleaseJobStatus::Running,
                'started_at' => $job->started_at ?? now(),
                'attempts' => $job->attempts + 1,
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return;
        }
        $job->refresh();
        try {
            // Only the claimed, per-release locked attempt may clear an older Stop.
            $worker->prepareAttempt($job->id);
            $this->progress($job, 8, 'Memulai rilisan dan menyiapkan sesi platform');
            $metadata = $this->reusableMetadata($job);
            if ($metadata === null) {
                $this->progress($job, 12, 'Membaca metadata rilisan dari Soundfresh');
                $soundfresh = $sessions->ensure(Platform::Soundfresh);
                $metadata = $worker->extract($job->id, $job->soundfresh_release_url, $soundfresh->session_state_encrypted);
                $metadata = $fieldSynchronizer->synchronize($metadata);
                $metadata = $mapper->mapAvailable($metadata);
                $job->update(['metadata_snapshot_json' => $metadata, 'release_title' => $metadata['title'] ?? $job->release_title, 'artist_name' => $metadata['primary_artist'] ?? $job->artist_name, 'release_type' => $metadata['release_type'] ?? null, 'track_count' => count($metadata['tracks'] ?? []), 'checkpoint' => ReleaseCheckpoint::MetadataExtracted]);
                $job->refresh();
            }
            $this->progress($job, 25, 'Metadata selesai dibaca dan divalidasi');
            if (! $this->hasReached($job, ReleaseCheckpoint::AssetsDownloaded)) {
                // Reject incomplete payloads before opening SoundOn or downloading
                // potentially large master/preview files. This also covers an
                // older metadata checkpoint created before this optimization.
                $metadata = $validator->handle($metadata);
                $job->update(['metadata_snapshot_json' => $metadata]);
            }
            if ($this->stopIfRequested($job)) {
                return;
            }
            $soundOn = $sessions->ensure(Platform::SoundOn);
            if ($soundOn->status === 'manual_auth_required') {
                $job->update(['status' => ReleaseJobStatus::ManualAuthRequired]);
                $job->automationRun->update(['status' => AutomationRunStatus::ManualAuthRequired]);

                return;
            }

            if (! $job->soundon_draft_id) {
                $forcedDuplicateUploadIds = array_map('strval', data_get($job->automationRun->summary_json, 'force_duplicate_upload_job_ids', []));
                $freshDuplicateCheckIds = array_map('strval', data_get($job->automationRun->summary_json, 'fresh_duplicate_check_job_ids', []));
                $forceDuplicateUpload = in_array((string) $job->id, $forcedDuplicateUploadIds, true);
                $needsFreshCheck = in_array((string) $job->id, $freshDuplicateCheckIds, true) || $job->attempts > 1;
                $duplicatesChecked = ! $needsFreshCheck && ($forceDuplicateUpload || (bool) data_get($job->automationRun->summary_json, 'duplicate_check_completed', false));
                $this->progress($job, 48, $forceDuplicateUpload
                    ? 'Upload paksa disetujui operator; melewati pemeriksaan duplikat'
                    : ($duplicatesChecked ? 'Memakai hasil pemeriksaan duplikat awal' : 'Memeriksa draft SoundOn untuk memastikan rilisan belum ada'));
                $existingDraft = $duplicatesChecked ? null : $worker->findDraft(
                    (string) ($metadata['title'] ?? $job->release_title),
                    (string) ($metadata['primary_artist'] ?? $job->artist_name),
                    $soundOn->session_state_encrypted,
                );
                if ($this->stopIfRequested($job)) {
                    return;
                }
                if ($existingDraft) {
                    $job->update([
                        'soundon_draft_id' => $existingDraft['draft_id'],
                        'soundon_draft_url' => $existingDraft['draft_url'] ?? null,
                        'soundon_draft_status' => 'already_exists',
                        'checkpoint' => ReleaseCheckpoint::DraftSaved,
                        'error_code' => 'DUPLICATE_RELEASE_FOUND',
                        'error_message' => 'Rilisan sudah dirilis sebelumnya di SoundOn Drafts (Draft ID '.$existingDraft['draft_id'].').',
                    ]);
                    $this->event($job, 'soundon_draft_already_exists', 'Matching SoundOn draft found by title and primary artist: Rilisan sudah dirilis sebelumnya di SoundOn Drafts.');
                } else {
                    if (! $this->hasReached($job, ReleaseCheckpoint::AssetsDownloaded)) {
                        $this->progress($job, 32, 'Mengunduh Cover Art, Audio Master, dan Audio Clip');
                        $metadata = $prepareAssets->handle($job, $metadata);
                        $job->refresh();
                    }
                    // Asset preparation can take minutes. Check again immediately
                    // before creation so a draft made meanwhile is never duplicated.
                    $this->progress($job, 50, 'Aset siap untuk upload');
                    $existingDraft = null;
                    if ($existingDraft) {
                        $job->update([
                            'soundon_draft_id' => $existingDraft['draft_id'],
                            'soundon_draft_url' => $existingDraft['draft_url'] ?? null,
                            'soundon_draft_status' => 'already_exists',
                            'checkpoint' => ReleaseCheckpoint::DraftSaved,
                        ]);
                        $this->event($job, 'soundon_draft_already_exists', 'Matching SoundOn draft found during final duplicate check.');
                    } else {
                        $this->progress($job, 55, 'Membuka SoundOn dan mengisi form draft');
                        $draft = $worker->createDraft($job->id, $metadata, $soundOn->session_state_encrypted, $duplicatesChecked);
                        $alreadyExists = (bool) ($draft['already_exists'] ?? false);
                        $this->progress($job, 95, $alreadyExists
                            ? 'Rilisan sudah ada di draft SoundOn; upload baru dibatalkan'
                            : 'Draft SoundOn tersimpan; menyelesaikan pencatatan');
                        $job->update([
                            'soundon_draft_id' => $draft['draft_id'],
                            'soundon_draft_url' => $draft['draft_url'] ?? null,
                            'soundon_draft_status' => $alreadyExists ? 'already_exists' : 'created',
                            'checkpoint' => ReleaseCheckpoint::DraftSaved,
                        ]);
                        if ($alreadyExists) {
                            $this->event($job, 'soundon_draft_already_exists', 'Pemeriksaan terakhir worker menemukan judul dan artis yang sama. Draft baru tidak dibuat.');
                        }
                    }
                    if ($this->stopIfRequested($job)) {
                        return;
                    }
                }
            }

            if ($this->stopIfRequested($job)) {
                return;
            }

            if (! $this->hasReached($job, ReleaseCheckpoint::SoundfreshReviewed)) {
                $this->progress($job, 97, 'Draft tersimpan; memindahkan rilisan Soundfresh ke Under Review');
                $soundfresh = $sessions->ensure(Platform::Soundfresh);
                if ($soundfresh->status === 'manual_auth_required') {
                    $job->update(['status' => ReleaseJobStatus::ManualAuthRequired]);
                    $job->automationRun->update(['status' => AutomationRunStatus::ManualAuthRequired]);

                    return;
                }
                $review = $worker->review(
                    $job->id,
                    $job->soundfresh_release_url,
                    (string) $job->soundon_draft_id,
                    $soundfresh->session_state_encrypted,
                );
                if (($review['reviewed'] ?? false) !== true) {
                    throw new \RuntimeException('Soundfresh tidak mengonfirmasi status Under Review.');
                }
                $job->update([
                    'checkpoint' => ReleaseCheckpoint::SoundfreshReviewed,
                    'soundfresh_workflow_status' => 'under_review',
                ]);
                $this->event($job, 'soundfresh_release_reviewed', 'Draft SoundOn tersimpan dan status Soundfresh dipindahkan dari Pending ke Under Review.');
            }

            $alreadyExisted = $job->fresh()->soundon_draft_status === 'already_exists';
            $job->update([
                'checkpoint' => ReleaseCheckpoint::Completed,
                'status' => ReleaseJobStatus::Completed,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => now(),
                'progress_percent' => 100,
                'progress_label' => $alreadyExisted
                    ? 'Sudah ada di draft SoundOn — upload dilewati'
                    : 'Draft SoundOn berhasil disimpan',
                'progress_updated_at' => now(),
            ]);
            $job->automationRun->increment('completed');
            $this->event($job, 'release_completed', 'SoundOn draft saved and Soundfresh release moved to Under Review. No publish was performed.');
            try {
                $deletedAssets = $cleanupAssets->handle($job->id);
                $this->event($job, 'release_assets_cleaned', $deletedAssets.' file aset lokal dihapus setelah draft SoundOn berhasil disimpan.');
            } catch (Throwable $cleanupError) {
                report($cleanupError);
                $this->event($job, 'release_assets_cleanup_failed', 'Draft SoundOn sudah berhasil, tetapi pembersihan file lokal gagal dan dapat diulang dari menu Pengaturan.');
            }
            $this->completeRunIfDone($job);
        } catch (Throwable $e) {
            if ($this->stopIfRequested($job)) {
                return;
            }
            if (str_contains(strtoupper($e->getMessage()), 'AUTH_SESSION_EXPIRED')) {
                // The remote session can expire between the periodic health
                // check and the actual extract/upload request. Clearing both
                // cached sessions makes the next queued attempt authenticate
                // again instead of reusing the same rejected browser state.
                $sessions->clear(Platform::Soundfresh);
                $sessions->clear(Platform::SoundOn);
            }
            $classification = $classifier->classify($e);
            $rawError = $e instanceof ValidationException
                ? collect($e->errors())->flatten()->implode(' ')
                : $e->getMessage();
            $message = $this->humanErrorMessage($classification['code'], $rawError);
            if ($classification['disposition'] === 'needs_attention') {
                $job->update(['status' => ReleaseJobStatus::NeedsAttention, 'error_code' => $classification['code'], 'error_message' => $message]);
                $this->event($job, 'release_needs_attention', $message);
                $notifier->send('release_needs_attention', 'Release requires operator attention.', $job->automation_run_id, $job->id, $classification['code']);

                return;
            }
            if ($classification['disposition'] === 'manual_auth') {
                $job->update(['status' => ReleaseJobStatus::ManualAuthRequired, 'error_code' => $classification['code'], 'error_message' => $message]);
                $job->automationRun->update(['status' => AutomationRunStatus::ManualAuthRequired]);
                $this->event($job, 'manual_auth_required', 'Operator authentication is required before resume.');
                $notifier->send('manual_auth_required', 'Platform authentication requires operator action.', $job->automation_run_id, $job->id, $classification['code']);

                return;
            }
            // Never let one remote/network/UI failure monopolize the single
            // SoundOn slot through automatic retries. Store the error and end
            // this queue message immediately; the operator can retry only this
            // release after the cause is fixed while the next release proceeds.
            $job->update([
                'status' => ReleaseJobStatus::Failed,
                'finished_at' => now(),
                'error_code' => $classification['code'],
                'error_message' => $message.' Antrean dilanjutkan ke rilisan berikutnya. Tekan Retry untuk mengulang rilisan ini saja.',
                'progress_label' => 'Proses gagal; antrean dilanjutkan ke rilisan berikutnya',
                'progress_updated_at' => now(),
            ]);
            $job->automationRun->increment('failed');
            $this->event($job, 'release_failed_final', 'Release failed once; queue continued to the next release.');
            $notifier->send('release_failed_final', $message, $job->automation_run_id, $job->id, $classification['code']);

            return;
        }
    }

    public function failed(Throwable $error): void
    {
        $job = Release::with('automationRun')->find($this->releaseJobId);
        if (! $job || $job->status === ReleaseJobStatus::Failed) {
            return;
        }
        if ($this->stopIfRequested($job)) {
            return;
        }
        // The catch path has already stored the operator-friendly explanation.
        // Do not replace it with the raw exception when retries are exhausted.
        $job->update([
            'status' => ReleaseJobStatus::Failed,
            'finished_at' => now(),
            'error_message' => filled($job->error_message)
                ? $job->error_message
                : $this->humanErrorMessage(
                    $job->error_code ?: 'QUEUE_ATTEMPTS_EXHAUSTED',
                    str_contains($error->getMessage(), 'attempted too many times')
                        ? 'Job terlalu lama menunggu slot proses tunggal dan belum sempat dijalankan.'
                        : $error->getMessage(),
                ),
        ]);
        $job->automationRun->increment('failed');
        if (! $job->automationRun->releaseJobs()->whereIn('status', [ReleaseJobStatus::Queued, ReleaseJobStatus::Running])->exists()) {
            $job->automationRun->update(['status' => AutomationRunStatus::Failed, 'finished_at' => now(), 'processed' => $job->automationRun->releaseJobs()->count()]);
        }
        $this->event($job, 'release_failed_final', 'Release job exhausted its retry policy.');
        app(AttentionNotifier::class)->send('release_failed_final', 'Release job exhausted its retry policy.', $job->automation_run_id, $job->id, $job->error_code);
    }

    private function completeRunIfDone(Release $job): void
    {
        $run = $job->automationRun->fresh();
        $run->update([
            'completed' => $run->releaseJobs()->where('status', ReleaseJobStatus::Completed)->count(),
            'failed' => $run->releaseJobs()->where('status', ReleaseJobStatus::Failed)->count(),
        ]);
        $run->refresh();
        if (! $run->releaseJobs()->whereNotIn('status', [ReleaseJobStatus::Completed, ReleaseJobStatus::Skipped])->exists()) {
            $run->update(['status' => AutomationRunStatus::Completed, 'finished_at' => now(), 'processed' => $run->releaseJobs()->count()]);
        }
    }

    private function stopIfRequested(Release $job): bool
    {
        $run = $job->automationRun()->first();
        if (! $run || (! $run->stop_requested_at && $run->status !== AutomationRunStatus::Stopped)) {
            return false;
        }

        $job->update([
            'status' => ReleaseJobStatus::Stopped,
            'finished_at' => now(),
        ]);

        return true;
    }

    private function event(Release $job, string $event, string $message): void
    {
        // A stopped/cleaned run can leave its queued release jobs behind.
        // Never let the diagnostic event itself crash the queue worker through
        // a foreign-key violation; the release status is still persisted.
        if (! $job->automation_run_id || ! AutomationRun::query()->whereKey($job->automation_run_id)->exists()) {
            return;
        }
        AutomationEvent::create([
            'automation_run_id' => $job->automation_run_id,
            'release_job_id' => $job->id,
            'event' => $event,
            'message' => $message,
        ]);
    }

    private function progress(Release $job, int $percent, string $label): void
    {
        $job->update([
            'progress_percent' => max(0, min(100, $percent)),
            'progress_label' => $label,
            'progress_updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function reusableMetadata(Release $job): ?array
    {
        $metadata = $job->metadata_snapshot_json;
        if (! is_array($metadata) || ! $this->hasReached($job, ReleaseCheckpoint::MetadataExtracted)) {
            return null;
        }
        if (blank($metadata['title'] ?? null) || blank($metadata['primary_artist'] ?? null) || empty($metadata['tracks'])) {
            return null;
        }

        return $metadata;
    }

    private function hasReached(Release $job, ReleaseCheckpoint $target): bool
    {
        $order = array_flip(array_map(
            static fn (ReleaseCheckpoint $checkpoint): string => $checkpoint->value,
            ReleaseCheckpoint::cases(),
        ));

        return ($order[$job->checkpoint?->value ?? ''] ?? -1) >= ($order[$target->value] ?? PHP_INT_MAX);
    }

    private function humanErrorMessage(string $code, string $raw): string
    {
        $detail = match ($code) {
            'AUDIO_INVALID' => 'Audio tidak sesuai persyaratan SoundOn. Periksa format WAV/FLAC, codec, sample rate, durasi, dan kondisi file.',
            'COVER_INVALID' => 'Cover art tidak sesuai persyaratan SoundOn. Periksa format JPG/PNG, dimensi, ukuran, dan kondisi file.',
            'AUDIO_UPLOAD_FAILED' => 'Audio Master gagal diunggah ke SoundOn. Periksa file tidak rusak, format WAV/FLAC didukung, ukuran file wajar, lalu tekan Retry.',
            'COVER_UPLOAD_FAILED' => 'Cover Art gagal diunggah ke SoundOn. Periksa file JPG/PNG, dimensi persegi, ukuran file, lalu tekan Retry.',
            'TIKTOK_AUDIO_UPLOAD_FAILED' => 'Audio Clip gagal diunggah ke SoundOn. Periksa potongan audio tersedia, dapat diputar, dan formatnya didukung, lalu tekan Retry.',
            'ALBUM_TRACK_UPLOAD_FAILED' => 'Salah satu Audio Master EP/Album tidak muncul setelah dipilih pada SoundOn. Detail teknis menyebut nama file track yang gagal; periksa file tersebut lalu tekan Retry.',
            'ASSET_MISSING' => 'File aset tidak ditemukan. Periksa Cover Art, Audio Master, dan Audio Clip pada Soundfresh, lalu tekan Retry.',
            'ASSET_RENAME_FAILED' => 'File berhasil diunduh tetapi tidak dapat dipindahkan ke folder rilisan. Periksa izin folder storage dan ruang disk, lalu tekan Retry.',
            'METADATA_MISSING' => 'Metadata wajib belum lengkap atau formatnya tidak valid. Baca nama field pada detail, perbaiki di Soundfresh, lalu tekan Retry.',
            'UPC_INVALID' => 'UPC tidak valid. UPC hanya boleh berisi 12 atau 13 digit angka.',
            'ISRC_INVALID' => 'ISRC tidak valid. ISRC harus berisi 12 karakter sesuai standar internasional.',
            'ORIGINAL_RELEASE_DATE_INVALID' => 'Original Released Date tidak valid atau belum diisi untuk rilis yang memiliki UPC.',
            'METADATA_MAPPING_MISSING' => 'Nilai bahasa, genre, subgenre, versi, atau role belum memiliki pilihan yang sesuai di SoundOn. Detail menunjukkan field dan nilainya; perbaiki mapping lalu tekan Retry.',
            'SOUNDON_ENTITY_MATCH_REQUIRED' => 'Nama artis atau contributor tidak menghasilkan pilihan yang dapat dipilih di SoundOn. Periksa ejaan nama dan role di Soundfresh atau buat profil artis di SoundOn, lalu tekan Retry.',
            'SOUNDON_CONTRIBUTOR_NOT_PERSISTED' => 'Artis, songwriter, lyricist, atau contributor sudah dicoba tetapi tidak tersimpan pada form SoundOn. Periksa nama/role dan kecocokan profil, lalu tekan Retry.',
            'SOUNDON_FORM_VALIDATION' => 'SoundOn menolak metadata pada form. Periksa detail field yang bermasalah sebelum Retry; proses tidak melanjutkan dengan data yang tidak lengkap.',
            'SOUNDON_FIELD_NOT_PERSISTED' => 'Nilai field sudah diisi tetapi tidak disimpan oleh form SoundOn. Detail menunjukkan field terkait; refresh sesi SoundOn lalu tekan Retry.',
            'PRE_RELEASE_INVALID' => 'Pilihan atau tanggal pre-release tidak berhasil disimpan. Periksa tanggal pre-release lebih awal dari tanggal rilis dan gunakan waktu 12:00 AM, lalu tekan Retry.',
            'AUTH_SESSION_EXPIRED' => 'Sesi SoundOn atau Soundfresh kedaluwarsa. Buka Pengaturan Platform, lakukan Login/refresh sesi, lalu tekan Continue atau Retry.',
            'AUTH_CAPTCHA_REQUIRED' => 'Platform meminta CAPTCHA. Buka Pengaturan Platform, selesaikan CAPTCHA secara manual, simpan sesi, lalu tekan Continue.',
            'AUTH_OTP_REQUIRED' => 'Platform meminta kode OTP. Buka Pengaturan Platform, selesaikan OTP secara manual, simpan sesi, lalu tekan Continue.',
            'AUTH_MANUAL_REQUIRED' => 'Platform meminta interaksi login manual. Buka Pengaturan Platform, login hingga berhasil, lalu tekan Continue.',
            'UI_INTERACTION_TIMEOUT' => 'Tombol atau kontrol SoundOn tidak merespons dalam batas waktu. Tutup modal yang terbuka, pastikan koneksi stabil, lalu tekan Retry.',
            'SOUNDON_RELEASE_TYPE_NOT_SELECTED' => 'SoundOn menampilkan pemilih jenis rilis, tetapi kartu EP / Album tidak ditemukan atau kliknya tidak membuka form Album Information. Ini masalah perubahan UI SoundOn, bukan metadata atau koneksi internet.',
            'SOUNDON_ALBUM_TIMEOUT' => 'Pemrosesan EP/Album melewati batas waktu karena pengunggahan atau pengisian track di halaman SoundOn terlalu lama. Detail teknis menunjukkan jumlah track dan durasinya; antrean tetap dilanjutkan ke rilisan berikutnya.',
            'NETWORK_TIMEOUT' => 'Koneksi ke Soundfresh atau SoundOn terlalu lambat atau terputus. Pastikan internet stabil; sistem akan mencoba ulang otomatis, atau tekan Retry setelah koneksi pulih.',
            'CONNECTION_INTERRUPTED' => 'Koneksi terputus saat data sedang dikirim atau diunduh. Pastikan internet stabil lalu tekan Retry.',
            'CONNECTION_FAILED' => 'Server Soundfresh/SoundOn tidak dapat dihubungi. Periksa internet, firewall, dan status platform lalu tekan Retry.',
            'DNS_ERROR' => 'Nama server tidak dapat ditemukan oleh DNS. Periksa koneksi internet atau DNS komputer lalu tekan Retry.',
            'WORKER_UNAVAILABLE' => 'Automation worker tidak dapat dihubungi. Tutup dan buka kembali aplikasi SoundOnMatic, lalu tekan Retry.',
            'RATE_LIMITED' => 'Platform membatasi terlalu banyak permintaan. Tunggu beberapa menit; sistem akan mencoba ulang otomatis.',
            'QUEUE_ATTEMPTS_EXHAUSTED' => 'Job kehabisan jatah antrean sebelum sempat diproses. Ini masalah antrean internal, bukan kesalahan metadata. Tekan Retry; job akan kembali menunggu giliran tanpa mengulang job lain.',
            'UPLOAD_FAILED' => 'Pengunggahan gagal tetapi jenis file belum dapat ditentukan. Periksa koneksi serta ketiga aset, lalu tekan Retry.',
            'UI_CHANGED' => 'Field, tombol, atau langkah yang diperlukan tidak ditemukan karena tampilan SoundOn berubah. Detail menunjukkan elemen yang hilang; laporkan detail tersebut sebelum Retry.',
            default => 'Proses otomatisasi gagal karena respons worker atau platform tidak dikenali. Periksa detail teknis, pastikan aplikasi dan koneksi aktif, lalu tekan Retry.',
        };
        $workerDetail = preg_replace('/^HTTP request returned status code \d+:\s*/i', '', trim($raw)) ?: trim($raw);

        return mb_substr($detail.' Detail teknis: '.$workerDetail, 0, 2000);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use App\Services\Automation\FailedUploadRejection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class RejectSoundfreshRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $releaseJobId, public readonly string $reason,
        public readonly bool $failedUpload = false, public readonly ?string $failureFingerprint = null)
    {
        $this->onQueue('soundfresh-rejects');
    }

    public function handle(PlaywrightClient $worker, SessionManager $sessions): void
    {
        $job = ReleaseJob::query()->findOrFail($this->releaseJobId);
        if (($this->failedUpload ?? false) && (! FailedUploadRejection::eligible($job, true)
            || ! hash_equals(FailedUploadRejection::fingerprint($job), (string) ($this->failureFingerprint ?? '')))) {
            if ($job->soundfresh_verify_status === 'rejecting') {
                $job->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Reject dibatalkan: kondisi kegagalan rilisan sudah berubah.']);
            }

            return;
        }
        if (! ($this->failedUpload ?? false) && ($job->soundon_release_status !== 'not_approved' || $job->soundfresh_workflow_status !== 'uploading')) {
            $job->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Reject hanya tersedia untuk rilisan Uploading berstatus Not Approved.']);
            return;
        }

        if ($job->soundfresh_verify_status !== 'rejecting') return;

        try {
            $job->update(['progress_percent' => 15, 'progress_label' => 'Membuka sesi Soundfresh untuk Reject', 'progress_updated_at' => now()]);
            if (trim($this->reason) === '' || mb_strlen($this->reason) > 3000) throw new RuntimeException('Alasan Reject wajib diisi, maksimal 3000 karakter.');
            $soundfresh = $sessions->ensure(Platform::Soundfresh);
            if ($soundfresh->status === 'manual_auth_required') {
                throw new RuntimeException('Sesi Soundfresh perlu login ulang dari Pengaturan Platform.');
            }
            $job->update(['progress_percent' => 50, 'progress_label' => 'Mengirim alasan Reject ke Soundfresh', 'progress_updated_at' => now()]);
            $result = $worker->rejectSoundfreshRelease($job->soundfresh_release_url, $this->reason, $soundfresh->session_state_encrypted);
            if (($result['rejected'] ?? false) !== true) {
                throw new RuntimeException('Soundfresh tidak mengonfirmasi penolakan rilisan.');
            }
            $job->refresh();
            if ($job->soundfresh_verify_status !== 'rejecting') {
                return;
            }
            $job->update([
                'soundfresh_workflow_status' => 'rejected',
                'soundfresh_verify_status' => 'rejected',
                'soundfresh_verify_error' => null,
                'soundfresh_rejected_at' => now(),
                'progress_percent' => 100, 'progress_label' => 'Reject berhasil dikonfirmasi Soundfresh', 'progress_updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $job->refresh();
            if ($job->soundfresh_verify_status !== 'rejecting') {
                return;
            }
            $job->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => $exception->getMessage(),
                'progress_label' => 'Reject gagal — periksa alasan dan coba kembali', 'progress_updated_at' => now()]);
        }
    }
    public function failed(?Throwable $exception): void
    {
        ReleaseJob::query()->whereKey($this->releaseJobId)->where('soundfresh_verify_status', 'rejecting')
            ->update(['soundfresh_verify_status' => 'failed',
                'soundfresh_verify_error' => 'Proses Reject berhenti sebelum Soundfresh mengonfirmasi. Periksa status rilisan sebelum mencoba kembali.',
                'progress_label' => 'Reject terhenti — perlu pemeriksaan', 'progress_updated_at' => now()]);
    }

}

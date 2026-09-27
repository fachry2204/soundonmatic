<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Enums\Platform;
use App\Exceptions\CredentialsNotConfiguredException;
use App\Services\Automation\SessionManager;
use App\Jobs\CheckSoundOnReleaseStatus;
use App\Jobs\QueueSoundOnStatusChecks;
use App\Jobs\VerifySoundfreshReleaseIdentifiers;
use App\Jobs\RejectSoundfreshRelease;
use App\Models\ReleaseJob;
use App\Models\AutomationRun;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReleaseStatus extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url(as: 'soundon')]
    public string $soundOnStatus = 'all';

    public string $releaseDateFilter = 'all';

    public ?string $syncMessage = null;

    public ?string $sendingJobId = null;

    public ?string $rejectingJobId = null;

    public string $rejectReason = '';

    public bool $bulkCheckRequested = false;

    public string $checkSourceTab = 'both';

    /** @var array<int, string> */
    public array $selectedJobIds = [];

    /**
     * IDs in the latest batch submitted from this browser.  Keeping these in
     * component state lets the polling view show real progress while the
     * Soundfresh worker is doing the updates.
     *
     * @var array<int, string>
     */
    public array $verificationJobIds = [];

    /** @param array<int, string> $ids */
    public function toggleAllVisible(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        $allSelected = $ids !== [] && count(array_intersect($ids, $this->selectedJobIds)) === count($ids);
        $this->selectedJobIds = $allSelected
            ? array_values(array_diff($this->selectedJobIds, $ids))
            : array_values(array_unique([...$this->selectedJobIds, ...$ids]));
    }

    public function verifySelected(): void
    {
        $selected = ReleaseJob::query()->whereIn('id', $this->selectedJobIds)->get();
        $eligible = $selected->filter(function (ReleaseJob $job): bool {
            $isrcs = array_values(array_filter((array) $job->soundon_isrcs_json));
            return $job->soundfresh_workflow_status === 'uploading'
                && filled($job->soundon_upc)
                && count($isrcs) >= max(1, (int) $job->track_count)
                && ! in_array($job->soundfresh_verify_status, ['success', 'sending', 'rejecting'], true);
        });
        if ($selected->isEmpty()) {
            $this->syncMessage = 'Pilih minimal satu rilisan Uploading yang UPC dan seluruh ISRC-nya sudah tersedia terlebih dahulu.';

            return;
        }

        $this->verificationJobIds = $eligible->pluck('id')->values()->all();
        foreach ($eligible as $job) {
            $job->update(['soundfresh_verify_status' => 'sending', 'soundfresh_verify_error' => null]);
            VerifySoundfreshReleaseIdentifiers::dispatch($job->id);
        }
        $skipped = $selected->count() - $eligible->count();
        $this->selectedJobIds = [];
        $this->syncMessage = $eligible->count().' rilisan diantrikan untuk Verify Release dan pengisian UPC/ISRC di Soundfresh'.($skipped > 0 ? '; '.$skipped.' dilewati karena belum memenuhi syarat.' : '.');
    }

    /** Stop only the UPC/ISRC verification batch shown in this page. */
    public function stopVerificationUpdates(): void
    {
        if ($this->verificationJobIds === []) {
            $this->verificationJobIds = ReleaseJob::query()
                ->whereIn('soundfresh_verify_status', ['sending', 'rejecting'])
                ->pluck('id')
                ->values()
                ->all();
        }
        if ($this->verificationJobIds === []) {
            $this->syncMessage = 'Tidak ada proses update UPC/ISRC yang sedang berjalan.';

            return;
        }

        $ids = array_values(array_unique($this->verificationJobIds));
        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += DB::table('jobs')
                ->where('queue', 'status-checks')
                ->where('payload', 'like', '%'.$id.'%')
                ->delete();
        }
        $stopped = ReleaseJob::query()
            ->whereIn('id', $ids)
            ->whereIn('soundfresh_verify_status', ['sending', 'rejecting'])
            ->update([
                'soundfresh_verify_status' => 'failed',
                'soundfresh_verify_error' => 'Dihentikan oleh operator sebelum update UPC/ISRC selesai. Klik tombol Gagal untuk mencoba kembali.',
            ]);

        $this->syncMessage = $stopped.' proses update UPC/ISRC dihentikan. '.$deleted.' pekerjaan antrean dibatalkan.';
    }

    public function refreshSoundOnStatuses(SessionManager $sessions): void
    {
        try {
            $sessions->assertReadable(Platform::Soundfresh);
            $sessions->assertReadable(Platform::SoundOn);
        } catch (CredentialsNotConfiguredException $error) {
            $label = $error->platform === Platform::Soundfresh ? 'Soundfresh' : 'SoundOn';
            $this->syncMessage = $error->keyMismatch
                ? "Credential {$label} dari database lama tidak dapat dibaca. Masukkan ulang email dan password di Pengaturan Platform sebelum cek status."
                : "Credential {$label} belum lengkap. Isi di Pengaturan Platform sebelum cek status.";

            return;
        }

        if (! in_array($this->checkSourceTab, ['under_review', 'uploading', 'both'], true)) {
            $this->checkSourceTab = 'both';
        }
        $alreadyRunning = AutomationRun::query()
            ->where('status', 'running')
            ->where('summary_json->purpose', 'soundon_status_check')
            ->whereHas('releaseJobs', fn ($query) => $query->whereIn('soundon_check_status', ['queued', 'checking']))
            ->exists();
        if ($alreadyRunning) {
            $this->bulkCheckRequested = true;
            $this->syncMessage = 'Pemeriksaan SoundOn masih berjalan. Tunggu sampai selesai agar antrean tidak terduplikasi.';

            return;
        }
        // Hide previous check results immediately without deleting upload
        // history or assets. Newly collected rows become visible when queued.
        ReleaseJob::query()->whereIn('soundfresh_workflow_status', match ($this->checkSourceTab) {
            'under_review' => ['under_review'],
            'uploading' => ['uploading'],
            default => ['under_review', 'uploading'],
        })->update(['soundon_check_status' => null]);
        $this->selectedJobIds = [];
        QueueSoundOnStatusChecks::dispatch(auth()->id(), $this->checkSourceTab);
        $this->bulkCheckRequested = true;
        $label = match ($this->checkSourceTab) {
            'under_review' => 'Under Review',
            'uploading' => 'Uploading',
            default => 'Under Review dan Uploading',
        };
        $this->syncMessage = 'Sedang memeriksa tab '.$label.' Soundfresh terhadap SoundOn.';
    }

    public function pollStatusUpdates(): void
    {
        $queueActive = DB::table('jobs')->where('queue', 'status-checks')->exists();
        $releaseActive = ReleaseJob::query()->whereIn('soundon_check_status', ['queued', 'checking'])->exists();
        $recoveredInterruptedChecks = false;

        // A PHP fatal error (for example an HTTP timeout) can terminate a queue
        // process before the job's catch/finally blocks run. Do not leave the UI
        // animating forever when there is no queued/reserved work left.
        if (! $queueActive && $releaseActive) {
            $recoveredInterruptedChecks = ReleaseJob::query()
                ->whereIn('soundon_check_status', ['queued', 'checking'])
                ->update([
                    'soundon_check_status' => 'failed',
                    'soundon_check_progress' => 100,
                    'soundon_check_error' => 'Pemeriksaan terputus karena worker berhenti atau melewati batas waktu. Silakan periksa ulang rilisan ini.',
                    'soundon_check_finished_at' => now(),
                ]) > 0;
            $releaseActive = false;
        }

        if (! $queueActive && ! $releaseActive) {
            $this->bulkCheckRequested = false;
            $this->syncMessage = $recoveredInterruptedChecks
                ? 'Pemeriksaan telah berhenti. Semua status loading sudah difinalisasi; rilisan yang terputus dapat diperiksa ulang.'
                : 'Pemeriksaan rilisan Under Review dan Uploading telah selesai.';
        }
    }

    public function checkSoundOnStatus(string $jobId): void
    {
        $job = ReleaseJob::query()->findOrFail($jobId);
        if (! in_array($job->soundfresh_workflow_status, ['under_review', 'uploading'], true)) {
            $this->syncMessage = 'Pemeriksaan hanya dapat dijalankan untuk rilisan pada tab Under Review atau Uploading Soundfresh.';

            return;
        }
        $job->update([
            'soundon_check_status' => 'queued',
            'soundon_check_progress' => 5,
            'soundon_check_error' => null,
            'soundon_check_started_at' => null,
            'soundon_check_finished_at' => null,
        ]);
        $sourceStatus = $job->soundfresh_workflow_status;
        CheckSoundOnReleaseStatus::dispatch($job->id, null, $sourceStatus);
        $this->syncMessage = 'Pemeriksaan SoundOn untuk '.$job->release_title.' sudah masuk antrean.';
    }

    public function sendToSoundfresh(string $jobId): void
    {
        $job = ReleaseJob::query()->findOrFail($jobId);
        if (in_array($job->soundfresh_verify_status, ['sending', 'rejecting'], true)) {
            $this->syncMessage = 'Pengiriman untuk '.$job->release_title.' masih berada dalam antrean. Tunggu hasilnya sebelum mencoba kembali.';

            return;
        }
        $isrcs = array_values(array_filter((array) $job->soundon_isrcs_json));
        if ($job->soundfresh_workflow_status !== 'uploading') {
            $this->syncMessage = 'Rilisan harus berada di tab Uploading Soundfresh sebelum Verify Release.';

            return;
        }
        if (! filled($job->soundon_upc) || $isrcs === []) {
            $this->syncMessage = 'UPC dan ISRC SoundOn belum tersedia untuk rilisan ini.';

            return;
        }
        if (count($isrcs) < max(1, (int) $job->track_count)) {
            $this->syncMessage = 'Jumlah ISRC belum sesuai dengan jumlah track rilisan.';

            return;
        }

        $job->update(['soundfresh_verify_status' => 'sending', 'soundfresh_verify_error' => null]);
        $this->verificationJobIds = array_values(array_unique([...$this->verificationJobIds, $jobId]));
        VerifySoundfreshReleaseIdentifiers::dispatch($job->id);
        $this->syncMessage = 'Pengiriman UPC dan ISRC untuk '.$job->release_title.' telah diantrikan. Status akan diperbarui otomatis.';
    }

    public function openRejectInSoundfresh(string $jobId): void
    {
        $job = ReleaseJob::query()->findOrFail($jobId);
        if ($job->soundon_release_status !== 'not_approved' || $job->soundfresh_workflow_status !== 'uploading') {
            $this->syncMessage = 'Reject hanya tersedia untuk rilisan Uploading yang berstatus Not Approved di SoundOn.';

            return;
        }
        $this->rejectingJobId = $job->id;
        $this->rejectReason = trim((string) $job->soundon_rejection_reason);
        $this->resetValidation('rejectReason');
    }

    public function cancelRejectInSoundfresh(): void
    {
        $this->rejectingJobId = null;
        $this->rejectReason = '';
        $this->resetValidation('rejectReason');
    }

    public function rejectInSoundfresh(): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'max:3000']]);
        $jobId = $this->rejectingJobId;
        abort_unless($jobId, 422, 'Rilisan untuk Reject belum dipilih.');
        $job = ReleaseJob::query()->findOrFail($jobId);
        $reason = trim($this->rejectReason);
        if ($job->soundon_release_status !== 'not_approved') {
            $this->syncMessage = 'Aksi Reject hanya tersedia untuk rilisan berstatus Not Approved di SoundOn.';
            return;
        }
        if ($job->soundfresh_workflow_status !== 'uploading') {
            $this->syncMessage = 'Rilisan harus berada di tab Uploading Soundfresh sebelum ditolak.';
            return;
        }
        $job->update(['soundfresh_verify_status' => 'rejecting', 'soundfresh_verify_error' => null]);
        RejectSoundfreshRelease::dispatch($job->id, $reason);
        $this->syncMessage = 'Reject untuk '.$job->release_title.' telah diantrikan. Worker akan mengirim alasan ke Soundfresh.';
        $this->cancelRejectInSoundfresh();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'soundOnStatus', 'releaseDateFilter');
        $this->status = 'all';
        $this->soundOnStatus = 'all';
        $this->releaseDateFilter = 'all';
    }

    public function filterBySoundOnStatus(string $status): void
    {
        if (! in_array($status, ['all', 'under_review', 'delivery', 'approved', 'not_approved', 'live'], true)) {
            return;
        }

        $this->soundOnStatus = $status;
        $this->releaseDateFilter = 'all';
    }

    public function filterByReleaseDate(string $filter): void
    {
        if (! in_array($filter, ['today', 'overdue'], true)) {
            return;
        }

        // These cards intentionally only track releases that SoundOn still
        // lists as Under Review. Other delivery states are not actionable as
        // "today" or "overdue" review items.
        $this->releaseDateFilter = $filter;
        $this->soundOnStatus = 'under_review';
    }

    public function downloadUnderReviewReport(): StreamedResponse
    {
        $today = CarbonImmutable::now($this->releaseTimezone())->startOfDay();
        $jobs = ReleaseJob::query()
            ->whereNotNull('soundon_check_status')
            ->where('soundon_release_status', 'under_review')
            ->get()
            ->filter(function (ReleaseJob $job) use ($today): bool {
                $releaseDate = $this->releaseDateFor($job);

                return $releaseDate !== null && $releaseDate->lessThanOrEqualTo($today);
            })
            ->sortBy(fn (ReleaseJob $job): int => $this->releaseDateFor($job)?->getTimestamp() ?? PHP_INT_MAX)
            ->values();
        $filename = 'rilis-under-review-'.$today->format('Y-m-d').'.xls';

        return response()->streamDownload(function () use ($jobs): void {
            echo '<html><head><meta charset="UTF-8"></head><body><table border="1">';
            echo '<thead><tr><th>Judul Rilis</th><th>Nama Artis</th><th>Tanggal Rilis</th><th>Link SoundOn</th></tr></thead><tbody>';
            foreach ($jobs as $job) {
                $cells = [
                    $job->release_title ?: 'Rilisan #'.$job->soundfresh_release_id,
                    $job->artist_name ?: '',
                    $this->releaseDateFor($job)?->format('Y-m-d') ?? '',
                    $job->soundon_draft_url ?: '',
                ];
                echo '<tr>';
                foreach ($cells as $cell) {
                    echo '<td>'.htmlspecialchars((string) $cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></body></html>';
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function render()
    {
        $sourceStatuses = match ($this->checkSourceTab) {
            'under_review' => ['under_review'],
            'uploading' => ['uploading'],
            default => ['under_review', 'uploading'],
        };

        $query = ReleaseJob::query()
            ->with('automationRun')
            ->whereNotNull('soundon_check_status')
            ->whereIn('soundfresh_workflow_status', $sourceStatuses)
            ->latest('updated_at');

        $metricQuery = ReleaseJob::query()
            ->whereNotNull('soundon_check_status')
            ->whereIn('soundfresh_workflow_status', $sourceStatuses);
        $reviewDateJobs = (clone $metricQuery)
            ->where('soundon_release_status', 'under_review')
            ->get(['id', 'metadata_snapshot_json']);
        $today = CarbonImmutable::now($this->releaseTimezone())->startOfDay();
        $todayReviewIds = $reviewDateJobs
            ->filter(fn (ReleaseJob $job): bool => $this->releaseDateFor($job)?->isSameDay($today) ?? false)
            ->pluck('id')
            ->all();
        $overdueReviewIds = $reviewDateJobs
            ->filter(fn (ReleaseJob $job): bool => ($date = $this->releaseDateFor($job)) !== null && $date->lessThan($today))
            ->pluck('id')
            ->all();

        $search = trim($this->search);
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('release_title', 'like', "%{$search}%")
                    ->orWhere('artist_name', 'like', "%{$search}%")
                    ->orWhere('soundfresh_release_id', 'like', "%{$search}%")
                    ->orWhere('soundon_draft_id', 'like', "%{$search}%");
            });
        }

        if ($this->status !== 'all') {
            $query->where('soundon_check_status', $this->status);
        }

        if (in_array($this->soundOnStatus, ['under_review', 'delivery', 'approved', 'not_approved', 'live'], true)) {
            $query->where('soundon_release_status', $this->soundOnStatus);
        } elseif ($this->soundOnStatus === 'pending') {
            $query->whereNull('soundon_release_status');
        }

        if ($this->releaseDateFilter === 'today') {
            $query->whereIn('id', $todayReviewIds);
        } elseif ($this->releaseDateFilter === 'overdue') {
            $query->whereIn('id', $overdueReviewIds);
        }

        $jobs = $query->limit(200)->get();
        $selectableJobIds = $jobs->filter(function (ReleaseJob $job): bool {
            $isrcs = array_values(array_filter((array) $job->soundon_isrcs_json));
            return $job->soundfresh_workflow_status === 'uploading'
                && filled($job->soundon_upc)
                && count($isrcs) >= max(1, (int) $job->track_count)
                && ! in_array($job->soundfresh_verify_status, ['success', 'sending', 'rejecting'], true);
        })->pluck('id')->values()->all();

        // On a full page refresh Livewire loses the in-memory batch IDs. Fall
        // back to all active verification records so the progress/Stop
        // controls remain available while the worker continues processing.
        $verificationJobs = $this->verificationJobIds === []
            ? ReleaseJob::query()->whereIn('soundfresh_verify_status', ['sending', 'rejecting'])->get()
            : ReleaseJob::query()->whereIn('id', $this->verificationJobIds)->get();
        $verificationTotal = $verificationJobs->count();
        $verificationSending = $verificationJobs->where('soundfresh_verify_status', 'sending')->count();
        $verificationSucceeded = $verificationJobs->where('soundfresh_verify_status', 'success')->count();
        $verificationFailed = $verificationJobs->where('soundfresh_verify_status', 'failed')->count();
        $verificationFinished = $verificationSucceeded + $verificationFailed;
        $verificationProgress = $verificationTotal > 0
            ? (int) floor(($verificationFinished / $verificationTotal) * 100)
            : 0;
        $verificationCurrentTitle = $verificationJobs
            ->firstWhere('soundfresh_verify_status', 'sending')?->release_title;

        return view('livewire.automation.release-status', [
            'jobs' => $jobs,
            'selectableJobIds' => $selectableJobIds,
            'totalJobs' => (clone $metricQuery)->count(),
            'underReviewJobs' => (clone $metricQuery)->where('soundon_release_status', 'under_review')->count(),
            'todayReviewJobs' => count($todayReviewIds),
            'overdueReviewJobs' => count($overdueReviewIds),
            'deliveredJobs' => (clone $metricQuery)->where('soundon_release_status', 'delivery')->count(),
            'approvedJobs' => (clone $metricQuery)->where('soundon_release_status', 'approved')->count(),
            'notApprovedJobs' => (clone $metricQuery)->where('soundon_release_status', 'not_approved')->count(),
            'liveJobs' => (clone $metricQuery)->where('soundon_release_status', 'live')->count(),
            'verificationTotal' => $verificationTotal,
            'verificationSending' => $verificationSending,
            'verificationSucceeded' => $verificationSucceeded,
            'verificationFailed' => $verificationFailed,
            'verificationProgress' => $verificationProgress,
            'verificationCurrentTitle' => $verificationCurrentTitle,
        ]);
    }

    private function releaseTimezone(): string
    {
        return (string) config('automation.release_timezone', 'Asia/Bangkok');
    }

    private function releaseDateFor(ReleaseJob $job): ?CarbonImmutable
    {
        $value = data_get($job->metadata_snapshot_json, 'release_date');
        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, $this->releaseTimezone())->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}

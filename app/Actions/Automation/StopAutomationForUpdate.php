<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\LocalSystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StopAutomationForUpdate
{
    public function handle(LocalSystemManager $system): void
    {
        // Signal cancellation before stopping the processes, so queue recovery
        // and in-flight work do not resume this run during the download.
        AutomationRun::query()->whereIn('status', ['queued', 'running', 'paused'])
            ->update(['status' => 'stopped', 'stop_requested_at' => now(), 'finished_at' => now()]);

        $status = $system->stopAllWorkers();
        if (! LocalSystemManager::workersStopped($status)) {
            $active = [];
            if ($status['worker'] ?? false) $active[] = 'browser otomatis';
            foreach (['release_workers' => 'upload', 'status_workers' => 'cek status', 'reject_workers' => 'Reject'] as $key => $label) {
                if (($status[$key] ?? 0) > 0) $active[] = $label.' ('.$status[$key].')';
            }
            throw new RuntimeException('Worker masih aktif: '.implode(', ', $active).'.');
        }

        DB::transaction(function (): void {
            $now = now();
            ReleaseJob::query()->whereIn('status', ['queued', 'running'])
                ->update(['status' => 'stopped', 'finished_at' => $now, 'updated_at' => $now]);
            ReleaseJob::query()->whereIn('soundon_check_status', ['queued', 'checking'])
                ->update(['soundon_check_status' => 'stopped', 'soundon_check_progress' => 100,
                    'soundon_check_error' => 'Pemeriksaan dihentikan untuk update aplikasi.',
                    'soundon_status_checked_at' => $now, 'soundon_check_finished_at' => $now, 'updated_at' => $now]);
            ReleaseJob::query()->whereIn('soundfresh_verify_status', ['sending', 'rejecting'])
                ->update(['soundfresh_verify_status' => 'failed',
                    'soundfresh_verify_error' => 'Dihentikan untuk update aplikasi.', 'updated_at' => $now]);
            DB::table('jobs')->whereIn('queue', ['release-automation', 'status-checks', 'soundfresh-rejects'])->delete();
        });
    }
}

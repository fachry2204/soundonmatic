<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecoverInterruptedAutomation extends Command
{
    protected $signature = 'soundonmatic:recover-interrupted {--fresh-session : Start with empty release monitors instead of resuming old work}';

    protected $description = 'Recover a release interrupted by an application or worker restart';

    public function handle(): int
    {
        // A partially written/legacy desktop database must never prevent the
        // application from booting. Eloquent casts the column to a backed enum,
        // so repair unknown raw values before any AutomationRun model is read.
        $validRunStatuses = array_map(
            static fn (AutomationRunStatus $status): string => $status->value,
            AutomationRunStatus::cases(),
        );
        $repairedRuns = DB::table('automation_runs')
            ->where(function ($query) use ($validRunStatuses): void {
                $query->whereNull('status')->orWhereNotIn('status', $validRunStatuses);
            })
            ->update([
                'status' => AutomationRunStatus::Failed->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        // WithoutOverlapping uses this exact shared lock key. At startup all
        // owned queue workers have already been stopped, so any remaining lock
        // is stale and may safely be released.
        Cache::lock('laravel-queue-overlap:soundon-release-global')->forceRelease();

        // The desktop process owns this queue and has already stopped its old
        // workers before this command runs. Rebuild its pending messages once,
        // removing reserved/orphaned/duplicate messages left by a forced close.
        DB::table('jobs')->whereIn('queue', ['release-automation', 'status-checks', 'soundfresh-rejects'])->delete();

        if ($this->option('fresh-session')) {
            DB::transaction(function (): void {
                ReleaseJob::query()->whereIn('status', ['running', 'queued'])->update([
                    'status' => ReleaseJobStatus::Stopped, 'finished_at' => now(),
                ]);
                ReleaseJob::query()->whereIn('soundon_check_status', ['queued', 'checking'])->update([
                    'soundon_check_status' => 'failed', 'soundon_check_progress' => 100,
                    'soundon_check_error' => 'Dihentikan saat aplikasi dibuka ulang. Jalankan pemeriksaan baru.',
                    'soundon_check_finished_at' => now(),
                ]);
                ReleaseJob::query()->whereIn('soundfresh_verify_status', ['sending', 'rejecting'])->update([
                    'soundfresh_verify_status' => 'failed',
                    'soundfresh_verify_error' => 'Dihentikan saat aplikasi dibuka ulang. Periksa status Soundfresh sebelum mencoba kembali.',
                ]);
                AutomationRun::query()->eachById(function (AutomationRun $run): void {
                    $values = ['summary_json' => [...($run->summary_json ?? []), 'monitor_archived' => true]];
                    if (in_array($run->status->value, ['running', 'queued', 'paused'], true)) {
                        $values += ['status' => AutomationRunStatus::Stopped, 'stop_requested_at' => now(), 'finished_at' => now()];
                    }
                    $run->update($values);
                });
            });
            Cache::forever('soundmatic.desktop-session', (string) Str::uuid());
            $this->info('Started a fresh application session; release monitors cleared without removing release data.');

            return self::SUCCESS;
        }

        $interrupted = ReleaseJob::query()
            ->whereIn('status', [ReleaseJobStatus::Running, ReleaseJobStatus::Queued])
            ->whereHas('automationRun', fn ($query) => $query->where('status', AutomationRunStatus::Running))
            ->pluck('id');

        foreach ($interrupted as $releaseJobId) {
            ReleaseJob::query()->whereKey($releaseJobId)->update([
                'status' => ReleaseJobStatus::Queued,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => null,
                'updated_at' => now(),
            ]);
            ProcessReleaseJob::dispatch((string) $releaseJobId);
        }

        $this->info('Repaired '.$repairedRuns.' invalid run status value(s); rebuilt '.$interrupted->count().' active release queue message(s).');

        return self::SUCCESS;
    }
}

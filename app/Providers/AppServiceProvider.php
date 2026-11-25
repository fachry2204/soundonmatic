<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $diagnostics = app(\App\Services\Diagnostics\ErrorData::class);
        $diagnostics->initialize();
        \Illuminate\Support\Facades\Log::listen(fn ($event) => $diagnostics->message($event));
        \App\Models\ReleaseJob::created(fn ($job) => $diagnostics->release($job, true));
        \App\Models\ReleaseJob::updated(fn ($job) => $diagnostics->release($job));
        \App\Models\AutomationRun::saved(function ($run) use ($diagnostics): void {
            if ($run->wasChanged('summary_json') && filled($error = data_get($run->summary_json, 'error'))) {
                $category = data_get($run->summary_json, 'purpose') === 'soundon_status_check' ? 'cek status' : 'upload rilis';
                $diagnostics->write($category, (string) $error, ['run_id' => $run->id, 'source' => 'automation_run']);
            }
        });
        \Illuminate\Support\Facades\Queue::failing(function ($event) use ($diagnostics): void {
            $category = match ($event->job->getQueue()) {
                'status-checks' => 'cek status', 'release-automation' => 'upload rilis', default => 'lainnya',
            };
            $diagnostics->write($category, $event->exception->getMessage(), [
                'source' => 'queue_failed', 'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(), 'job_id' => $event->job->getJobId(),
            ]);
        });
        $this->app['queue']->addConnector('database', function () {
            return new class($this->app['db']) extends \Illuminate\Queue\Connectors\DatabaseConnector {
                public function connect(array $config)
                {
                    return new \App\Services\Automation\RetryingDatabaseQueue(
                        $this->connections->connection($config['connection'] ?? null),
                        $config['table'], $config['queue'],
                        $config['retry_after'] ?? 60, $config['after_commit'] ?? null,
                    );
                }
            };
        });
    }
}

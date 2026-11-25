<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RecoverInterruptedAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_desktop_session_clears_monitors_without_resuming_or_deleting_release_data(): void
    {
        Queue::fake();
        $pipeline = AutomationRun::create(['status' => AutomationRunStatus::Running, 'pending_found' => 1]);
        $scan = AutomationRun::create(['status' => AutomationRunStatus::Running,
            'summary_json' => ['purpose' => 'soundon_status_check'], 'pending_found' => 1]);
        foreach ([$pipeline, $scan] as $index => $run) {
            ReleaseJob::create([
                'automation_run_id' => $run->id, 'soundfresh_release_id' => 'SESSION-'.$index,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/session-'.$index,
                'idempotency_key' => 'session-'.$index, 'release_title' => 'Sesi lama '.$index,
                'status' => ReleaseJobStatus::Running, 'soundfresh_workflow_status' => 'uploading',
                'soundon_check_status' => 'checking', 'soundfresh_verify_status' => 'sending',
                'soundon_draft_id' => 'saved-draft-'.$index, 'soundon_upc' => '5063962826298',
                'soundon_isrcs_json' => ['QT8CF2691505'],
            ]);
        }
        $this->artisan('soundonmatic:recover-interrupted --fresh-session')->assertSuccessful();
        $firstSession = Cache::get('soundmatic.desktop-session');
        $this->assertNotEmpty($firstSession);
        $this->assertSame('soundon_status_check', $scan->fresh()->summary_json['purpose']);
        foreach (ReleaseJob::all() as $job) {
            $this->assertSame(ReleaseJobStatus::Stopped, $job->status);
            $this->assertSame('failed', $job->soundon_check_status);
            $this->assertSame('failed', $job->soundfresh_verify_status);
            $this->assertNotEmpty($job->soundon_draft_id);
            $this->assertSame('5063962826298', $job->soundon_upc);
            $this->assertTrue($job->automationRun->summary_json['monitor_archived']);
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('release_jobs', 2);

        $this->seed(\Database\Seeders\AccessControlSeeder::class);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Livewire\Automation\Dashboard::class)
            ->assertViewHas('releaseCount', 0)->assertViewHas('runs', fn ($runs) => $runs->isEmpty())
            ->assertDontSee('Sesi lama');
        \Livewire\Livewire::test(\App\Livewire\Automation\ReleaseStatus::class)
            ->assertViewHas('totalJobs', 0)->assertViewHas('jobs', fn ($jobs) => $jobs->isEmpty())
            ->assertDontSee('Sesi lama');
        $this->artisan('soundonmatic:recover-interrupted --fresh-session')->assertSuccessful();
        $this->assertNotSame($firstSession, Cache::get('soundmatic.desktop-session'));
    }

    public function test_it_releases_the_stale_slot_and_requeues_an_interrupted_release(): void
    {
        Queue::fake();
        Cache::lock('laravel-queue-overlap:soundon-release-global', 1200)->get();
        DB::table('jobs')->insert([
            'queue' => 'release-automation',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        $run = AutomationRun::create(['status' => AutomationRunStatus::Running]);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2083',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2083',
            'idempotency_key' => 'recover-2083',
            'status' => ReleaseJobStatus::Running,
        ]);
        $queuedJob = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2082',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2082',
            'idempotency_key' => 'recover-2082',
            'status' => ReleaseJobStatus::Queued,
        ]);

        $this->artisan('soundonmatic:recover-interrupted')->assertSuccessful();

        $this->assertSame(ReleaseJobStatus::Queued, $job->fresh()->status);
        $this->assertTrue(Cache::lock('laravel-queue-overlap:soundon-release-global')->get());
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertPushed(ProcessReleaseJob::class, fn (ProcessReleaseJob $queued) => $queued->releaseJobId === $job->id);
        Queue::assertPushed(ProcessReleaseJob::class, fn (ProcessReleaseJob $queued) => $queued->releaseJobId === $queuedJob->id);
        Queue::assertPushed(ProcessReleaseJob::class, 2);
    }

    public function test_it_repairs_an_invalid_raw_run_status_before_models_are_loaded(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => AutomationRunStatus::Running]);
        DB::table('automation_runs')->where('id', $run->id)->update(['status' => '21']);

        $this->artisan('soundonmatic:recover-interrupted')->assertSuccessful();

        $this->assertDatabaseHas('automation_runs', [
            'id' => $run->id,
            'status' => AutomationRunStatus::Failed->value,
        ]);
        $this->assertNotNull($run->fresh()->finished_at);
    }
}

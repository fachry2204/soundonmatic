<?php

namespace Tests\Feature;

use App\Livewire\Automation\{Dashboard, ReleaseStatus};
use App\Models\{AutomationRun, ReleaseJob, User};
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginMonitorResetTest extends TestCase
{
    use RefreshDatabase;

    private function release(AutomationRun $run, string $title): ReleaseJob
    {
        return ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => $title,
            'soundfresh_release_url' => 'https://example.test/releases/'.$title,
            'idempotency_key' => $title, 'release_title' => $title,
            'status' => 'completed', 'checkpoint' => 'completed',
            'soundfresh_workflow_status' => 'uploading', 'soundon_check_status' => 'detected',
            'soundon_release_status' => 'approved', 'soundon_draft_id' => 'saved-'.$title,
            'soundon_upc' => '5063962826298', 'soundon_isrcs_json' => ['QT8CF2691505'],
        ]);
    }

    public function test_login_hides_old_monitors_and_new_results_appear_without_deleting_history(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Admin');
        $oldPipeline = AutomationRun::create(['status' => 'completed', 'pending_found' => 1]);
        $oldScan = AutomationRun::create(['status' => 'completed', 'summary_json' => ['purpose' => 'soundon_status_check']]);
        $oldJob = $this->release($oldPipeline, 'OldPipeline');
        $this->release($oldScan, 'OldStatus');

        $this->post('/login', ['username' => $user->username, 'password' => 'wrong'])->assertSessionHasErrors();
        $this->assertNull(session('soundmatic.hidden_monitor_run_ids'));
        $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        Livewire::test(Dashboard::class)->assertViewHas('releaseCount', 0)
            ->assertViewHas('totalReleaseJobsCount', 0)->assertDontSee('OldPipeline');
        Livewire::test(ReleaseStatus::class)->assertViewHas('totalJobs', 0)
            ->assertViewHas('approvedJobs', 0)->assertDontSee('OldStatus');
        $this->assertSame('saved-OldPipeline', $oldJob->fresh()->soundon_draft_id);
        $this->assertDatabaseCount('release_jobs', 2);
        $this->assertNull($oldPipeline->fresh()->summary_json);

        $newPipeline = AutomationRun::create(['status' => 'completed', 'pending_found' => 1]);
        $this->release($newPipeline, 'NewPipeline');
        $newScan = AutomationRun::create(['status' => 'completed', 'summary_json' => ['purpose' => 'soundon_status_check']]);
        $this->release($newScan, 'NewStatus');
        Livewire::test(Dashboard::class)->assertViewHas('totalReleaseJobsCount', 1)
            ->assertSee('NewPipeline')->assertDontSee('OldPipeline');
        Livewire::test(ReleaseStatus::class)->assertViewHas('totalJobs', 1)
            ->assertSee('NewStatus')->assertDontSee('OldStatus');
        $this->get('/')->assertOk()->assertDontSee('OldPipeline');
        $this->get('/release-status')->assertOk()->assertDontSee('OldStatus');
    }
}

<?php
namespace Tests\Feature;

use App\Jobs\CheckPendingReleaseDuplicates;
use App\Jobs\ProcessReleaseJob;
use App\Jobs\ReviewManuallyVerifiedDraft;
use App\Livewire\Automation\Dashboard;
use App\Models\AutomationRun;
use App\Models\AutomationAccount;
use App\Models\ReleaseJob;
use App\Models\User;
use App\Services\Automation\PlaywrightClient;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicateQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_queue_manual_draft_review_without_creating_a_draft(): void
    {
        Queue::fake();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $run = AutomationRun::create(['status' => 'completed']);
        $release = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'manual-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/v2/admin/releases/4473', 'idempotency_key' => 'manual-1',
            'release_title' => 'Ember City', 'artist_name' => 'Phonk Forge', 'status' => 'needs_attention', 'error_code' => 'DUPLICATE_RELEASE_FOUND']);
        Livewire::actingAs($admin)->test(Dashboard::class)->assertSee('Ubah status Soundfresh')->call('reviewVerifiedDraft', $release->id)->assertHasNoErrors();
        $this->assertSame('DRAFT_MANUAL_REVIEW_PENDING', $release->fresh()->error_code);
        Queue::assertPushed(fn (ReviewManuallyVerifiedDraft $job) => $job->releaseJobId === $release->id);
        Queue::assertNotPushed(ProcessReleaseJob::class);
    }

    public function test_manual_review_requires_a_real_draft_and_does_not_create_one(): void
    {
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => [], 'origins' => []], 'last_authenticated_at' => now()]);
        $run = AutomationRun::create(['status' => 'completed']);
        $release = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'manual-2',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/v2/admin/releases/4473', 'idempotency_key' => 'manual-2',
            'release_title' => 'Ember City', 'artist_name' => 'Phonk Forge', 'status' => 'queued', 'error_code' => 'DRAFT_MANUAL_REVIEW_PENDING']);
        Http::fake(['worker.test/v1/soundon/drafts/find' => Http::response(['success' => true, 'data' => ['found' => true, 'draft_id' => 'existing-fake']])]);
        app()->call([new ReviewManuallyVerifiedDraft($release->id), 'handle']);
        $this->assertSame('DUPLICATE_RELEASE_FOUND', $release->fresh()->error_code);
        $this->assertNull($release->fresh()->soundon_draft_id);
        Http::assertSentCount(1);
    }

    public function test_manual_review_records_success_only_after_soundfresh_confirms(): void
    {
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        foreach (['soundon', 'soundfresh'] as $platform) {
            AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active',
                'session_state_encrypted' => ['cookies' => [], 'origins' => []], 'last_authenticated_at' => now()]);
        }
        $run = AutomationRun::create(['status' => 'completed']);
        $release = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'manual-3',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/v2/admin/releases/4473', 'idempotency_key' => 'manual-3',
            'release_title' => 'Ember City', 'artist_name' => 'Phonk Forge', 'status' => 'queued', 'error_code' => 'DRAFT_MANUAL_REVIEW_PENDING']);
        Http::fake([
            'worker.test/v1/soundon/drafts/find' => Http::response(['success' => true, 'data' => ['found' => true, 'draft_id' => '7709712260272785414', 'draft_url' => 'https://www.soundon.global/library/list?type=drafts']]),
            'worker.test/v1/soundfresh/releases/review' => Http::response(['success' => true, 'data' => ['reviewed' => true]]),
        ]);
        app()->call([new ReviewManuallyVerifiedDraft($release->id), 'handle']);
        $this->assertSame('under_review', $release->fresh()->soundfresh_workflow_status);
        $this->assertSame('7709712260272785414', $release->fresh()->soundon_draft_id);
        $this->assertSame('completed', $release->fresh()->status->value);
        Http::assertSentCount(2);
    }

    public function test_draft_lookup_failure_blocks_upload_without_querying_all_releases(): void
    {
        Queue::fake();
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => [], 'origins' => []], 'last_authenticated_at' => now()]);
        $run = AutomationRun::create(['status' => 'running']);
        $release = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'draft-check',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/1', 'idempotency_key' => 'draft-check',
            'release_title' => 'Lagu', 'artist_name' => 'Artis', 'status' => 'queued', 'error_code' => 'DUPLICATE_CHECK_PENDING']);
        Http::fake(['worker.test/v1/soundon/drafts/find-many' => Http::response(['error' => 'DRAFT_LOOKUP_UNCONFIRMED'], 500)]);
        app()->call([new CheckPendingReleaseDuplicates([$release->id], $run->id), 'handle']);
        $this->assertSame('DUPLICATE_CHECK_FAILED', $release->fresh()->error_code);
        $this->assertStringContainsString('SoundOn Drafts', $release->fresh()->error_message);
        Queue::assertNotPushed(ProcessReleaseJob::class);
        Http::assertSentCount(1);
    }

    public function test_initial_check_uses_the_pipeline_queue_and_failure_clears_waiting(): void
    {
        $run = AutomationRun::create(['status' => 'running']);
        $release = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'q1', 'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/q1', 'idempotency_key' => 'q1', 'status' => 'queued', 'error_code' => 'DUPLICATE_CHECK_PENDING']);
        $check = new CheckPendingReleaseDuplicates([$release->id], $run->id);
        $this->assertSame('release-automation', $check->queue);
        $check->failed(new \RuntimeException('Timeout'));
        $this->assertSame('DUPLICATE_CHECK_FAILED', $release->fresh()->error_code);
    }

    public function test_upload_passes_completed_initial_check_to_browser(): void
    {
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);
        app(PlaywrightClient::class)->createDraft('test', [], null, true);
        Http::assertSent(fn ($request) => $request['duplicates_checked'] === true);
    }

    public function test_duplicate_check_preserves_the_worker_cause_instead_of_generic_internal_server_error(): void
    {
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        Http::fake([
            'worker.test/v1/soundfresh/pending' => Http::response([
                'statusCode' => 500,
                'error' => 'Internal Server Error',
                'message' => 'locator.click: Timeout 20000ms exceeded',
            ], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('locator.click: Timeout 20000ms exceeded');

        app(PlaywrightClient::class)->soundfreshDuplicates([
            ['key' => 'release-1', 'title' => 'Single', 'artist' => 'Artist'],
        ]);
    }

    public function test_operator_can_force_upload_after_a_duplicate_is_found(): void
    {
        Queue::fake();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $run = AutomationRun::create(['status' => 'completed', 'summary_json' => ['duplicate_check_completed' => true]]);
        $release = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'force-duplicate-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/force-duplicate-1',
            'idempotency_key' => 'force-duplicate-1',
            'release_title' => 'Rilisan Duplikat',
            'status' => 'needs_attention',
            'error_code' => 'DUPLICATE_RELEASE_FOUND',
            'error_message' => 'Rilisan dengan judul dan artis yang sama ditemukan.',
        ]);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('Tetap Upload')
            ->call('forceDuplicateUpload', $release->id)
            ->assertSet('operationError', null)
            ->assertSee('Rilisan Duplikat diantrikan untuk tetap di-upload ke Aggregator.');

        $release->refresh();
        $this->assertSame('queued', $release->status->value);
        $this->assertNull($release->error_code);
        $this->assertContains((string) $release->id, $run->fresh()->summary_json['force_duplicate_upload_job_ids']);
        Queue::assertPushed(fn (ProcessReleaseJob $job) => $job->releaseJobId === $release->id);
    }
}

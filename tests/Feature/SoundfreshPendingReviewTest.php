<?php

namespace Tests\Feature;

use App\Jobs\CheckPendingReleaseDuplicates;
use App\Jobs\ProcessReleaseJob;
use App\Jobs\ReviewManuallyVerifiedDraft;
use App\Livewire\Automation\Dashboard;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class SoundfreshPendingReviewTest extends TestCase
{
    use RefreshDatabase;

    private function release(array $changes = []): ReleaseJob
    {
        return ReleaseJob::create(array_merge([
            'automation_run_id' => AutomationRun::create(['status' => 'completed'])->id,
            'soundfresh_release_id' => 'pending-review', 'idempotency_key' => 'pending-review',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/1',
            'release_title' => 'Matching Song', 'artist_name' => 'Matching Artist',
            'soundfresh_workflow_status' => 'pending', 'status' => 'needs_attention',
            'checkpoint' => 'discovered', 'error_code' => 'DUPLICATE_RELEASE_FOUND',
        ], $changes));
    }

    private function accounts(): void
    {
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        Http::preventStrayRequests();
        foreach (['soundfresh', 'soundon'] as $platform) {
            AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active',
                'session_state_encrypted' => ['cookies' => [], 'origins' => []], 'last_authenticated_at' => now()]);
        }
    }

    private function user(string $role = 'Admin'): User
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    public function test_pending_with_a_known_soundon_id_can_queue_status_change_only_once(): void
    {
        Queue::fake();
        $record = $this->release(['status' => 'completed', 'error_code' => null, 'soundon_draft_id' => '7712024909908609032']);
        $component = Livewire::actingAs($this->user())->test(Dashboard::class)
            ->assertSee('Ubah Status ke Under Review')->call('reviewVerifiedDraft', $record->id)->assertHasNoErrors()
            ->assertSee('Mengubah status ke Under Review...');
        $component->call('reviewVerifiedDraft', $record->id)->assertStatus(409);
        $this->assertSame('pending', $record->fresh()->soundfresh_workflow_status);
        Queue::assertPushed(ReviewManuallyVerifiedDraft::class, 1);
        Queue::assertNotPushed(ProcessReleaseJob::class);
    }

    public function test_non_pending_releases_and_viewers_cannot_request_status_change(): void
    {
        Queue::fake();
        $record = $this->release();
        $admin = $this->user();
        foreach (['under_review', 'uploading', 'verified', 'rejected'] as $status) {
            $record->update(['soundfresh_workflow_status' => $status]);
            $this->assertFalse($record->fresh()->canChangeSoundfreshToUnderReview());
            Livewire::actingAs($admin)->test(Dashboard::class)->call('reviewVerifiedDraft', $record->id)->assertStatus(409);
        }
        $record->update(['soundfresh_workflow_status' => 'pending']);
        Livewire::actingAs($this->user('Viewer'))->test(Dashboard::class)->call('reviewVerifiedDraft', $record->id)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_submitted_soundon_match_blocks_upload_and_enables_status_change(): void
    {
        $this->accounts();
        Queue::fake();
        $record = $this->release(['error_code' => 'DUPLICATE_CHECK_PENDING']);
        Http::fake([
            '*/v1/soundon/drafts/find-many' => Http::response(['success' => true, 'data' => ['matches' => []]]),
            '*/v1/soundon/releases/statuses' => Http::response(['success' => true, 'data' => ['matches' => [
                $record->id => ['found' => true, 'release_id' => '7712024909908609032', 'status' => 'approved', 'release_url' => 'https://www.soundon.global/library/list'],
            ]]]),
        ]);
        app()->call([new CheckPendingReleaseDuplicates([$record->id], $record->automation_run_id), 'handle']);
        $this->assertSame('DUPLICATE_RELEASE_FOUND', $record->fresh()->error_code);
        $this->assertTrue($record->fresh()->canChangeSoundfreshToUnderReview());
        $this->assertSame('pending', $record->fresh()->soundfresh_workflow_status);
        Http::assertSent(fn ($request) => ($request['strict_artist'] ?? false) === true);
        Queue::assertNotPushed(ProcessReleaseJob::class);
    }

    public function test_submitted_release_is_rechecked_before_soundfresh_is_changed(): void
    {
        $this->accounts();
        $record = $this->release(['error_code' => 'DRAFT_MANUAL_REVIEW_PENDING']);
        Http::fake([
            '*/v1/soundon/drafts/find' => Http::response(['success' => true, 'data' => ['found' => false]]),
            '*/v1/soundon/releases/statuses' => Http::response(['success' => true, 'data' => ['matches' => [
                $record->id => ['found' => true, 'release_id' => '7712024909908609032', 'status' => 'approved', 'release_url' => 'https://www.soundon.global/library/list'],
            ]]]),
            '*/v1/soundfresh/releases/review' => Http::response(['success' => true, 'data' => ['reviewed' => true]]),
        ]);
        app()->call([new ReviewManuallyVerifiedDraft($record->id), 'handle']);
        $this->assertSame('under_review', $record->fresh()->soundfresh_workflow_status);
        $this->assertSame('approved', $record->fresh()->soundon_release_status);
        $this->assertFalse($record->fresh()->canChangeSoundfreshToUnderReview());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/review') && $request['draft_id'] === '7712024909908609032');
        Http::assertSentCount(3);
        app()->call([new ReviewManuallyVerifiedDraft($record->id), 'handle']);
        Http::assertSentCount(3);
    }

    public function test_missing_soundon_match_never_changes_pending_status(): void
    {
        $this->accounts();
        $record = $this->release(['error_code' => 'DRAFT_MANUAL_REVIEW_PENDING', 'soundon_draft_id' => '7712024909908609032']);
        Http::fake([
            '*/v1/soundon/drafts/find' => Http::response(['success' => true, 'data' => ['found' => false]]),
            '*/v1/soundon/releases/statuses' => Http::response(['success' => true, 'data' => ['matches' => []]]),
        ]);
        app()->call([new ReviewManuallyVerifiedDraft($record->id), 'handle']);
        $this->assertSame('pending', $record->fresh()->soundfresh_workflow_status);
        $this->assertSame('DUPLICATE_RELEASE_FOUND', $record->fresh()->error_code);
        Http::assertSentCount(2);
    }

    public function test_soundfresh_must_confirm_under_review_before_local_success(): void
    {
        $this->accounts();
        $record = $this->release(['error_code' => 'DRAFT_MANUAL_REVIEW_PENDING']);
        Http::fake([
            '*/v1/soundon/drafts/find' => Http::response(['success' => true, 'data' => ['found' => true, 'draft_id' => '7712024909908609032']]),
            '*/v1/soundfresh/releases/review' => Http::response(['success' => true, 'data' => ['reviewed' => false]]),
        ]);
        app()->call([new ReviewManuallyVerifiedDraft($record->id), 'handle']);
        $this->assertSame('pending', $record->fresh()->soundfresh_workflow_status);
        $this->assertSame('needs_attention', $record->fresh()->status->value);
        $this->assertTrue($record->fresh()->canChangeSoundfreshToUnderReview());
    }
}

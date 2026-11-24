<?php

namespace Tests\Feature;

use App\Jobs\RejectSoundfreshRelease;
use App\Livewire\Automation\Dashboard;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use App\Services\Automation\FailedUploadRejection;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FailedUploadRejectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        Queue::fake();
    }

    private function release(array $attributes = []): ReleaseJob
    {
        $run = AutomationRun::create(['status' => 'completed', 'pending_found' => 1]);

        return ReleaseJob::create([...[
            'automation_run_id' => $run->id, 'soundfresh_release_id' => '4599',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/v2/admin/releases/4599',
            'idempotency_key' => 'reject-fixture-'. $run->id, 'release_title' => 'Berkah bersholawat',
            'status' => 'needs_attention', 'checkpoint' => 'discovered', 'soundfresh_workflow_status' => 'pending',
            'error_code' => 'ISRC_INVALID', 'error_message' => 'ISRC tidak valid. ISRC harus berisi 12 karakter.',
        ], ...$attributes]);
    }

    public function test_button_opens_confirmation_with_editable_failure_reason_and_cancel_sends_nothing(): void
    {
        $job = $this->release();
        Livewire::test(Dashboard::class)->assertSee('Reject di Soundfresh')
            ->call('openRejectFailedUpload', $job->id)
            ->assertSet('uploadRejectReason', FailedUploadRejection::reason($job))
            ->assertSee('Alasan penolakan')->assertSee('Berkah bersholawat')
            ->call('cancelRejectFailedUpload')->assertSet('rejectingUploadJobId', null);
        Queue::assertNotPushed(RejectSoundfreshRelease::class);
        $this->assertSame('pending', $job->fresh()->soundfresh_workflow_status);
        $preview = Livewire::test(Dashboard::class)->call('openRejectFailedUpload', $job->id);
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/testing'));
        file_put_contents(storage_path('app/testing/failed-upload-reject-preview.html'), $preview->html());
    }

    public function test_confirm_queues_the_edited_reason_only_once_without_claiming_remote_success(): void
    {
        $job = $this->release();
        Livewire::test(Dashboard::class)->call('openRejectFailedUpload', $job->id)
            ->set('uploadRejectReason', 'Mohon perbaiki ISRC menjadi 12 karakter.')
            ->call('rejectFailedUpload')->assertHasNoErrors()->assertSet('rejectingUploadJobId', null);
        Queue::assertPushed(RejectSoundfreshRelease::class, fn ($action) => $action->releaseJobId === $job->id
            && $action->reason === 'Mohon perbaiki ISRC menjadi 12 karakter.' && $action->failedUpload);
        $this->assertSame('rejecting', $job->fresh()->soundfresh_verify_status);
        $this->assertSame('pending', $job->fresh()->soundfresh_workflow_status);
        Livewire::test(Dashboard::class)->call('openRejectFailedUpload', $job->id)->assertStatus(409);
        Queue::assertPushed(RejectSoundfreshRelease::class, 1);
    }

    public function test_empty_reason_and_changed_failure_are_rejected(): void
    {
        $job = $this->release();
        $page = Livewire::test(Dashboard::class)->call('openRejectFailedUpload', $job->id);
        $page->set('uploadRejectReason', '   ')->call('rejectFailedUpload')->assertHasErrors('uploadRejectReason');
        $job->update(['error_code' => 'AUDIO_INVALID']);
        $page->set('uploadRejectReason', 'Periksa data.')->call('rejectFailedUpload')->assertStatus(409);
        Queue::assertNotPushed(RejectSoundfreshRelease::class);
    }

    public function test_running_completed_and_already_rejected_releases_are_blocked(): void
    {
        foreach ([['status' => 'running'], ['status' => 'completed'], ['soundfresh_workflow_status' => 'rejected'], ['soundfresh_workflow_status' => 'uploading']] as $attributes) {
            $job = $this->release($attributes);
            Livewire::test(Dashboard::class)->call('openRejectFailedUpload', $job->id)->assertStatus(409);
        }
    }

    public function test_user_without_operator_permission_cannot_open_rejection(): void
    {
        $job = $this->release();
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        Livewire::actingAs($viewer)->test(Dashboard::class)->call('openRejectFailedUpload', $job->id)->assertForbidden();
    }

    public function test_worker_records_rejection_only_after_soundfresh_confirms_and_preserves_soundon_draft(): void
    {
        AutomationAccount::create(['platform' => 'soundfresh', 'name' => 'Soundfresh', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now()]);
        $job = $this->release(['soundfresh_verify_status' => 'rejecting', 'soundon_draft_id' => 'saved-draft']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['rejected' => true]])]);
        app()->call([new RejectSoundfreshRelease($job->id, 'ISRC harus diperbaiki.', true, FailedUploadRejection::fingerprint($job)), 'handle']);
        $this->assertSame('rejected', $job->fresh()->soundfresh_workflow_status);
        $this->assertSame('saved-draft', $job->fresh()->soundon_draft_id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/soundfresh/releases/reject')
            && $request['reason'] === 'ISRC harus diperbaiki.');
    }

    public function test_worker_does_not_reject_when_failure_changed_after_queuing(): void
    {
        $job = $this->release(['soundfresh_verify_status' => 'rejecting']);
        $fingerprint = FailedUploadRejection::fingerprint($job);
        $job->update(['status' => 'running']);
        Http::fake();
        app()->call([new RejectSoundfreshRelease($job->id, 'Periksa ISRC.', true, $fingerprint), 'handle']);
        Http::assertNothingSent();
        $this->assertSame('pending', $job->fresh()->soundfresh_workflow_status);
    }

    public function test_failed_remote_confirmation_does_not_set_source_to_rejected(): void
    {
        AutomationAccount::create(['platform' => 'soundfresh', 'name' => 'Soundfresh', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now()]);
        $job = $this->release(['soundfresh_verify_status' => 'rejecting']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['rejected' => false]])]);
        app()->call([new RejectSoundfreshRelease($job->id, 'Periksa ISRC.', true, FailedUploadRejection::fingerprint($job)), 'handle']);
        $this->assertSame('pending', $job->fresh()->soundfresh_workflow_status);
        $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
    }

    public function test_retry_is_blocked_while_rejecting_and_stale_upload_messages_do_nothing(): void
    {
        $job = $this->release(['soundfresh_verify_status' => 'rejecting']);
        Livewire::test(Dashboard::class)->call('retryRelease', $job->id)->assertStatus(409);
        Http::fake();
        app()->call([new \App\Jobs\ProcessReleaseJob($job->id), 'handle']);
        Http::assertNothingSent();
    }

    public function test_technical_failure_reason_does_not_copy_raw_sql_or_session_values(): void
    {
        $job = $this->release(['error_code' => 'AUTOMATION_FAILED', 'error_message' => 'SQL session_state_encrypted=PRIVATE']);
        $this->assertStringNotContainsString('PRIVATE', FailedUploadRejection::reason($job));
        $this->assertStringContainsString('pemeriksaan operator', FailedUploadRejection::reason($job));
    }
}

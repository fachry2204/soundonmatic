<?php

namespace Tests\Feature;

use App\Actions\Automation\StartAutomationRun;
use App\Enums\AutomationRunStatus;
use App\Enums\Platform;
use App\Jobs\ProcessReleaseJob;
use App\Jobs\CheckPendingReleaseDuplicates;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StartAutomationRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_pending_finishes_run(): void
    {
        config(['automation.hmac_key' => 'test-secret', 'automation.worker_url' => 'http://127.0.0.1:3100']);
        AutomationAccount::create(['platform' => Platform::Soundfresh, 'name' => 'Soundfresh', 'email_encrypted' => 'test@example.com', 'password_encrypted' => 'secret', 'status' => 'expired']);
        Http::fakeSequence()
            ->push(['success' => true, 'data' => ['session_state' => ['cookies' => [], 'origins' => []]]])
            ->push(['success' => true, 'data' => ['items' => []]]);
        $run = app(StartAutomationRun::class)->handle(limit: 1);
        $this->assertSame(AutomationRunStatus::CompletedNoPending, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Automation-Signature'));
        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/v1/soundfresh/pending')) {
                return false;
            }

            return data_get($request->data(), 'options.max_items') === 1;
        });
    }

    public function test_default_pending_collection_has_no_fixed_limit(): void
    {
        config(['automation.hmac_key' => 'test-secret', 'automation.worker_url' => 'http://127.0.0.1:3100']);
        AutomationAccount::create([
            'platform' => Platform::Soundfresh,
            'name' => 'Soundfresh',
            'status' => 'active',
            'session_state_encrypted' => ['cookies' => [], 'origins' => []],
            'last_authenticated_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['items' => []]])]);

        app(StartAutomationRun::class)->handle();

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/v1/soundfresh/pending')) {
                return false;
            }

            return data_get($request->data(), 'options.max_items') === null;
        });
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/v1/soundon/drafts/find-many'));
    }

    public function test_pending_releases_wait_for_operator_selection(): void
    {
        Queue::fake();
        config(['automation.hmac_key' => 'test-secret', 'automation.worker_url' => 'http://127.0.0.1:3100']);
        AutomationAccount::create(['platform' => Platform::Soundfresh, 'name' => 'Soundfresh', 'email_encrypted' => 'test@example.com', 'password_encrypted' => 'secret', 'status' => 'expired']);
        Http::fakeSequence()
            ->push(['success' => true, 'data' => ['session_state' => ['cookies' => [], 'origins' => []]]])
            ->push(['success' => true, 'data' => ['items' => [[
                'release_id' => '123',
                'detail_url' => 'https://cms.soundfresh.id/admin/releases/123',
                'title' => 'Selected later',
            ]]]]);

        $run = app(StartAutomationRun::class)->handle(limit: 1)->fresh();

        $this->assertSame(AutomationRunStatus::Queued, $run->status);
        $this->assertTrue((bool) data_get($run->summary_json, 'awaiting_selection'));
        $this->assertFalse((bool) data_get($run->summary_json, 'collection_pending'));
        $this->assertSame('queued', $run->releaseJobs()->first()->status->value);
        Queue::assertNotPushed(ProcessReleaseJob::class);
    }

    public function test_pending_release_already_in_soundon_is_marked_without_upload_queue(): void
    {
        Queue::fake();
        config(['automation.hmac_key' => 'test-secret', 'automation.worker_url' => 'http://127.0.0.1:3100']);
        foreach ([Platform::Soundfresh, Platform::SoundOn] as $platform) {
            AutomationAccount::create([
                'platform' => $platform,
                'name' => $platform->value,
                'status' => 'active',
                'session_state_encrypted' => ['cookies' => [], 'origins' => []],
                'last_authenticated_at' => now(),
            ]);
        }
        $previousRun = AutomationRun::query()->create(['status' => 'completed']);
        ReleaseJob::query()->create([
            'automation_run_id' => $previousRun->id,
            'soundfresh_release_id' => '456',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/456',
            'idempotency_key' => 'existing-local-draft-456',
            'release_title' => 'Existing Album',
            'artist_name' => 'Existing Artist',
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundon_draft_id' => 'draft-456',
            'soundon_draft_url' => 'https://www.soundon.global/draft/456',
        ]);
        Http::fakeSequence()
            ->push(['success' => true, 'data' => ['items' => [[
                'release_id' => '456',
                'detail_url' => 'https://cms.soundfresh.id/admin/releases/456',
                'title' => 'Existing Album',
                'primary_artist' => 'Existing Artist',
                'track_count' => 4,
            ]]]]);

        $run = app(StartAutomationRun::class)->handle(limit: 10)->fresh();

        $this->assertSame(AutomationRunStatus::Queued, $run->status);
        $this->assertSame(1, $run->pending_found);
        $this->assertSame(1, $run->skipped);
        $this->assertSame(1, data_get($run->summary_json, 'duplicate_blocked'));
        $job = $run->releaseJobs()->firstOrFail();
        $this->assertSame('needs_attention', $job->status->value);
        $this->assertSame('DUPLICATE_RELEASE_FOUND', $job->error_code);
        $this->assertStringContainsString('SoundOn Drafts', $job->error_message);
        Queue::assertNotPushed(ProcessReleaseJob::class);
    }

    public function test_matching_title_and_artist_in_remote_sources_blocks_upload_with_clear_reason(): void
    {
        Queue::fake();
        config(['automation.hmac_key' => 'test-secret', 'automation.worker_url' => 'http://127.0.0.1:3100']);
        foreach ([Platform::Soundfresh, Platform::SoundOn] as $platform) {
            AutomationAccount::create([
                'platform' => $platform,
                'name' => $platform->value,
                'status' => 'active',
                'session_state_encrypted' => ['cookies' => [], 'origins' => []],
                'last_authenticated_at' => now(),
            ]);
        }

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v1/soundfresh/pending')) {
                $all = data_get($request->data(), 'options.release_status') === 'all';
                if ($all && data_get($request->data(), 'options.duplicate_lookups')) {
                    $lookupKey = (string) data_get($request->data(), 'options.duplicate_lookups.0.key');
                    return Http::response(['success' => true, 'data' => ['matches' => [$lookupKey => [
                        'release_id' => '200', 'workflow_status' => 'under_review',
                    ]]]]);
                }
                return Http::response(['success' => true, 'data' => ['items' => $all ? [[
                    'release_id' => '100', 'title' => 'Lagu Sama', 'primary_artist' => 'Artis Sama', 'workflow_status' => 'pending',
                ], [
                    'release_id' => '200', 'title' => 'lagu   sama', 'primary_artist' => 'ARTIS SAMA', 'workflow_status' => 'under_review',
                ]] : [[
                    'release_id' => '100', 'detail_url' => 'https://cms.soundfresh.id/admin/releases/100',
                    'title' => ' Lagu Sama ', 'primary_artist' => 'Artis Sama', 'track_count' => 1,
                ]]]]);
            }
            $lookupKey = (string) data_get($request->data(), 'items.0.key');
            if (str_ends_with($request->url(), '/v1/soundon/releases/statuses')) {
                return Http::response(['success' => true, 'data' => ['matches' => [$lookupKey => [
                    'found' => true, 'status' => 'live', 'release_url' => 'https://soundon.global/release/100',
                ]]]]);
            }
            return Http::response(['success' => true, 'data' => ['matches' => [$lookupKey => [
                'found' => true, 'draft_id' => 'draft-100', 'draft_url' => 'https://soundon.global/draft/100',
            ]]]]);
        });

        $run = app(StartAutomationRun::class)->handle(limit: 10)->fresh();
        $job = $run->releaseJobs()->firstOrFail();

        $this->assertSame('queued', $job->status->value);
        $this->assertSame('DUPLICATE_CHECK_PENDING', $job->error_code);
        Queue::assertPushed(CheckPendingReleaseDuplicates::class);

        app()->call([new CheckPendingReleaseDuplicates([$job->id], $run->id), 'handle']);
        $job->refresh();

        $this->assertSame('needs_attention', $job->status->value);
        $this->assertStringContainsString('Soundfresh tab under review (ID 200)', $job->error_message);
        $this->assertStringContainsString('SoundOn All releases (status live)', $job->error_message);
        $this->assertStringContainsString('SoundOn Drafts', $job->error_message);
    }
}

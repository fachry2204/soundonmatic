<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Automation\ApplicationUpdate;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Actions\Automation\StopAutomationForUpdate;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class AutomaticApplicationUpdateTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalReceipt = null;
    private string $receiptPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        config(['automation.app_version' => '1.1.97', 'automation.bundled_app_version' => '1.1.97']);
        $this->receiptPath = storage_path('app/updates/update-completed.json');
        if (! is_dir(dirname($this->receiptPath))) {
            mkdir(dirname($this->receiptPath), 0755, true);
        }
        $this->originalReceipt = is_file($this->receiptPath) ? file_get_contents($this->receiptPath) : null;
        if (is_file($this->receiptPath)) {
            unlink($this->receiptPath);
        }
        Cache::forget('soundmatic-release:'.config('automation.update_repository'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->originalReceipt !== null) {
            file_put_contents($this->receiptPath, $this->originalReceipt);
        } elseif (is_file($this->receiptPath)) {
            unlink($this->receiptPath);
        }
        parent::tearDown();
    }

    private function fakeRelease(array $assetChanges = []): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.1.98', 'assets' => [array_merge([
            'name' => 'SoundMatic-Setup-v1.1.98.exe',
            'browser_download_url' => 'https://github.com/fachry2204/soundonmatic/releases/download/v1.1.98/SoundMatic-Setup-v1.1.98.exe',
        ], $assetChanges)]]), 'github.com/*' => Http::response('installer-binary')]);
    }

    public function test_startup_popup_shows_versions_using_cached_metadata(): void
    {
        $this->fakeRelease();
        $preview = Livewire::test(ApplicationUpdate::class, ['popup' => true])
            ->assertSet('showPopup', false)->call('checkForUpdate', true)
            ->assertSet('showPopup', true)->assertSee('Sistem versi terbaru tersedia')
            ->assertSee('Update Sistem')->assertSee('v1.1.97')->assertSee('v1.1.98');
        if (! is_dir(storage_path('app/testing'))) {
            mkdir(storage_path('app/testing'), 0755, true);
        }
        file_put_contents(storage_path('app/testing/system-update-preview.html'), $preview->html());
        Livewire::test(ApplicationUpdate::class, ['popup' => true])->call('checkForUpdate', true);
        Http::assertSentCount(1);
    }

    public function test_later_dismisses_this_version_and_manual_check_reopens(): void
    {
        $this->fakeRelease();
        Livewire::test(ApplicationUpdate::class, ['popup' => true])
            ->call('checkForUpdate', true)->call('dismissPopup')->assertSet('showPopup', false);
        Livewire::test(ApplicationUpdate::class, ['popup' => true])
            ->call('checkForUpdate', true)->assertSet('showPopup', false)
            ->call('checkForUpdate')->assertSet('showPopup', true);
    }

    public function test_up_to_date_and_offline_do_not_open_popup(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.1.97'])]);
        Livewire::test(ApplicationUpdate::class, ['popup' => true])->call('checkForUpdate', true)
            ->assertSet('showPopup', false)->assertSet('updateAvailable', false);
        Http::fake(['api.github.com/*' => Http::response([], 503)]);
        Livewire::test(ApplicationUpdate::class, ['popup' => true])->call('checkForUpdate')
            ->assertSet('showPopup', false)->assertSet('updateAvailable', false);
    }

    public function test_periodic_check_detects_a_new_release_and_hides_an_obsolete_popup(): void
    {
        Http::fakeSequence()
            ->push(['tag_name' => 'v1.1.97', 'assets' => []])
            ->push(['tag_name' => 'v1.1.98', 'assets' => []])
            ->push(['tag_name' => 'v1.1.97', 'assets' => []]);

        $screen = Livewire::test(ApplicationUpdate::class, ['popup' => true])
            ->assertSeeHtml('wire:poll.60s="checkForUpdate(true)"')
            ->call('checkForUpdate', true)->assertSet('showPopup', false);
        Cache::forget('soundmatic-release:'.config('automation.update_repository'));
        $screen->call('checkForUpdate', true)->assertSet('showPopup', true)->assertSet('latestVersion', '1.1.98');
        Cache::forget('soundmatic-release:'.config('automation.update_repository'));
        $screen->call('checkForUpdate', true)->assertSet('showPopup', false)->assertSet('updateAvailable', false);
    }

    public function test_stale_installer_or_external_url_is_not_installable(): void
    {
        foreach ([['name' => 'SoundMatic-Setup-v1.1.90.exe'],
            ['browser_download_url' => 'https://example.com/installer.exe']] as $changes) {
            $this->fakeRelease($changes);
            Livewire::test(ApplicationUpdate::class)->call('checkForUpdate')
                ->assertSet('updateAvailable', true)->assertSet('installerUrl', null)
                ->call('downloadAndInstall')->assertNotDispatched('soundmatic-update-ready');
        }
    }

    public function test_update_stops_active_work_and_downloads_without_manual_intervention(): void
    {
        $this->fakeRelease();
        $run = AutomationRun::query()->create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'update-active',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/update-active',
            'idempotency_key' => 'update-active', 'status' => 'running',
            'soundon_check_status' => 'checking', 'soundfresh_verify_status' => 'sending',
        ]);
        $completed = ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'update-completed',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/update-completed',
            'idempotency_key' => 'update-completed', 'status' => 'completed',
            'soundon_draft_id' => 'draft-preserved',
        ]);
        foreach (['release-automation', 'status-checks', 'soundfresh-rejects', 'unrelated'] as $queue) {
            DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0,
                'available_at' => time(), 'created_at' => time()]);
        }
        Livewire::test(ApplicationUpdate::class)->call('downloadAndInstall')
            ->assertSee('berhasil didownload')->assertDontSee('Update ditunda');
        $this->assertSame('stopped', $run->fresh()->status->value);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $this->assertSame('stopped', $job->fresh()->status->value);
        $this->assertSame('stopped', $job->fresh()->soundon_check_status);
        $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
        $this->assertSame('completed', $completed->fresh()->status->value);
        $this->assertSame('draft-preserved', $completed->fresh()->soundon_draft_id);
        $this->assertSame(['unrelated'], DB::table('jobs')->pluck('queue')->all());
        Http::assertSentCount(2);
    }

    public function test_work_started_during_download_is_stopped_before_installer_handoff(): void
    {
        $lateRun = null;
        Http::fake(function ($request) use (&$lateRun) {
            if (str_contains($request->url(), 'api.github.com')) {
                return Http::response(['tag_name' => 'v1.1.98', 'assets' => [[
                    'name' => 'SoundMatic-Setup-v1.1.98.exe',
                    'browser_download_url' => 'https://github.com/fachry2204/soundonmatic/releases/download/v1.1.98/SoundMatic-Setup-v1.1.98.exe',
                ]]]);
            }
            $lateRun = AutomationRun::create(['status' => 'queued']);
            return Http::response('installer-binary');
        });
        Livewire::test(ApplicationUpdate::class)->call('downloadAndInstall')->assertSee('berhasil didownload');
        $this->assertNotNull($lateRun);
        $this->assertSame('stopped', $lateRun->fresh()->status->value);
        $this->assertNotNull($lateRun->fresh()->stop_requested_at);
    }

    public function test_update_does_not_download_when_workers_cannot_be_stopped(): void
    {
        $this->fakeRelease();
        $this->mock(StopAutomationForUpdate::class)->shouldReceive('handle')->once()
            ->andThrow(new \RuntimeException('Worker masih aktif.'));
        Livewire::test(ApplicationUpdate::class)->call('downloadAndInstall')
            ->assertSee('worker gagal dihentikan')->assertSet('installStarted', false)
            ->assertNotDispatched('soundmatic-update-ready');
        Http::assertSentCount(1);
    }

    public function test_update_names_remaining_workers_without_exposing_internal_commands(): void
    {
        $this->fakeRelease();
        $this->mock(StopAutomationForUpdate::class)->shouldReceive('handle')->once()
            ->andThrow(new \RuntimeException('Worker masih aktif: browser otomatis, Reject (1).'));
        Livewire::test(ApplicationUpdate::class, ['popup' => true])->call('downloadAndInstall')
            ->assertSee('Worker masih aktif: browser otomatis, Reject (1).')
            ->assertSee('Database dan sesi login tidak dihapus.')
            ->assertSee('Seluruh worker dihentikan otomatis')
            ->assertSet('installStarted', false)->assertNotDispatched('soundmatic-update-ready');
        Http::assertSentCount(1);
    }

    public function test_checking_for_updates_does_not_stop_workers(): void
    {
        $this->fakeRelease();
        $run = AutomationRun::create(['status' => 'running']);
        $this->mock(StopAutomationForUpdate::class)->shouldNotReceive('handle');
        Livewire::test(ApplicationUpdate::class)->call('checkForUpdate')->assertSet('updateAvailable', true);
        $this->assertSame('running', $run->fresh()->status->value);
    }

    public function test_bad_checksum_does_not_launch_or_mark_success(): void
    {
        $this->fakeRelease(['digest' => 'sha256:'.str_repeat('0', 64)]);
        Livewire::test(ApplicationUpdate::class)->call('downloadAndInstall')
            ->assertSet('installStarted', false)->assertSet('updatedFrom', null)
            ->assertNotDispatched('soundmatic-update-ready')->assertSee('Gagal download installer update');
        $this->assertFileDoesNotExist(storage_path('app/updates/SoundMatic-Setup-v1.1.98.exe.part'));
        $this->assertFileDoesNotExist($this->receiptPath);
    }

    public function test_matching_checksum_downloads_without_premature_success_notice(): void
    {
        $this->fakeRelease(['digest' => 'sha256:'.hash('sha256', 'installer-binary')]);
        $path = storage_path('app/updates/SoundMatic-Setup-v1.1.98.exe');
        $original = is_file($path) ? file_get_contents($path) : null;
        try {
            Livewire::test(ApplicationUpdate::class)->call('downloadAndInstall')
                ->assertSet('updatedFrom', null)->assertSee('berhasil didownload');
            $this->assertSame('installer-binary', file_get_contents($path));
            $this->assertFileDoesNotExist($this->receiptPath);
        } finally {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_browser_cannot_replace_installer_url(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(ApplicationUpdate::class)->set('installerUrl', 'https://example.com/untrusted.exe');
    }

    public function test_success_receipt_does_not_open_popup_or_block_update_check(): void
    {
        file_put_contents($this->receiptPath, json_encode(['status' => 'installed', 'from' => '1.1.96', 'to' => '1.1.97']));
        $this->fakeRelease();
        Livewire::test(ApplicationUpdate::class, ['popup' => true])
            ->assertSet('showPopup', false)->assertSet('updatedFrom', '1.1.96')
            ->call('checkForUpdate', true)->assertSet('showPopup', true)
            ->assertSee('v1.1.97')->assertSee('v1.1.98')->assertDontSee('v1.1.96');
        Http::assertSentCount(1);
    }

    public function test_incomplete_or_wrong_version_receipts_do_not_claim_success(): void
    {
        foreach ([['status' => 'downloaded', 'from' => '1.1.96', 'to' => '1.1.97'],
            ['status' => 'installed', 'from' => '1.1.97', 'to' => '1.1.98'],
            ['status' => 'installed', 'from' => '1.1.98', 'to' => '1.1.97'],
            ['status' => 'installed', 'from' => 'not-a-version', 'to' => '1.1.97']] as $receipt) {
            file_put_contents($this->receiptPath, json_encode($receipt));
            Livewire::test(ApplicationUpdate::class, ['popup' => true])
                ->assertSet('showPopup', false)->assertSet('updatedFrom', null);
        }
    }

    public function test_acknowledged_receipt_does_not_interfere_with_later_update_dismissal(): void
    {
        file_put_contents($this->receiptPath, json_encode(['status' => 'installed', 'from' => '1.1.96', 'to' => '1.1.97']));
        Livewire::test(ApplicationUpdate::class, ['popup' => true])->call('dismissPopup');
        $this->fakeRelease();
        Livewire::test(ApplicationUpdate::class, ['popup' => true])
            ->call('checkForUpdate', true)->assertSet('showPopup', true)
            ->call('dismissPopup');
        Livewire::test(ApplicationUpdate::class, ['popup' => true])
            ->call('checkForUpdate', true)->assertSet('showPopup', false);
    }

    public function test_viewer_cannot_check_or_install_update(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        Livewire::actingAs($viewer)->test(ApplicationUpdate::class)->call('checkForUpdate')->assertForbidden();
        Livewire::actingAs($viewer)->test(ApplicationUpdate::class)->call('downloadAndInstall')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_receipt_is_emitted_only_after_bootstrap(): void
    {
        $source = file_get_contents(base_path('scripts/installer/Program.cs'));
        $this->assertGreaterThan(strpos($source, 'artisan soundonmatic:bootstrap'), strpos($source, '"update-completed.json"'));
        $this->assertStringContainsString('status = "installed", from = previousVersion, to = installedVersion', $source);
        $this->assertStringContainsString('FileVersionInfo.GetVersionInfo(previousExecutable)', $source);
    }
}

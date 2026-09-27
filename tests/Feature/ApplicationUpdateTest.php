<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Automation\ApplicationUpdate;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use App\Models\User;

final class ApplicationUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_latest_github_update_and_run_installer_download(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        config([
            'automation.app_version' => '1.1.40',
            'automation.update_repository' => 'fachry2204/soundonmatic',
        ]);
        Http::fake([
            'https://api.github.com/repos/fachry2204/soundonmatic/releases/latest' => Http::response([
                'tag_name' => 'v1.1.41',
                'html_url' => 'https://github.com/fachry2204/soundonmatic/releases/tag/v1.1.41',
                'assets' => [[
                    'name' => 'SoundMatic-Setup-v1.1.41.exe',
                    'browser_download_url' => 'https://github.com/fachry2204/soundonmatic/releases/download/v1.1.41/SoundMatic-Setup-v1.1.41.exe',
                ]],
            ]),
            'https://github.com/fachry2204/soundonmatic/releases/download/v1.1.41/SoundMatic-Setup-v1.1.41.exe' => Http::response('installer-binary'),
        ]);

        Livewire::test(ApplicationUpdate::class)
            ->assertSee('Update Aplikasi')
            ->call('checkForUpdate')
            ->assertSet('latestVersion', '1.1.41')
            ->assertSet('updateAvailable', true)
            ->assertSee('Update tersedia: v1.1.41')
            ->call('downloadAndInstall')
            ->assertSet('message', 'Installer update v1.1.41 berhasil didownload. Jalankan file installer untuk menyelesaikan update.');

        $this->assertFileExists(storage_path('app/updates/SoundMatic-Setup-v1.1.41.exe'));
        $this->assertSame('installer-binary', file_get_contents(storage_path('app/updates/SoundMatic-Setup-v1.1.41.exe')));
    }

    public function test_no_update_message_when_github_version_is_not_newer(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        config([
            'automation.app_version' => '1.1.40',
            'automation.update_repository' => 'fachry2204/soundonmatic',
        ]);
        Http::fake([
            'https://api.github.com/repos/fachry2204/soundonmatic/releases/latest' => Http::response([
                'tag_name' => 'v1.1.40',
                'assets' => [],
            ]),
        ]);

        Livewire::test(ApplicationUpdate::class)
            ->call('checkForUpdate')
            ->assertSet('updateAvailable', false)
            ->assertSet('message', 'Aplikasi sudah versi terbaru.');
    }
}

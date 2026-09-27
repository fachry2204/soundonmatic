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

    public function test_windows_installer_uses_direct_self_detaching_command(): void
    {
        $component = new ApplicationUpdate;
        $method = new \ReflectionMethod($component, 'installerCommand');

        $this->assertSame(
            ['C:\\SoundMatic Updates\\SoundMatic-Setup-v1.1.46.exe', '--silent-update'],
            $method->invoke($component, 'C:\\SoundMatic Updates\\SoundMatic-Setup-v1.1.46.exe'),
        );
    }

    public function test_installer_schedules_downloaded_executable_for_deletion(): void
    {
        $source = file_get_contents(base_path('scripts/installer/Program.cs'));

        $this->assertStringContainsString('ScheduleSelfDelete();', $source);
        $this->assertStringContainsString('private static void ScheduleSelfDelete()', $source);
        $this->assertStringContainsString('File.WriteAllLines(script', $source);
        $this->assertStringContainsString('del /F /Q ""%~f0""', $source);
    }

    public function test_installer_persists_failure_diagnostics(): void
    {
        $source = file_get_contents(base_path('scripts/installer/Program.cs'));

        $this->assertStringContainsString('"SoundMatic",', $source);
        $this->assertStringContainsString('"installer.log"', $source);
        $this->assertStringContainsString('Log("Instalasi gagal: " + error);', $source);
        $this->assertStringContainsString('Log: " + InstallerLogPath', $source);
    }

    public function test_update_page_shows_download_progress_and_automatic_flow(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Livewire::actingAs($admin)
            ->test(ApplicationUpdate::class)
            ->assertSee('Progress download update')
            ->assertSee('Aplikasi otomatis ditutup setelah download selesai')
            ->assertSee('installer dihapus otomatis');
    }

    public function test_version_can_be_recovered_from_installer_name_when_release_tag_is_malformed(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        config([
            'automation.app_version' => '1.1.41',
            'automation.update_repository' => 'fachry2204/soundonmatic',
        ]);
        Http::fake([
            'https://api.github.com/repos/fachry2204/soundonmatic/releases/latest' => Http::response([
                'tag_name' => 'SoundMatic-Setup-v1.1.42',
                'assets' => [[
                    'name' => 'SoundMatic-Setup-v1.1.42.exe',
                    'browser_download_url' => 'https://github.com/fachry2204/soundonmatic/releases/download/v1.1.42/SoundMatic-Setup-v1.1.42.exe',
                ]],
            ]),
        ]);

        Livewire::test(ApplicationUpdate::class)
            ->call('checkForUpdate')
            ->assertSet('latestVersion', '1.1.42')
            ->assertSet('updateAvailable', true);
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

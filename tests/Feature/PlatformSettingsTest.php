<?php

namespace Tests\Feature;

use App\Livewire\Automation\AccountSessions;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseAsset;
use App\Models\ReleaseJob;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_platform_credentials_encrypted(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)->set('emails.soundfresh', 'source@example.com')->set('passwords.soundfresh', 'private-password')->call('saveCredentials', 'soundfresh')->assertHasNoErrors();
        $account = AutomationAccount::where('platform', 'soundfresh')->firstOrFail();
        $this->assertSame('source@example.com', $account->email_encrypted);
        $this->assertSame('private-password', $account->password_encrypted);
        $raw = DB::table('automation_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString('source@example.com', $raw->email_encrypted);
        $this->assertStringNotContainsString('private-password', $raw->password_encrypted);
    }

    public function test_password_is_not_rendered_back_to_settings_page(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn', 'email_encrypted' => 'target@example.com', 'password_encrypted' => 'hidden-password', 'status' => 'expired']);
        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)->assertSet('emails.soundon', 'target@example.com')->assertSet('passwords.soundon', '')->assertDontSee('hidden-password');
    }

    public function test_restored_credentials_with_another_key_can_be_reentered(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $account = AutomationAccount::create(['platform' => 'soundfresh', 'name' => 'Soundfresh', 'status' => 'active']);
        DB::table('automation_accounts')->where('id', $account->id)->update([
            'email_encrypted' => 'old-key-ciphertext',
            'password_encrypted' => 'old-key-ciphertext',
            'session_state_encrypted' => 'old-key-ciphertext',
        ]);

        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)
            ->assertSee('Masukkan ulang email dan password')
            ->set('emails.soundfresh', 'new@example.com')
            ->set('passwords.soundfresh', 'new-password')
            ->call('saveCredentials', 'soundfresh')
            ->assertHasNoErrors();

        $this->assertSame('new@example.com', $account->fresh()->email_encrypted);
        $this->assertSame('new-password', $account->fresh()->password_encrypted);
        $this->assertNull($account->fresh()->session_state_encrypted);
    }

    public function test_reentered_credentials_are_saved_and_logged_in_in_one_action(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $account = AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'credentials_need_reentry']);
        DB::table('automation_accounts')->where('id', $account->id)->update([
            'email_encrypted' => 'old-key-ciphertext',
            'password_encrypted' => 'old-key-ciphertext',
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'session_state' => ['cookies' => [['name' => 'session', 'value' => 'test']]],
        ]])]);

        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)
            ->set('emails.soundon', 'target@example.com')
            ->set('passwords.soundon', 'new-password')
            ->call('saveAndLogin', 'soundon')
            ->assertSet('message', 'soundon: active')
            ->assertSee('Aktif');

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame('target@example.com', $account->email_encrypted);
        $this->assertNotNull($account->session_state_encrypted);
    }

    public function test_admin_can_clear_downloaded_release_assets(): void
    {
        Storage::fake('local');
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        $run = AutomationRun::create(['status' => 'completed']);
        $job = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'download-cleanup', 'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/download-cleanup', 'idempotency_key' => 'download-cleanup', 'status' => 'completed']);
        $path = 'automation/downloads/Test - Artist/Test-Artist.jpg';
        Storage::disk('local')->put($path, 'cover');
        $asset = ReleaseAsset::create(['release_job_id' => $job->id, 'type' => 'cover', 'temporary_path' => $path, 'original_filename' => 'Test-Artist.jpg']);

        Livewire::test(AccountSessions::class)->call('clearDownloadData')->assertSet('downloadFiles', 0)->assertSee('Data download berhasil dihapus');

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($asset->fresh()->temporary_path);
        $this->assertNotNull($asset->fresh()->deleted_at_source_cache);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Exceptions\BrowserWorkerException;
use App\Livewire\Automation\AccountSessions;
use App\Models\AutomationAccount;
use App\Models\User;
use App\Services\Automation\SessionManager;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class PlatformSessionReuseTest extends TestCase
{
    use RefreshDatabase;

    private function account(): AutomationAccount
    {
        return AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'email_encrypted' => 'artist@example.com', 'password_encrypted' => 'password-test',
            'session_state_encrypted' => ['cookies' => [['name' => 'old', 'value' => 'secret']]],
            'last_authenticated_at' => now()->subHour()]);
    }

    public function test_validated_session_is_reused_and_refreshed_without_login(): void
    {
        $account = $this->account();
        Http::fake(['*/v1/sessions/validate' => Http::response(['success' => true, 'data' => ['valid' => true, 'status' => 'valid',
            'session_state' => ['cookies' => [['name' => 'new', 'value' => 'secret']]]]])]);
        app(SessionManager::class)->ensure(Platform::SoundOn);
        $this->assertSame('new', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
        Http::assertSentCount(1);
    }

    public function test_unknown_or_failed_validation_preserves_session_and_never_logs_in(): void
    {
        $account = $this->account();
        Http::fake(['*/v1/sessions/validate' => Http::response(['success' => true, 'data' => ['valid' => false, 'status' => 'unknown']])]);
        try { app(SessionManager::class)->ensure(Platform::SoundOn); $this->fail('Expected validation failure'); }
        catch (BrowserWorkerException $error) { $this->assertSame('session_validation_failed', $error->reason); }
        $this->assertSame('old', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
        Http::assertSentCount(1);
    }

    public function test_only_explicit_expiration_triggers_relogin(): void
    {
        $account = $this->account();
        Http::fake(['*/v1/sessions/validate' => Http::response(['success' => true, 'data' => ['valid' => false, 'status' => 'expired']]),
            '*/v1/sessions/login' => Http::response(['success' => true, 'data' => ['session_state' => ['cookies' => [['name' => 'relogin']]]]])]);
        app(SessionManager::class)->ensure(Platform::SoundOn);
        $this->assertSame('relogin', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
        Http::assertSentCount(2);
    }

    public function test_manual_challenge_does_not_delete_session_or_login_again(): void
    {
        $account = $this->account();
        Http::fake(['*/v1/sessions/validate' => Http::response(['success' => true, 'data' => ['valid' => false, 'status' => 'manual_auth']])]);
        $result = app(SessionManager::class)->ensure(Platform::SoundOn);
        $this->assertSame('manual_auth_required', $result->status);
        $this->assertSame('old', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
        Http::assertSentCount(1);
    }

    public function test_saving_unchanged_credentials_preserves_session(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $account = $this->account();
        Livewire::actingAs($admin)->test(AccountSessions::class)->set('emails.soundon', 'artist@example.com')
            ->set('passwords.soundon', '')->call('saveCredentials', 'soundon')->assertHasNoErrors();
        $this->assertSame('old', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
    }
}

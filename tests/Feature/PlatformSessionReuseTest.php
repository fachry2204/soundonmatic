<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Exceptions\BrowserWorkerException;
use App\Livewire\Automation\AccountSessions;
use App\Models\AutomationAccount;
use App\Models\User;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        try {
            app(SessionManager::class)->ensure(Platform::SoundOn);
            $this->fail('Expected validation failure');
        } catch (BrowserWorkerException $error) {
            $this->assertSame('session_validation_failed', $error->reason);
        }
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
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $account = $this->account();
        Livewire::actingAs($admin)->test(AccountSessions::class)->set('emails.soundon', 'artist@example.com')
            ->set('passwords.soundon', '')->call('saveCredentials', 'soundon')->assertHasNoErrors();
        $this->assertSame('old', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
    }

    public function test_structured_login_rejection_is_reported_as_invalid_credentials(): void
    {
        $account = $this->account();
        Http::fake([
            '*/v1/sessions/validate' => Http::response(['success' => true, 'data' => ['valid' => false, 'status' => 'expired']]),
            '*/v1/sessions/login' => Http::response(['success' => false, 'error' => 'AUTH_INVALID_CREDENTIALS'], 401),
        ]);

        try {
            app(SessionManager::class)->ensure(Platform::SoundOn);
            $this->fail('Expected rejected credentials');
        } catch (BrowserWorkerException $error) {
            $this->assertSame('invalid_credentials', $error->reason);
        }

        $this->assertSame('authentication_failed', $account->fresh()->status);
        $this->assertSame('old', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
        Http::assertSentCount(2);
    }

    public function test_health_check_preserves_an_unconfirmed_session(): void
    {
        $account = $this->account();
        Http::fake(['*/v1/sessions/validate' => Http::response(['success' => true, 'data' => ['valid' => false, 'status' => 'unknown']])]);

        $this->artisan('soundon:session:health')->assertFailed();

        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame('old', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
        Http::assertSentCount(1);
    }

    public function test_health_check_distinguishes_manual_authentication_from_expiration(): void
    {
        $account = $this->account();
        Http::fake(['*/v1/sessions/validate' => Http::response(['success' => true, 'data' => ['valid' => false, 'status' => 'manual_auth']])]);

        $this->artisan('soundon:session:health')->assertFailed();

        $this->assertSame('manual_auth_required', $account->fresh()->status);
        $this->assertSame('old', $account->fresh()->session_state_encrypted['cookies'][0]['name']);
        Http::assertSentCount(1);
    }

    public function test_soundfresh_success_refreshes_cookies_without_overwriting_newer_session(): void
    {
        $old = ['cookies' => [['name' => 'old', 'value' => 'secret']]];
        $new = ['cookies' => [['name' => 'new', 'value' => 'secret']]];
        $account = AutomationAccount::create(['platform' => 'soundfresh', 'name' => 'Soundfresh', 'status' => 'active',
            'session_state_encrypted' => $old, 'last_authenticated_at' => now()->subHour()]);
        Http::fake(['*/v1/soundfresh/pending' => Http::response(['success' => true, 'data' => ['items' => [], 'session_state' => $new]])]);
        app(PlaywrightClient::class)->pending(null, $old);
        $this->assertSame($new, $account->fresh()->session_state_encrypted);
        $newer = ['cookies' => [['name' => 'newer', 'value' => 'secret']]];
        $account->update(['session_state_encrypted' => $newer]);
        app(PlaywrightClient::class)->pending(null, $old);
        $this->assertSame($newer, $account->fresh()->session_state_encrypted);
    }

    public function test_cookie_refresh_retries_a_database_lock_without_repeating_the_platform_operation(): void
    {
        $account = $this->account();
        $old = $account->session_state_encrypted;
        $new = ['cookies' => [['name' => 'refreshed', 'value' => 'secret']]];
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['matches' => [], 'session_state' => $new]])]);
        $collisions = 0;
        $armed = true;
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use (&$collisions, &$armed): void {
            if ($armed && str_starts_with($query, 'update "automation_accounts"') && str_contains($query, 'session_state_encrypted')) {
                $armed = false;
                $collisions++;
                throw new QueryException($connection->getName(), $query, $bindings, new \PDOException('database is locked'));
            }
        });
        app(PlaywrightClient::class)->releaseStatuses([], $old);
        $this->assertSame(1, $collisions);
        $this->assertSame($new, $account->fresh()->session_state_encrypted);
        Http::assertSentCount(1);
    }

    public function test_cookie_refresh_does_not_replace_a_session_changed_between_read_and_write(): void
    {
        $account = $this->account();
        $old = $account->session_state_encrypted;
        $newer = ['cookies' => [['name' => 'new-login', 'value' => 'secret']]];
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['matches' => [], 'session_state' => ['cookies' => [['name' => 'stale-refresh']]]]])]);
        $armed = true;
        DB::connection()->beforeExecuting(function ($query) use (&$armed, $account, $newer): void {
            if ($armed && str_starts_with($query, 'update "automation_accounts"') && str_contains($query, 'session_state_encrypted')) {
                $armed = false;
                $account->update(['session_state_encrypted' => $newer]);
            }
        });
        app(PlaywrightClient::class)->releaseStatuses([], $old);
        $this->assertFalse($armed);
        $this->assertSame($newer, $account->fresh()->session_state_encrypted);
    }
}

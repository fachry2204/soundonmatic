<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\Platform;
use App\Exceptions\BrowserWorkerException;
use App\Exceptions\CredentialsNotConfiguredException;
use App\Models\AutomationAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

final class SessionManager
{
    public function __construct(private readonly PlaywrightClient $worker) {}

    public function ensure(Platform $platform): AutomationAccount
    {
        // Queue workers can need the same account simultaneously. Serialize
        // validation/login so only one browser refreshes a saved session.
        return \Illuminate\Support\Facades\Cache::lock('platform-session:'.$platform->value, 240)
            ->block(210, fn () => $this->ensureLocked($platform));
    }

    private function ensureLocked(Platform $platform): AutomationAccount
    {
        $account = AutomationAccount::firstOrCreate(['platform' => $platform], ['name' => $platform === Platform::Soundfresh ? 'Soundfresh' : 'SoundOn', 'status' => 'expired']);
        $this->assertReadable($platform, $account);
        $validationInterval = max(0, (int) config('automation.session_validation_interval_seconds', 300));
        if (
            $account->session_state_encrypted
            && $account->status === 'active'
            && $account->last_authenticated_at?->isAfter(now()->subSeconds($validationInterval))
        ) {
            return $account;
        }

        if ($account->session_state_encrypted) {
            try {
                $result = $this->worker->validateSession($platform->value, $account->session_state_encrypted);
            } catch (\Throwable) {
                throw new BrowserWorkerException('session_validation_failed', "Browser worker failed while validating {$platform->value} session.");
            }
            if (($result['valid'] ?? false) === true) {
                $account->update(['status' => 'active', 'last_authenticated_at' => now(),
                    'session_state_encrypted' => $result['session_state'] ?? $account->session_state_encrypted]);

                return $account;
            }
            if (($result['status'] ?? null) === 'manual_auth') {
                $account->update(['status' => 'manual_auth_required']);
                return $account;
            }
            // Unknown pages, loading errors and service outages do not prove
            // expiration. Preserve the last known session and avoid relogin.
            if (($result['status'] ?? null) !== 'expired') {
                throw new BrowserWorkerException('session_validation_failed', "{$platform->value} session status could not be confirmed; cached session preserved.");
            }
        }
        $email = (string) ($account->email_encrypted ?? '');
        $password = (string) ($account->password_encrypted ?? '');
        if ($email === '' || $password === '') {
            throw new CredentialsNotConfiguredException($platform);
        }
        try {
            $result = $this->worker->login($platform->value, $email, $password);
        } catch (RequestException $error) {
            $workerCode = $error->response?->json('error');
            if ($error->response?->status() === 401 && in_array($workerCode, ['AUTH_INVALID_CREDENTIALS', 'AUTH_LOGIN_FAILED'], true)) {
                $account->update(['status' => 'authentication_failed']);
                throw new BrowserWorkerException('invalid_credentials', "{$platform->value} rejected the stored credentials.");
            }

            throw new BrowserWorkerException('authentication_failed', "Browser worker failed while authenticating {$platform->value}.");
        } catch (ConnectionException) {
            throw new BrowserWorkerException('worker_unavailable', 'Browser worker is unavailable.');
        } catch (\Throwable) {
            throw new BrowserWorkerException('authentication_failed', "Browser worker failed while authenticating {$platform->value}.");
        }
        if (($result['manual_auth_required'] ?? false) === true) {
            $account->update(['status' => 'manual_auth_required']);

            return $account;
        }
        if (empty($result['session_state']) || ! is_array($result['session_state'])) {
            throw new BrowserWorkerException('authentication_failed', "{$platform->value} login did not return a reusable session.");
        }
        $account->update(['session_state_encrypted' => $result['session_state'], 'session_expires_at' => now()->addHours(8), 'last_authenticated_at' => now(), 'status' => 'active']);

        return $account;
    }

    public function assertReadable(Platform $platform, ?AutomationAccount $account = null): void
    {
        $account ??= AutomationAccount::query()->where('platform', $platform)->first();
        if (! $account) {
            throw new CredentialsNotConfiguredException($platform);
        }

        try {
            $email = $account->email_encrypted;
            $password = $account->password_encrypted;
            $session = $account->session_state_encrypted;
        } catch (DecryptException) {
            // Keep the original ciphertext intact for recovery with the old key.
            $account->update(['status' => 'credentials_need_reentry']);
            throw new CredentialsNotConfiguredException($platform, true);
        }

        if ($session === null && (blank($email) || blank($password))) {
            throw new CredentialsNotConfiguredException($platform);
        }
    }

    public function clear(Platform $platform): void
    {
        AutomationAccount::where('platform', $platform)->update(['session_state_encrypted' => null, 'session_expires_at' => null, 'status' => 'expired']);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Actions\Automation\CleanupTemporaryAssets;
use App\Actions\Automation\StopAutomationRun;
use App\Models\AutomationRun;
use App\Enums\Platform;
use App\Enums\ReleaseJobStatus;
use App\Exceptions\BrowserWorkerException;
use App\Exceptions\CredentialsNotConfiguredException;
use App\Models\AutomationAccount;
use App\Models\AutomationSetting;
use App\Services\Automation\OperatorAudit;
use App\Services\Automation\SessionManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Encryption\DecryptException;
use Livewire\Component;
use Symfony\Component\Process\Process;
use Throwable;

final class AccountSessions extends Component
{
    public ?string $message = null;

    public array $emails = ['soundfresh' => '', 'soundon' => ''];

    public array $passwords = ['soundfresh' => '', 'soundon' => ''];

    public int $downloadFiles = 0;

    public string $downloadSize = '0 B';

    public bool $automaticRunEnabled = false;

    public int $automaticRunIntervalMinutes = 30;

    public string $automaticRunStartTime = '08:00';

    public string $automaticRunEndTime = '22:00';

    public function mount(): void
    {
        $setting = AutomationSetting::current();
        $this->automaticRunEnabled = $setting->automatic_run_enabled;
        $this->automaticRunIntervalMinutes = $setting->automatic_run_interval_minutes;
        $this->automaticRunStartTime = substr((string) $setting->automatic_run_start_time, 0, 5);
        $this->automaticRunEndTime = substr((string) $setting->automatic_run_end_time, 0, 5);

        foreach (AutomationAccount::all() as $account) {
            try {
                $this->emails[$account->platform->value] = (string) ($account->email_encrypted ?? '');
            } catch (DecryptException) {
                $this->message = 'Credential lama tidak dapat dibaca setelah pemulihan database. Masukkan ulang email dan password Soundfresh serta SoundOn, lalu simpan dan login kembali.';
            }
        }
        $this->refreshDownloadStats();
    }

    public function saveCredentials(string $platform): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        $account = AutomationAccount::firstOrCreate(['platform' => $enum], ['name' => $enum === Platform::Soundfresh ? 'Soundfresh' : 'SoundOn', 'status' => 'expired']);
        try {
            $account->email_encrypted;
            $account->session_state_encrypted;
            $hasPassword = filled($account->password_encrypted);
        } catch (DecryptException) {
            $hasPassword = false;
        }
        $rules = ['email' => ['required', 'email', 'max:255'], 'password' => [$hasPassword ? 'nullable' : 'required', 'string', 'min:6', 'max:255']];
        $data = validator(['email' => $this->emails[$platform] ?? '', 'password' => $this->passwords[$platform] ?? ''], $rules)->validate();
        $updates = ['email_encrypted' => $data['email'], 'session_state_encrypted' => null, 'session_expires_at' => null, 'status' => 'expired'];
        if ($data['password'] !== '') {
            $updates['password_encrypted'] = $data['password'];
        }
        if (! $hasPassword) {
            // A restored database may contain ciphertext from an older APP_KEY.
            // Updating that model can decrypt its original values during dirty
            // checking, so replace only this account's fields atomically.
            DB::table('automation_accounts')->where('id', $account->id)->update([
                'email_encrypted' => Crypt::encryptString($data['email']),
                'password_encrypted' => Crypt::encryptString($data['password']),
                'session_state_encrypted' => null,
                'session_expires_at' => null,
                'status' => 'expired',
                'updated_at' => now(),
            ]);
        } else {
            $account->update($updates);
        }
        $this->passwords[$platform] = '';
        app(OperatorAudit::class)->record('platform_credentials_saved', 'Operator updated encrypted platform credentials.', context: ['platform' => $platform]);
        $this->message = $platform.': credential terenkripsi berhasil disimpan.';
    }

    public function saveAndLogin(string $platform, SessionManager $sessions): void
    {
        $this->saveCredentials($platform);
        $this->refresh($platform, $sessions);
    }

    public function saveAutomaticRunSettings(): void
    {
        Gate::authorize('sessions.manage');
        $data = validator([
            'enabled' => $this->automaticRunEnabled,
            'interval' => $this->automaticRunIntervalMinutes,
            'start' => $this->automaticRunStartTime,
            'end' => $this->automaticRunEndTime,
        ], [
            'enabled' => ['boolean'],
            'interval' => ['required', 'integer', 'min:5', 'max:1440'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
        ])->validate();

        AutomationSetting::current()->update([
            'automatic_run_enabled' => (bool) $data['enabled'],
            'automatic_run_interval_minutes' => (int) $data['interval'],
            'automatic_run_start_time' => $data['start'],
            'automatic_run_end_time' => $data['end'],
        ]);

        app(OperatorAudit::class)->record('automatic_run_settings_saved', 'Operator updated automatic automation schedule.', context: [
            'enabled' => (bool) $data['enabled'],
            'interval_minutes' => (int) $data['interval'],
            'start_time' => $data['start'],
            'end_time' => $data['end'],
        ]);
        $this->message = 'Jadwal otomatis berhasil disimpan.';
    }

    public function clearCredentials(string $platform): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        AutomationAccount::where('platform', $enum)->update(['email_encrypted' => null, 'password_encrypted' => null, 'session_state_encrypted' => null, 'session_expires_at' => null, 'status' => 'disabled']);
        $this->emails[$platform] = '';
        $this->passwords[$platform] = '';
        app(OperatorAudit::class)->record('platform_credentials_cleared', 'Operator removed encrypted platform credentials.', context: ['platform' => $platform]);
        $this->message = $platform.': credential dan cached session dihapus.';
    }

    public function refresh(string $platform, SessionManager $sessions): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        try {
            $account = $sessions->ensure($enum);
            app(OperatorAudit::class)->record('platform_session_refreshed', 'Operator refreshed platform session.', context: ['platform' => $enum->value, 'status' => $account->status]);
            $this->message = $enum->value.': '.$account->status;
        } catch (BrowserWorkerException $e) {
            logger()->warning('platform_session_refresh_failed', ['platform' => $enum->value, 'exception_class' => $e::class]);
            $this->message = match ($e->reason) {
                'invalid_credentials' => $enum->value.': email atau password ditolak oleh platform. Masukkan ulang password, simpan credential, lalu coba login kembali.',
                'worker_unavailable' => $enum->value.': layanan browser worker tidak dapat dihubungi.',
                default => $enum->value.': proses autentikasi gagal. Silakan coba kembali atau periksa status worker.',
            };
        } catch (CredentialsNotConfiguredException $e) {
            $this->message = $e->keyMismatch
                ? $enum->value.': credential lama tidak dapat dibaca. Masukkan ulang email dan password, simpan, lalu login kembali.'
                : $enum->value.': masukkan email dan password, simpan, lalu login kembali.';
        } catch (Throwable $e) {
            logger()->warning('platform_session_refresh_failed', ['platform' => $enum->value, 'exception_class' => $e::class]);
            $this->message = $enum->value.': proses autentikasi gagal.';
        }
    }

    public function clear(string $platform, SessionManager $sessions): void
    {
        Gate::authorize('sessions.manage');
        $enum = Platform::from($platform);
        $sessions->clear($enum);
        app(OperatorAudit::class)->record('platform_session_cleared', 'Operator cleared cached platform session.', context: ['platform' => $enum->value]);
        $this->message = $enum->value.': cached session dihapus.';
    }

    public function openDownloadFolder(): void
    {
        Gate::authorize('sessions.manage');
        $path = $this->downloadFolderPath();
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->message = 'Folder download: '.$path;

            return;
        }

        try {
            (new Process(['explorer.exe', $path]))->setTimeout(10)->mustRun();
            $this->message = 'Folder download dibuka: '.$path;
        } catch (Throwable $error) {
            report($error);
            $this->message = 'Folder download gagal dibuka. Lokasi: '.$path;
        }
    }

    private function downloadFolderPath(): string
    {
        return Storage::disk('local')->path('automation/downloads');
    }

    public function clearDownloadData(CleanupTemporaryAssets $cleanup, StopAutomationRun $stop): void
    {
        Gate::authorize('sessions.manage');
        $active = \App\Models\ReleaseJob::query()->whereIn('status', [ReleaseJobStatus::Running, ReleaseJobStatus::Queued])->exists();
        if ($active) {
            $run = AutomationRun::query()->whereIn('status', ['running', 'queued', 'paused'])->first();
            if ($run) {
                $stop->handle($run);
            }
        }
        $count = $cleanup->purgeDownloadDirectory();
        app(OperatorAudit::class)->record('download_data_cleared', 'Operator deleted locally downloaded release assets.', context: ['asset_records' => $count]);
        $this->refreshDownloadStats();
        $this->message = ($active ? 'Proses aktif dihentikan. ' : '').'Data download berhasil dihapus dari aplikasi dan cache worker ('.$count.' catatan aset dibersihkan).';
    }

    private function refreshDownloadStats(): void
    {
        $files = Storage::disk('local')->allFiles('automation/downloads');
        $bytes = collect($files)->sum(fn (string $file): int => (int) Storage::disk('local')->size($file));
        $workerRoot = base_path('automation-worker/storage/extracted-assets');
        // Unit tests fake Laravel's local disk but must never count or delete
        // real browser-worker assets from the developer workspace.
        $workerFiles = ! app()->runningUnitTests() && is_dir($workerRoot)
            ? File::allFiles($workerRoot)
            : [];
        $bytes += collect($workerFiles)->sum(fn (\SplFileInfo $file): int => $file->getSize());
        $this->downloadFiles = count($files) + count($workerFiles);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $level = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
        $this->downloadSize = number_format($bytes / (1024 ** $level), $level === 0 ? 0 : 2).' '.$units[$level];
    }

    public function render()
    {
        $accounts = AutomationAccount::all()->keyBy(fn ($account) => $account->platform->value);
        $credentialAvailability = [];
        foreach ($accounts as $platform => $account) {
            try {
                $credentialAvailability[$platform] = filled($account->email_encrypted) && filled($account->password_encrypted);
            } catch (DecryptException) {
                $credentialAvailability[$platform] = false;
            }
        }

        return view('livewire.automation.account-sessions', compact('accounts', 'credentialAvailability'));
    }
}

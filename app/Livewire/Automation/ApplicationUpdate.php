<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Actions\Automation\StopAutomationForUpdate;
use App\Services\Automation\LocalSystemManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class ApplicationUpdate extends Component
{
    #[Locked]
    public bool $popup = false;

    #[Locked]
    public bool $showPopup = false;

    #[Locked]
    public bool $showCheckResult = false;

    #[Locked]
    public bool $checkFailed = false;

    public function checkUpdateManually(): void
    {
        $this->checkForUpdate();
        $this->showCheckResult = true;
    }

    public function closeCheckResult(): void
    {
        $this->showCheckResult = false;
    }

    #[Locked]
    public ?string $updatedFrom = null;

    #[Locked]
    public ?string $completionId = null;

    public ?string $message = null;

    #[Locked]
    public string $currentVersion = '';

    #[Locked]
    public ?string $latestVersion = null;

    #[Locked]
    public ?string $latestTag = null;

    #[Locked]
    public ?string $releaseUrl = null;

    #[Locked]
    public ?string $installerName = null;

    #[Locked]
    public ?string $installerUrl = null;

    #[Locked]
    public bool $updateAvailable = false;

    #[Locked]
    public bool $installStarted = false;

    public function mount(bool $popup = false): void
    {
        $this->popup = $popup;
        $environmentVersion = $this->normalizeVersion((string) config('automation.app_version', '1.1.40'));
        $bundledVersion = $this->normalizeVersion((string) config('automation.bundled_app_version', $environmentVersion));

        $this->currentVersion = version_compare($bundledVersion, $environmentVersion, '>')
            ? $bundledVersion
            : $environmentVersion;
        $this->readUpdateCompletion();
    }

    public function checkForUpdate(bool $automatic = false): void
    {
        Gate::authorize('sessions.manage');
        $this->checkFailed = false;
        $repository = (string) config('automation.update_repository', 'fachry2204/soundonmatic');
        try {
            $fetch = fn () => Http::acceptJson()
                ->withHeaders(['User-Agent' => 'SoundMatic-Updater'])
                ->connectTimeout(5)->timeout(15)
                ->get("https://api.github.com/repos/{$repository}/releases/latest")
                ->throw()
                ->json();
            $release = $automatic
                ? Cache::remember('soundmatic-release:'.$repository, 60, $fetch)
                : $fetch();
            if (! is_array($release)) {
                throw new \RuntimeException('Respons update tidak valid.');
            }
        } catch (Throwable $error) {
            $this->checkFailed = true;
            $this->message = 'Gagal cek update dari GitHub. Periksa koneksi internet lalu coba lagi.';
            $this->updateAvailable = false;

            return;
        }

        $this->latestTag = (string) ($release['tag_name'] ?? '');
        $this->releaseUrl = (string) ($release['html_url'] ?? '');
        $asset = collect($release['assets'] ?? [])->first(function (array $asset): bool {
            $name = (string) ($asset['name'] ?? '');

            return str_ends_with(strtolower($name), '.exe') && str_contains(strtolower($name), 'setup');
        });
        $this->installerName = $asset ? (string) ($asset['name'] ?? '') : null;
        $this->installerUrl = $asset ? (string) ($asset['browser_download_url'] ?? '') : null;
        $digest = $asset['digest'] ?? null;
        $this->assetDigest = is_string($digest) && preg_match('/^sha256:[a-f0-9]{64}$/', $digest) ? $digest : null;
        $this->latestVersion = $this->extractVersion($this->latestTag)
            ?? $this->extractVersion((string) $this->installerName)
            ?? '0.0.0';
        // An old installer attached to a new tag must never be installed.
        $expectedName = 'SoundMatic-Setup-v'.$this->latestVersion.'.exe';
        $prefix = "https://github.com/{$repository}/releases/download/";
        if ($this->installerName !== $expectedName || ! str_starts_with((string) $this->installerUrl, $prefix)
            || basename((string) parse_url((string) $this->installerUrl, PHP_URL_PATH)) !== $expectedName) {
            $this->installerUrl = null;
        }
        $this->updateAvailable = version_compare($this->latestVersion, $this->currentVersion, '>');
        if (! $this->updateAvailable && $this->popup) {
            $this->showPopup = false;
        }
        if ($this->popup && $this->updateAvailable
            && (! $automatic || session('soundmatic.update-dismissed') !== $this->latestVersion
                || session('soundmatic.update-dismissed-session', 'web') !== Cache::get('soundmatic.desktop-session', 'web'))) {
            $this->showPopup = true;
        }

        if (! $this->updateAvailable) {
            $this->message = 'Aplikasi sudah versi terbaru.';

            return;
        }
        $this->message = $this->installerUrl
            ? 'Update tersedia: '.$this->latestTag
            : 'Update tersedia: '.$this->latestTag.', tetapi file installer tidak ditemukan di GitHub Release.';
    }

    public function downloadAndInstall(): void
    {
        Gate::authorize('sessions.manage');
        // Never trust a URL or version hydrated from the browser.
        $this->mount($this->popup);
        $this->checkForUpdate();
        if (! $this->updateAvailable || blank($this->installerUrl) || blank($this->installerName)) {
            $this->message = 'Cek update terlebih dahulu. Installer GitHub Release belum tersedia.';

            return;
        }

        $lock = null;
        $temporaryPath = null;
        try {
            if (! app()->runningUnitTests()) {
                set_time_limit(1900);
            }
            $directory = storage_path('app/updates');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $lock = fopen($directory.DIRECTORY_SEPARATOR.'download.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Update sedang diunduh di jendela lain.');
            }
            if (! $this->stopWorkersForUpdate()) {
                return;
            }
            $path = $directory.DIRECTORY_SEPARATOR.basename($this->installerName);
            $temporaryPath = $path.'.part';
            Http::withHeaders(['User-Agent' => 'SoundMatic-Updater'])
                ->withOptions(['sink' => $temporaryPath])
                ->timeout(1800)
                ->get($this->installerUrl)
                ->throw();
            if (! is_file($temporaryPath) || (! app()->runningUnitTests() && filesize($temporaryPath) < 10_000_000)) {
                throw new \RuntimeException('Installer update tidak lengkap.');
            }
            // GitHub's asset digest protects against an incomplete/corrupt download.
            // Use the digest from fresh metadata, not an older startup cache.
            $digest = $this->assetDigest;
            if ($digest && ! hash_equals(substr($digest, 7), hash_file('sha256', $temporaryPath))) {
                throw new \RuntimeException('Checksum pembaruan tidak cocok.');
            }
            if (! rename($temporaryPath, $path)) {
                throw new \RuntimeException('File update tidak dapat disiapkan.');
            }
        } catch (Throwable $error) {
            if ($temporaryPath && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
            $this->message = 'Gagal download installer update. Periksa koneksi internet lalu coba lagi.';

            return;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        // Another window may have started work during a long download.
        if (! $this->stopWorkersForUpdate()) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && ! app()->runningUnitTests()) {
            // PHP runs as a child service of the desktop host. Hand the installer
            // path to WebView2 so the desktop process can launch it independently
            // and close itself immediately. Launching from this short-lived HTTP
            // request can dispose the child before its detached bootstrap runs.
            $this->installStarted = true;
            $this->message = 'Installer update v'.$this->latestVersion.' siap dijalankan. SoundMatic akan ditutup, diperbarui, lalu dibuka kembali.';
            $this->dispatch('soundmatic-update-ready', path: $path);

            return;
        }

        $this->message = 'Installer update v'.$this->latestVersion.' berhasil didownload. Jalankan file installer untuk menyelesaikan update.';
    }

    public function render()
    {
        return view('livewire.automation.application-update');
    }

    private function extractVersion(?string $value): ?string
    {
        if (preg_match('/(?:^|[^0-9])v?(\d+\.\d+\.\d+)(?:[^0-9]|$)/i', (string) $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeVersion(string $version): string
    {
        return $this->extractVersion($version) ?? '0.0.0';
    }

    private ?string $assetDigest = null;

    public function dismissPopup(): void
    {
        Gate::authorize('sessions.manage');
        $this->showPopup = false;
        // Derive dismissal keys from trusted data, not editable Livewire properties.
        if ($this->latestVersion) {
            session()->put('soundmatic.update-dismissed', $this->latestVersion);
            session()->put('soundmatic.update-dismissed-session', Cache::get('soundmatic.desktop-session', 'web'));
        } elseif ($this->completionId) {
            session()->put('soundmatic.update-completed', $this->completionId);
        }
    }

    private function readUpdateCompletion(): void
    {
        $this->updatedFrom = null;
        $this->completionId = null;
        $path = storage_path('app/updates/update-completed.json');
        $receipt = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($receipt) || ($receipt['status'] ?? '') !== 'installed'
            || ($receipt['to'] ?? '') !== $this->currentVersion
            || ! preg_match('/^\d+\.\d+\.\d+$/', (string) ($receipt['from'] ?? ''))
            || ! version_compare($receipt['from'], $this->currentVersion, '<')) {
            return;
        }
        $completionId = hash('sha256', json_encode($receipt));
        if (session('soundmatic.update-completed') !== $completionId) {
            $this->completionId = $completionId;
            $this->updatedFrom = $receipt['from'];
        }
    }

    private function stopWorkersForUpdate(): bool
    {
        try {
            app(StopAutomationForUpdate::class)->handle(app(LocalSystemManager::class));

            return true;
        } catch (Throwable $error) {
            report($error);
            $this->message = 'Update belum dapat dilanjutkan karena worker gagal dihentikan. Klik Update Sistem untuk mencoba kembali.';

            return false;
        }
    }
}

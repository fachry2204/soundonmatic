<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Symfony\Component\Process\Process;
use Throwable;

final class ApplicationUpdate extends Component
{
    public ?string $message = null;

    public string $currentVersion = '';

    public ?string $latestVersion = null;

    public ?string $latestTag = null;

    public ?string $releaseUrl = null;

    public ?string $installerName = null;

    public ?string $installerUrl = null;

    public bool $updateAvailable = false;

    public bool $installStarted = false;

    public function mount(): void
    {
        $this->currentVersion = $this->normalizeVersion((string) config('automation.app_version', '1.1.40'));
    }

    public function checkForUpdate(): void
    {
        $repository = (string) config('automation.update_repository', 'fachry2204/soundonmatic');
        try {
            $release = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'SoundMatic-Updater'])
                ->timeout(20)
                ->get("https://api.github.com/repos/{$repository}/releases/latest")
                ->throw()
                ->json();
        } catch (Throwable $error) {
            $this->message = 'Gagal cek update dari GitHub. Periksa koneksi internet lalu coba lagi.';
            $this->updateAvailable = false;

            return;
        }

        $this->latestTag = (string) ($release['tag_name'] ?? '');
        $this->latestVersion = $this->normalizeVersion($this->latestTag);
        $this->releaseUrl = (string) ($release['html_url'] ?? '');
        $asset = collect($release['assets'] ?? [])->first(function (array $asset): bool {
            $name = (string) ($asset['name'] ?? '');

            return str_ends_with(strtolower($name), '.exe') && str_contains(strtolower($name), 'setup');
        });
        $this->installerName = $asset ? (string) ($asset['name'] ?? '') : null;
        $this->installerUrl = $asset ? (string) ($asset['browser_download_url'] ?? '') : null;
        $this->updateAvailable = version_compare($this->latestVersion, $this->currentVersion, '>');

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
        if (! $this->updateAvailable || blank($this->installerUrl) || blank($this->installerName)) {
            $this->message = 'Cek update terlebih dahulu. Installer GitHub Release belum tersedia.';

            return;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'SoundMatic-Updater'])->timeout(600)->get($this->installerUrl)->throw();
            $directory = storage_path('app/updates');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $path = $directory.DIRECTORY_SEPARATOR.basename($this->installerName);
            file_put_contents($path, $response->body());
        } catch (Throwable $error) {
            $this->message = 'Gagal download installer update. Periksa koneksi internet lalu coba lagi.';

            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && ! app()->runningUnitTests()) {
            (new Process([$path, '--silent-update']))->setWorkingDirectory(dirname($path))->start();
            $this->installStarted = true;
            $this->message = 'Installer update v'.$this->latestVersion.' sudah dijalankan. Ikuti proses instalasi, aplikasi akan diperbarui.';

            return;
        }

        $this->message = 'Installer update v'.$this->latestVersion.' berhasil didownload. Jalankan file installer untuk menyelesaikan update.';
    }

    public function render()
    {
        return view('livewire.automation.application-update');
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        $version = ltrim($version, 'vV');

        return $version !== '' ? $version : '0.0.0';
    }
}

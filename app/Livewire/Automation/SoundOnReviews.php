<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Enums\Platform;
use App\Exceptions\CredentialsNotConfiguredException;
use App\Models\AutomationAccount;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Livewire\Component;
use Throwable;

final class SoundOnReviews extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public ?string $message = null;

    public ?string $fetchedAt = null;

    public bool $fetching = false;

    public function fetchReviews(SessionManager $sessions, PlaywrightClient $worker): void
    {
        if ($this->fetching) return;
        $this->fetching = true;
        $this->message = null;
        $this->items = [];
        $this->fetchedAt = null;

        try {
            $sessions->assertReadable(Platform::Soundfresh);
            $soundfresh = AutomationAccount::query()->where('platform', Platform::Soundfresh)->firstOrFail();
            $soundfreshSession = $soundfresh->session_state_encrypted;
            if (! is_array($soundfreshSession) || $soundfreshSession === []) {
                $this->message = 'Sesi Soundfresh belum aktif. Buka Pengaturan Platform, lalu Login / refresh sesi sebelum mengambil data Review.';
                return;
            }

            $releases = array_values($worker->underReview(null, $soundfreshSession));
            $this->items = array_map(static fn (array $release): array => [
                'title' => $release['title'] ?? '',
                'artist' => $release['primary_artist'] ?? '',
                'release_date' => $release['release_date'] ?? '',
                'last_updated' => '',
            ], $releases);
            $lookups = [];
            foreach ($releases as $index => $release) {
                $title = trim((string) ($release['title'] ?? ''));
                $artist = trim((string) ($release['primary_artist'] ?? ''));
                if ($title === '' || $artist === '') continue;
                $lookups[] = ['key' => (string) $index, 'title' => $title, 'artist' => $artist];
            }

            $dateMessage = '';
            try {
                $sessions->assertReadable(Platform::SoundOn);
                $soundOn = AutomationAccount::query()->where('platform', Platform::SoundOn)->firstOrFail();
                $soundOnSession = $soundOn->session_state_encrypted;
                if (! is_array($soundOnSession) || $soundOnSession === []) {
                    $dateMessage = ' Sesi SoundOn tidak aktif, sehingga Last Updated Date belum dapat diisi.';
                } elseif ($lookups !== []) {
                    $lastUpdated = $worker->soundOnReviewLastUpdated($lookups, $soundOnSession);
                    foreach ($lastUpdated as $key => $value) {
                        if (isset($this->items[(int) $key])) $this->items[(int) $key]['last_updated'] = (string) $value;
                    }
                }
            } catch (CredentialsNotConfiguredException) {
                $dateMessage = ' Credential atau sesi SoundOn belum siap, sehingga Last Updated Date belum dapat diisi.';
            } catch (Throwable $error) {
                report($error);
                $dateMessage = ' Last Updated Date SoundOn gagal dibaca; data Soundfresh tetap ditampilkan.';
            }

            $this->fetchedAt = now()->format('d M Y H:i:s');
            $this->message = count($this->items).' rilisan Under Review berhasil diambil dari Soundfresh.'.$dateMessage;
        } catch (CredentialsNotConfiguredException $error) {
            $this->message = $error->keyMismatch
                ? 'Credential Soundfresh lama tidak dapat dibaca. Masukkan ulang credential dan login kembali di Pengaturan Platform.'
                : 'Credential Soundfresh belum dikonfigurasi. Atur credential dan login di Pengaturan Platform.';
        } catch (Throwable $error) {
            report($error);
            $this->message = 'Data Review Soundfresh gagal diambil. Pastikan sesi Soundfresh aktif dan layanan browser berjalan, lalu coba kembali.';
        } finally {
            $this->fetching = false;
        }
    }

    public function render()
    {
        return view('livewire.automation.soundfresh-reviews')->layout('components.layouts.app');
    }
}

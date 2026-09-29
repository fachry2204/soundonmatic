<?php

namespace App\Services\Automation;

final class ReadableReleaseError
{
    public static function explain(?string $code, ?string $message): array
    {
        $message = (string) $message;
        if (preg_match('/SOUNDON_UI_CHANGED:(?:blocking_dialog|stale_dialog):([^\r\n]+)/', $message, $match)) {
            $field = trim($match[1]);
            $field = ['Primary artists' => 'artis utama', 'Songwriters' => 'penulis lagu', 'Production and additional contributors' => 'kontributor produksi'][$field] ?? $field;
            return [
                'title' => 'Pengisian metadata terhenti',
                'reason' => "Sistem tidak dapat menutup jendela isian sebelumnya di SoundOn, sehingga belum bisa melanjutkan ke bagian {$field}.",
                'action' => 'Perlu pemeriksaan dialog oleh sistem. Jangan langsung upload ulang: cek dahulu apakah rilisan sudah tersimpan di SoundOn. Error ini tidak membuktikan file audio rusak atau upload gagal.',
            ];
        }
        if (preg_match('/SOUNDON_UI_CHANGED:field:([^\r\n]+)/', $message, $match)) {
            $field = trim($match[1]);
            return [
                'title' => 'Pengisian metadata SoundOn terhenti',
                'reason' => "Tahap pengisian form metadata berhenti karena field “{$field}” tidak ditemukan pada tampilan SoundOn.",
                'action' => 'Cover Art, Audio Master, dan Audio Clip baru selesai diunduh ke komputer; aset belum mulai diunggah ke SoundOn. Periksa perubahan form SoundOn sebelum menekan Retry.',
            ];
        }
        if ($code === 'SOUNDON_FORM_VALIDATION') {
            $detail = trim(explode('Detail teknis:', $message, 2)[1] ?? 'field wajib belum lengkap');

            return [
                'title' => 'Pengisian form SoundOn gagal',
                'reason' => 'SoundOn menolak data saat sistem mengisi atau menyimpan form metadata. Ini bukan file rusak dan bukan berarti upload audio/cover gagal.',
                'action' => 'Perbaiki metadata yang diminta SoundOn'.($detail !== '' ? ' ('.$detail.')' : '').', lalu tekan Retry.',
            ];
        }

        return [
            'title' => match ($code) {
                'UI_CHANGED' => 'Kontrol SoundOn belum dapat diakses',
                'UI_INTERACTION_TIMEOUT' => 'Kontrol form SoundOn tidak merespons',
                'AUTH_SESSION_EXPIRED' => 'Sesi login kedaluwarsa',
                'UPLOAD_FAILED' => 'Pengiriman belum berhasil dikonfirmasi',
                default => 'Proses memerlukan pemeriksaan',
            },
            'reason' => trim(explode('Detail teknis:', $message, 2)[0]),
            'action' => '',
        ];
    }
}

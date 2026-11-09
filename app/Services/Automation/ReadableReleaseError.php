<?php

namespace App\Services\Automation;

final class ReadableReleaseError
{
    public static function explain(?string $code, ?string $message): array
    {
        $message = (string) $message;
        if (in_array($code, ['DRAFT_CREATION_IN_PROGRESS', 'DRAFT_LOOKUP_UNCONFIRMED'], true)) {
            return [
                'title' => 'Pencegahan draft ganda aktif',
                'reason' => $code === 'DRAFT_CREATION_IN_PROGRESS'
                    ? 'Judul dan artis yang sama sedang diproses oleh job lain. Permintaan ini tidak membuat draft baru.'
                    : 'Pencarian draft SoundOn belum dapat memastikan hasilnya. Pembuatan draft baru dihentikan agar tidak menggandakan rilisan.',
                'action' => 'Tunggu proses yang berjalan selesai, lalu periksa draft SoundOn sebelum Retry. Draft yang sudah ada tidak dihapus oleh sistem.',
            ];
        }
        if ($code === 'SOUNDFRESH_REVIEW_FAILED' || str_contains($message, 'SOUNDFRESH_REVIEW_FAILED:')) {
            return [
                'title' => 'Pembaruan status Soundfresh gagal',
                'reason' => 'Draft SoundOn sudah tersimpan. Kegagalan terjadi saat mengubah status Soundfresh dari Pending ke Under Review, bukan saat upload ke SoundOn.',
                'action' => 'Periksa sesi Soundfresh, pemilihan English, dan dialog Review Release. Retry melanjutkan dari checkpoint draft tersimpan; jangan membuat upload baru. Kirim Detail teknis jika tetap gagal.',
            ];
        }
        if ($code === 'SOUNDON_REQUIRED_FIELD_UNRESOLVED'
            && preg_match('/Isian:\s*([^\r\n]+)/u', $message, $fields)) {
            return [
                'title' => 'Isian wajib SoundOn belum tersimpan',
                'reason' => 'Field yang belum terkonfirmasi: '.trim($fields[1]),
                'action' => 'Draft belum dinyatakan berhasil dan Soundfresh tetap Pending. Periksa nama, role, atau pilihan pada field tersebut; jangan mengubah metadata lain yang tidak berkaitan.',
            ];
        }
        if ($code === 'SOUNDON_CONTRIBUTOR_NOT_PERSISTED'
            && preg_match('/SOUNDON_CONTRIBUTOR_NOT_PERSISTED:([^:]+):(.+?)(?=:stage=|:role=|[\r\n]|$)/u', $message, $match)) {
            $field = ['Primary artists' => 'Artis utama', 'Featured artists' => 'Artis pendukung', 'Songwriters' => 'Penulis lagu',
                'Lyricists' => 'Penulis lirik', 'Contributors' => 'Kontributor', 'Production and additional contributors' => 'Kontributor produksi'][$match[1]] ?? $match[1];

            if (str_contains(strtolower($message), 'artist name cannot contain urls or website links')) {
                return [
                    'title' => 'Nama artis ditolak validasi SoundOn',
                    'reason' => 'Sumber: form SoundOn. Nama “'.trim($match[2]).'” pada field “'.$field.'” ditolak dengan pesan: Artist name cannot contain URLs or website links.',
                    'action' => 'Nama tetap dipertahankan sesuai Soundfresh; sistem tidak menghapus tanda titik atau mengganti identitas artis. Coba pilih profil artis yang sudah ada dengan nama yang sama di SoundOn. Jika nama yang sah tetap ditolak, minta bantuan SoundOn. Draft belum terverifikasi, sehingga status Soundfresh belum diubah.',
                ];
            }

            return [
                'title' => 'Kredit SoundOn belum tersimpan',
                'reason' => 'Sumber: SoundOn. Nama “'.trim($match[2]).'” pada field “'.$field.'” belum terkonfirmasi tersimpan pada rilisan setelah Submit.',
                'action' => 'Profil yang terlihat di jendela pencarian belum berarti kredit sudah tersimpan. Draft dan status Soundfresh hanya dinyatakan berhasil setelah hasil penyimpanan terverifikasi. Detail teknis menunjukkan tahap dan respons form.',
            ];
        }
        if ($code === 'SOUNDON_MONETIZATION_NOT_ENABLED'
            || preg_match('/SOUNDON_MONETIZATION_NOT_ENABLED:([^\r\n]+)/', $message)) {
            preg_match('/SOUNDON_MONETIZATION_NOT_ENABLED:([^\r\n]+)/', $message, $option);
            return [
                'title' => 'Pengaturan monetisasi SoundOn belum tersimpan',
                'reason' => 'Sumber: form SoundOn. Opsi “'.trim($option[1] ?? 'Monetisasi').'” belum terkonfirmasi aktif setelah dipilih.',
                'action' => 'Periksa kelayakan genre dan keterangan pada opsi SoundOn. Opsi yang dinonaktifkan SoundOn dilewati dengan peringatan; opsi aktif yang gagal tersimpan memerlukan pemeriksaan. Ini bukan bukti file audio rusak.',
            ];
        }
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

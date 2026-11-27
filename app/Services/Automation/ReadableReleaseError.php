<?php

namespace App\Services\Automation;

final class ReadableReleaseError
{
    public static function displayText(?string $text): string
    {
        return preg_replace('/\bsoundon\b/i', 'Aggregator', (string) $text);
    }

    public static function soundfreshMessage(?string $message): string
    {
        if (str_contains((string) $message, 'SOUNDFRESH_UI_CHANGED:reject_reason_input')) {
            return 'Reject belum terkirim: sistem belum menemukan kolom alasan yang bisa diisi pada dialog Reject Soundfresh. '
                .'Rilisan belum dikonfirmasi ditolak. Gunakan versi perbaikan, buka Detail, lalu pilih Reject di Soundfresh. Tombol Retry menjalankan ulang proses upload.';
        }

        return self::displayText($message);
    }

    public static function explain(?string $code, ?string $message): array
    {
        return array_map(self::displayText(...), self::explainOriginal($code, $message));
    }

    private static function explainOriginal(?string $code, ?string $message): array
    {
        $message = (string) $message;
        if (in_array($code, ['UI_INTERACTION_TIMEOUT', 'UI_CHANGED'], true)
            || str_contains($message, 'entity_control_not_clickable')) {
            $field = null;
            foreach (['Release date' => 'tanggal rilis', 'Primary artists' => 'artis utama',
                'Songwriters' => 'penulis lagu', 'Lyricists' => 'penulis lirik',
                'Previously released?' => 'keterangan apakah lagu pernah dirilis',
                'Featured artists' => 'artis pendukung'] as $label => $translation) {
                if (stripos($message, $label) !== false) {
                    $field = $translation;
                    break;
                }
            }
            $blocked = str_contains(strtolower($message), 'intercepts pointer events');
            $dialogTitle = null;
            if (preg_match('/blocking_dialog_title=("(?:\\\\.|[^"\\\\])*");/', $message, $match)) {
                $dialogTitle = json_decode($match[1], true);
                $blocked = true;
            }
            if ($blocked || str_contains($message, 'entity_control_not_clickable')) {
                return [
                    'title' => $blocked ? 'Isian tertutup jendela lain' : 'Isian belum bisa diklik',
                    'reason' => 'Sistem berhenti saat mencoba membuka '.($field ? 'kolom '.$field : 'salah satu isian').' di Aggregator. '
                        .($blocked ? ($dialogTitle ? 'Jendela “'.$dialogTitle.'” masih terbuka dan menghalangi klik.' : 'Ada jendela atau lapisan tampilan di atas kolom tersebut yang menghalangi klik. Nama jendelanya tidak tercatat pada laporan ini.')
                            : 'Kolom tersebut belum bisa diklik sampai batas waktu tunggu habis. Penyebab pastinya belum dapat dipastikan dari laporan ini.'),
                    'action' => 'Buka halaman rilisan di Aggregator. Tutup jendela pop-up yang masih terbuka atau selesaikan isian di dalamnya, '
                        .'lalu pastikan '.($field ? 'kolom '.$field : 'isian yang dituju').' dapat diklik. Setelah itu tekan Retry di aplikasi. '
                        .'Periksa draft yang sudah ada sebelum membuat rilisan baru. Pesan ini tidak menunjukkan bahwa file audio rusak.',
                ];
            }
            if ($code === 'UI_INTERACTION_TIMEOUT') {
                return [
                    'title' => 'Tampilan Aggregator belum merespons',
                    'reason' => 'Sistem menunggu tombol atau isian di Aggregator, tetapi belum bisa melanjutkan sampai batas waktu habis. Laporan ini belum memastikan penyebabnya.',
                    'action' => 'Buka halaman rilisan di Aggregator, periksa apakah halaman sudah selesai dimuat dan apakah ada pop-up yang perlu ditutup. Setelah halaman siap, tekan Retry. Periksa draft yang sudah tersimpan terlebih dahulu.',
                ];
            }
        }
        if (in_array($code, ['DRAFT_CREATION_IN_PROGRESS', 'DRAFT_LOOKUP_UNCONFIRMED'], true)) {
            return [
                'title' => 'Pencegahan draft ganda aktif',
                'reason' => $code === 'DRAFT_CREATION_IN_PROGRESS'
                    ? 'Judul dan artis yang sama sedang diproses oleh job lain. Permintaan ini tidak membuat draft baru.'
                    : 'Pencarian draft Aggregator belum dapat memastikan hasilnya. Pembuatan draft baru dihentikan agar tidak menggandakan rilisan.',
                'action' => 'Tunggu proses yang berjalan selesai, lalu periksa draft Aggregator sebelum Retry. Draft yang sudah ada tidak dihapus oleh sistem.',
            ];
        }
        if ($code === 'SOUNDFRESH_REVIEW_FAILED' || str_contains($message, 'SOUNDFRESH_REVIEW_FAILED:')) {
            return [
                'title' => 'Pembaruan status Soundfresh gagal',
                'reason' => 'Draft Aggregator sudah tersimpan. Kegagalan terjadi saat mengubah status Soundfresh dari Pending ke Under Review, bukan saat upload ke Aggregator.',
                'action' => 'Periksa sesi Soundfresh, pemilihan English, dan dialog Review Release. Retry melanjutkan dari checkpoint draft tersimpan; jangan membuat upload baru. Kirim Detail teknis jika tetap gagal.',
            ];
        }
        if ($code === 'SOUNDON_REQUIRED_FIELD_UNRESOLVED'
            && preg_match('/Isian:\s*([^\r\n]+)/u', $message, $fields)) {
            return [
                'title' => 'Isian wajib Aggregator belum tersimpan',
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
                    'title' => 'Nama artis ditolak validasi Aggregator',
                    'reason' => 'Sumber: form Aggregator. Nama “'.trim($match[2]).'” pada field “'.$field.'” ditolak dengan pesan: Artist name cannot contain URLs or website links.',
                    'action' => 'Nama tetap dipertahankan sesuai Soundfresh; sistem tidak menghapus tanda titik atau mengganti identitas artis. Coba pilih profil artis yang sudah ada dengan nama yang sama di Aggregator. Jika nama yang sah tetap ditolak, minta bantuan Aggregator. Draft belum terverifikasi, sehingga status Soundfresh belum diubah.',
                ];
            }

            return [
                'title' => 'Kredit Aggregator belum tersimpan',
                'reason' => 'Sumber: Aggregator. Nama “'.trim($match[2]).'” pada field “'.$field.'” belum terkonfirmasi tersimpan pada rilisan setelah Submit.',
                'action' => 'Profil yang terlihat di jendela pencarian belum berarti kredit sudah tersimpan. Draft dan status Soundfresh hanya dinyatakan berhasil setelah hasil penyimpanan terverifikasi. Detail teknis menunjukkan tahap dan respons form.',
            ];
        }
        if ($code === 'SOUNDON_MONETIZATION_NOT_ENABLED'
            || preg_match('/SOUNDON_MONETIZATION_NOT_ENABLED:([^\r\n]+)/', $message)) {
            preg_match('/SOUNDON_MONETIZATION_NOT_ENABLED:([^\r\n]+)/', $message, $option);
            return [
                'title' => 'Pengaturan monetisasi Aggregator belum tersimpan',
                'reason' => 'Sumber: form Aggregator. Opsi “'.trim($option[1] ?? 'Monetisasi').'” belum terkonfirmasi aktif setelah dipilih.',
                'action' => 'Tekan Retry untuk melanjutkan penyimpanan draft. Opsi monetisasi yang tidak tersedia atau belum berhasil diaktifkan akan dicatat sebagai peringatan, sehingga proses dapat dilanjutkan. Periksa pengaturan monetisasi pada draft sebelum distribusi. Ini bukan bukti file audio rusak.',
            ];
        }
        if (preg_match('/SOUNDON_UI_CHANGED:(?:blocking_dialog|stale_dialog):([^\r\n]+)/', $message, $match)) {
            $field = trim($match[1]);
            $field = ['Primary artists' => 'artis utama', 'Songwriters' => 'penulis lagu', 'Production and additional contributors' => 'kontributor produksi'][$field] ?? $field;
            return [
                'title' => 'Pengisian metadata terhenti',
                'reason' => "Sistem tidak dapat menutup jendela isian sebelumnya di Aggregator, sehingga belum bisa melanjutkan ke bagian {$field}.",
                'action' => 'Perlu pemeriksaan dialog oleh sistem. Jangan langsung upload ulang: cek dahulu apakah rilisan sudah tersimpan di Aggregator. Error ini tidak membuktikan file audio rusak atau upload gagal.',
            ];
        }
        if (preg_match('/SOUNDON_UI_CHANGED:field:([^\r\n]+)/', $message, $match)) {
            $field = trim($match[1]);
            return [
                'title' => 'Pengisian metadata Aggregator terhenti',
                'reason' => "Tahap pengisian form metadata berhenti karena field “{$field}” tidak ditemukan pada tampilan Aggregator.",
                'action' => 'Cover Art, Audio Master, dan Audio Clip baru selesai diunduh ke komputer; aset belum mulai diunggah ke Aggregator. Periksa perubahan form Aggregator sebelum menekan Retry.',
            ];
        }
        if ($code === 'SOUNDON_FORM_VALIDATION') {
            $detail = trim(explode('Detail teknis:', $message, 2)[1] ?? 'field wajib belum lengkap');

            return [
                'title' => 'Pengisian form Aggregator gagal',
                'reason' => 'Aggregator menolak data saat sistem mengisi atau menyimpan form metadata. Ini bukan file rusak dan bukan berarti upload audio/cover gagal.',
                'action' => 'Perbaiki metadata yang diminta Aggregator'.($detail !== '' ? ' ('.$detail.')' : '').', lalu tekan Retry.',
            ];
        }

        return [
            'title' => match ($code) {
                'UI_CHANGED' => 'Kontrol Aggregator belum dapat diakses',
                'UI_INTERACTION_TIMEOUT' => 'Kontrol form Aggregator tidak merespons',
                'AUTH_SESSION_EXPIRED' => 'Sesi login kedaluwarsa',
                'UPLOAD_FAILED' => 'Pengiriman belum berhasil dikonfirmasi',
                default => 'Proses memerlukan pemeriksaan',
            },
            'reason' => trim(explode('Detail teknis:', $message, 2)[0]),
            'action' => '',
        ];
    }
}

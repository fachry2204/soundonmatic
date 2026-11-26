<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\ReleaseJobStatus;
use App\Models\ReleaseJob;

final class FailedUploadRejection
{
    public static function eligible(ReleaseJob $job, bool $processing = false): bool
    {
        return in_array($job->status, [ReleaseJobStatus::Failed, ReleaseJobStatus::NeedsAttention], true)
            && filled($job->error_code) && filled($job->soundfresh_release_url)
            && in_array($job->soundfresh_workflow_status, [null, '', 'pending', 'under_review', 'revision'], true)
            && ($processing ? $job->soundfresh_verify_status === 'rejecting'
                : ! in_array($job->soundfresh_verify_status, ['sending', 'rejecting', 'rejected', 'success'], true));
    }

    public static function reason(ReleaseJob $job): string
    {
        $error = ReadableReleaseError::explain($job->error_code, $job->error_message);
        [$reason, $action] = match ($job->error_code) {
            'ISRC_INVALID' => ['Kode ISRC pada salah satu atau beberapa lagu belum valid. ISRC harus terdiri dari 12 karakter dan digunakan untuk mengidentifikasi rekaman lagu.',
                'Mohon periksa kode ISRC pada setiap lagu, pastikan penulisannya benar dan sesuai rekaman yang didaftarkan. Jika ISRC belum tersedia, sampaikan kepada tim Soundfresh agar pengisian kode dapat diperiksa sebelum rilisan dikirim ulang.'],
            'UPC_INVALID' => ['Kode UPC rilisan belum valid sehingga identitas rilisan belum dapat diverifikasi.',
                'Mohon periksa kembali kode UPC dan pastikan kode tersebut sesuai dengan rilisan ini. Jika kode belum tersedia atau terdapat keraguan mengenai penggunaannya, hubungi tim Soundfresh untuk pemeriksaan lebih lanjut.'],
            'AUDIO_INVALID' => ['File audio belum lolos pemeriksaan yang diperlukan untuk melanjutkan pengiriman rilisan.',
                'Mohon periksa apakah file audio dapat diputar sampai selesai, sesuai dengan lagu yang didaftarkan, dan memenuhi persyaratan format yang ditampilkan di Soundfresh. Unggah kembali file yang sesuai apabila ditemukan masalah.'],
            'COVER_INVALID' => ['Gambar sampul belum lolos pemeriksaan yang diperlukan untuk melanjutkan pengiriman rilisan.',
                'Mohon periksa ukuran, format, keterbacaan gambar, serta kesesuaian sampul dengan judul dan artis yang didaftarkan. Perbaiki atau unggah ulang sampul sesuai persyaratan yang ditampilkan di Soundfresh.'],
            'SOUNDON_CONTRIBUTOR_NOT_PERSISTED', 'SOUNDON_ENTITY_MATCH_REQUIRED', 'SOUNDON_REQUIRED_FIELD_UNRESOLVED', 'SOUNDON_FORM_VALIDATION' => [
                'Data artis, penulis lagu, kontributor, atau isian wajib pada rilisan belum dapat dikonfirmasi tersimpan dengan benar.',
                'Mohon periksa kelengkapan nama dan peran setiap pihak serta kecocokannya dengan data di Soundfresh. Tim Soundfresh perlu memeriksa isian yang belum tersimpan sebelum pengiriman dilanjutkan. Jangan mengganti nama atau identitas artis tanpa dasar yang benar.'],
            default => [$error['title'].'. Penyelesaian proses belum dapat dikonfirmasi dan masih memerlukan pemeriksaan operator.',
                'Tim Soundfresh perlu memeriksa kendala pada halaman pengiriman dan memastikan apakah draft sudah tersimpan sebelum mencoba kembali. Kendala ini tidak dengan sendirinya menunjukkan bahwa file audio, sampul, atau data yang Anda kirimkan salah. Anda tidak perlu mengunggah ulang berkas kecuali tim meminta perbaikan tertentu.'],
        };

        return self::clientMessage($job, $reason, $action);
    }

    public static function platformReason(ReleaseJob $job): string
    {
        $source = trim((string) $job->soundon_rejection_reason);
        $reason = match (true) {
            preg_match('/upc.*(?:already|used|duplicate)/i', $source) === 1 => 'Kode UPC yang dicantumkan sudah digunakan untuk rilisan lain sehingga pengajuan ini belum dapat diterima.',
            preg_match('/(?:artwork|cover).*(?:blur|buram)/i', $source) === 1 => 'Gambar sampul dinilai kurang jelas atau buram sehingga belum memenuhi pemeriksaan rilisan.',
            preg_match('/(?:audio).*(?:ketentuan|requirement|invalid)/i', $source) === 1 => 'File audio belum memenuhi ketentuan pemeriksaan rilisan.',
            $source !== '' && ! preg_match('/locator|SOUNDON_|stack trace|Timeout \d+ms/i', $source) => ReadableReleaseError::displayText($source),
            default => 'Pengajuan rilisan belum disetujui. Alasan terperinci masih perlu diperiksa oleh tim Soundfresh sebelum menentukan perbaikan yang diperlukan.',
        };
        return self::clientMessage($job, $reason,
            'Mohon periksa kembali bagian yang disebutkan pada alasan di atas. Jika terdapat data atau berkas yang perlu diperbaiki, sesuaikan pada rilisan yang sama di Soundfresh. '
            .'Untuk kendala kode UPC, konfirmasikan penggunaannya kepada tim Soundfresh sebelum mengganti kode. Jika alasan belum jelas, hubungi tim Soundfresh agar pemeriksaan lebih lanjut dapat dilakukan.');
    }

    private static function clientMessage(ReleaseJob $job, string $reason, string $action): string
    {
        $title = trim((string) $job->release_title);
        $intro = $title !== '' ? 'Rilisan “'.$title.'” belum dapat dilanjutkan ke tahap distribusi.' : 'Rilisan ini belum dapat dilanjutkan ke tahap distribusi.';
        $message = $intro."\n\nAlasan pemeriksaan: ".$reason."\n\nLangkah yang perlu dilakukan: ".$action
            ."\n\nSetelah kendala diselesaikan dan data diperiksa kembali, rilisan dapat diajukan ulang untuk peninjauan. Pesan ini tidak menyatakan bahwa rilisan sudah berhasil diterbitkan.";
        // Client messages must not expose a provider name, URL, or raw automation log.
        $message = preg_replace('/https?:\/\/\S+/i', '[tautan layanan distribusi]', $message);
        $message = preg_replace('/soundon/i', 'layanan distribusi', $message);
        return mb_substr($message, 0, 3000);
    }

    public static function fingerprint(ReleaseJob $job): string
    {
        return hash('sha256', json_encode([$job->status->value, $job->error_code, $job->error_message,
            $job->soundfresh_workflow_status, $job->soundfresh_release_url], JSON_THROW_ON_ERROR));
    }
}

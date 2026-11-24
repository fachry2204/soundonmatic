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
        $reason = match ($job->error_code) {
            'ISRC_INVALID' => 'ISRC tidak valid. ISRC harus berisi 12 karakter sesuai standar internasional. Periksa dan perbaiki ISRC sebelum mengirim ulang.',
            'UPC_INVALID' => 'UPC tidak valid. Periksa dan perbaiki UPC sebelum mengirim ulang.',
            'AUDIO_INVALID' => 'File audio tidak memenuhi validasi. Periksa format dan kualitas file audio sebelum mengirim ulang.',
            'COVER_INVALID' => 'Cover art tidak memenuhi validasi. Periksa format dan ukuran cover sebelum mengirim ulang.',
            'SOUNDON_CONTRIBUTOR_NOT_PERSISTED', 'SOUNDON_ENTITY_MATCH_REQUIRED', 'SOUNDON_REQUIRED_FIELD_UNRESOLVED', 'SOUNDON_FORM_VALIDATION' => $error['reason'],
            default => $error['title'].'. Proses otomatis belum berhasil; diperlukan pemeriksaan operator sebelum mengirim ulang. Ini bukan bukti file audio rusak.',
        };

        return mb_substr('Rilisan belum dapat diproses ke SoundOn. '.$reason, 0, 3000);
    }

    public static function fingerprint(ReleaseJob $job): string
    {
        return hash('sha256', json_encode([$job->status->value, $job->error_code, $job->error_message,
            $job->soundfresh_workflow_status, $job->soundfresh_release_url], JSON_THROW_ON_ERROR));
    }
}

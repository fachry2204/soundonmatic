<?php

declare(strict_types=1);

namespace App\Services\SoundOn;

/** A missing or partial scan is never evidence that a draft is orphaned. */
final class DraftDeletionEligibility
{
    private const PROTECTED = ['pending', 'under_review', 'rejected', 'revision', 'uploading'];

    /** @param array<string, array{complete: bool, items: array}> $scans */
    public function evaluate(array $draft, array $scans): array
    {
        $title = $this->normalize($draft['title'] ?? null);
        $artist = $this->normalize($draft['artist'] ?? null);
        $id = $draft['draft_id'] ?? null;
        if ($title === '' || $artist === '' || ! is_string($id) || ! preg_match('/^\d{10,}$/', $id)) {
            return ['eligible' => false, 'reason' => 'Identitas draft belum lengkap.'];
        }

        foreach (self::PROTECTED as $status) {
            $scan = $scans[$status] ?? null;
            if (! is_array($scan) || ($scan['complete'] ?? false) !== true || ! is_array($scan['items'] ?? null)) {
                return ['eligible' => false, 'reason' => 'Pemeriksaan '.$status.' belum lengkap.'];
            }
            foreach ($scan['items'] as $release) {
                if (! is_array($release)) return ['eligible' => false, 'reason' => 'Data Soundfresh tidak lengkap.'];
                $releaseTitle = $this->normalize($release['title'] ?? null);
                $releaseArtist = $this->normalize($release['artist'] ?? $release['primary_artist'] ?? null);
                if ($releaseTitle === '' || $releaseArtist === '') return ['eligible' => false, 'reason' => 'Data rilisan Soundfresh tidak lengkap.'];
                // Title alone is deliberately protective: artist variants or
                // contributor formatting must never make deletion more likely.
                similar_text($title, $releaseTitle, $similarity);
                $possiblySameTitle = $title === $releaseTitle || ($artist === $releaseArtist && (
                    str_contains($title, $releaseTitle) || str_contains($releaseTitle, $title) || $similarity >= 80
                ));
                if ($possiblySameTitle) return ['eligible' => false, 'reason' => 'Judul cocok atau mirip ditemukan di '.$status.'.'];
            }
        }

        return ['eligible' => true, 'reason' => 'Tidak ditemukan di status yang dilindungi.'];
    }

    private function normalize(mixed $value): string
    {
        if (! is_string($value)) return '';
        // Treat punctuation and spacing variations as the same title; false
        // positives protect a draft, while false negatives risk deleting it.
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '');
    }
}

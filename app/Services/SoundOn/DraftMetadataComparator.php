<?php

declare(strict_types=1);

namespace App\Services\SoundOn;

/** Fail closed when an existing draft cannot be independently identified. */
final class DraftMetadataComparator
{
    /** @return array{verified:bool,problems:array<int,string>} */
    public function compare(array $source, array $draft): array
    {
        $problems = [];
        $check = function (string $label, mixed $expected, mixed $actual) use (&$problems): void {
            if (! is_scalar($expected) || ! is_scalar($actual) || trim((string) $expected) === '' || trim((string) $actual) === '') {
                $problems[] = $label.': data belum lengkap';
            } elseif ($this->normalize((string) $expected) !== $this->normalize((string) $actual)) {
                $problems[] = $label.': berbeda';
            }
        };
        $check('Judul album', $source['title'] ?? null, $draft['title'] ?? null);
        $check('Artis utama album', $source['primary_artist'] ?? null, $draft['primary_artist'] ?? null);
        $date = static function (mixed $value): ?string {
            if (! is_string($value) || trim($value) === '') return null;
            try { return \Carbon\CarbonImmutable::parse($value)->format('Y-m-d'); }
            catch (\Throwable) { return null; }
        };
        $check('Tanggal rilis', $date($source['release_date'] ?? null), $date($draft['release_date'] ?? null));
        foreach (['title_language' => 'Bahasa judul album', 'genre' => 'Genre album', 'subgenre' => 'Subgenre album'] as $key => $label) {
            if (filled($source[$key] ?? null)) $check($label, $source[$key], $draft[$key] ?? null);
        }
        $sourceTracks = $source['tracks'] ?? null;
        $draftTracks = $draft['tracks'] ?? null;
        if (! is_array($sourceTracks) || ! is_array($draftTracks) || count($sourceTracks) === 0 || count($sourceTracks) !== count($draftTracks)) {
            $problems[] = 'Jumlah track: berbeda atau belum lengkap';
        } else {
            foreach (array_values($sourceTracks) as $index => $track) {
                $actual = array_values($draftTracks)[$index] ?? [];
                if (! is_array($track) || ! is_array($actual)) { $problems[] = 'Track '.($index + 1).': data belum lengkap'; continue; }
                $prefix = 'Track '.($index + 1).' ';
                $check($prefix.'judul', $track['title'] ?? null, $actual['title'] ?? null);
                $check($prefix.'artis utama', $track['primary_artist'] ?? null, $actual['primary_artist'] ?? null);
                foreach (['title_language' => 'bahasa judul', 'language' => 'bahasa lirik', 'genre' => 'genre', 'subgenre' => 'subgenre', 'lyrics' => 'lirik'] as $key => $label) {
                    if (filled($track[$key] ?? null)) $check($prefix.$label, $track[$key], $actual[$key] ?? null);
                }
                foreach (['instrumental' => 'instrumental', 'explicit' => 'explicit'] as $key => $label) {
                    if (array_key_exists($key, $track) && (! array_key_exists($key, $actual) || ! is_bool($actual[$key]) || $actual[$key] !== (bool) $track[$key]))
                        $problems[] = $prefix.$label.': berbeda atau belum lengkap';
                }
                if (($actual['audio_uploaded'] ?? null) !== true) $problems[] = $prefix.'audio: belum terkonfirmasi';
            }
        }
        // Credits must be read structurally; matching title/artist alone is
        // never proof that writers and contributors were saved correctly.
        foreach (['credits_verified', 'cover_verified'] as $proof) {
            if (($draft[$proof] ?? null) !== true) $problems[] = ($proof === 'credits_verified' ? 'Kredit' : 'Cover').': belum terkonfirmasi';
        }
        return ['verified' => $problems === [], 'problems' => array_values(array_unique($problems))];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }
}

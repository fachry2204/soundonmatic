<?php

namespace Tests\Unit;

use App\Services\SoundOn\DraftMetadataComparator;
use Tests\TestCase;

final class DraftMetadataComparatorTest extends TestCase
{
    private function source(): array
    {
        return ['title' => 'The Silent Observer', 'primary_artist' => 'Spies Cult', 'release_date' => 'November 28, 2026',
            'genre' => 'Rock', 'tracks' => [
                ['title' => 'Track A', 'primary_artist' => 'Spies Cult', 'instrumental' => false, 'explicit' => false],
                ['title' => 'Track B', 'primary_artist' => 'Spies Cult', 'instrumental' => false, 'explicit' => false],
            ]];
    }

    public function test_accepts_only_complete_matching_order_and_confirmed_cover_and_credits(): void
    {
        $source = $this->source();
        $draft = $source;
        $draft['release_date'] = '2026-11-28';
        $draft['credits_verified'] = true; $draft['cover_verified'] = true;
        foreach ($draft['tracks'] as &$track) $track['audio_uploaded'] = true;
        unset($track);
        $result = (new DraftMetadataComparator)->compare($source, $draft);
        $this->assertTrue($result['verified']);
        $draft['tracks'] = array_reverse($draft['tracks']);
        $result = (new DraftMetadataComparator)->compare($source, $draft);
        $this->assertFalse($result['verified']);
        $this->assertContains('Track 1 judul: berbeda', $result['problems']);
    }

    public function test_rejects_title_artist_only_and_unconfirmed_assets(): void
    {
        $result = (new DraftMetadataComparator)->compare($this->source(), ['title' => 'The Silent Observer', 'primary_artist' => 'Spies Cult']);
        $this->assertFalse($result['verified']);
        $this->assertContains('Jumlah track: berbeda atau belum lengkap', $result['problems']);
        $this->assertContains('Cover: belum terkonfirmasi', $result['problems']);
        $this->assertContains('Kredit: belum terkonfirmasi', $result['problems']);
    }
}

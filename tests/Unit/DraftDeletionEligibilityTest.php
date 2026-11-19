<?php

namespace Tests\Unit;

use App\Services\SoundOn\DraftDeletionEligibility;
use PHPUnit\Framework\TestCase;

final class DraftDeletionEligibilityTest extends TestCase
{
    public function test_incomplete_status_scan_always_blocks_deletion(): void
    {
        $result = (new DraftDeletionEligibility)->evaluate($this->draft(), []);
        $this->assertFalse($result['eligible']);
    }

    public function test_each_protected_status_blocks_matching_titles_even_with_different_artist(): void
    {
        foreach (array_keys($this->scans()) as $status) {
            $scans = $this->scans();
            $scans[$status]['items'][] = ['title' => ' EMBER  CITY ', 'artist' => 'Someone else'];
            $this->assertFalse((new DraftDeletionEligibility)->evaluate($this->draft(), $scans)['eligible'], $status);
        }
    }

    public function test_complete_scans_and_real_identity_are_required(): void
    {
        $service = new DraftDeletionEligibility;
        $this->assertTrue($service->evaluate($this->draft(), $this->scans())['eligible']);
        $this->assertFalse($service->evaluate([...$this->draft(), 'draft_id' => 'existing-fake'], $this->scans())['eligible']);
        $scans = $this->scans();
        $scans['uploading']['items'][] = ['title' => '', 'artist' => 'Missing'];
        $this->assertFalse($service->evaluate($this->draft(), $scans)['eligible']);
    }

    public function test_punctuation_variants_remain_protected(): void
    {
        $scans = $this->scans();
        $scans['uploading']['items'][] = ['title' => 'Ember-City!', 'artist' => 'Phonk Forge'];
        $this->assertFalse((new DraftDeletionEligibility)->evaluate($this->draft(), $scans)['eligible']);
    }

    public function test_similar_title_for_same_artist_is_protected(): void
    {
        $scans = $this->scans();
        $scans['under_review']['items'][] = ['title' => 'Ember Citi', 'artist' => 'Phonk Forge'];
        $this->assertFalse((new DraftDeletionEligibility)->evaluate($this->draft(), $scans)['eligible']);
    }

    private function draft(): array { return ['draft_id' => '7709712260272785414', 'title' => 'Ember City', 'artist' => 'Phonk Forge']; }
    private function scans(): array
    {
        return array_fill_keys(['pending', 'under_review', 'rejected', 'revision', 'uploading'], ['complete' => true, 'items' => []]);
    }
}

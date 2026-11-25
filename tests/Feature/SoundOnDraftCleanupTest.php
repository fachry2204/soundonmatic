<?php

namespace Tests\Feature;

use App\Livewire\Automation\SoundOnDraftCleanup;
use App\Models\AutomationAccount;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class SoundOnDraftCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $this->seed(AccessControlSeeder::class);
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        foreach (['soundon', 'soundfresh'] as $platform) AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active', 'session_state_encrypted' => ['cookies' => [], 'origins' => []], 'last_authenticated_at' => now()]);
        $user = User::factory()->create(); $user->assignRole('Admin'); return $user;
    }

    public function test_uploading_release_is_never_sent_to_delete_endpoint(): void
    {
        $user = $this->operator();
        $scans = array_fill_keys(['pending', 'under_review', 'revision', 'rejected', 'uploading'], ['complete' => true, 'items' => []]);
        $scans['uploading']['items'][] = ['title' => 'Ember City', 'artist' => 'Phonk Forge'];
        Http::fake([
            'worker.test/v1/soundon/drafts/inventory' => Http::response(['success' => true, 'data' => ['drafts' => [['draft_id' => '7709712260272785414', 'title' => 'Ember City', 'artist' => 'Phonk Forge', 'track_count' => 10]]]]),
            'worker.test/v1/soundfresh/protected-scan' => Http::response(['success' => true, 'data' => ['scans' => $scans]]),
        ]);
        Livewire::actingAs($user)->test(SoundOnDraftCleanup::class)->call('deleteDraft', '7709712260272785414')->assertSee('Penghapusan tidak terkonfirmasi');
        Http::assertSentCount(2);
    }

    public function test_absent_release_is_rescanned_before_one_draft_is_deleted(): void
    {
        $user = $this->operator();
        $scans = array_fill_keys(['pending', 'under_review', 'revision', 'rejected', 'uploading'], ['complete' => true, 'items' => []]);
        Http::fake([
            'worker.test/v1/soundon/drafts/inventory' => Http::response(['success' => true, 'data' => ['drafts' => [['draft_id' => '7709712260272785414', 'title' => 'Ember City', 'artist' => 'Phonk Forge', 'track_count' => 10]]]]),
            'worker.test/v1/soundfresh/protected-scan' => Http::response(['success' => true, 'data' => ['scans' => $scans]]),
            'worker.test/v1/soundon/drafts/delete-one' => Http::response(['success' => true, 'data' => ['deleted' => true]]),
        ]);
        Livewire::actingAs($user)->test(SoundOnDraftCleanup::class)->call('deleteDraft', '7709712260272785414')->assertSee('dihapus dari Aggregator');
        Http::assertSentCount(3);
    }
}

<?php

namespace Tests\Feature;

use App\Models\{User, AutomationRun, ReleaseJob};
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_requires_authorization_and_returns_fresh_worker_values_without_metadata(): void
    {
        $this->getJson('/automation/progress')->assertUnauthorized();
        $user = User::factory()->create();
        $this->seed(AccessControlSeeder::class);
        $this->actingAs($user)->getJson('/automation/progress')->assertForbidden();
        $user->assignRole('Admin');
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'progress-test',
            'soundfresh_release_url' => 'https://example.test/release', 'idempotency_key' => 'progress-test',
            'status' => 'running', 'progress_percent' => 55, 'progress_label' => 'Mengisi metadata',
            'metadata_snapshot_json' => ['private' => 'not exposed'],
        ]);
        $url = '/automation/progress?ids[]='.$job->id;
        $this->getJson($url)->assertOk()->assertJsonPath('jobs.0.progress_percent', 55)
            ->assertJsonMissingPath('jobs.0.metadata_snapshot_json');
        $job->update(['progress_percent' => 73, 'progress_label' => 'Mengunggah audio']);
        $this->getJson($url)->assertOk()->assertJsonPath('jobs.0.progress_percent', 73)
            ->assertJsonPath('jobs.0.progress_label', 'Mengunggah audio')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/automation/progress?ids[]=invalid')->assertUnprocessable();
    }
}

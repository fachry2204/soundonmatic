<?php

namespace Tests\Feature;

use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Diagnostics\ErrorData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ErrorDataTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/error-data-'.bin2hex(random_bytes(8)));
        config(['diagnostics.enabled' => true, 'diagnostics.directory' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_error_folder_is_created_with_instructions_and_all_categories_without_overwriting_history(): void
    {
        $data = app(ErrorData::class);
        $data->initialize();
        foreach (ErrorData::CATEGORIES as $category) $this->assertFileExists($this->directory.'/error '.$category.'.log');
        $this->assertFileExists($this->directory.'/BACA SAYA.txt');
        $data->write('system', 'Persist this failure');
        $data->initialize();
        $this->assertStringContainsString('Persist this failure', file_get_contents($this->directory.'/error system.log'));
    }

    public function test_laravel_errors_are_captured_and_secrets_are_redacted(): void
    {
        Log::error('Login failed password="private word" Bearer secret-bearer https://example.test/path?token=secret-query', [
            'exception' => new \RuntimeException('password=exception-secret'),
            'session' => ['cookies' => 'private-session'], 'password' => 'private-password',
        ]);
        $contents = file_get_contents($this->directory.'/error akun.log');
        $this->assertStringContainsString('REDACTED', $contents);
        foreach (['private word', 'secret-bearer', 'secret-query', 'exception-secret', 'private-session', 'private-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
        $record = json_decode(trim($contents), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(config('automation.bundled_app_version'), $record['version']);
        $this->assertArrayHasKey('trace', $record['context']);
    }

    public function test_handled_release_errors_capture_the_title_and_status_in_separate_files(): void
    {
        $run = AutomationRun::create(['status' => 'completed']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'ERROR-TEST',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/error-test',
            'idempotency_key' => 'error-test', 'release_title' => 'Judul bermasalah',
            'status' => 'failed', 'checkpoint' => 'discovered', 'error_message' => 'Upload timeout',
        ]);
        $this->assertStringContainsString('Judul bermasalah', file_get_contents($this->directory.'/error upload rilis.log'));
        $job->update(['soundon_check_error' => 'SoundOn unavailable']);
        $job->update(['soundfresh_verify_error' => 'Verify failed']);
        $contents = file_get_contents($this->directory.'/error cek status.log');
        $this->assertStringContainsString('SoundOn unavailable', $contents);
        $this->assertStringContainsString('Verify failed', $contents);
        $before = file_get_contents($this->directory.'/error upload rilis.log');
        $job->update(['progress_percent' => 10]);
        $this->assertSame($before, file_get_contents($this->directory.'/error upload rilis.log'));
    }

    public function test_failed_collection_is_logged_even_without_a_release_job(): void
    {
        $run = AutomationRun::create(['status' => 'running']);
        $run->update(['status' => 'failed', 'summary_json' => ['purpose' => 'soundon_status_check', 'error' => 'Collection failed']]);
        $this->assertStringContainsString('Collection failed', file_get_contents($this->directory.'/error cek status.log'));
    }

    public function test_unhandled_queue_failure_is_captured_without_serializing_job_payloads(): void
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('getQueue')->andReturn('status-checks');
        $job->shouldReceive('resolveName')->andReturn('CheckSoundOnReleaseStatus');
        $job->shouldReceive('getJobId')->andReturn('QUEUE-42');
        event(new \Illuminate\Queue\Events\JobFailed('database', $job, new \RuntimeException('Queue timed out')));
        $contents = file_get_contents($this->directory.'/error cek status.log');
        $this->assertStringContainsString('QUEUE-42', $contents);
        $this->assertStringContainsString('Queue timed out', $contents);
    }

    public function test_rotation_keeps_previous_errors_and_logging_failure_does_not_break_the_app(): void
    {
        config(['diagnostics.max_bytes' => 1]);
        $data = app(ErrorData::class);
        $data->write('system', 'First error');
        $data->write('system', 'Second error');
        $this->assertStringContainsString('First error', file_get_contents($this->directory.'/error system.log.1'));
        $this->assertStringContainsString('Second error', file_get_contents($this->directory.'/error system.log'));
        config(['diagnostics.directory' => $this->directory.'/error system.log/invalid']);
        $data->write('system', 'Cannot create this directory');
        $this->assertFileExists($this->directory.'/error system.log');
    }
}

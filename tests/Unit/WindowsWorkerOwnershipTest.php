<?php

namespace Tests\Unit;

use App\Services\Automation\LocalSystemManager;
use PHPUnit\Framework\TestCase;

final class WindowsWorkerOwnershipTest extends TestCase
{
    private string $root = 'C:\\Apps\\Soundfresh';

    public function test_recognizes_the_desktop_browser_with_a_relative_entrypoint(): void
    {
        $this->assertTrue(LocalSystemManager::ownsBrowserWorkerCommand('"C:\\Apps\\Soundfresh\\runtime\\node\\node.exe" --env-file=.env dist/server.js', $this->root));
        $this->assertTrue(LocalSystemManager::ownsBrowserWorkerCommand('php "C:\\Apps\\Soundfresh\\bootstrap\\app.php" --node-runner marker', $this->root));
        $this->assertTrue(LocalSystemManager::ownsBrowserWorkerCommand('node "C:\\Apps\\Soundfresh\\automation-worker\\dist\\server.js"', $this->root));
        $this->assertFalse(LocalSystemManager::ownsBrowserWorkerCommand('"C:\\OtherApp\\runtime\\node\\node.exe" dist/server.js', $this->root));
        $this->assertFalse(LocalSystemManager::ownsBrowserWorkerCommand('node dist/server.js', $this->root));
        $this->assertFalse(LocalSystemManager::ownsBrowserWorkerCommand('php "C:\\OtherApp\\bootstrap\\app.php" --node-runner marker', $this->root));
    }

    public function test_queue_process_belongs_to_this_install_and_exact_queue(): void
    {
        $command = 'php "C:\\Apps\\Soundfresh\\artisan" queue:work database --queue=release-automation,default';
        $this->assertTrue(LocalSystemManager::ownsQueueWorkerCommand($command, $this->root, 'release-automation'));
        $this->assertFalse(LocalSystemManager::ownsQueueWorkerCommand($command, $this->root, 'release'));
        $this->assertFalse(LocalSystemManager::ownsQueueWorkerCommand(str_replace('Soundfresh', 'OtherApp', $command), $this->root, 'release-automation'));
        $this->assertFalse(LocalSystemManager::ownsQueueWorkerCommand('php "C:\\Apps\\Soundfresh\\artisan" serve --queue=release-automation', $this->root, 'release-automation'));
        $this->assertTrue(LocalSystemManager::ownsQueueWorkerCommand('php "C:\\Apps\\Soundfresh\\bootstrap\\app.php" --queue-runner marker queue:work database --queue=soundfresh-rejects', $this->root, 'soundfresh-rejects'));
    }

    public function test_wmic_snapshot_filters_other_apps_and_handles_windows_line_endings(): void
    {
        $output = "CommandLine=\"C:\\Apps\\Soundfresh\\runtime\\node\\node.exe\" dist/server.js\r\r\nProcessId=451\r\r\n\r\r\n"
            ."CommandLine=\"C:\\OtherApp\\runtime\\node\\node.exe\" dist/server.js\r\r\nProcessId=452\r\r\n";
        $this->assertSame([451], LocalSystemManager::ownedProcessIds($output, $this->root));
        $queue = "CommandLine=php C:/Apps/Soundfresh/artisan queue:work --queue=status-checks\nProcessId=453\n\n"
            ."CommandLine=php C:/Apps/OtherApp/artisan queue:work --queue=status-checks\nProcessId=454\n";
        $this->assertSame([453], LocalSystemManager::ownedProcessIds($queue, $this->root, 'status-checks'));
    }

    public function test_update_waits_for_browser_and_every_queue_without_requiring_server_shutdown(): void
    {
        $stopped = ['server' => true, 'worker' => false, 'release_workers' => 0, 'status_workers' => 0, 'reject_workers' => 0];
        $this->assertTrue(LocalSystemManager::workersStopped($stopped));
        foreach (['worker', 'release_workers', 'status_workers', 'reject_workers'] as $key) {
            $this->assertFalse(LocalSystemManager::workersStopped([...$stopped, $key => 1]));
        }
    }
}

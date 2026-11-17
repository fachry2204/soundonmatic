<?php

namespace Tests\Unit;

use App\Services\Automation\LocalSystemManager;
use PHPUnit\Framework\TestCase;

final class BrowserWorkerStartupDiagnosticTest extends TestCase
{
    public function test_identifies_cs_prng_failure_without_exposing_worker_log(): void
    {
        $message = LocalSystemManager::classifyWorkerStartupError('Assertion failed: ncrypto::CSPRNG(nullptr, 0) secret log');
        $this->assertStringContainsString('BROWSER_WORKER_CSPRNG_FAILED', $message);
        $this->assertStringNotContainsString('secret log', $message);
        $this->assertNull(LocalSystemManager::classifyWorkerStartupError('another startup error'));
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HealthcheckTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLiveDoesNotNeedDatabaseOrSession(): void
    {
        putenv('DB_DSN=pgsql:host=invalid.invalid');
        putenv('OLLAMA_BASE_URL=http://invalid.invalid');
        ob_start();
        require __DIR__ . '/../health/live.php';
        $this->assertSame("ok\n", ob_get_clean());
        $this->assertSame(200, http_response_code());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadyWithoutConfigurationIsUnavailable(): void
    {
        putenv('DB_DSN');
        ob_start();
        require __DIR__ . '/../health/ready.php';
        $this->assertSame("unavailable\n", ob_get_clean());
        $this->assertSame(503, http_response_code());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadyConnectionFailureDoesNotLeakDetails(): void
    {
        putenv('DB_DSN=pgsql:host=127.0.0.1;port=1;dbname=health_probe');
        putenv('DB_USER=health_probe');
        $start = microtime(true);
        ob_start();
        require __DIR__ . '/../health/ready.php';
        $this->assertSame("unavailable\n", ob_get_clean());
        $this->assertSame(503, http_response_code());
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertLessThan(5, microtime(true) - $start);
    }
}

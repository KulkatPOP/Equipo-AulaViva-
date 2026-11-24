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
        $this->assertNull($pdo);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadyTimesOutWhenServerAcceptsButDoesNotRespond(): void
    {
        // TCP is available, but PostgreSQL startup/authentication never completes.
        // This detects a missing connect timeout, unlike a refused connection.
        $code = '$s=stream_socket_server("tcp://127.0.0.1:0",$n,$e);'
            . 'echo stream_socket_get_name($s,false),"\\n";flush();'
            . '$c=stream_socket_accept($s,8);if($c){sleep(8);}';
        $server = proc_open([PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($server);
        try {
            $address = trim((string) fgets($pipes[1]));
            $port = (int) substr(strrchr($address, ':'), 1);
            $this->assertGreaterThan(0, $port);
            putenv('DB_DSN=pgsql:host=127.0.0.1;port=' . $port . ';dbname=health_probe');
            putenv('DB_USER');
            putenv('DB_PASSWORD');
            $start = microtime(true);
            ob_start();
            require __DIR__ . '/../health/ready.php';
            $this->assertSame("unavailable\n", ob_get_clean());
            $this->assertSame(503, http_response_code());
            $this->assertLessThan(6, microtime(true) - $start);
            $this->assertSame(PHP_SESSION_NONE, session_status());
            $this->assertNull($pdo);
        } finally {
            proc_terminate($server);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($server);
        }
    }
}

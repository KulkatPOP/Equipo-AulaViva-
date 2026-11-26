<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/tutor/OllamaKeepAlive.php';

final class OllamaKeepAliveTest extends TestCase
{
    private const VARIABLE = 'TUTOR_OLLAMA_KEEP_ALIVE_SECONDS';

    private string|false $previousValue;

    protected function setUp(): void
    {
        $this->previousValue = getenv(self::VARIABLE);
        putenv(self::VARIABLE);
    }

    protected function tearDown(): void
    {
        putenv(
            $this->previousValue === false
                ? self::VARIABLE
                : self::VARIABLE . '=' . $this->previousValue
        );
    }

    public function testPreservesDefaultWhenNotConfigured(): void
    {
        self::assertSame([], OllamaKeepAlive::parametros());
    }

    public static function validValues(): array
    {
        return [
            'un minuto' => ['60', 60],
            'cinco minutos' => ['300', 300],
            'descarga inmediata' => ['0', 0],
            'un segundo' => ['1', 1],
            'limite superior' => ['3600', 3600],
        ];
    }

    #[DataProvider('validValues')]
    public function testAcceptsValidValues(
        string $value,
        int $expected
    ): void {
        putenv(self::VARIABLE . '=' . $value);

        self::assertSame(
            ['keep_alive' => $expected],
            OllamaKeepAlive::parametros()
        );
    }

    public static function invalidValues(): array
    {
        return [
            'vacio' => [''],
            'negativo' => ['-1'],
            'decimal' => ['1.5'],
            'texto' => ['abc'],
            'unidad incluida' => ['60s'],
            'espacios' => [' 60 '],
            'sobre el limite' => ['3601'],
            'notacion cientifica' => ['1e2'],
            'numero excesivo' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $value): void
    {
        putenv(self::VARIABLE . '=' . $value);

        $this->expectException(RuntimeException::class);

        OllamaKeepAlive::parametros();
    }
}

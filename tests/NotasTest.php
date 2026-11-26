<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/notas.php';

final class NotasTest extends TestCase
{
    public static function notasValidas(): array
    {
        return [
            ['1', '1.0'],
            ['1,0', '1.0'],
            ['2.5', '2.5'],
            ['3,9', '3.9'],
            ['4', '4.0'],
            ['5,5', '5.5'],
            ['6.9', '6.9'],
            ['7', '7.0'],
            ['7.0', '7.0'],
            ['7,0', '7.0'],
            [' 6,5 ', '6.5']
        ];
    }

    #[DataProvider('notasValidas')]
    public function testValidarNotasCorrectas(
        string $entrada,
        string $esperada
    ): void {
        self::assertSame(
            $esperada,
            validarNotaManual($entrada)
        );
    }

    public static function notasInvalidas(): array
    {
        return [
            ['0'],
            ['0.9'],
            ['7.1'],
            ['8'],
            ['-1'],
            ['4.55'],
            ['4,55'],
            [''],
            [' '],
            ['texto'],
            ['NaN'],
            ['INF'],
            ['4e0'],
            ['04'],
            ['+4'],
            ['4.'],
            ['4,'],
            [null],
            [true],
            [false],
            [4],
            [4.5],
            [[]],
            [['nota' => '5.0']],
            ['<script>alert(1)</script>'],
            ["4.0\0"],
            ["4\n5"]
        ];
    }

    #[DataProvider('notasInvalidas')]
    public function testRechazarNotasInvalidas(
        mixed $entrada
    ): void {
        self::assertNull(
            validarNotaManual($entrada)
        );
    }

    public function testFormatearNotas(): void
    {
        self::assertSame('—', formatearNota(null));
        self::assertSame('1,0', formatearNota(1.0));
        self::assertSame('3,9', formatearNota(3.9));
        self::assertSame('4,0', formatearNota(4.0));
        self::assertSame('6,5', formatearNota(6.5));
        self::assertSame('7,0', formatearNota(7.0));
    }

    public function testFormatearPromedioConRedondeo(): void
    {
        self::assertSame('5,3', formatearNota(5.325));
        self::assertSame('4,7', formatearNota(4.66));
    }

    public function testPromedioSinNotas(): void
    {
        self::assertNull(
            calcularPromedioNotas([])
        );
    }

    public function testPromedioUnaNota(): void
    {
        self::assertSame(
            6.5,
            calcularPromedioNotas([6.5])
        );
    }

    public function testPromedioVariasNotas(): void
    {
        self::assertEqualsWithDelta(
            5.0,
            calcularPromedioNotas([4.0, 5.0, 6.0]),
            0.00001
        );
    }

    public function testPromedioConIdentificadores(): void
    {
        self::assertEqualsWithDelta(
            5.5,
            calcularPromedioNotas([
                10 => 4.0,
                20 => 6.0,
                30 => 6.5
            ]),
            0.00001
        );
    }

    public function testPromedioNoRedondeado(): void
    {
        self::assertEqualsWithDelta(
            5.325,
            calcularPromedioNotas([
                6.3, 1.0, 7.0, 7.0
            ]),
            0.00001
        );
    }

    public function testBloqueoRequiereTransaccion(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(LogicException::class);

        $this->expectExceptionMessage(
            'Se requiere una transacción activa.'
        );

        bloquearLibroNotas($pdo, 1);
    }

    public function testGuardarNotaRequiereTransaccion(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(LogicException::class);

        guardarNotaManual(
            $pdo,
            1,
            1,
            10,
            1,
            20,
            '5.0'
        );
    }

    public function testGuardarNotaInvalida(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->beginTransaction();

        try {
            $this->expectException(
                InvalidArgumentException::class
            );

            $this->expectExceptionMessage(
                'La nota debe estar entre 1,0 y 7,0.'
            );

            guardarNotaManual(
                $pdo,
                1,
                1,
                10,
                1,
                20,
                '8.0'
            );
        } finally {
            $pdo->rollBack();
        }
    }

    public function testCrearColumnaRequiereTransaccion(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(LogicException::class);

        crearColumnaNota($pdo, 1, 10);
    }

    public function testInicializarRequiereTransaccion(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(LogicException::class);

        inicializarLibroNotas($pdo, 1, 10);
    }

    public function testSincronizarRequiereTransaccion(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(LogicException::class);

        sincronizarNotasAutomaticas($pdo, 1, 10);
    }

    public function testEliminarColumnaRequiereTransaccion(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(LogicException::class);

        eliminarColumnaNota($pdo, 1, 1);
    }

    public function testGuardarCalificacionRequiereTransaccion(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(LogicException::class);

        guardarCalificacionLibro(
            $pdo,
            1,
            1,
            10,
            1,
            20,
            '6.0'
        );
    }
}

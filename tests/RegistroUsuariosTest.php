<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../config/registro_usuarios.php';

final class RegistroUsuariosTest extends TestCase
{
    #[DataProvider('textosValidos')]
    public function testNormalizarTexto(
        string $entrada,
        string $esperado
    ): void {
        self::assertSame(
            $esperado,
            normalizarTextoRegistro($entrada)
        );
    }

    public static function textosValidos(): array
    {
        return [
            ['Matemáticas', 'matematicas'],
            ['LENGUAJE', 'lenguaje'],
            ['  Ciencias Naturales  ', 'ciencias naturales'],
            ['8   Básico   A', '8 basico a'],
            ['Educación Física', 'educacion fisica'],
            ['INGLÉS', 'ingles'],
            ['Música', 'musica'],
            ['Pingüino', 'pinguino'],
            ['ÁÉÍÓÚÜ', 'aeiouu'],
            ["Ciencias\tNaturales", 'ciencias naturales'],
            ["Ciencias\nNaturales", 'ciencias naturales'],
            ['', ''],
            ['   ', ''],
            ['Historia', 'historia'],
            ['8° Básico B', '8° basico b']
        ];
    }

    #[DataProvider('rutsNormalizados')]
    public function testNormalizarRut(
        string $entrada,
        string $esperado
    ): void {
        self::assertSame(
            $esperado,
            normalizarRutRegistro($entrada)
        );
    }

    public static function rutsNormalizados(): array
    {
        return [
            ['12.345.678-5', '123456785'],
            ['12345678-5', '123456785'],
            ['123456785', '123456785'],
            [' 12.345.678-5 ', '123456785'],
            ['10.000.013-k', '10000013K'],
            ['10.000.013-K', '10000013K'],
            ['12 345 678-5', '123456785'],
            ['', ''],
            ['   ', '']
        ];
    }

    #[DataProvider('rutsValidos')]
    public function testAceptarRutValido(string $rut): void
    {
        self::assertTrue(validarRutRegistro($rut));
    }

    public static function rutsValidos(): array
    {
        return [
            ['12.345.678-5'],
            ['12345678-5'],
            ['123456785'],
            ['11.111.111-1'],
            ['22.222.222-2'],
            ['10.000.013-K'],
            ['10.000.013-k'],
            ['10.000.004-0'],
            ['1.234.567-4'],
            [' 12.345.678-5 ']
        ];
    }

    #[DataProvider('rutsInvalidos')]
    public function testRechazarRutInvalido(string $rut): void
    {
        self::assertFalse(validarRutRegistro($rut));
    }

    public static function rutsInvalidos(): array
    {
        return [
            [''],
            [' '],
            ['123'],
            ['12345678'],
            ['12.345.678-9'],
            ['12.345.678-K'],
            ['11.111.111-2'],
            ['22.222.222-3'],
            ['10.000.013-1'],
            ['10.000.004-1'],
            ['123456789012'],
            ['ABCDEFGH'],
            ['12345678-A'],
            ['1234567-X'],
            ['-'],
            ['K'],
            ['12345678-55'],
            ['12345678/5'],
            ['12345678_5'],
            ['12345678@5'],
            ["1234\05678-5"],
            ['<script>alert(1)</script>']
        ];
    }

    #[DataProvider('erroresSubida')]
    public function testRechazarErrorDeSubida(int $error): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'No se pudo recibir el archivo Excel.'
        );

        validarArchivoRegistro([
            'error' => $error
        ]);
    }

    public static function erroresSubida(): array
    {
        return [
            [UPLOAD_ERR_INI_SIZE],
            [UPLOAD_ERR_FORM_SIZE],
            [UPLOAD_ERR_PARTIAL],
            [UPLOAD_ERR_NO_FILE],
            [UPLOAD_ERR_NO_TMP_DIR],
            [UPLOAD_ERR_CANT_WRITE],
            [UPLOAD_ERR_EXTENSION]
        ];
    }

    public function testRechazarArchivoSinDatos(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        validarArchivoRegistro([]);
    }

    public function testRechazarArchivoInexistente(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Archivo no válido.'
        );

        validarArchivoRegistro([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => 'archivo-inexistente.xlsx',
            'name' => 'usuarios.xlsx'
        ]);
    }

    public function testRechazarArchivoLocalNoSubido(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'registro_');

        self::assertNotFalse($ruta);

        try {
            file_put_contents($ruta, 'Archivo de prueba');

            $this->expectException(
                InvalidArgumentException::class
            );

            $this->expectExceptionMessage(
                'Archivo no válido.'
            );

            validarArchivoRegistro([
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $ruta,
                'name' => 'usuarios.xlsx'
            ]);
        } finally {
            unlink($ruta);
        }
    }

    public function testLimitesDeImportacion(): void
    {
        self::assertSame(
            5242880,
            REGISTRO_MAX_ARCHIVO
        );

        self::assertSame(
            1000,
            REGISTRO_MAX_FILAS
        );

        self::assertSame(
            41943040,
            REGISTRO_MAX_DESCOMPRIMIDO
        );
    }
}
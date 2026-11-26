<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

require_once __DIR__ . '/../config/registro_usuarios.php';

final class RegistroExcelTest extends TestCase
{
    private array $archivos = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos as $ruta) {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
    }

    private function crearPlantilla(): Spreadsheet
    {
        $excel = new Spreadsheet();

        $estudiantes = $excel->getActiveSheet();
        $estudiantes->setTitle('Estudiantes');

        $estudiantes->fromArray([
            'RUT',
            'Nombre',
            'Apellido',
            'Usuario',
            'Contraseña',
            'Curso'
        ], null, 'A1');

        $apoderados = $excel->createSheet();
        $apoderados->setTitle('Apoderados');

        $apoderados->fromArray([
            'RUT',
            'Nombre',
            'Apellido',
            'Usuario',
            'Contraseña',
            'RUT estudiante'
        ], null, 'A1');

        $profesores = $excel->createSheet();
        $profesores->setTitle('Profesores');

        $profesores->fromArray([
            'RUT',
            'Nombre',
            'Apellido',
            'Usuario',
            'Contraseña',
            'Curso',
            'Asignatura'
        ], null, 'A1');

        return $excel;
    }

    private function guardarExcel(Spreadsheet $excel): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'registro_');

        self::assertNotFalse($ruta);

        $this->archivos[] = $ruta;

        try {
            $writer = new Xlsx($excel);
            $writer->save($ruta);
        } finally {
            $excel->disconnectWorksheets();
        }

        return $ruta;
    }

    public function testLeerPlantillaVacia(): void
    {
        $ruta = $this->guardarExcel(
            $this->crearPlantilla()
        );

        $resultado = leerExcelRegistro($ruta);

        self::assertSame([
            'estudiantes' => [],
            'apoderados' => [],
            'profesores' => [],
            'errores' => []
        ], $resultado);
    }

    public function testLeerEstudiante(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')->fromArray([
            '12.345.678-5',
            'Martín',
            'Pérez',
            'martin.prueba',
            'Clave12345',
            '8 Básico A'
        ], null, 'A2');

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        self::assertCount(1, $resultado['estudiantes']);

        self::assertSame([
            'fila' => 2,
            'rut' => '123456785',
            'nombre' => 'Martín',
            'apellido' => 'Pérez',
            'username' => 'martin.prueba',
            'password' => 'Clave12345',
            'rol' => 'alumno',
            'curso' => '8 Básico A'
        ], $resultado['estudiantes'][0]);

        self::assertEmpty($resultado['errores']);
    }

    public function testLeerApoderado(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Apoderados')->fromArray([
            '11.111.111-1',
            'Laura',
            'Pérez',
            'laura.prueba',
            'Clave12345',
            '12.345.678-5'
        ], null, 'A2');

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        self::assertCount(1, $resultado['apoderados']);

        $usuario = $resultado['apoderados'][0];

        self::assertSame('apoderado', $usuario['rol']);
        self::assertSame('111111111', $usuario['rut']);
        self::assertSame(
            '123456785',
            $usuario['rut_estudiante']
        );

        self::assertEmpty($resultado['errores']);
    }

    public function testLeerProfesor(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Profesores')->fromArray([
            '22.222.222-2',
            'Carlos',
            'González',
            'carlos.prueba',
            'Clave12345',
            '8 Básico A',
            'Matemáticas'
        ], null, 'A2');

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        self::assertCount(1, $resultado['profesores']);

        $usuario = $resultado['profesores'][0];

        self::assertSame('profesor', $usuario['rol']);
        self::assertSame('8 Básico A', $usuario['curso']);
        self::assertSame(
            'Matemáticas',
            $usuario['asignatura']
        );

        self::assertEmpty($resultado['errores']);
    }

    public function testLeerLasTresHojas(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')->fromArray([
            '12.345.678-5',
            'Martín',
            'Pérez',
            'martin',
            'Clave12345',
            '8 Básico A'
        ], null, 'A2');

        $excel->getSheetByName('Apoderados')->fromArray([
            '11.111.111-1',
            'Laura',
            'Pérez',
            'laura',
            'Clave12345',
            '12.345.678-5'
        ], null, 'A2');

        $excel->getSheetByName('Profesores')->fromArray([
            '22.222.222-2',
            'Carlos',
            'González',
            'carlos',
            'Clave12345',
            '8 Básico A',
            'Matemáticas'
        ], null, 'A2');

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        self::assertCount(1, $resultado['estudiantes']);
        self::assertCount(1, $resultado['apoderados']);
        self::assertCount(1, $resultado['profesores']);
        self::assertEmpty($resultado['errores']);
    }

    public function testIgnorarFilasVacias(): void
    {
        $excel = $this->crearPlantilla();

        $hoja = $excel->getSheetByName('Estudiantes');

        $hoja->fromArray([
            '12.345.678-5',
            'Martín',
            'Pérez',
            'martin',
            'Clave12345',
            '8 Básico A'
        ], null, 'A5');

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        self::assertCount(1, $resultado['estudiantes']);

        self::assertSame(
            5,
            $resultado['estudiantes'][0]['fila']
        );
    }

    public function testNormalizarEspacios(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')->fromArray([
            ' 12.345.678-5 ',
            ' Martín ',
            ' Pérez ',
            ' martin ',
            ' Clave12345 ',
            ' 8 Básico A '
        ], null, 'A2');

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        $usuario = $resultado['estudiantes'][0];

        self::assertSame('123456785', $usuario['rut']);
        self::assertSame('Martín', $usuario['nombre']);
        self::assertSame('Pérez', $usuario['apellido']);
        self::assertSame('martin', $usuario['username']);
        self::assertSame('8 Básico A', $usuario['curso']);

        // La contraseña debe conservarse exactamente.
        self::assertSame(
            ' Clave12345 ',
            $usuario['password']
        );
    }

    public function testRechazarFormula(): void
    {
        $excel = $this->crearPlantilla();

        $hoja = $excel->getSheetByName('Estudiantes');

        $hoja->fromArray([
            '12.345.678-5',
            'Martín',
            'Pérez',
            'martin',
            'Clave12345',
            '8 Básico A'
        ], null, 'A2');

        $hoja->setCellValueExplicit(
            'B2',
            '=1+1',
            DataType::TYPE_FORMULA
        );

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        self::assertEmpty($resultado['estudiantes']);
        self::assertCount(1, $resultado['errores']);

        self::assertSame([
            'hoja' => 'Estudiantes',
            'fila' => 2,
            'mensaje' =>
                'No se permiten fórmulas ni valores complejos.'
        ], $resultado['errores'][0]);
    }

    public function testRechazarHojaFaltante(): void
    {
        $excel = $this->crearPlantilla();

        $excel->removeSheetByIndex(2);

        $ruta = $this->guardarExcel($excel);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'El Excel debe contener las tres hojas de la plantilla.'
        );

        leerExcelRegistro($ruta);
    }

    public function testRechazarHojaAdicional(): void
    {
        $excel = $this->crearPlantilla();

        $excel->createSheet()->setTitle('Extra');

        $ruta = $this->guardarExcel($excel);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'El Excel debe contener las tres hojas de la plantilla.'
        );

        leerExcelRegistro($ruta);
    }

    public function testRechazarNombreDeHojaIncorrecto(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')
            ->setTitle('Alumnos');

        $ruta = $this->guardarExcel($excel);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'El Excel debe contener las tres hojas de la plantilla.'
        );

        leerExcelRegistro($ruta);
    }

    public function testRechazarEncabezadoIncorrecto(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')
            ->setCellValue('A1', 'Identificador');

        $ruta = $this->guardarExcel($excel);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Encabezados incorrectos en la hoja Estudiantes.'
        );

        leerExcelRegistro($ruta);
    }

    public function testRechazarColumnaAdicional(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')
            ->setCellValue('G1', 'Columna extra');

        $ruta = $this->guardarExcel($excel);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'La hoja Estudiantes supera las dimensiones permitidas.'
        );

        leerExcelRegistro($ruta);
    }

    public function testRechazarExcesoDeFilas(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')
            ->setCellValue('A1002', '123456785');

        $ruta = $this->guardarExcel($excel);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'La hoja Estudiantes supera las dimensiones permitidas.'
        );

        leerExcelRegistro($ruta);
    }

    public function testConservarRegistroIncompleto(): void
    {
        $excel = $this->crearPlantilla();

        $excel->getSheetByName('Estudiantes')
            ->setCellValue('B2', 'Martín');

        $resultado = leerExcelRegistro(
            $this->guardarExcel($excel)
        );

        self::assertCount(1, $resultado['estudiantes']);

        $usuario = $resultado['estudiantes'][0];

        self::assertSame('', $usuario['rut']);
        self::assertSame('Martín', $usuario['nombre']);
        self::assertSame('', $usuario['password']);
    }
}
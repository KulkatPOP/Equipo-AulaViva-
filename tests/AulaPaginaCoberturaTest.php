<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AulaPaginaCoberturaTest extends TestCase
{
    private function prepararBaseDeDatos(
        bool $conAlumnos = true
    ): PDO {
        $pdo = new PDO('sqlite::memory:');

        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $pdo->exec("
            CREATE TABLE aulas (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER,
                nombre TEXT,
                activo INTEGER
            );

            CREATE TABLE aula_profesores (
                aula_id INTEGER,
                profesor_id INTEGER
            );

            CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER,
                rut TEXT,
                username TEXT,
                nombre TEXT,
                apellido TEXT,
                rol TEXT,
                activo INTEGER
            );

            CREATE TABLE aula_alumnos (
                aula_id INTEGER,
                alumno_id INTEGER
            );

            INSERT INTO aulas VALUES
                (1, 1, 'Matemática 1A', 1);

            INSERT INTO aula_profesores VALUES
                (1, 10);

            INSERT INTO usuarios VALUES
                (
                    10, 1, '11111111-1', 'profesor10',
                    'Carlos', 'Profesor', 'profesor', 1
                );
        ");

        if ($conAlumnos) {
            $pdo->exec("
                INSERT INTO usuarios VALUES
                    (
                        20, 1, '12345678-9', 'alumno20',
                        'Juan', 'Pérez', 'alumno', 1
                    ),
                    (
                        21, 2, '22222222-2', 'alumno21',
                        'Estudiante', 'Ajeno', 'alumno', 1
                    ),
                    (
                        22, 1, '33333333-3', 'alumno22',
                        'Estudiante', 'Inactivo', 'alumno', 0
                    );

                INSERT INTO aula_alumnos VALUES
                    (1, 20),
                    (1, 21),
                    (1, 22);
            ");
        }

        return $pdo;
    }

    private function ejecutarPagina(
        bool $conAlumnos
    ): string {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['id'] = '1';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [
            'usuario_id' => 10,
            'colegio_id' => 1,
            'rol' => 'profesor',
            'nombre' => 'Carlos Profesor'
        ];

        $pdo = $this->prepararBaseDeDatos($conAlumnos);

        ob_start();

        require __DIR__ . '/../aula.php';

        return ob_get_clean();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMuestraAulaConAlumnos(): void
    {
        $html = $this->ejecutarPagina(true);

        self::assertStringContainsString(
            'Matemática 1A',
            $html
        );

        self::assertStringContainsString(
            'Juan Pérez',
            $html
        );

        self::assertStringContainsString(
            'alumno20',
            $html
        );

        self::assertStringContainsString(
            'Carlos Profesor',
            $html
        );

        self::assertStringContainsString(
            'materiales.php?aula_id=1',
            $html
        );

        self::assertStringContainsString(
            'evaluaciones.php?aula_id=1',
            $html
        );

        self::assertStringContainsString(
            'notas.php?aula_id=1',
            $html
        );

        self::assertStringContainsString(
            'Total: 1',
            $html
        );

        self::assertStringNotContainsString(
            'Estudiante Ajeno',
            $html
        );

        self::assertStringNotContainsString(
            'Estudiante Inactivo',
            $html
        );

        self::assertStringNotContainsString(
            'alumno21',
            $html
        );

        self::assertStringNotContainsString(
            'alumno22',
            $html
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMuestraAulaSinAlumnos(): void
    {
        $html = $this->ejecutarPagina(false);

        self::assertStringContainsString(
            'Matemática 1A',
            $html
        );

        self::assertStringContainsString(
            'No hay estudiantes asignados a esta aula.',
            $html
        );

        self::assertStringContainsString(
            'Total: 0',
            $html
        );

        self::assertStringNotContainsString(
            'class="av-student-row"',
            $html
        );
    }
}

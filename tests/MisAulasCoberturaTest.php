<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class MisAulasCoberturaTest extends TestCase
{
    private function crearBaseDeDatos(): PDO
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $pdo->exec("
            CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER,
                nombre TEXT,
                apellido TEXT,
                rol TEXT,
                activo INTEGER
            );

            CREATE TABLE aulas (
                id INTEGER PRIMARY KEY,
                nombre TEXT,
                colegio_id INTEGER,
                activo INTEGER
            );

            CREATE TABLE aula_profesores (
                aula_id INTEGER,
                profesor_id INTEGER
            );

            INSERT INTO usuarios VALUES
                (10, 1, 'Juan', 'Pérez', 'profesor', 1),
                (20, 1, 'Otro', 'Profesor', 'profesor', 1),
                (999, 1, 'Profesor', 'Sin aulas', 'profesor', 1);

            INSERT INTO aulas VALUES
                (1, 'Matemáticas', 1, 1),
                (2, 'Lenguaje', 1, 1),
                (3, 'Aula de otro colegio', 2, 1),
                (4, 'Aula inactiva', 1, 0),
                (5, 'Aula de otro profesor', 1, 1),
                (6, '<script>alert(1)</script>', 1, 1);

            INSERT INTO aula_profesores VALUES
                (1, 10),
                (2, 10),
                (3, 10),
                (4, 10),
                (5, 20),
                (6, 10);
        ");

        return $pdo;
    }

    private function ejecutarPagina(
        int $profesorId = 10,
        int $colegioId = 1
    ): array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [
            'usuario_id' => $profesorId,
            'colegio_id' => $colegioId,
            'rol' => 'profesor',
            'nombre' => 'Juan Pérez'
        ];

        $pdo = $this->crearBaseDeDatos();

        ob_start();

        require __DIR__ . '/../mis_aulas.php';

        $html = ob_get_clean();

        return [
            'status' => http_response_code(),
            'html' => $html
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMuestraAulasDelProfesor(): void
    {
        $resultado = $this->ejecutarPagina();

        self::assertContains(
            $resultado['status'],
            [false, 200]
        );

        self::assertStringContainsString(
            'Matemáticas',
            $resultado['html']
        );

        self::assertStringContainsString(
            'Lenguaje',
            $resultado['html']
        );

        self::assertStringContainsString(
            'aula.php?id=1',
            $resultado['html']
        );

        self::assertStringContainsString(
            'aula.php?id=2',
            $resultado['html']
        );

        self::assertStringContainsString(
            'Ingresar al aula',
            $resultado['html']
        );

        self::assertSame(
            3,
            substr_count(
                $resultado['html'],
                'class="av-course-card"'
            )
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoMuestraAulasDeOtros(): void
    {
        $resultado = $this->ejecutarPagina();

        self::assertStringNotContainsString(
            'Aula de otro colegio',
            $resultado['html']
        );

        self::assertStringNotContainsString(
            'Aula de otro profesor',
            $resultado['html']
        );

        self::assertStringNotContainsString(
            'Aula inactiva',
            $resultado['html']
        );

        foreach ([3, 4, 5] as $id) {
            self::assertStringNotContainsString(
                'href="aula.php?id=' . $id . '"',
                $resultado['html']
            );
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMuestraMensajeSinAulas(): void
    {
        $resultado = $this->ejecutarPagina(999);

        self::assertStringContainsString(
            'No tienes aulas asignadas.',
            $resultado['html']
        );

        self::assertStringNotContainsString(
            'aula.php?id=',
            $resultado['html']
        );

        self::assertStringNotContainsString(
            'class="av-course-card"',
            $resultado['html']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEscapaHtmlEnNombreDeAula(): void
    {
        $resultado = $this->ejecutarPagina();

        self::assertStringContainsString(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $resultado['html']
        );

        self::assertStringNotContainsString(
            '<script>alert(1)</script>',
            $resultado['html']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMuestraNombreDelProfesor(): void
    {
        $resultado = $this->ejecutarPagina();

        self::assertStringContainsString(
            'Juan Pérez',
            $resultado['html']
        );

        self::assertStringContainsString(
            'Cerrar sesión',
            $resultado['html']
        );

        self::assertStringContainsString(
            'href="logout.php"',
            $resultado['html']
        );

        self::assertStringContainsString(
            'Inicio docente | AulaIA',
            $resultado['html']
        );
    }
}

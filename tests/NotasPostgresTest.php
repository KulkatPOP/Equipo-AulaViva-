<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/notas.php';

final class NotasPostgresTest extends TestCase
{
    private PDO $pdo;
    private string $schema;

    protected function setUp(): void
    {
        $dsn = getenv('US05_TEST_DSN');

        if (!$dsn) {
            $this->markTestSkipped(
                'Requiere PostgreSQL desechable us05_test.'
            );
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('US05_TEST_USER') ?: 'postgres',
            getenv('US05_TEST_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        if (
            $this->pdo->query(
                'SELECT current_database()'
            )->fetchColumn() !== 'us05_test'
        ) {
            throw new RuntimeException(
                'Las pruebas requieren la base desechable us05_test.'
            );
        }

        $this->schema = 'notas_' . bin2hex(random_bytes(6));

        $this->pdo->exec(
            'CREATE SCHEMA ' . $this->schema
        );

        $this->pdo->exec(
            'SET search_path TO ' . $this->schema
        );

        $this->pdo->exec("
            CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER NOT NULL,
                nombre TEXT NOT NULL,
                apellido TEXT NOT NULL,
                username TEXT,
                rol TEXT NOT NULL,
                activo BOOLEAN NOT NULL DEFAULT TRUE
            );

            CREATE TABLE aulas (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER NOT NULL,
                nombre TEXT NOT NULL,
                activo BOOLEAN NOT NULL DEFAULT TRUE
            );

            CREATE TABLE aula_alumnos (
                aula_id INTEGER REFERENCES aulas(id),
                alumno_id INTEGER REFERENCES usuarios(id),
                PRIMARY KEY (aula_id, alumno_id)
            );

            CREATE TABLE aula_profesores (
                aula_id INTEGER REFERENCES aulas(id),
                profesor_id INTEGER REFERENCES usuarios(id),
                PRIMARY KEY (aula_id, profesor_id)
            );
        ");

        foreach ([
            '002_evaluaciones.sql',
            '003_rendicion.sql',
            '005_apoderados.sql',
            '007_libro_notas.sql',
            '008_ajustes_notas.sql',
            '009_evaluaciones_disponibilidad.sql',
            '010_tipo_respuesta.sql'
        ] as $archivo) {
            $sql = file_get_contents(
                __DIR__ . '/../migrations/' . $archivo
            );

            if ($sql === false) {
                throw new RuntimeException(
                    'No se pudo leer ' . $archivo
                );
            }

            $this->pdo->exec($sql);
        }

        $this->pdo->exec("
            INSERT INTO usuarios
                (id, colegio_id, nombre, apellido, rol, activo)
            VALUES
                (1,1,'Carlos','Profesor','profesor',TRUE),
                (2,1,'Martin','Alumno','alumno',TRUE),
                (3,1,'Pedro','Alumno','alumno',TRUE),
                (4,2,'Alumno','Ajeno','alumno',TRUE),
                (5,1,'Profesor','Ajeno','profesor',TRUE),
                (6,1,'Alumno','Inactivo','alumno',FALSE),
                (7,1,'Profesor','Inactivo','profesor',FALSE),
                (8,2,'Profesor','Otro colegio','profesor',TRUE),
                (9,1,'Profesor','Asignado','profesor',TRUE);

            INSERT INTO aulas
                (id, colegio_id, nombre, activo)
            VALUES
                (10,1,'Matematicas',TRUE),
                (11,1,'Lenguaje',TRUE),
                (12,2,'Ciencias',TRUE),
                (13,1,'Aula inactiva',FALSE);

            INSERT INTO aula_alumnos VALUES
                (10,2),
                (10,6),
                (11,3),
                (12,4),
                (13,2);

            INSERT INTO aula_profesores VALUES
                (10,1),
                (10,7),
                (10,9),
                (11,5),
                (12,8),
                (13,1);
        ");

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->schema)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->pdo->exec(
                'SET search_path TO public'
            );

            $this->pdo->exec(
                'DROP SCHEMA IF EXISTS '
                . $this->schema
                . ' CASCADE'
            );
        }
    }

    private function inicializar(int $aula = 10): array
    {
        inicializarLibroNotas(
            $this->pdo,
            $aula,
            1
        );

        return obtenerColumnasNotas(
            $this->pdo,
            $aula
        );
    }

    private function columnaManual(int $aula = 10): int
    {
        crearColumnaNota(
            $this->pdo,
            $aula,
            1
        );

        $columnas = obtenerColumnasNotas(
            $this->pdo,
            $aula
        );

        return (int) $columnas[
            count($columnas) - 1
        ]['id'];
    }

    private function crearEvaluacion(
        int $numero,
        int $aula = 10,
        string $estado = 'publicada'
    ): string {
        $id = sprintf(
            '00000000-0000-4000-8000-%012d',
            $numero
        );

        $publicacion = match ($estado) {
            'publicada' => "CURRENT_TIMESTAMP - INTERVAL '1 day'",
            'futura' => "CURRENT_TIMESTAMP + INTERVAL '1 day'",
            default => 'NULL'
        };

        $stmt = $this->pdo->prepare(
            "INSERT INTO evaluaciones (
                id, aula_id, titulo, publicada_en
            ) VALUES (?, ?, ?, $publicacion)"
        );

        $stmt->execute([
            $id,
            $aula,
            'Evaluacion ' . $numero
        ]);

        return $id;
    }

    private function crearEntrega(
        string $evaluacionId,
        int $estudiante = 2,
        string $nota = '4.5',
        string $estado = 'calificada'
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO entregas (
                id,
                evaluacion_id,
                estudiante_id,
                fecha_inicio,
                fecha_vencimiento,
                fecha_entrega,
                puntaje_obtenido,
                puntaje_maximo,
                calificacion,
                estado
            ) VALUES (
                ?, ?, ?,
                CURRENT_TIMESTAMP - INTERVAL '1 hour',
                CURRENT_TIMESTAMP,
                ?, ?, ?, ?, ?
            )"
        );

        $stmt->execute([
            $evaluacionId,
            $evaluacionId,
            $estudiante,
            $estado === 'en_progreso'
                ? null
                : $this->pdo->query(
                    "SELECT CURRENT_TIMESTAMP - INTERVAL '30 minutes'"
                )->fetchColumn(),
            $estado === 'calificada' ? 5 : null,
            $estado === 'calificada' ? 10 : null,
            $estado === 'calificada' ? $nota : null,
            $estado
        ]);
    }

    private function columnaAutomatica(
        string $evaluacionId
    ): int {
        crearColumnaNota(
            $this->pdo,
            10,
            1,
            $evaluacionId
        );

        $stmt = $this->pdo->prepare(
            'SELECT id FROM libro_notas
             WHERE evaluacion_id = ?'
        );

        $stmt->execute([$evaluacionId]);

        return (int) $stmt->fetchColumn();
    }

    public function testLibroInicialTieneTresColumnas(): void
    {
        self::assertSame(
            [],
            obtenerColumnasNotas($this->pdo, 10)
        );

        $columnas = $this->inicializar();

        self::assertCount(3, $columnas);

        self::assertSame(
            ['Nota 1', 'Nota 2', 'Nota 3'],
            array_column($columnas, 'titulo')
        );

        self::assertSame(
            ['manual', 'manual', 'manual'],
            array_column($columnas, 'tipo')
        );

        self::assertSame(
            [1, 2, 3],
            array_map(
                'intval',
                array_column($columnas, 'numero')
            )
        );
    }

    public function testInicializacionEsIdempotente(): void
    {
        $primera = $this->inicializar();
        $segunda = $this->inicializar();

        self::assertSame($primera, $segunda);
        self::assertCount(3, $segunda);
    }

    public function testAgregarColumnasManuales(): void
    {
        $this->inicializar();

        $id = $this->columnaManual();

        $columnas = obtenerColumnasNotas(
            $this->pdo,
            10
        );

        self::assertCount(4, $columnas);
        self::assertSame(
            $id,
            (int) $columnas[3]['id']
        );

        self::assertSame(
            'Nota 4',
            $columnas[3]['titulo']
        );

        self::assertNull(
            $columnas[3]['evaluacion_id']
        );
    }

    public function testColumnasSeparadasPorAula(): void
    {
        $this->inicializar(10);
        $this->inicializar(11);

        $this->columnaManual(10);

        self::assertCount(
            4,
            obtenerColumnasNotas($this->pdo, 10)
        );

        self::assertCount(
            3,
            obtenerColumnasNotas($this->pdo, 11)
        );
    }

    public function testSincronizarSoloEvaluacionesPublicadas(): void
    {
        $publicada = $this->crearEvaluacion(1);

        $this->crearEvaluacion(2, 10, 'borrador');
        $this->crearEvaluacion(3, 10, 'futura');
        $this->crearEvaluacion(4, 11);

        sincronizarNotasAutomaticas(
            $this->pdo,
            10,
            1
        );

        $columnas = obtenerColumnasNotas(
            $this->pdo,
            10
        );

        self::assertCount(1, $columnas);

        self::assertSame(
            'automatica',
            $columnas[0]['tipo']
        );

        self::assertSame(
            $publicada,
            $columnas[0]['evaluacion_id']
        );

        self::assertSame(
            'Evaluacion 1',
            $columnas[0]['evaluacion_titulo']
        );
    }

    public function testSincronizacionNoDuplicaEvaluaciones(): void
    {
        $id = $this->crearEvaluacion(1);

        sincronizarNotasAutomaticas(
            $this->pdo, 10, 1
        );

        sincronizarNotasAutomaticas(
            $this->pdo, 10, 1
        );

        crearColumnaNota(
            $this->pdo, 10, 1, $id
        );

        self::assertCount(
            1,
            obtenerColumnasNotas($this->pdo, 10)
        );
    }

    public function testInicializarConEvaluacionesPublicadas(): void
    {
        $this->crearEvaluacion(1);
        $this->crearEvaluacion(2);

        $columnas = $this->inicializar();

        self::assertCount(3, $columnas);

        self::assertSame(
            ['automatica', 'automatica', 'manual'],
            array_column($columnas, 'tipo')
        );
    }

    public function testRechazarEvaluacionDeOtraAula(): void
    {
        $id = $this->crearEvaluacion(1, 11);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Evaluación no disponible.'
        );

        crearColumnaNota(
            $this->pdo, 10, 1, $id
        );
    }

    public function testRechazarEvaluacionNoPublicada(): void
    {
        $id = $this->crearEvaluacion(
            1, 10, 'borrador'
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        crearColumnaNota(
            $this->pdo, 10, 1, $id
        );
    }

    public function testGuardarNotaManual(): void
    {
        $id = $this->columnaManual();

        guardarNotaManual(
            $this->pdo,
            10, 1, 1, $id, 2, '6,5'
        );

        $notas = obtenerCalificacionesLibro(
            $this->pdo,
            10,
            1
        );

        self::assertSame(
            6.5,
            $notas[2][$id]
        );

        self::assertCount(1, $notas);
    }

    public function testActualizarNotaManualSinDuplicar(): void
    {
        $id = $this->columnaManual();

        guardarNotaManual(
            $this->pdo,
            10, 1, 1, $id, 2, '4.0'
        );

        guardarNotaManual(
            $this->pdo,
            10, 1, 9, $id, 2, '6.0'
        );

        $fila = $this->pdo->query(
            'SELECT * FROM notas_manuales'
        )->fetch(PDO::FETCH_ASSOC);

        self::assertSame(
            6.0,
            (float) $fila['calificacion']
        );

        self::assertSame(
            1,
            (int) $fila['registrado_por']
        );

        self::assertSame(
            9,
            (int) $fila['actualizado_por']
        );

        self::assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM notas_manuales'
            )->fetchColumn()
        );
    }

    public static function permisosInvalidos(): array
    {
        return [
            'profesor no asignado' => [10,1,5,2],
            'profesor inactivo' => [10,1,7,2],
            'profesor otro colegio' => [10,1,8,2],
            'alumno no matriculado' => [10,1,1,3],
            'alumno otro colegio' => [10,1,1,4],
            'alumno inactivo' => [10,1,1,6],
            'colegio incorrecto' => [10,2,1,2],
            'aula incorrecta' => [11,1,1,2],
            'profesor inexistente' => [10,1,999,2],
            'alumno inexistente' => [10,1,1,999]
        ];
    }

    #[DataProvider('permisosInvalidos')]
    public function testDenegarNotaManual(
        int $aula,
        int $colegio,
        int $profesor,
        int $alumno
    ): void {
        $id = $this->columnaManual();

        try {
            guardarNotaManual(
                $this->pdo,
                $aula,
                $colegio,
                $profesor,
                $id,
                $alumno,
                '7.0'
            );

            self::fail(
                'La operacion debio ser rechazada.'
            );
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'No tienes permiso para modificar esta nota.',
                $e->getMessage()
            );
        }

        self::assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM notas_manuales'
            )->fetchColumn()
        );
    }

    #[DataProvider('permisosInvalidos')]
    public function testDenegarCalificacionLibro(
        int $aula,
        int $colegio,
        int $profesor,
        int $alumno
    ): void {
        $id = $this->columnaManual();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'No tienes permiso para modificar esta nota.'
        );

        guardarCalificacionLibro(
            $this->pdo,
            $aula,
            $colegio,
            $profesor,
            $id,
            $alumno,
            '7.0'
        );
    }

    public function testDenegarAulaInactiva(): void
    {
        $id = $this->columnaManual(13);

        $this->expectException(
            InvalidArgumentException::class
        );

        guardarCalificacionLibro(
            $this->pdo,
            13, 1, 1, $id, 2, '6.0'
        );
    }

    public function testGuardarCalificacionManualDesdeLibro(): void
    {
        $id = $this->columnaManual();

        guardarCalificacionLibro(
            $this->pdo,
            10, 1, 1, $id, 2, '5,5'
        );

        $notas = obtenerCalificacionesLibro(
            $this->pdo, 10, 1
        );

        self::assertSame(
            5.5,
            $notas[2][$id]
        );
    }

    public function testRechazarCalificacionInvalida(): void
    {
        $id = $this->columnaManual();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'La nota debe estar entre 1,0 y 7,0.'
        );

        guardarCalificacionLibro(
            $this->pdo,
            10, 1, 1, $id, 2, '9.0'
        );
    }

    public function testObtenerNotaAutomaticaOriginal(): void
    {
        $evaluacion = $this->crearEvaluacion(1);

        $this->crearEntrega($evaluacion);

        $id = $this->columnaAutomatica(
            $evaluacion
        );

        $notas = obtenerCalificacionesLibro(
            $this->pdo, 10, 1
        );

        self::assertSame(
            4.5,
            $notas[2][$id]
        );
    }

    public function testAjustarNotaAutomatica(): void
    {
        $evaluacion = $this->crearEvaluacion(1);

        $this->crearEntrega($evaluacion);

        $id = $this->columnaAutomatica(
            $evaluacion
        );

        guardarCalificacionLibro(
            $this->pdo,
            10, 1, 1, $id, 2, '6.0'
        );

        guardarCalificacionLibro(
            $this->pdo,
            10, 1, 9, $id, 2, '6.5'
        );

        $notas = obtenerCalificacionesLibro(
            $this->pdo, 10, 1
        );

        self::assertSame(
            6.5,
            $notas[2][$id]
        );

        self::assertSame(
            4.5,
            (float) $this->pdo->query(
                'SELECT calificacion FROM entregas'
            )->fetchColumn()
        );

        $ajuste = $this->pdo->query(
            'SELECT * FROM ajustes_notas'
        )->fetch(PDO::FETCH_ASSOC);

        self::assertSame(
            1,
            (int) $ajuste['registrado_por']
        );

        self::assertSame(
            9,
            (int) $ajuste['actualizado_por']
        );

        self::assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ajustes_notas'
            )->fetchColumn()
        );
    }

    public function testNoAjustarEvaluacionSinEntrega(): void
    {
        $evaluacion = $this->crearEvaluacion(1);

        $id = $this->columnaAutomatica(
            $evaluacion
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'El estudiante todavía no tiene una evaluación calificada.'
        );

        guardarCalificacionLibro(
            $this->pdo,
            10, 1, 1, $id, 2, '7.0'
        );
    }

    public function testNoAjustarEvaluacionPendiente(): void
    {
        $evaluacion = $this->crearEvaluacion(1);

        $this->crearEntrega(
            $evaluacion,
            2,
            '4.5',
            'en_progreso'
        );

        $id = $this->columnaAutomatica(
            $evaluacion
        );

        self::assertSame(
            [],
            obtenerCalificacionesLibro(
                $this->pdo, 10, 1
            )
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        guardarCalificacionLibro(
            $this->pdo,
            10, 1, 1, $id, 2, '7.0'
        );
    }

    public function testCombinarNotasManualesYAutomaticas(): void
    {
        $manual = $this->columnaManual();

        guardarNotaManual(
            $this->pdo,
            10, 1, 1, $manual, 2, '7.0'
        );

        $evaluacion = $this->crearEvaluacion(1);

        $this->crearEntrega(
            $evaluacion, 2, '5.0'
        );

        $automatica = $this->columnaAutomatica(
            $evaluacion
        );

        $notas = obtenerCalificacionesLibro(
            $this->pdo, 10, 1
        );

        self::assertSame(
            7.0,
            $notas[2][$manual]
        );

        self::assertSame(
            5.0,
            $notas[2][$automatica]
        );

        self::assertSame(
            6.0,
            calcularPromedioNotas($notas[2])
        );
    }

    public function testOcultarNotasDeAlumnoInactivo(): void
    {
        $id = $this->columnaManual();

        guardarNotaManual(
            $this->pdo,
            10, 1, 1, $id, 2, '5.0'
        );

        $this->pdo->exec(
            'UPDATE usuarios SET activo = FALSE
             WHERE id = 2'
        );

        self::assertSame(
            [],
            obtenerCalificacionesLibro(
                $this->pdo, 10, 1
            )
        );
    }

    public function testOcultarNotasDeOtroColegio(): void
    {
        $id = $this->columnaManual();

        guardarNotaManual(
            $this->pdo,
            10, 1, 1, $id, 2, '5.0'
        );

        self::assertSame(
            [],
            obtenerCalificacionesLibro(
                $this->pdo, 10, 2
            )
        );
    }

    public function testEliminarColumnaManualVacia(): void
    {
        $this->inicializar();

        $id = $this->columnaManual();

        eliminarColumnaNota(
            $this->pdo, 10, $id
        );

        self::assertCount(
            3,
            obtenerColumnasNotas($this->pdo, 10)
        );
    }

    public function testMantenerMinimoTresColumnas(): void
    {
        $columnas = $this->inicializar();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'El libro debe mantener un mínimo de 3 notas.'
        );

        eliminarColumnaNota(
            $this->pdo,
            10,
            (int) $columnas[0]['id']
        );
    }

    public function testNoEliminarColumnaConCalificaciones(): void
    {
        $this->inicializar();

        $id = $this->columnaManual();

        guardarNotaManual(
            $this->pdo,
            10, 1, 1, $id, 2, '5.0'
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Solo puedes eliminar notas manuales sin calificaciones registradas.'
        );

        eliminarColumnaNota(
            $this->pdo, 10, $id
        );
    }

    public function testNoEliminarColumnaAutomatica(): void
    {
        $this->inicializar();

        $evaluacion = $this->crearEvaluacion(1);

        $id = $this->columnaAutomatica(
            $evaluacion
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        eliminarColumnaNota(
            $this->pdo, 10, $id
        );
    }

    public function testNoEliminarColumnaDeOtraAula(): void
    {
        $this->inicializar();

        $this->columnaManual();

        $idAjeno = $this->columnaManual(11);

        $this->expectException(
            InvalidArgumentException::class
        );

        eliminarColumnaNota(
            $this->pdo, 10, $idAjeno
        );
    }

    public function testRenumerarColumnasVisualmente(): void
    {
        $columnas = $this->inicializar();

        $this->columnaManual();

        eliminarColumnaNota(
            $this->pdo,
            10,
            (int) $columnas[1]['id']
        );

        $resultado = obtenerColumnasNotas(
            $this->pdo, 10
        );

        self::assertSame(
            ['Nota 1', 'Nota 2', 'Nota 3'],
            array_column($resultado, 'titulo')
        );

        self::assertSame(
            [1, 3, 4],
            array_map(
                'intval',
                array_column($resultado, 'numero')
            )
        );
    }

    public function testBaseDatosRechazaNotaFueraDeRango(): void
    {
        $id = $this->columnaManual();

        $this->expectException(PDOException::class);

        $stmt = $this->pdo->prepare(
            'INSERT INTO notas_manuales (
                libro_nota_id,
                aula_id,
                estudiante_id,
                calificacion,
                registrado_por,
                actualizado_por
            ) VALUES (?, 10, 2, 8.0, 1, 1)'
        );

        $stmt->execute([$id]);
    }

    public function testBaseDatosRechazaNotaSinMatricula(): void
    {
        $id = $this->columnaManual();

        $this->expectException(PDOException::class);

        $stmt = $this->pdo->prepare(
            'INSERT INTO notas_manuales (
                libro_nota_id,
                aula_id,
                estudiante_id,
                calificacion,
                registrado_por,
                actualizado_por
            ) VALUES (?, 10, 3, 5.0, 1, 1)'
        );

        $stmt->execute([$id]);
    }

    public function testBaseDatosRechazaAjusteEnColumnaManual(): void
    {
        $id = $this->columnaManual();

        $this->expectException(PDOException::class);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ajustes_notas (
                libro_nota_id,
                aula_id,
                estudiante_id,
                calificacion,
                registrado_por,
                actualizado_por
            ) VALUES (?, 10, 2, 5.0, 1, 1)'
        );

        $stmt->execute([$id]);
    }
}

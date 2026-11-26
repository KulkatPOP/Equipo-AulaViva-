<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/apoderados.php';

final class ApoderadosConsultasTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite(
            'sqlite::memory:',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->pdo->createFunction(
            'BTRIM',
            static fn($valor) => $valor === null
                ? null
                : trim($valor),
            1
        );

        $this->pdo->exec("
            CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER,
                nombre TEXT,
                apellido TEXT,
                rol TEXT,
                activo BOOLEAN
            );

            CREATE TABLE aulas (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER,
                nombre TEXT,
                asignatura_nombre TEXT,
                activo BOOLEAN
            );

            CREATE TABLE aula_alumnos (
                aula_id INTEGER,
                alumno_id INTEGER
            );

            CREATE TABLE evaluaciones (
                id INTEGER PRIMARY KEY,
                aula_id INTEGER,
                titulo TEXT,
                publicada_en TEXT
            );

            CREATE TABLE entregas (
                id INTEGER PRIMARY KEY,
                evaluacion_id INTEGER,
                estudiante_id INTEGER,
                estado TEXT,
                calificacion NUMERIC,
                fecha_entrega TEXT
            );

            CREATE TABLE libro_notas (
                id INTEGER PRIMARY KEY,
                aula_id INTEGER,
                numero INTEGER,
                titulo TEXT,
                tipo TEXT,
                evaluacion_id INTEGER,
                creado_por INTEGER
            );

            CREATE TABLE notas_manuales (
                id INTEGER PRIMARY KEY,
                libro_nota_id INTEGER,
                aula_id INTEGER,
                estudiante_id INTEGER,
                calificacion NUMERIC
            );

            CREATE TABLE ajustes_notas (
                id INTEGER PRIMARY KEY,
                libro_nota_id INTEGER,
                aula_id INTEGER,
                estudiante_id INTEGER,
                calificacion NUMERIC
            );

            CREATE TABLE apoderado_estudiantes (
                colegio_id INTEGER,
                apoderado_id INTEGER,
                estudiante_id INTEGER,
                estado TEXT,
                confirmada_en TEXT,
                confirmada_por INTEGER,
                PRIMARY KEY (
                    colegio_id,
                    apoderado_id,
                    estudiante_id
                )
            );

            INSERT INTO usuarios VALUES
                (1,1,'Admin','Uno','admin',1),
                (2,1,'Apoderado','Uno','apoderado',1),
                (3,1,'Alumno','Uno','alumno',1),
                (4,2,'Alumno','Ajeno','alumno',1),
                (5,1,'Alumno','Dos','alumno',1),
                (6,1,'Profesor','Uno','profesor',1);

            INSERT INTO aulas VALUES
                (10,1,'Matematicas','Matematicas',1),
                (11,1,'Sin configurar',NULL,1),
                (12,2,'Ajena','Ciencias',1);

            INSERT INTO aula_alumnos VALUES
                (10,3),(11,3),(12,4),(10,5);
        ");
    }

    public function testConfirmacionSeleccionYDenegacion(): void
    {
        self::assertSame(
            [],
            pupilosAutorizados($this->pdo, 2, 1)
        );

        self::assertFalse(
            confirmarVinculoApoderado($this->pdo, 1, 1, 2, 2)
        );

        self::assertFalse(
            confirmarVinculoApoderado($this->pdo, 1, 1, 2, 4)
        );

        self::assertFalse(
            confirmarVinculoApoderado($this->pdo, 2, 1, 2, 3)
        );

        self::assertTrue(
            confirmarVinculoApoderado($this->pdo, 1, 1, 2, 3)
        );

        self::assertFalse(
            confirmarVinculoApoderado($this->pdo, 1, 1, 2, 3)
        );

        self::assertCount(
            1,
            pupilosAutorizados($this->pdo, 2, 1)
        );

        self::assertSame(
            [],
            rendimientoApoderado($this->pdo, 2, 1, 4)
        );

        self::assertSame(
            [],
            rendimientoApoderado($this->pdo, 2, 2, 3)
        );

        self::assertSame(
            [],
            rendimientoApoderado($this->pdo, 2, 1, 5)
        );

        $this->pdo->exec("
            UPDATE apoderado_estudiantes
            SET estado = 'pendiente',
                confirmada_en = NULL,
                confirmada_por = NULL
        ");

        self::assertSame(
            [],
            pupilosAutorizados($this->pdo, 2, 1)
        );

        self::assertSame(
            [],
            rendimientoApoderado($this->pdo, 2, 1, 3)
        );

        self::assertTrue(
            confirmarVinculoApoderado($this->pdo, 1, 1, 2, 3)
        );
    }

    public function testPromedioNoLimitadoACinco(): void
    {
        confirmarVinculoApoderado($this->pdo, 1, 1, 2, 3);

        foreach ([1, 5, 6, 6, 6, 6] as $i => $nota) {
            $id = $i + 1;

            consultaApoderados(
                $this->pdo,
                "INSERT INTO evaluaciones
                 VALUES (?,10,?,'2026-01-01')",
                [$id, 'Prueba ' . $id]
            );

            consultaApoderados(
                $this->pdo,
                "INSERT INTO entregas
                 VALUES (?,?,3,'calificada',?,'2026-01-01')",
                [$id, $id, $nota]
            );

            consultaApoderados(
                $this->pdo,
                "INSERT INTO libro_notas
                 VALUES (?,10,?,?,'automatica',?,6)",
                [$id, $id, 'Prueba ' . $id, $id]
            );
        }

        $this->pdo->exec("
            INSERT INTO evaluaciones VALUES
                (7,10,'Borrador',NULL),
                (8,10,'Pendiente','2026-01-01');

            INSERT INTO entregas VALUES
                (7,7,3,'calificada',7,'2026-01-01'),
                (8,8,3,'entregada',NULL,'2026-01-01');

            INSERT INTO libro_notas VALUES
                (7,10,7,'Borrador','automatica',7,6),
                (8,10,8,'Pendiente','automatica',8,6);
        ");

        $aulas = rendimientoApoderado(
            $this->pdo, 2, 1, 3
        );

        self::assertEquals(5.0, $aulas[10]['promedio']);

        self::assertCount(8, $aulas[10]['notas']);

        self::assertSame(
            [1.0, 5.0, 6.0, 6.0, 6.0, 6.0, null, null],
            array_column($aulas[10]['notas'], 'calificacion')
        );

        self::assertSame(
            [
                'Prueba 1',
                'Prueba 2',
                'Prueba 3',
                'Prueba 4',
                'Prueba 5',
                'Prueba 6',
                'Borrador',
                'Pendiente'
            ],
            array_column($aulas[10]['notas'], 'titulo')
        );

        self::assertNull($aulas[11]['promedio']);
        self::assertSame([], $aulas[11]['notas']);

        self::assertSame(
            'Sin configurar',
            $aulas[11]['asignatura']
        );
    }

    public function testEstadoActualDeCuentaYMatricula(): void
    {
        confirmarVinculoApoderado($this->pdo, 1, 1, 2, 3);

        $this->pdo->exec(
            'DELETE FROM aula_alumnos WHERE alumno_id=3'
        );

        self::assertSame(
            [],
            rendimientoApoderado($this->pdo, 2, 1, 3)
        );

        $this->pdo->exec(
            'UPDATE usuarios SET activo=0 WHERE id=3'
        );

        self::assertSame(
            [],
            pupilosAutorizados($this->pdo, 2, 1)
        );

        $this->pdo->exec("
            UPDATE usuarios
            SET activo=1, rol='profesor'
            WHERE id=3
        ");

        self::assertSame(
            [],
            pupilosAutorizados($this->pdo, 2, 1)
        );
    }

    public function testNotasManualesYAjustesAutomaticos(): void
    {
        confirmarVinculoApoderado($this->pdo, 1, 1, 2, 3);

        $this->pdo->exec("
            INSERT INTO evaluaciones VALUES
                (1,10,'Evaluacion','2026-01-01');

            INSERT INTO entregas VALUES
                (1,1,3,'calificada',4.0,'2026-01-01');

            INSERT INTO libro_notas VALUES
                (1,10,1,'Nota manual','manual',NULL,6),
                (2,10,2,'Evaluacion','automatica',1,6),
                (3,10,3,'Sin nota','manual',NULL,6);

            INSERT INTO notas_manuales VALUES
                (1,1,10,3,7.0),
                (2,1,10,5,1.0),
                (3,3,10,5,2.0);

            INSERT INTO ajustes_notas VALUES
                (1,2,10,3,6.0);
        ");

        $aulas = rendimientoApoderado(
            $this->pdo, 2, 1, 3
        );

        self::assertSame(
            [7.0, 6.0, null],
            array_column($aulas[10]['notas'], 'calificacion')
        );

        self::assertEquals(
            6.5,
            $aulas[10]['promedio']
        );

        self::assertSame(
            [],
            rendimientoApoderado($this->pdo, 2, 1, 5)
        );

        self::assertEquals(
            4.0,
            $this->pdo->query(
                'SELECT calificacion FROM entregas WHERE id=1'
            )->fetchColumn()
        );
    }
}
<?php
declare(strict_types=1);

function consultaApoderados(PDO $pdo, string $sql, array $params): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function idApoderados(mixed $valor): ?int
{
    if (!is_string($valor) && !is_int($valor)) { return null; }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    return $id === false ? null : $id;
}

function pupilosAutorizados(PDO $pdo, int $apoderado, int $colegio): array
{
    return consultaApoderados($pdo, "
        SELECT u.id, u.nombre, u.apellido FROM apoderado_estudiantes v
        JOIN usuarios a ON a.id = v.apoderado_id AND a.colegio_id = v.colegio_id
        JOIN usuarios u ON u.id = v.estudiante_id AND u.colegio_id = v.colegio_id
        WHERE v.apoderado_id = ? AND v.colegio_id = ? AND v.estado = 'confirmada'
          AND a.activo = TRUE AND a.rol = 'apoderado'
          AND u.activo = TRUE AND u.rol = 'alumno' AND u.id <> a.id
        ORDER BY u.apellido, u.nombre, u.id
    ", [$apoderado, $colegio])->fetchAll(PDO::FETCH_ASSOC);
}

function rendimientoApoderado(
    PDO $pdo,
    int $apoderado,
    int $colegio,
    int $estudiante
): array {
    $filas = consultaApoderados($pdo, "
        WITH autorizadas AS (
            SELECT
                a.id,
                a.nombre,
                a.asignatura_nombre,
                u.id AS estudiante_id
            FROM aulas a
            JOIN aula_alumnos m
                ON m.aula_id = a.id
            JOIN usuarios u
                ON u.id = m.alumno_id
                AND u.colegio_id = a.colegio_id
            JOIN apoderado_estudiantes v
                ON v.estudiante_id = u.id
                AND v.colegio_id = u.colegio_id
            JOIN usuarios p
                ON p.id = v.apoderado_id
                AND p.colegio_id = v.colegio_id
            WHERE u.id = ?
              AND v.apoderado_id = ?
              AND v.colegio_id = ?
              AND v.estado = 'confirmada'
              AND u.activo = TRUE
              AND u.rol = 'alumno'
              AND p.activo = TRUE
              AND p.rol = 'apoderado'
              AND u.id <> p.id
              AND a.activo = TRUE
        )
        SELECT
            a.id AS aula_id,
            a.nombre,
            a.asignatura_nombre,
            ln.id AS libro_nota_id,
            ln.numero,
            ln.titulo,
            ln.tipo,
            CASE
                WHEN ln.tipo = 'manual'
                    THEN nm.calificacion
                WHEN ln.tipo = 'automatica'
                    AND ev.publicada_en IS NOT NULL
                    AND e.estado = 'calificada'
                    THEN COALESCE(
                        an.calificacion,
                        e.calificacion
                    )
                ELSE NULL
            END AS calificacion
        FROM autorizadas a
        LEFT JOIN libro_notas ln
            ON ln.aula_id = a.id
        LEFT JOIN notas_manuales nm
            ON nm.libro_nota_id = ln.id
            AND nm.aula_id = a.id
            AND nm.estudiante_id = a.estudiante_id
        LEFT JOIN evaluaciones ev
            ON ev.id = ln.evaluacion_id
            AND ev.aula_id = a.id
        LEFT JOIN entregas e
            ON e.evaluacion_id = ev.id
            AND e.estudiante_id = a.estudiante_id
        LEFT JOIN ajustes_notas an
            ON an.libro_nota_id = ln.id
            AND an.aula_id = a.id
            AND an.estudiante_id = a.estudiante_id
        ORDER BY
            COALESCE(
                NULLIF(BTRIM(a.asignatura_nombre), ''),
                a.nombre
            ),
            a.id,
            ln.numero,
            ln.id
    ", [
        $estudiante,
        $apoderado,
        $colegio
    ])->fetchAll(PDO::FETCH_ASSOC);

    $aulas = [];

    foreach ($filas as $fila) {
        $id = (int) $fila['aula_id'];

        if (!isset($aulas[$id])) {
            $aulas[$id] = [
                'nombre' => $fila['nombre'],
                'asignatura' => trim(
                    $fila['asignatura_nombre'] ?? ''
                ) ?: $fila['nombre'],
                'notas' => [],
                'promedio' => null
            ];
        }

        if ($fila['libro_nota_id'] !== null) {
            $aulas[$id]['notas'][] = [
                'titulo' => $fila['titulo'],
                'calificacion' =>
                    $fila['calificacion'] !== null
                        ? (float) $fila['calificacion']
                        : null
            ];
        }
    }

    foreach ($aulas as &$aula) {
        $calificaciones = array_filter(
            array_column($aula['notas'], 'calificacion'),
            static fn($nota) => $nota !== null
        );

        if ($calificaciones) {
            $aula['promedio'] = round(
                array_sum($calificaciones)
                / count($calificaciones),
                1
            );
        }
    }

    unset($aula);

    return $aulas;
}

function confirmarVinculoApoderado(PDO $pdo, int $admin, int $colegio, int $apoderado, int $estudiante): bool
{
    if ($apoderado === $estudiante) { return false; }
    $stmt = consultaApoderados($pdo, "
        INSERT INTO apoderado_estudiantes (colegio_id, apoderado_id, estudiante_id, estado, confirmada_por, confirmada_en)
        SELECT d.colegio_id, p.id, u.id, 'confirmada', d.id, CURRENT_TIMESTAMP
        FROM usuarios d JOIN usuarios p ON p.colegio_id = d.colegio_id
        JOIN usuarios u ON u.colegio_id = d.colegio_id
        WHERE d.id = ? AND d.colegio_id = ? AND d.activo = TRUE AND d.rol = 'admin'
          AND p.id = ? AND p.activo = TRUE AND p.rol = 'apoderado'
          AND u.id = ? AND u.activo = TRUE AND u.rol = 'alumno' AND p.id <> u.id
        ON CONFLICT (colegio_id, apoderado_id, estudiante_id) DO UPDATE
        SET estado = EXCLUDED.estado, confirmada_por = EXCLUDED.confirmada_por, confirmada_en = EXCLUDED.confirmada_en
        WHERE apoderado_estudiantes.estado = 'pendiente'
        RETURNING estudiante_id
    ", [$admin, $colegio, $apoderado, $estudiante]);
    return $stmt->fetchColumn() !== false;
}

function escaparApoderados(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatearFechaApoderados(?string $valor): string
{
    if ($valor === null || trim($valor) === '') { return 'Sin fecha'; }
    try {
        // Conservar la zona/offset recibido de PostgreSQL; no convertir el instante.
        $fecha = new DateTimeImmutable($valor);
    } catch (Exception $e) {
        return 'Sin fecha';
    }
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $fecha->format('j') . ' de ' . $meses[(int) $fecha->format('n')]
        . ' de ' . $fecha->format('Y, H:i');
}

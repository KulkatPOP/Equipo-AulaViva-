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

function rendimientoApoderado(PDO $pdo, int $apoderado, int $colegio, int $estudiante): array
{
    // La autorización forma parte de la propia consulta académica.
    $filas = consultaApoderados($pdo, "
        WITH autorizadas AS (
            SELECT a.id, a.nombre, a.asignatura_nombre FROM aulas a
            JOIN aula_alumnos m ON m.aula_id = a.id AND m.alumno_id = ?
            JOIN usuarios u ON u.id = m.alumno_id AND u.colegio_id = a.colegio_id
            JOIN apoderado_estudiantes v ON v.estudiante_id = u.id AND v.colegio_id = u.colegio_id
            JOIN usuarios p ON p.id = v.apoderado_id AND p.colegio_id = v.colegio_id
            WHERE v.apoderado_id = ? AND v.colegio_id = ? AND v.estado = 'confirmada'
              AND u.activo = TRUE AND u.rol = 'alumno' AND p.activo = TRUE AND p.rol = 'apoderado'
              AND u.id <> p.id AND a.activo = TRUE
        ), notas AS (
            SELECT e.id, e.calificacion, e.fecha_entrega, ev.titulo, ev.aula_id,
                   round(avg(e.calificacion) OVER (PARTITION BY ev.aula_id), 1) AS promedio,
                   row_number() OVER (PARTITION BY ev.aula_id ORDER BY e.fecha_entrega DESC, e.id DESC) AS posicion
            FROM entregas e JOIN evaluaciones ev ON ev.id = e.evaluacion_id
            JOIN autorizadas a ON a.id = ev.aula_id
            WHERE e.estudiante_id = ? AND e.estado = 'calificada'
              AND e.calificacion IS NOT NULL AND ev.publicada_en IS NOT NULL
        )
        SELECT a.id AS aula_id, a.nombre, a.asignatura_nombre, n.id AS entrega_id,
               n.titulo, n.calificacion, n.fecha_entrega, n.promedio
        FROM autorizadas a LEFT JOIN notas n ON n.aula_id = a.id AND n.posicion <= 5
        ORDER BY a.nombre, a.id, n.fecha_entrega DESC, n.id DESC
    ", [$estudiante, $apoderado, $colegio, $estudiante])->fetchAll(PDO::FETCH_ASSOC);
    $aulas = [];
    foreach ($filas as $fila) {
        $id = $fila['aula_id'];
        if (!isset($aulas[$id])) {
            $aulas[$id] = ['nombre' => $fila['nombre'], 'asignatura' => $fila['asignatura_nombre'] ?? 'Asignatura sin configurar',
                'promedio' => $fila['promedio'], 'evaluaciones' => []];
        }
        if ($fila['entrega_id'] !== null) { $aulas[$id]['evaluaciones'][] = $fila; }
    }
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

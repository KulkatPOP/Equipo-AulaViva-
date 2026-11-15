<?php

function registrarEventoRendicion(PDO $pdo, array $entrega, string $tipo, array $datos = []): void
{
    $stmt = $pdo->prepare('INSERT INTO outbox_events (tipo, entrega_id, datos) VALUES (?, ?, CAST(? AS jsonb))');
    $stmt->execute([$tipo, $entrega['id'], json_encode([
        'evaluacion_id' => $entrega['evaluacion_id'],
        'estudiante_id' => $entrega['estudiante_id'],
    ] + $datos, JSON_THROW_ON_ERROR)]);
}

function rendicionPDO(PDO $pdo, string $sql, array $params): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** La llamada debe mantener bloqueada la entrega y estar dentro de una transacción. */
function cerrarRendicion(PDO $pdo, array $entrega): void
{
    if ($entrega['estado'] !== 'en_progreso') {
        return;
    }
    $id = $entrega['id'];
    $datos = rendicionPDO($pdo, "
        UPDATE entregas SET estado = 'entregada', fecha_entrega = LEAST(clock_timestamp(), fecha_vencimiento)
        WHERE id = ? RETURNING floor(extract(epoch FROM fecha_entrega - fecha_inicio))::integer AS tiempo
    ", [$id])->fetch();
    $contestadas = rendicionPDO($pdo, '
        SELECT count(DISTINCT a.pregunta_id) FROM respuestas r JOIN alternativas a ON a.id = r.alternativa_id
        WHERE r.entrega_id = ?
    ', [$id])->fetchColumn();
    registrarEventoRendicion($pdo, $entrega, 'evaluacion.entregada', [
        'cantidad_respuestas' => (int) $contestadas,
        'tiempo_empleado_segundos' => $datos['tiempo'],
    ]);

    $nota = rendicionPDO($pdo, "
        WITH puntos AS (
            SELECT sum(p.puntaje) AS maximo,
                   sum(CASE WHEN NOT EXISTS (
                       SELECT 1 FROM alternativas a
                       LEFT JOIN respuestas r ON r.alternativa_id = a.id AND r.entrega_id = ?
                       WHERE a.pregunta_id = p.id AND a.es_correcta <> (r.alternativa_id IS NOT NULL)
                   ) THEN p.puntaje ELSE 0 END) AS obtenido
            FROM preguntas p WHERE p.evaluacion_id = ?
        )
        UPDATE entregas e SET estado = 'calificada', puntaje_maximo = maximo, puntaje_obtenido = obtenido,
            calificacion = round(CASE WHEN obtenido * 100 < maximo * 60
                THEN 1 + 5 * obtenido / maximo
                ELSE 4 + (obtenido * 100 - maximo * 60) * 3 / (maximo * 40)
            END, 1)
        FROM puntos WHERE e.id = ?
        RETURNING puntaje_obtenido, puntaje_maximo, calificacion
    ", [$id, $entrega['evaluacion_id'], $id])->fetch();
    registrarEventoRendicion($pdo, $entrega, 'evaluacion.calificada', $nota);
}

function iniciarRendicion(PDO $pdo, string $evaluacion, int $alumno): void
{
    $entrega = rendicionPDO($pdo, "
        INSERT INTO entregas (evaluacion_id, estudiante_id, fecha_inicio, fecha_vencimiento)
        SELECT ?, ?, ahora, ahora + interval '60 minutes' FROM (SELECT clock_timestamp() AS ahora) reloj
        ON CONFLICT (evaluacion_id, estudiante_id) DO NOTHING RETURNING *
    ", [$evaluacion, $alumno])->fetch();
    if ($entrega) {
        registrarEventoRendicion($pdo, $entrega, 'evaluacion.iniciada', [
            'fecha_inicio' => $entrega['fecha_inicio'],
            'fecha_vencimiento' => $entrega['fecha_vencimiento'],
        ]);
    }
}

function guardarRespuestasRendicion(PDO $pdo, array $entrega, array $selecciones): bool
{
    $ids = json_encode($selecciones, JSON_THROW_ON_ERROR);
    $validas = rendicionPDO($pdo, '
        SELECT count(*) FROM alternativas a JOIN preguntas p ON p.id = a.pregunta_id
        WHERE p.evaluacion_id = ? AND a.id IN (
            SELECT CAST(value AS bigint) FROM jsonb_array_elements_text(CAST(? AS jsonb))
        )
    ', [$entrega['evaluacion_id'], $ids])->fetchColumn();
    if ((int) $validas !== count($selecciones)) {
        return false;
    }
    // Se acepta la revisión solo si el reloj del servidor todavía está dentro del plazo.
    $stmt = rendicionPDO($pdo, '
        UPDATE entregas SET revision_respuestas = revision_respuestas + 1
        WHERE id = ? AND clock_timestamp() < fecha_vencimiento
    ', [$entrega['id']]);
    if (!$stmt->rowCount()) {
        return false;
    }
    rendicionPDO($pdo, 'DELETE FROM respuestas WHERE entrega_id = ?', [$entrega['id']]);
    rendicionPDO($pdo, '
        INSERT INTO respuestas (entrega_id, alternativa_id)
        SELECT ?, CAST(value AS bigint) FROM jsonb_array_elements_text(CAST(? AS jsonb))
    ', [$entrega['id'], $ids]);
    return true;
}

function rendicionEstado(PDO $pdo, string $id): array
{
    $estado = rendicionPDO($pdo, "
        SELECT id, estado, revision_respuestas,
               greatest(0, ceil(extract(epoch FROM fecha_vencimiento - clock_timestamp()) * 1000))::bigint AS restante_ms
        FROM entregas WHERE id = ?
    ", [$id])->fetch();
    $estado['selecciones'] = rendicionPDO($pdo, '
        SELECT alternativa_id::text FROM respuestas WHERE entrega_id = ? ORDER BY alternativa_id
    ', [$id])->fetchAll(PDO::FETCH_COLUMN);
    return $estado;
}

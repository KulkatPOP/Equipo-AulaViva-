<?php

// Registrar eventos de la evaluación

function registrarEventoRendicion(
    PDO $pdo,
    array $entrega,
    string $tipo,
    array $datos = []
): void {
    $stmt = $pdo->prepare('
        INSERT INTO outbox_events (
            tipo,
            entrega_id,
            datos
        )
        VALUES (?, ?, CAST(? AS jsonb))
    ');

    $stmt->execute([
        $tipo,
        $entrega['id'],
        json_encode([
            'evaluacion_id' => $entrega['evaluacion_id'],
            'estudiante_id' => $entrega['estudiante_id'],
        ] + $datos, JSON_THROW_ON_ERROR)
    ]);
}

// Ejecutar consultas preparadas

function rendicionPDO(
    PDO $pdo,
    string $sql,
    array $params
): PDOStatement {
    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    return $stmt;
}

// Finalizar y calificar evaluación.
// Requiere una transacción y la entrega bloqueada.

function cerrarRendicion(
    PDO $pdo,
    array $entrega
): void {
    if ($entrega['estado'] !== 'en_progreso') {
        return;
    }

    $id = $entrega['id'];

    // Registrar entrega

    $datos = rendicionPDO($pdo, "
        UPDATE entregas
        SET estado = 'entregada',
            fecha_entrega = LEAST(
                clock_timestamp(),
                fecha_vencimiento
            )
        WHERE id = ?
        RETURNING floor(
            extract(
                epoch FROM fecha_entrega - fecha_inicio
            )
        )::integer AS tiempo
    ", [$id])->fetch();

    // Contar preguntas respondidas

    $contestadas = rendicionPDO($pdo, '
        SELECT COUNT(DISTINCT a.pregunta_id)
        FROM respuestas r
        JOIN alternativas a
            ON a.id = r.alternativa_id
        WHERE r.entrega_id = ?
    ', [$id])->fetchColumn();

    registrarEventoRendicion(
        $pdo,
        $entrega,
        'evaluacion.entregada',
        [
            'cantidad_respuestas' => (int) $contestadas,
            'tiempo_empleado_segundos' => $datos['tiempo'],
        ]
    );

    // Calcular puntaje y calificación

    $nota = rendicionPDO($pdo, "
        WITH puntos AS (
            SELECT
                SUM(p.puntaje) AS maximo,

                SUM(
                    CASE
                        WHEN NOT EXISTS (
                            SELECT 1
                            FROM alternativas a

                            LEFT JOIN respuestas r
                                ON r.alternativa_id = a.id
                                AND r.entrega_id = ?

                            WHERE a.pregunta_id = p.id

                                AND a.es_correcta <>
                                    (r.alternativa_id IS NOT NULL)
                        )
                        THEN p.puntaje
                        ELSE 0
                    END
                ) AS obtenido

            FROM preguntas p
            WHERE p.evaluacion_id = ?
        )

        UPDATE entregas e

        SET estado = 'calificada',
            puntaje_maximo = maximo,
            puntaje_obtenido = obtenido,

            calificacion = (
                CASE
                    WHEN obtenido * 100 < maximo * 60

                    THEN div(
                        21 * maximo + 100 * obtenido,
                        2 * maximo
                    )

                    ELSE div(
                        150 * obtenido - 9 * maximo,
                        2 * maximo
                    )
                END
            ) * 0.1

        FROM puntos

        WHERE e.id = ?

        RETURNING
            puntaje_obtenido,
            puntaje_maximo,
            calificacion

    ", [
        $id,
        $entrega['evaluacion_id'],
        $id
    ])->fetch();

    // Registrar calificación

    registrarEventoRendicion(
        $pdo,
        $entrega,
        'evaluacion.calificada',
        $nota
    );
}

// Iniciar evaluación

function iniciarRendicion(
    PDO $pdo,
    string $evaluacion,
    int $alumno
): void {
    $entrega = rendicionPDO($pdo, "
        WITH reloj AS MATERIALIZED (
            SELECT clock_timestamp() AS ahora
        )

        INSERT INTO entregas (
            evaluacion_id,
            estudiante_id,
            fecha_inicio,
            fecha_vencimiento
        )

        SELECT
            e.id,
            ?,
            reloj.ahora,

            LEAST(
                reloj.ahora + INTERVAL '60 minutes',

                COALESCE(
                    e.disponible_hasta,
                    reloj.ahora + INTERVAL '60 minutes'
                )
            )

        FROM evaluaciones e

        CROSS JOIN reloj

        WHERE e.id = ?

            AND e.publicada_en IS NOT NULL

            AND (
                e.disponible_desde IS NULL
                OR reloj.ahora >= e.disponible_desde
            )

            AND (
                e.disponible_hasta IS NULL
                OR reloj.ahora < e.disponible_hasta
            )

        ON CONFLICT (evaluacion_id, estudiante_id)
        DO NOTHING

        RETURNING *

    ", [
        $alumno,
        $evaluacion
    ])->fetch();

    // Registrar inicio

    if ($entrega) {

        registrarEventoRendicion(
            $pdo,
            $entrega,
            'evaluacion.iniciada',
            [
                'fecha_inicio' => $entrega['fecha_inicio'],

                'fecha_vencimiento' =>
                    $entrega['fecha_vencimiento'],
            ]
        );
    }
}

// Guardar respuestas del estudiante.
// Requiere una transacción y la entrega bloqueada.

function guardarRespuestasRendicion(
    PDO $pdo,
    array $entrega,
    array $selecciones
): bool {
    $ids = json_encode(
        $selecciones,
        JSON_THROW_ON_ERROR
    );

    // Validar pertenencia de las alternativas

    $validas = rendicionPDO($pdo, '
        SELECT COUNT(*)

        FROM alternativas a

        JOIN preguntas p
            ON p.id = a.pregunta_id

        WHERE p.evaluacion_id = ?

            AND a.id IN (
                SELECT CAST(value AS bigint)

                FROM jsonb_array_elements_text(
                    CAST(? AS jsonb)
                )
            )

    ', [
        $entrega['evaluacion_id'],
        $ids
    ])->fetchColumn();

    if ((int) $validas !== count($selecciones)) {
        return false;
    }

    // Validar preguntas de selección única

    $invalidas = rendicionPDO($pdo, "
        SELECT COUNT(*)

        FROM (
            SELECT p.id

            FROM preguntas p

            JOIN alternativas a
                ON a.pregunta_id = p.id

            WHERE p.evaluacion_id = ?

                AND p.tipo_respuesta = 'unica'

                AND a.id IN (
                    SELECT CAST(value AS bigint)

                    FROM jsonb_array_elements_text(
                        CAST(? AS jsonb)
                    )
                )

            GROUP BY p.id

            HAVING COUNT(*) > 1

        ) AS preguntas_invalidas

    ", [
        $entrega['evaluacion_id'],
        $ids
    ])->fetchColumn();

    if ((int) $invalidas > 0) {
        return false;
    }

    // Comprobar vigencia del intento

    $stmt = rendicionPDO($pdo, "
        UPDATE entregas

        SET revision_respuestas =
            revision_respuestas + 1

        WHERE id = ?

            AND estado = 'en_progreso'

            AND clock_timestamp() < fecha_vencimiento

    ", [
        $entrega['id']
    ]);

    if (!$stmt->rowCount()) {
        return false;
    }

    // Eliminar selección anterior

    rendicionPDO($pdo, '
        DELETE FROM respuestas

        WHERE entrega_id = ?

    ', [
        $entrega['id']
    ]);

    // Guardar selección actual

    rendicionPDO($pdo, '
        INSERT INTO respuestas (
            entrega_id,
            alternativa_id
        )

        SELECT
            ?,
            CAST(value AS bigint)

        FROM jsonb_array_elements_text(
            CAST(? AS jsonb)
        )

    ', [
        $entrega['id'],
        $ids
    ]);

    return true;
}

// Consultar estado de la evaluación

function rendicionEstado(
    PDO $pdo,
    string $id
): array {
    $estado = rendicionPDO($pdo, "
        SELECT
            id,
            estado,
            revision_respuestas,

            greatest(
                0,

                ceil(
                    extract(
                        epoch FROM
                        fecha_vencimiento - clock_timestamp()
                    ) * 1000
                )

            )::bigint AS restante_ms

        FROM entregas

        WHERE id = ?

    ", [
        $id
    ])->fetch();

    // Recuperar respuestas guardadas

    $estado['selecciones'] = rendicionPDO($pdo, '
        SELECT alternativa_id::text

        FROM respuestas

        WHERE entrega_id = ?

        ORDER BY alternativa_id

    ', [
        $id
    ])->fetchAll(PDO::FETCH_COLUMN);

    return $estado;
}
BEGIN;

LOCK TABLE evaluaciones, preguntas IN SHARE ROW EXCLUSIVE MODE;
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM preguntas p JOIN evaluaciones e ON e.id = p.evaluacion_id
        WHERE e.publicada_en IS NOT NULL AND p.puntaje < 0
    ) THEN
        RAISE EXCEPTION 'Existen evaluaciones publicadas con puntajes negativos';
    END IF;
END
$$;

ALTER TABLE preguntas ADD CONSTRAINT preguntas_puntaje_no_negativo
    CHECK (puntaje >= 0 AND puntaje::text NOT IN ('NaN', 'Infinity', '-Infinity')) NOT VALID;

CREATE TABLE entregas (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    evaluacion_id uuid NOT NULL REFERENCES evaluaciones(id),
    estudiante_id integer NOT NULL REFERENCES usuarios(id),
    fecha_inicio timestamptz NOT NULL,
    fecha_vencimiento timestamptz NOT NULL CHECK (fecha_vencimiento = fecha_inicio + interval '60 minutes'),
    fecha_entrega timestamptz,
    puntaje_obtenido numeric,
    puntaje_maximo numeric,
    calificacion numeric(2,1),
    estado text NOT NULL DEFAULT 'en_progreso',
    revision_respuestas integer NOT NULL DEFAULT 0 CHECK (revision_respuestas >= 0),
    UNIQUE (evaluacion_id, estudiante_id),
    CHECK (fecha_entrega BETWEEN fecha_inicio AND fecha_vencimiento),
    CHECK (
        (estado = 'en_progreso' AND fecha_entrega IS NULL AND puntaje_obtenido IS NULL AND puntaje_maximo IS NULL AND calificacion IS NULL)
        OR (estado = 'entregada' AND fecha_entrega IS NOT NULL AND puntaje_obtenido IS NULL AND puntaje_maximo IS NULL AND calificacion IS NULL)
        OR (estado = 'calificada' AND fecha_entrega IS NOT NULL AND puntaje_maximo IS NOT NULL AND puntaje_maximo > 0
            AND puntaje_obtenido IS NOT NULL AND puntaje_obtenido BETWEEN 0 AND puntaje_maximo
            AND calificacion IS NOT NULL AND calificacion BETWEEN 1 AND 7)
    )
);
CREATE INDEX entregas_vencimiento_idx ON entregas(fecha_vencimiento, id) WHERE estado = 'en_progreso';
CREATE INDEX entregas_estudiante_idx ON entregas(estudiante_id, id) WHERE estado = 'calificada';

CREATE TABLE respuestas (
    entrega_id uuid NOT NULL REFERENCES entregas(id),
    alternativa_id bigint NOT NULL REFERENCES alternativas(id),
    PRIMARY KEY (entrega_id, alternativa_id)
);
CREATE INDEX respuestas_alternativa_idx ON respuestas(alternativa_id);

CREATE TABLE solicitudes_ayuda (
    id uuid PRIMARY KEY,
    entrega_id uuid NOT NULL REFERENCES entregas(id),
    tipo text NOT NULL DEFAULT 'problema_conexion' CHECK (tipo = 'problema_conexion'),
    estado text NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'resuelta')),
    creada_en timestamptz NOT NULL DEFAULT clock_timestamp(),
    mensaje text NOT NULL DEFAULT '' CHECK (char_length(mensaje) <= 500),
    resuelta_en timestamptz,
    CHECK ((estado = 'resuelta') = (resuelta_en IS NOT NULL))
);
CREATE INDEX solicitudes_ayuda_entrega_idx ON solicitudes_ayuda(entrega_id, creada_en);

CREATE TABLE outbox_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tipo text NOT NULL,
    entrega_id uuid NOT NULL REFERENCES entregas(id),
    ocurrido_en timestamptz NOT NULL DEFAULT clock_timestamp(),
    datos jsonb NOT NULL,
    publicado_en timestamptz,
    UNIQUE (entrega_id, tipo)
);
CREATE INDEX outbox_pendiente_idx ON outbox_events(ocurrido_en, id) WHERE publicado_en IS NULL;

COMMIT;

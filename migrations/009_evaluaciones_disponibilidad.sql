BEGIN;

-- Disponibilidad de las evaluaciones

ALTER TABLE evaluaciones
    ADD COLUMN disponible_desde TIMESTAMPTZ,
    ADD COLUMN disponible_hasta TIMESTAMPTZ;

ALTER TABLE evaluaciones
    ADD CONSTRAINT evaluaciones_disponibilidad_check
    CHECK (
        (
            disponible_desde IS NULL
            AND disponible_hasta IS NULL
        )
        OR
        (
            disponible_desde IS NOT NULL
            AND disponible_hasta IS NOT NULL
            AND disponible_hasta > disponible_desde
        )
    );

-- Duración máxima de la rendición

ALTER TABLE entregas
    DROP CONSTRAINT entregas_check;

ALTER TABLE entregas
    ADD CONSTRAINT entregas_duracion_check
    CHECK (
        fecha_vencimiento > fecha_inicio
        AND fecha_vencimiento <= fecha_inicio + INTERVAL '60 minutes'
    );

COMMIT;
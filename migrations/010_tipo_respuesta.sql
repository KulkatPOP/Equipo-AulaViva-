BEGIN;

-- Agregar tipo de respuesta.

ALTER TABLE preguntas
ADD COLUMN tipo_respuesta TEXT NOT NULL DEFAULT 'unica';

-- Clasificar las preguntas existentes.

UPDATE preguntas p
SET tipo_respuesta = 'multiple'
WHERE (
    SELECT COUNT(*)
    FROM alternativas a
    WHERE a.pregunta_id = p.id
      AND a.es_correcta = TRUE
) > 1;

-- Restringir los tipos permitidos.

ALTER TABLE preguntas
ADD CONSTRAINT preguntas_tipo_respuesta_check
CHECK (tipo_respuesta IN ('unica', 'multiple'));

COMMIT;
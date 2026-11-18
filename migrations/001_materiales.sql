BEGIN;

CREATE TABLE IF NOT EXISTS materiales (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    aula_id integer NOT NULL REFERENCES aulas (id),
    titulo text NOT NULL CHECK (char_length(btrim(titulo)) BETWEEN 1 AND 120),
    nombre_archivo text NOT NULL CHECK (char_length(nombre_archivo) BETWEEN 1 AND 180),
    contenido bytea NOT NULL CHECK (octet_length(contenido) BETWEEN 1 AND 10485760),
    creado_en timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS materiales_aula_id_idx ON materiales (aula_id, id DESC);

COMMIT;

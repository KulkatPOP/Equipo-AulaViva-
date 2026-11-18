BEGIN;

CREATE TABLE evaluaciones (
    id uuid PRIMARY KEY,
    aula_id integer NOT NULL REFERENCES aulas(id),
    titulo text NOT NULL,
    publicada_en timestamptz
);
CREATE INDEX evaluaciones_aula_publicacion_idx ON evaluaciones(aula_id, publicada_en, id);

CREATE TABLE preguntas (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    evaluacion_id uuid NOT NULL REFERENCES evaluaciones(id) ON DELETE CASCADE,
    enunciado text NOT NULL,
    orden integer NOT NULL CHECK (orden > 0),
    puntaje numeric NOT NULL,
    UNIQUE (evaluacion_id, orden)
);

CREATE TABLE alternativas (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pregunta_id bigint NOT NULL REFERENCES preguntas(id) ON DELETE CASCADE,
    texto text NOT NULL,
    orden integer NOT NULL CHECK (orden > 0),
    es_correcta boolean NOT NULL,
    UNIQUE (pregunta_id, orden)
);

COMMIT;

BEGIN;

CREATE TABLE material_ingestas (
    material_id bigint PRIMARY KEY REFERENCES materiales(id) ON DELETE CASCADE,
    estado text NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'procesando', 'procesado', 'sin_texto', 'error')),
    error text,
    intentos integer NOT NULL DEFAULT 0 CHECK (intentos >= 0),
    creado_en timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((estado = 'error') = (error IS NOT NULL))
);

CREATE TABLE material_fragmentos (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    material_id bigint NOT NULL REFERENCES materiales(id) ON DELETE CASCADE,
    orden integer NOT NULL CHECK (orden > 0),
    pagina integer CHECK (pagina > 0),
    texto text NOT NULL CHECK (texto ~ '[^[:space:]]'),
    embedding vector(768),
    estado text NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'procesando', 'procesado', 'sin_texto', 'error')),
    creado_en timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (material_id, orden),
    CHECK ((estado = 'procesado') = (embedding IS NOT NULL))
);

COMMIT;

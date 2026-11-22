BEGIN;

ALTER TABLE aulas ADD COLUMN asignatura_nombre text
    CHECK (asignatura_nombre IS NULL OR btrim(asignatura_nombre) <> '');

-- La base inicial no tiene una migración versionada. Fallar ante un CHECK
-- desconocido evita eliminar restricciones ajenas al dominio de roles.
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname, pg_get_constraintdef(oid) AS definition
             FROM pg_constraint WHERE conrelid = 'usuarios'::regclass AND contype = 'c'
               AND conkey @> ARRAY[(SELECT attnum FROM pg_attribute
                   WHERE attrelid = 'usuarios'::regclass AND attname = 'rol')]::smallint[]
    LOOP
        IF regexp_replace(regexp_replace(c.definition,
            '''(alumno|profesor|admin|apoderado)''::text', '', 'g'), '\s', '', 'g')
            NOT IN ('CHECK((rol=ANY(ARRAY[,,])))', 'CHECK((rol=ANY(ARRAY[,,,])))') THEN
            RAISE EXCEPTION 'Revisar CHECK de rol no reconocido: %', c.conname;
        END IF;
        EXECUTE format('ALTER TABLE usuarios DROP CONSTRAINT %I', c.conname);
    END LOOP;
END $$;
ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_us05_check
    CHECK (rol IN ('alumno', 'profesor', 'admin', 'apoderado'));
ALTER TABLE usuarios ADD CONSTRAINT usuarios_id_colegio_unique UNIQUE (id, colegio_id);

CREATE TABLE apoderado_estudiantes (
    colegio_id integer NOT NULL,
    apoderado_id integer NOT NULL,
    estudiante_id integer NOT NULL,
    estado text NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'confirmada')),
    confirmada_en timestamptz,
    confirmada_por integer,
    PRIMARY KEY (colegio_id, apoderado_id, estudiante_id),
    FOREIGN KEY (apoderado_id, colegio_id) REFERENCES usuarios(id, colegio_id),
    FOREIGN KEY (estudiante_id, colegio_id) REFERENCES usuarios(id, colegio_id),
    FOREIGN KEY (confirmada_por, colegio_id) REFERENCES usuarios(id, colegio_id),
    CHECK (apoderado_id <> estudiante_id),
    CHECK ((estado = 'pendiente' AND confirmada_en IS NULL AND confirmada_por IS NULL)
        OR (estado = 'confirmada' AND confirmada_en IS NOT NULL AND confirmada_por IS NOT NULL))
);
CREATE INDEX apoderado_estudiantes_estudiante_idx
    ON apoderado_estudiantes(colegio_id, estudiante_id);

CREATE FUNCTION validar_vinculo_apoderado() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM usuarios WHERE id = NEW.apoderado_id AND colegio_id = NEW.colegio_id
        AND rol = 'apoderado' AND activo FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Apoderado no disponible'; END IF;
    PERFORM 1 FROM usuarios WHERE id = NEW.estudiante_id AND colegio_id = NEW.colegio_id
        AND rol = 'alumno' AND activo FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Estudiante no disponible'; END IF;
    IF NEW.estado = 'confirmada' THEN
        PERFORM 1 FROM usuarios WHERE id = NEW.confirmada_por AND colegio_id = NEW.colegio_id
            AND rol = 'admin' AND activo FOR SHARE;
        IF NOT FOUND THEN RAISE EXCEPTION 'Confirmación no autorizada'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER apoderado_estudiantes_validar BEFORE INSERT OR UPDATE
    ON apoderado_estudiantes FOR EACH ROW EXECUTE FUNCTION validar_vinculo_apoderado();
COMMIT;

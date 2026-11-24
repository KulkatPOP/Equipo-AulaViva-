-- ONLY for a new disposable database named bootstrap_test.
-- psql -X -v ON_ERROR_STOP=1 -f test/bootstrap-postgres.sql
\set ON_ERROR_STOP on
DO $$ BEGIN
    IF current_database() <> 'bootstrap_test'
       OR EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public') THEN
        RAISE EXCEPTION 'Requires an empty disposable bootstrap_test database';
    END IF;
END $$;

\ir ../migrations/000_bootstrap.sql
\ir ../migrations/001_materiales.sql
\ir ../migrations/002_evaluaciones.sql
\ir ../migrations/003_rendicion.sql
\ir ../migrations/004_tutor_rag.sql
\ir ../migrations/005_apoderados.sql
\ir ../migrations/006_invitaciones.sql

DO $$
DECLARE actual text[];
BEGIN
    SELECT array_agg(tablename::text ORDER BY tablename) INTO actual
    FROM pg_tables WHERE schemaname = 'public';
    IF actual <> ARRAY['alternativas','apoderado_estudiantes','aula_alumnos',
        'aula_profesores','aulas','entregas','evaluaciones','invitaciones',
        'material_fragmentos','material_ingestas','materiales','outbox_events',
        'preguntas','respuestas','solicitudes_ayuda','usuarios'] THEN
        RAISE EXCEPTION 'Unexpected tables: %', actual;
    END IF;
    IF (SELECT count(*) FROM pg_constraint WHERE connamespace = 'public'::regnamespace
        AND contype = 'p') <> 16 THEN RAISE EXCEPTION 'Missing primary keys'; END IF;
    IF (SELECT count(*) FROM pg_constraint WHERE connamespace = 'public'::regnamespace
        AND contype = 'f') <> 19 THEN RAISE EXCEPTION 'Unexpected foreign keys'; END IF;
    IF (SELECT count(*) FROM pg_constraint WHERE connamespace = 'public'::regnamespace
        AND contype = 'u') <> 10 THEN RAISE EXCEPTION 'Unexpected unique constraints'; END IF;
    IF (SELECT count(*) FROM pg_constraint WHERE connamespace = 'public'::regnamespace
        AND contype = 'c') <> 34 THEN RAISE EXCEPTION 'Unexpected check constraints'; END IF;
    IF (SELECT count(*) FROM pg_indexes WHERE schemaname = 'public') <> 35 THEN
        RAISE EXCEPTION 'Unexpected indexes';
    END IF;
    IF NOT EXISTS (SELECT FROM pg_extension WHERE extname = 'vector') THEN
        RAISE EXCEPTION 'Missing vector extension';
    END IF;
    IF (SELECT format_type(atttypid, atttypmod) FROM pg_attribute
        WHERE attrelid = 'material_fragmentos'::regclass AND attname = 'embedding') <> 'vector(768)' THEN
        RAISE EXCEPTION 'Wrong embedding type';
    END IF;
    IF NOT EXISTS (SELECT FROM pg_trigger WHERE tgrelid = 'apoderado_estudiantes'::regclass
        AND tgname = 'apoderado_estudiantes_validar' AND NOT tgisinternal
        AND tgfoid = 'validar_vinculo_apoderado()'::regprocedure) THEN
        RAISE EXCEPTION 'Missing guardian validation trigger';
    END IF;
END $$;

BEGIN;
DO $$
DECLARE docente integer; alumno integer; administrador integer; apoderado integer;
    aula integer; material bigint; vector_prueba vector(768); distancia double precision;
BEGIN
    INSERT INTO usuarios(colegio_id,rut,username,nombre,apellido,password_hash,rol)
    VALUES (1,'bootstrap-profesor','bootstrap-profesor','Prueba','Profesor','not-a-login-hash','profesor') RETURNING id INTO docente;
    INSERT INTO usuarios(colegio_id,rut,username,nombre,apellido,password_hash,rol)
    VALUES (1,'bootstrap-alumno','bootstrap-alumno','Prueba','Alumno','not-a-login-hash','alumno') RETURNING id INTO alumno;
    INSERT INTO usuarios(colegio_id,rut,username,nombre,apellido,password_hash,rol)
    VALUES (1,'bootstrap-admin','bootstrap-admin','Prueba','Admin','not-a-login-hash','admin') RETURNING id INTO administrador;
    INSERT INTO usuarios(colegio_id,rut,username,nombre,apellido,password_hash,rol)
    VALUES (1,'bootstrap-apoderado','bootstrap-apoderado','Prueba','Apoderado','not-a-login-hash','apoderado') RETURNING id INTO apoderado;
    INSERT INTO aulas(colegio_id,nombre) VALUES(1,'Bootstrap disposable') RETURNING id INTO aula;
    INSERT INTO aula_profesores VALUES(aula,docente);
    INSERT INTO aula_alumnos VALUES(aula,alumno);
    BEGIN
        INSERT INTO aula_alumnos VALUES(aula,2147483647);
        RAISE EXCEPTION 'Invalid student FK accepted';
    EXCEPTION WHEN foreign_key_violation THEN NULL; END;
    BEGIN
        INSERT INTO aula_profesores VALUES(2147483647,docente);
        RAISE EXCEPTION 'Invalid classroom FK accepted';
    EXCEPTION WHEN foreign_key_violation THEN NULL; END;
    BEGIN
        INSERT INTO aula_alumnos VALUES(aula,alumno);
        RAISE EXCEPTION 'Duplicate enrollment accepted';
    EXCEPTION WHEN unique_violation THEN NULL; END;
    INSERT INTO apoderado_estudiantes VALUES(1,apoderado,alumno,'confirmada',CURRENT_TIMESTAMP,administrador);
    BEGIN
        INSERT INTO apoderado_estudiantes VALUES(1,docente,alumno,'pendiente',NULL,NULL);
        RAISE EXCEPTION USING ERRCODE = 'ZX001', MESSAGE = 'Invalid guardian role accepted';
    EXCEPTION WHEN raise_exception THEN NULL; END;
    INSERT INTO materiales(aula_id,titulo,nombre_archivo,contenido)
    VALUES(aula,'Fixture','fixture.pdf',convert_to('%PDF-1.4 bootstrap fixture %%EOF','UTF8')) RETURNING id INTO material;
    INSERT INTO material_ingestas(material_id,estado) VALUES(material,'procesado');
    vector_prueba := ('[1,' || repeat('0,',766) || '0]')::vector(768);
    INSERT INTO material_fragmentos(material_id,orden,pagina,texto,embedding,estado)
    VALUES(material,1,1,'Disposable evidence',vector_prueba,'procesado');
    SELECT embedding <=> vector_prueba INTO distancia FROM material_fragmentos
    WHERE material_id = material ORDER BY embedding <=> vector_prueba LIMIT 1;
    IF distancia IS NULL OR abs(distancia) > 0.000001 THEN
        RAISE EXCEPTION 'Cosine retrieval failed: %', distancia;
    END IF;
    BEGIN
        INSERT INTO material_fragmentos(material_id,orden,pagina,texto,embedding,estado)
        VALUES(material,2,1,'Wrong dimensions','[1,0]'::vector,'procesado');
        RAISE EXCEPTION 'Wrong vector dimensions accepted';
    EXCEPTION WHEN data_exception THEN NULL; END;
END $$;
ROLLBACK;

SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename;
SELECT contype, count(*) FROM pg_constraint WHERE connamespace = 'public'::regnamespace GROUP BY contype ORDER BY contype;
SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY indexname;
SELECT extname, extversion FROM pg_extension ORDER BY extname;
SELECT 'bootstrap chain and functional checks passed' AS result;

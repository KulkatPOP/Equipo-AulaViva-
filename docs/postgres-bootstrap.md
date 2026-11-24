# Instalación inicial PostgreSQL

`migrations/000_bootstrap.sql` prepara una base **vacía** antes de 001–006.
No crea base, usuarios, credenciales ni datos. El servidor debe tener pgvector
instalado; la cuenta operadora debe poder habilitar `vector` y crear los objetos.

El bootstrap formaliza las cuatro tablas base utilizadas por PHP. Conserva el
CHECK inicial de roles profesor/alumno/admin que 005 sustituye para incorporar
apoderado. No adelanta `asignatura_nombre`, vínculos de apoderados ni objetos RAG.
La integridad de colegio/rol en aula_profesores/aula_alumnos sigue comprobándose
en la aplicación: sus FK no constituyen por sí solas aislamiento entre colegios.

## Ejecución futura autorizada

Verificar primero destino y esquema. Configurar la conexión de `psql` fuera del
repositorio; no incluir contraseñas en comandos ni archivos versionados.

```sh
psql -X -v ON_ERROR_STOP=1 -c 'SELECT current_database(), current_user'
psql -X -v ON_ERROR_STOP=1 -f migrations/000_bootstrap.sql
psql -X -v ON_ERROR_STOP=1 -f migrations/001_materiales.sql
psql -X -v ON_ERROR_STOP=1 -f migrations/002_evaluaciones.sql
psql -X -v ON_ERROR_STOP=1 -f migrations/003_rendicion.sql
psql -X -v ON_ERROR_STOP=1 -f migrations/004_tutor_rag.sql
psql -X -v ON_ERROR_STOP=1 -f migrations/005_apoderados.sql
psql -X -v ON_ERROR_STOP=1 -f migrations/006_invitaciones.sql
```

Cada archivo tiene su propia transacción. Ante un fallo, detenerse y revisar;
los archivos anteriores ya confirmados no se revierten automáticamente.
Registrar versiones/checksums externamente: no existe runner/historial automático.
No ejecutar el bootstrap sobre DEV ni sobre un esquema preexistente.

## Prueba desechable

`test/bootstrap-postgres.sql` exige una base vacía llamada `bootstrap_test`, incluye
000–006 mediante rutas relativas al script, valida catálogo y prueba operaciones
con fixtures SQL ficticios dentro de una transacción que revierte los datos.
Las secuencias pueden avanzar aunque los fixtures se reviertan; usar solo una
base desechable. No es una prueba de login, ingestión PDF ni generación Ollama.

```sh
psql -X -v ON_ERROR_STOP=1 -d bootstrap_test -f test/bootstrap-postgres.sql
```

Resultado validado con PostgreSQL 16 y pgvector 0.8.7: 16 tablas, 16 PK,
19 FK, 10 restricciones UNIQUE, 34 CHECK y 35 índices. Existe la función/trigger
de 005 y `material_fragmentos.embedding` es `vector(768)`; búsqueda coseno `<=>`.
No se crea índice vectorial aproximado ni un catálogo de colegios.

## Repetición

No es una cadena idempotente. 000 falla al encontrar `usuarios`; 001 vuelve a
ejecutarse con avisos de objetos existentes; 002 falla en `evaluaciones`; 003
en `preguntas_puntaje_no_negativo`; 004 en `material_ingestas`; 005 en la columna
`asignatura_nombre`; 006 en `invitaciones`. No usar la repetición como mecanismo
de actualización ni cambiar los scripts históricos para ocultar esos errores.

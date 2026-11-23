# ADR 0004 - Modelo de datos y eventos

## Estado

Aceptado.

Documento reconciliado con la implementación de `main` en `2ef05a8`.
Distingue la persistencia implementada, la publicación opcional de eventos
y las posibles evoluciones del sistema.

## Contexto

AulaViva necesita almacenar usuarios, aulas, materiales, evaluaciones,
rendiciones, vínculos de apoderados e invitaciones. Las operaciones críticas
requieren transacciones para evitar cambios parciales.

El Tutor IA necesita conservar el texto extraído de los materiales y sus
embeddings para recuperar información autorizada por aula y colegio.

Durante la rendición se registran eventos de inicio, entrega y calificación.
Estos eventos deben quedar guardados aunque Redis no esté disponible.
Existe la posibilidad de publicarlos posteriormente mediante Redis Streams.

Este ADR no presupone servicios gestionados de AWS ni otros proveedores cloud.

## Decisiones

### 1. Persistencia

Utilizar PostgreSQL como persistencia principal de la aplicación, mediante
PDO PostgreSQL.

Los materiales PDF se almacenan en `materiales.contenido` como `bytea`.
El acceso al archivo pasa por la autorización del servidor.

Reutilizar PostgreSQL con pgvector para los embeddings del Tutor IA:

`material_fragmentos.embedding vector(768)`

`VectorTutor` valida la dimensión y los componentes de los vectores.
La implementación utiliza Ollama para generar embeddings con
`nomic-embed-text`.

Mantener datos y vectores en PostgreSQL evita incorporar una base vectorial
adicional al MVP. La recuperación híbrida combina distancia coseno y búsqueda
textual dentro de las consultas autorizadas de la aplicación.

Las migraciones 001–006 describen las ampliaciones del esquema. Las tablas
académicas base son preexistentes; su esquema mínimo también está documentado
en `docs/tutor-demo.md`.

### 2. Eventos y Outbox

El Outbox implementado está limitado al flujo de rendición:

- `evaluacion.iniciada`
- `evaluacion.entregada`
- `evaluacion.calificada`

`config/rendicion.php` registra estos eventos en `outbox_events`.
La tabla tiene una restricción de unicidad sobre `(entrega_id, tipo)`.

La creación de la entrega y su evento de inicio se realizan dentro de la
transacción de rendición. El cierre, la autocorrección y sus eventos también
comparten la transacción correspondiente.

Si la operación se revierte, sus eventos no quedan confirmados de manera
independiente. Esto no convierte al Outbox en un registro general de todos
los cambios de AulaViva.

La autocorrección se ejecuta directamente en `cerrarRendicion()`. No depende
de que un consumidor reciba `evaluacion.entregada`.

### 3. Publicación mediante Redis Streams

`cli/rendicion.php` contiene un relay que consulta eventos pendientes,
los publica mediante `xAdd` y después actualiza `publicado_en`.

Redis es una integración opcional. La publicación requiere:

- extensión PHP Redis disponible;
- `REDIS_HOST`;
- `REDIS_PORT`, si no se utiliza el valor predeterminado;
- `REDIS_PASSWORD`, si el servidor exige autenticación;
- `REDIS_STREAM`, si se desea cambiar el nombre del stream.

El stream predeterminado es `aulaviva:dominio`.
El Dockerfile actual no instala la extensión PHP Redis.

El CLI puede ejecutarse una vez o con `--continuo`. Su existencia no demuestra
que haya un proceso permanente desplegado o programado.

Sin publicación, los eventos permanecen pendientes en PostgreSQL.
La disponibilidad de las calificaciones no depende de Redis.

La publicación no garantiza exactly-once: si Redis acepta un mensaje y
posteriormente falla el marcado o el commit en PostgreSQL, el evento puede
publicarse nuevamente.

Actualmente no existen consumidores de eventos de dominio en el código.
Los futuros consumidores de Streams deberán deduplicar por `event_id`.

### 4. Decisiones que no forman parte de la implementación actual

Actualmente no están implementados:

- CQRS con proyecciones persistidas;
- consumidores de eventos de dominio;
- BullMQ;
- notificaciones derivadas de estos eventos;
- consumidores de seguimiento o de autocorrección.

El panel de apoderados consulta directamente las tablas académicas mediante
`config/apoderados.php`. Calcula promedios y obtiene las últimas evaluaciones
mediante SQL autorizado; no depende de una proyección actualizada por eventos.

Amazon RDS y Amazon ElastiCache no se documentan como infraestructura existente.

## Consecuencias

### Positivas

- PostgreSQL permite mantener consistencia transaccional entre la rendición
  y sus registros Outbox.
- Los eventos pueden publicarse después de la operación académica.
- Redis puede permanecer sin configurar sin impedir la autocorrección.
- PostgreSQL y pgvector permiten reutilizar una misma persistencia para
  datos académicos y recuperación del Tutor IA.

### Negativas y límites

- El relay añade configuración y operación de un proceso CLI.
- La publicación puede repetirse; no existe exactly-once.
- La unicidad del Outbox no evita duplicados en Redis tras un fallo de marcado.
- No existen consumidores actuales que procesen los eventos publicados.
- El Outbox cubre rendición, no todos los procesos de la aplicación.
- No se demuestra un despliegue permanente del relay por disponer de su código.

## Evolución futura

Si aparecen necesidades concretas, se podrá evaluar:

- consumidores idempotentes con deduplicación por `event_id`;
- supervisión de eventos pendientes, reintentos y fallos del relay;
- trazabilidad con identificadores de petición independientes;
- proyecciones CQRS si las consultas directas dejan de ser suficientes.

Estas posibilidades no constituyen capacidades implementadas ni requisitos
de despliegue actuales.

## Evidencia

- `config/conexion.php`
- `migrations/001_materiales.sql`
- `migrations/003_rendicion.sql`
- `migrations/004_tutor_rag.sql`
- `config/rendicion.php`
- `cli/rendicion.php`
- `config/tutor/Embeddings.php`
- `config/tutor/TutorRetriever.php`
- `config/apoderados.php`
- `docs/rendicion-operacion.md`
- `Dockerfile`

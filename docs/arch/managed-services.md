# Servicios gestionados AulaViva

## Base de datos

Servicio: Amazon RDS for PostgreSQL

Proveedor: Amazon Web Services (AWS)

Justificación:

Se utilizará Amazon RDS para PostgreSQL como servicio gestionado de base de
datos para almacenar la información de usuarios, colegios, cursos,
evaluaciones, matrículas y demás información académica de AulaViva.

Además, se contempla el uso de pgvector para almacenar embeddings utilizados
por el Tutor IA basado en RAG.


## Almacenamiento

Servicio: Amazon S3

Proveedor: Amazon Web Services (AWS)

Justificación:

Se utilizará Amazon S3 para almacenar documentos, guías, archivos y otros
materiales educativos utilizados por los cursos y por el Tutor IA.

Su uso permite disponer de almacenamiento escalable y administrado,
evitando depender del almacenamiento local del servidor.


## IA

Servicio: API de OpenAI

Proveedor: OpenAI

Justificación:

El Tutor IA de AulaViva utilizará un proveedor externo de modelos LLM
mediante API.

La integración permitirá generar respuestas utilizando el contexto
recuperado desde los contenidos educativos almacenados por la plataforma.

OpenAI se considera un servicio externo a AWS y no forma parte de los
servicios gestionados directamente por el proveedor Cloud seleccionado.


## Otros servicios

Servicio: Amazon ECS + AWS Fargate

Proveedor: Amazon Web Services (AWS)

Justificación:

Se utilizará Amazon ECS con AWS Fargate para ejecutar el backend
monolítico modular de AulaViva mediante contenedores, sin necesidad de
administrar directamente los servidores.

Servicio: Amazon ElastiCache for Redis

Proveedor: Amazon Web Services (AWS)

Justificación:

Se utilizará Redis mediante Amazon ElastiCache para soportar el
procesamiento de tareas asíncronas mediante BullMQ y las necesidades de
caché de la aplicación.

Servicio: Amazon CloudWatch

Proveedor: Amazon Web Services (AWS)

Justificación:

Se utilizará Amazon CloudWatch para centralizar logs, métricas y
monitoreo de la aplicación, facilitando el diagnóstico y seguimiento
operacional de AulaViva.

# Managed Services y Dependencias Operativas — AulaViva

## Alcance

AulaViva actualmente **no está desplegada sobre AWS como arquitectura productiva demostrada**.

El despliegue validado corresponde a Kubernetes en Docker Desktop, gestionado mediante un repositorio GitOps y Argo CD. PostgreSQL y Ollama se encuentran fuera del Pod de aplicación.

Este documento distingue dependencias actuales de servicios gestionados considerados como evolución futura. La presencia de una tecnología en una propuesta histórica no demuestra su implementación.

## Dependencias actuales

### PostgreSQL

- **Función:** persistencia de usuarios, aulas, matrículas, materiales, evaluaciones, entregas, vínculos de apoderados, invitaciones y Outbox.
- **Dependencia:** obligatoria para los flujos integrados que utilizan estas tablas.
- **Configuración:** `DB_DSN`, `DB_USER` y `DB_PASSWORD`. En DEV proceden del Secret `aulaviva-db`.
- **Límites actuales:** la base DEV es externa al Pod. El repositorio GitOps no despliega un servicio PostgreSQL ni demuestra una base gestionada, alta disponibilidad o recuperación automática.

Los PDFs se almacenan actualmente en PostgreSQL como `bytea`. No se utilizan S3 ni URLs prefirmadas para servirlos; su acceso pasa por autorización en PHP.

### pgvector

- **Función:** almacenar embeddings y realizar búsqueda vectorial para el Tutor.
- **Dependencia:** obligatoria para la persistencia y recuperación RAG de US04.
- **Configuración:** extensión `vector` en PostgreSQL y esquema de `migrations/004_tutor_rag.sql`, con embeddings `vector(768)`.
- **Límites actuales:** su disponibilidad depende de la instalación y configuración del servidor PostgreSQL. No es un servicio separado del motor.

### Ollama

- **Función:** generar embeddings mediante `nomic-embed-text` y respuestas mediante `qwen3:1.7b`.
- **Dependencia:** obligatoria para la ingesta que genera embeddings y para la generación de respuestas del Tutor. No es necesaria para todos los módulos de AulaViva.
- **Configuración:** `OLLAMA_BASE_URL`, `OLLAMA_EMBEDDING_MODEL`, `OLLAMA_CHAT_MODEL` y `TUTOR_AI_TIMEOUT_SECONDS`.
- **Límites actuales:** servicio externo al Pod, con modelos aprovisionados separadamente. La disponibilidad y latencia dependen del equipo que lo ejecuta.

DEV utiliza `http://host.docker.internal:11434` con autorización explícita mediante `OLLAMA_ALLOW_DOCKER_HOST=true`. Esta opción permite ese host, esquema y puerto concretos; no autoriza URLs arbitrarias ni comprueba `APP_ENV`.

Ollama sustituye el supuesto histórico de utilizar OpenAI. La implementación actual del Tutor no requiere OpenAI ni claves de una API de pago.

### Redis Streams — opcional

- **Función:** destino de publicación de los eventos almacenados en el Outbox de rendición.
- **Dependencia:** opcional; la persistencia del Outbox y la autocorrección no requieren publicar en Redis.
- **Configuración:** el CLI consulta `REDIS_HOST`; utiliza además `REDIS_PORT`, `REDIS_PASSWORD` y `REDIS_STREAM` cuando corresponden.
- **Límites actuales:** requiere la extensión PHP Redis, que la imagen actual no instala. No existen consumidores implementados o desplegados demostrados.

El Outbox contiene únicamente los eventos de rendición:

- `evaluacion.iniciada`.
- `evaluacion.entregada`.
- `evaluacion.calificada`.

El relay utiliza `cli/rendicion.php`. Una publicación puede repetirse si Redis acepta el evento y falla posteriormente la confirmación en PostgreSQL. Los futuros consumidores deberán deduplicar por `event_id`.

Redis no almacena sesiones PHP ni constituye un mecanismo de rate limiting demostrado. No existe BullMQ ni un sistema CQRS implementado.

### Kubernetes

- **Función:** ejecutar el Deployment y proporcionar el Service interno de la aplicación.
- **Dependencia:** necesaria para reproducir el despliegue DEV validado; no es un requisito intrínseco para ejecutar PHP fuera de ese despliegue.
- **Configuración:** manifiestos del repositorio GitOps, namespace `aulaviva-dev` y Secret aprovisionado fuera de Git.
- **Límites actuales:** una réplica, sin HPA, Ingress de aplicación ni probes declaradas. El entorno observado es Docker Desktop, no un servicio Kubernetes gestionado demostrado.

### Argo CD / GitOps

- **Función:** mantener la relación entre los manifiestos versionados y el estado del despliegue.
- **Dependencia:** forma parte del mecanismo de entrega DEV; no participa en cada solicitud de negocio.
- **Configuración:** repositorio GitOps `kevinramosr/AulaViva-GitOps`, ruta `overlays/dev` y Application `aulvaviva-dev`.
- **Límites actuales:** se observó `Synced / Healthy`, pero esto no demuestra sincronización automática ni comprueba por sí solo todos los flujos funcionales.

La promoción desde CI propone un cambio de imagen mediante PR. La creación de ese PR no equivale a su aprobación, integración o despliegue.

## Persistencia y estado local

| Información | Almacenamiento actual |
|---|---|
| Datos relacionales | PostgreSQL |
| PDFs y materiales binarios | PostgreSQL, columna `bytea` |
| Fragmentos y embeddings | PostgreSQL con pgvector, `vector(768)` |
| Eventos de rendición | PostgreSQL, `outbox_events` |
| Sesiones PHP | Archivos locales del entorno de ejecución |
| Configuración de ejecución | Variables de entorno; referencias a Secret para `DB_*` en DEV |

Las sesiones locales impiden describir la aplicación como completamente stateless. Redis no sustituye actualmente ese almacenamiento.

## Servicios gestionados considerados como evolución futura

Todos los elementos de esta sección son **PROPUESTA FUTURA**. No describen infraestructura actual ni autorizan una migración.

| Servicio | Estado | Posible función | Condición previa |
|---|---|---|---|
| Amazon RDS | PROPUESTA FUTURA | Operar PostgreSQL como servicio gestionado | Verificar compatibilidad de versión, pgvector, conectividad y recuperación |
| Amazon S3 | PROPUESTA FUTURA | Almacenamiento externo de materiales | Aprobar un cambio del almacenamiento `bytea` y conservar la autorización de acceso |
| Amazon ElastiCache | PROPUESTA FUTURA | Operar un servicio compatible con el uso de Redis que se adopte | Definir necesidad real; no asumir sesiones, BullMQ o consumidores existentes |
| Amazon ECS/Fargate | PROPUESTA FUTURA | Alternativa de ejecución de contenedores | Evaluar su relación con el despliegue Kubernetes/GitOps actual |
| Amazon CloudWatch | PROPUESTA FUTURA | Centralización de registros y métricas | Definir integración, retención, seguridad y costes |

La decisión sobre una plataforma cloud deberá documentarse y validarse por separado. Estos servicios no son dependencias actuales de AulaViva.

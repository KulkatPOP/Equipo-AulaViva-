# Despliegue actual — AulaViva

## Alcance

Describe la arquitectura de `main = 1882ac5` y el despliegue DEV observado durante la reconciliación de S04.

Los datos de DEV representan una observación del entorno, no una garantía permanente. Los cambios documentales de `main` no actualizan automáticamente la imagen desplegada.

La [reconciliación S07](../infra/s07-reconciliacion.md) registra la revisión del 23 de noviembre de 2026, las brechas de salud y seguridad y la inconsistencia del renderizado PROD. Renderizar un overlay no demuestra su despliegue.

## Arquitectura lógica

AulaViva es un monolito PHP con páginas renderizadas en servidor y una API REST dentro del mismo código de aplicación.

| Componente | Responsabilidad |
|---|---|
| PHP/Apache | Ejecutar páginas, handlers y API REST |
| PostgreSQL/pgvector | Persistencia relacional, PDFs, fragmentos y embeddings |
| Ollama | Generar embeddings y respuestas del Tutor |
| Procesos CLI | Ingesta de materiales y operaciones de rendición |

La imagen utiliza PHP 8.4.27 con Apache, controladores PDO para PostgreSQL, MySQL y SQLite y Poppler para extracción de texto mediante `pdftotext`.

Las sesiones y los controles de autorización se ejecutan en PHP. No existe una SPA independiente ni un backend Node/NestJS.

Los comandos CLI pertenecen a la misma aplicación. Su existencia no implica que haya trabajadores permanentes desplegados.

## Despliegue DEV validado

| Elemento | Estado observado |
|---|---|
| Plataforma | Kubernetes de Docker Desktop |
| Namespace | `aulaviva-dev` |
| Deployment | `aulaviva-api` |
| Réplicas | 1 |
| Service | `aulaviva-api`, tipo ClusterIP |
| Puertos del Service | TCP 80 → 80 |
| Secret de base de datos | `aulaviva-db` |
| Requests | CPU `100m`, memoria `128Mi` |
| Limits | CPU `500m`, memoria `256Mi` |
| Application de Argo CD | `aulvaviva-dev` |
| Estado de Argo CD observado | `Synced / Healthy` |
| Revisión GitOps observada | `a7a2a7b2d38e31f270311f1dc1e28b3792c439f0` |
| Imagen observada | `ghcr.io/kulkatpop/aulaviva-api:9b3a1caab23c16ab44839b17678d97b05c27e302` |

El Secret se aprovisiona fuera de Git. El Deployment referencia sus claves `DB_DSN`, `DB_USER` y `DB_PASSWORD`, sin incorporar los valores al repositorio.

PostgreSQL y Ollama son externos al Pod. DEV configura Ollama mediante:

- `OLLAMA_BASE_URL=http://host.docker.internal:11434`.
- `OLLAMA_EMBEDDING_MODEL=nomic-embed-text`.
- `OLLAMA_CHAT_MODEL=qwen3:1.7b`.
- `OLLAMA_ALLOW_DOCKER_HOST=true`.
- `TUTOR_AI_TIMEOUT_SECONDS=60`.

La autorización del host Docker es explícita. El código no determina mediante `APP_ENV` que esa configuración pertenezca exclusivamente a DEV.

La imagen observada corresponde al commit `9b3a1caab23c16ab44839b17678d97b05c27e302`; no se afirma que DEV ejecute el último `main` documental.

## Recursos NO presentes actualmente

En el DEV observado no existen:

- HPA.
- Ingress de aplicación.
- Readiness, liveness o startup probes declaradas.
- Jobs o CronJobs de la aplicación.
- StatefulSet en el namespace de aplicación.

No se ha demostrado sincronización automática de Argo CD; la Application observada no tenía `syncPolicy.automated` configurado.

El estado `Healthy` y un Pod preparado no sustituyen una prueba funcional de PostgreSQL, Ollama o los flujos educativos.

## GitOps

### Repositorios

- **Aplicación:** `KulkatPOP/Equipo-AulaViva-`.
- **Despliegue:** `kevinramosr/AulaViva-GitOps`.

El repositorio de aplicación contiene el código, Dockerfile, migraciones, pruebas y workflows.

El repositorio GitOps contiene los manifiestos base y overlays de entorno. DEV aplica patches de configuración y selecciona la imagen mediante Kustomize.

### Construcción y promoción

El workflow `.github/workflows/ci.yml` declara validaciones, construcción y publicación de la imagen en GHCR.

El tag utiliza el SHA del commit de aplicación:

```text
ghcr.io/kulkatpop/aulaviva-api:<SHA>
```

Para `main`, el flujo de promoción depende de los jobs de construcción, supply chain y DAST. Utiliza una credencial almacenada en GitHub Secrets para proponer la actualización de DEV mediante PR en el repositorio GitOps.

Deben distinguirse estos pasos:

1. Validar el código.
2. Construir y publicar una imagen.
3. Generar una propuesta de actualización GitOps.
4. Revisar e integrar esa propuesta.
5. Sincronizar los manifiestos mediante Argo CD.
6. Verificar la imagen y el funcionamiento del entorno.

Generar o actualizar un PR no demuestra que el cambio esté desplegado. Tampoco se presume sincronización automática después de integrarlo.

### Sincronización

Argo CD utiliza el repositorio GitOps y la ruta `overlays/dev` como estado deseado para `aulaviva-dev`.

El despliegue observado estaba sincronizado y saludable. Esto no implica que cada publicación posterior de una imagen cambie automáticamente el entorno.

## Persistencia

### PostgreSQL

Almacena los datos de usuarios, aulas, matrículas, evaluaciones, rendiciones, invitaciones y vínculos de apoderados. Las matrículas corresponden a la relación `aula_alumnos`; no existe una tabla independiente llamada `matriculas`.

### Materiales

Los archivos PDF se almacenan en PostgreSQL como `bytea`. PHP verifica la autorización antes de entregarlos al usuario.

No existe almacenamiento S3 implementado ni acceso público directo a un directorio de PDFs.

### Tutor IA

Los estados de ingesta y fragmentos se almacenan en PostgreSQL. Los embeddings utilizan pgvector con dimensión 768.

Ollama se ejecuta fuera del Pod de aplicación. Sus modelos no se incluyen en la imagen PHP.

### Outbox

`outbox_events` almacena los tres eventos implementados de rendición:

- `evaluacion.iniciada`.
- `evaluacion.entregada`.
- `evaluacion.calificada`.

La publicación hacia Redis Streams es opcional y se realiza mediante el CLI de rendición cuando dispone de configuración Redis y de la extensión PHP Redis.

La imagen actual no instala dicha extensión. No existen consumidores demostrados ni proyecciones CQRS implementadas. Los futuros consumidores deberán deduplicar por `event_id`.

### Sesiones

PHP utiliza archivos locales para las sesiones. El Deployment observado no incorpora un volumen compartido de sesiones.

La aplicación no es completamente stateless: no debe asumirse continuidad de sesión entre réplicas o después de sustituir el Pod.

## Procesos administrativos

Existen comandos para:

- Ingestar materiales del Tutor: `cli/tutor_ingestar.php`.
- Cerrar rendiciones vencidas y, opcionalmente, publicar Outbox: `cli/rendicion.php`.

Las migraciones están versionadas en `migrations/`. Su existencia no demuestra que se ejecuten automáticamente durante el despliegue.

No se han observado Jobs o CronJobs de aplicación que programen estos procesos.

## Evolución futura

Las siguientes capacidades requieren decisiones, implementación y validación adicionales:

- Almacenamiento externo de materiales, si resulta necesario.
- Readiness, liveness y startup probes.
- Escalado horizontal y HPA, considerando antes las sesiones.
- Mayor paridad entre CI y las dependencias de DEV.
- Operación programada de procesos administrativos.
- Observabilidad centralizada.
- Despliegue cloud gestionado.
- Separación de servicios cuando exista una necesidad demostrada.

Ninguna se presenta como capacidad ya desplegada. La referencia arquitectónica vigente continúa siendo el monolito documentado en S03 y el modelo de datos y eventos descrito en S06.

# S07 — Reconciliación de contenedores, Kubernetes e IaC

## 1. Alcance

S07 se auditó contra la implementación vigente. No se incorporan directamente los archivos de `origin/feature/s07-containers-k8s-iac`: su Dockerfile Node, manifiestos y Terraform no representan la aplicación actual.

Este documento registra trazabilidad y brechas, sin implementar cambios. Distingue **implementado** (código/configuración existente), **observado en DEV** (comprobación del entorno), **documentado** (descripción con alcance explícito) y **propuesto** (trabajo futuro, no disponible actualmente).

La arquitectura corresponde a [S03](../adr/0003-estilo-arquitectonico.md), el despliegue y sus límites a [S04](../arch/deployment.md), y los datos/eventos a [S06](../adr/0004-datos-y-eventos.md). No se redefine ninguna de esas decisiones.

## 2. Bases de la revisión

| Referencia | Base inspeccionada |
|---|---|
| Fecha de revisión | 23 de noviembre de 2026 |
| Aplicación | `KulkatPOP/Equipo-AulaViva-` |
| Main revisado | `0476be1`, merge del PR #22 de S05 |
| GitOps | `kevinramosr/AulaViva-GitOps` |
| Revisión GitOps local y observada por Argo CD | `a7a2a7b2d38e31f270311f1dc1e28b3792c439f0` |

Las observaciones son una fotografía del entorno; no garantizan su estado posterior. No se consultaron valores de secretos ni se modificaron recursos.

## 3. Matriz de infraestructura

| Elemento | Implementación/documentación vigente | Observación o límite |
|---|---|---|
| Docker | `Dockerfile`: PHP 8.4.27/Apache; PDO PostgreSQL, MySQL y SQLite; Poppler (`pdftotext`) | No incorpora PostgreSQL ni Ollama dentro de la imagen |
| GHCR | `.github/workflows/ci.yml`: construcción/publicación con tag SHA | Publicar no equivale a desplegar el último main |
| Firma/procedencia | `.github/workflows/supply-chain.yml`: escaneo, firma y procedencia | Capacidad del CI; no prueba enforcement en DEV |
| Kubernetes/Kustomize | GitOps: `base/` y `overlays/dev`, `overlays/prod` | DEV y PROD tienen configuraciones distintas |
| Configuración DEV | Patches `deployment-db-env.yaml` y `deployment-ollama-env.yaml` | DB mediante `secretKeyRef`; configuración Ollama explícita |
| PostgreSQL/pgvector | Persistencia relacional, PDFs `bytea` y embeddings de 768 dimensiones | Dependencia externa al Pod; véanse S04/S06 |
| Ollama | Proveedor PHP; modelos `nomic-embed-text` y `qwen3:1.7b` | Servicio externo al Pod |
| Redis | Relay CLI opcional del Outbox de rendición | Requiere configuración y extensión PHP Redis, no instalada por la imagen actual; sin consumidores implementados |
| Argo CD | Application `aulvaviva-dev`, fuente GitOps `overlays/dev` | `Synced / Healthy`; sin `syncPolicy.automated` configurado |
| Terraform | `terraform/main.tf`: namespace `aulaviva` y NetworkPolicies | Archivos existentes; no acreditan protección de `aulaviva-dev` |

La publicación/promoción declarada en CI y la sincronización de Argo CD son pasos diferentes. La promoción de main propone un PR en GitOps; no demuestra que el cambio esté integrado o desplegado.

## 4. DEV observado

| Elemento | Resultado |
|---|---|
| Contexto Kubernetes | `docker-desktop` |
| Namespace | `aulaviva-dev` |
| Deployment | `aulaviva-api`, una réplica disponible (`1/1`) |
| Service | `aulaviva-api`, ClusterIP, TCP 80 → 80 |
| Requests | CPU `100m`, memoria `128Mi` |
| Limits | CPU `500m`, memoria `256Mi` |
| Secret referenciado | `aulaviva-db` |
| Probes | Sin readiness, liveness ni startup probes declaradas |
| HPA / PVC | No encontrados en el namespace |
| Volúmenes | Deployment sin volúmenes declarados |
| Ingress de aplicación | No encontrado en el namespace |

Imagen declarada:

```text
ghcr.io/kulkatpop/aulaviva-api:9b3a1caab23c16ab44839b17678d97b05c27e302
```

Referencia observada en `imageID` del Pod:

```text
ghcr.io/kulkatpop/aulaviva-api@sha256:8ca8b0ee6cd47b9153711dff38c2378c0a93e8cfd1622f922540b0d10e45d5a2
```

Variables de base de datos: `DB_DSN`, `DB_USER`, `DB_PASSWORD`, cada una desde una clave homónima del Secret. No se registran sus valores.

Configuración no secreta de Ollama/Tutor:

- `OLLAMA_BASE_URL=http://host.docker.internal:11434`.
- `OLLAMA_EMBEDDING_MODEL=nomic-embed-text`.
- `OLLAMA_CHAT_MODEL=qwen3:1.7b`.
- `OLLAMA_ALLOW_DOCKER_HOST=true`.
- `TUTOR_AI_TIMEOUT_SECONDS=60`.

La autorización del host Docker es explícita; `AIProvider.php` no comprueba `APP_ENV` para limitarla por sí mismo a DEV. PostgreSQL y Ollama son dependencias externas según la configuración y validaciones documentadas; esta reconciliación no repite sus pruebas funcionales.

## 5. Salud

No existe un endpoint dedicado de salud en el código versionado inspeccionado.

`login.php` no debe presentarse como health endpoint: GET inicia sesión y abre una conexión PDO mediante `config/conexion.php`. Una indisponibilidad de PostgreSQL puede lanzar una excepción, sin contrato específico de salud. No consulta Ollama en ese GET.

`deploy/lab-receiver.php` es un receptor de laboratorio, excluido de la imagen y protegido por Apache; tampoco debe utilizarse para probes.

Readiness y liveness quedan como trabajo futuro. La orientación pendiente de implementación/validación es:

- Liveness: comprobar Apache/PHP sin depender de PostgreSQL u Ollama.
- Readiness: comprobar PostgreSQL mediante una operación mínima, controlada y con timeout.
- Ollama: tratar su caída como indisponibilidad del Tutor, no como condición para retirar toda la aplicación educativa del Service.

No se han añadido endpoints ni probes. Un Pod disponible y Argo CD Healthy no sustituyen esas comprobaciones.

## 6. Inconsistencia del overlay PROD

En el repositorio GitOps inspeccionado:

- `base/deployment.yaml` declara `image: nginx:alpine`.
- `overlays/prod/kustomization.yaml` busca `images.name: ghcr.io/kulkatpop/aulaviva-api` y declara `newTag: latest`.
- `kubectl kustomize overlays/prod` produce `image: nginx:alpine` y dos réplicas.

El nombre buscado por la transformación no coincide con la imagen base. DEV sí transforma `name: nginx` hacia la imagen AulaViva.

Es una inconsistencia del **manifiesto renderizado**, no una afirmación sobre una aplicación PROD desplegada. No se encontraron Deployment ni Service de aplicación en `aulaviva-prod` durante la revisión.

No se corrige aquí el manifiesto, no se selecciona una imagen productiva y no se propone `latest` como solución. Corregir la sustitución tampoco demostraría por sí solo que PROD disponga de las dependencias y configuración necesarias.

## 7. Seguridad: controles y brechas

| Control | Estado y alcance comprobado |
|---|---|
| Secrets | Referencias `secretKeyRef` en DEV; valores aprovisionados fuera de Git |
| `securityContext` | Sin controles explícitos en el Deployment observado |
| `runAsNonRoot` | No declarado |
| `allowPrivilegeEscalation` | No declarado |
| Capabilities | Sin eliminación explícita |
| `readOnlyRootFilesystem` | No declarado |
| NetworkPolicies | Existen en Terraform para `aulaviva`; no encontradas en `aulaviva-dev` |
| Selector de NetworkPolicy | `app=aulaviva`; DEV utiliza `app=aulaviva-api` |
| Kyverno | Archivo `k8s/policies/require-signature.yaml` limitado a `aulaviva` |
| Enforcement Kyverno | No demostrado en DEV; el clúster consultado no reconoce el recurso `ClusterPolicy` |
| Referencia de imagen | DEV declara tag SHA; se observó el digest del Pod. No equivale a fijarlo en el manifiesto |
| Firma/procedencia | Implementadas en workflow; no equivalen a admisión verificada en DEV |

La política Kyverno versionada exige digest, firma y procedencia en su alcance. Ni su existencia ni las políticas Terraform permiten atribuir esos controles a otros namespaces. Estas brechas quedan identificadas, sin aplicar recomendaciones de configuración en esta etapa.

## 8. HPA pospuesto

El escalado horizontal queda pospuesto: DEV tiene una réplica, sesiones PHP locales y ningún volumen compartido declarado. No se dispone en esta revisión de mediciones de carga que justifiquen umbrales de escalado, y aún no existen probes. PostgreSQL es una dependencia de la aplicación y Ollama una dependencia externa del Tutor.

No debe asumirse continuidad de sesión entre réplicas o tras sustituir el Pod. El orden futuro es:

1. Salud.
2. Comportamiento ante fallos.
3. Sesiones.
4. Seguridad.
5. Medición de carga.
6. Escalado.

## 9. Reconciliación de S07

Se rescatan conceptualmente reproducibilidad, Kubernetes, GitOps, recursos, health checks y separación entre Terraform y despliegue de aplicación GitOps. Algunos ya existen; los health checks permanecen propuestos.

No se rescatan Node 20, el Dockerfile Node, los manifiestos antiguos, OpenAI, `DATABASE_URL`, `REDIS_HOST` como dependencia obligatoria ni el Terraform histórico. `evidencia.png` muestra errores de descarga de imagen y un Deployment no disponible; no es evidencia de éxito.

No se integra la rama histórica ni se crea otro ADR. Las fuentes vigentes prevalecen sobre aquella propuesta.

## 10. Criterios de cierre documental

S07 documental queda cerrado cuando:

- La infraestructura vigente está identificada y vinculada a sus fuentes.
- DEV y PROD están diferenciados.
- Las inconsistencias conocidas están registradas.
- Las propuestas futuras están separadas de lo implementado/observado.
- No se atribuyen capacidades no comprobadas.

Este cierre documental no implica haber corregido PROD, implementado probes/HPA ni ampliado el alcance de controles de seguridad.

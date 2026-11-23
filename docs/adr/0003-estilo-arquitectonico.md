# ADR 0003: estilo arquitectónico de AulaViva

## Estado y alcance

Aceptado como decisión de estilo; actualizado al estado implementado en
`main` (`f908f01`, 22 de noviembre de 2026). Recupera el razonamiento del ADR
de `feature/s03-arquitectura-c4` sin incorporar sus tecnologías hipotéticas.
Describe el producto actual y distingue sus procesos de su despliegue DEV.

## Contexto

AulaViva permite acceder a materiales, crear y rendir evaluaciones, consultar
un Tutor IA y revisar rendimiento desde el panel del apoderado. Un equipo pequeño
y el alcance de un MVP favorecen una aplicación desplegable única y transacciones
locales antes que una distribución prematura en microservicios.

## Decisión

Mantener una **aplicación monolítica con separación de responsabilidades en PHP**,
orientada al estilo de monolito modular. Apache y PHP 8.4 sirven páginas, handlers,
recursos HTML/CSS/JavaScript y la API REST desde el mismo artefacto. No existe una
SPA separada ni un backend Java o Node.js. La separación actual es por archivos,
helpers y clases; no supone módulos completamente aislados, esquemas PostgreSQL
independientes ni fronteras de dominio verificadas automáticamente.

### Responsabilidades y evidencia

| Responsabilidad | Implementación existente |
| --- | --- |
| Identidad y acceso | `login.php`, `config/sesion.php`, `config/funciones_rol.php`, `api/v1/_helpers/api.php`; sesiones PHP, cookie de sesión y roles alumno/profesor/admin/apoderado. No JWT. |
| Aulas y materiales | `mis_aulas.php`, `mis_cursos.php`, `aula.php`, `materiales.php`, `archivo_material.php`, `config/materiales.php`; comprobación de colegio y relación con el aula. |
| Evaluaciones y rendición | `evaluaciones.php`, `evaluacion_editar.php`, `evaluacion_rendir.php`, `evaluacion_resultado.php`, `config/evaluaciones.php`, `config/rendicion.php`. |
| Tutor IA | `tutor.php`, `tutor_ask.php`, `config/tutor/`; recuperación híbrida en PHP, evidencia explícita y validación de la respuesta y sus citas. |
| Apoderados | `panel_apoderado.php`, `vinculos_apoderado.php`, `config/apoderados.php`; solo vínculos confirmados y autorizados permiten consultar rendimiento. |
| Invitación y activación | `api/v1/invitaciones/`, `activar_cuenta.php`, `config/activacion.php`; token almacenado como hash y creación/consumo transaccional. |

### Persistencia y dependencias

- **PostgreSQL + pgvector**: acceso mediante PDO PostgreSQL. Persisten usuarios,
  aulas, relaciones, evaluaciones, entregas y respuestas, vínculos de apoderados,
  invitaciones y datos del Tutor. Las migraciones oficiales están en `migrations/`.
- Los materiales PDF se almacenan como `bytea` en `materiales.contenido`.
  `archivo_material.php` comprueba autorización antes de servirlos. No se utiliza
  S3, almacenamiento público ni enlaces firmados de un proveedor cloud.
- **Ollama** es un servicio externo al proceso PHP. `nomic-embed-text` genera
  embeddings de 768 dimensiones; `qwen3:1.7b` genera respuestas con `think=false`.
  PHP recupera los fragmentos y valida evidencia y salida; Ollama no decide la
  autorización ni consulta directamente la base. No se requiere OpenAI ni API key.
- `cli/tutor_ingestar.php` utiliza PHP CLI y Poppler/pdftotext para extraer,
  fragmentar y generar/persistir embeddings mediante las clases de ingesta.
- `cli/rendicion.php` cierra intentos vencidos. Su relay publica Outbox a
  **Redis Streams solo cuando se configura Redis** y está disponible la extensión
  PHP Redis. La imagen actual no instala esa extensión. Sin el relay, los eventos
  quedan en PostgreSQL; las calificaciones no dependen de su publicación.
  No se atribuyen consumidores desplegados; los futuros consumidores de Streams
  deben deduplicar por `event_id`.
- Los CLI son procesos del mismo producto y reutilizan su código/configuración;
  no se presupone un servicio Kubernetes permanente para ejecutarlos.

### Despliegue y límites operativos

`Dockerfile` empaqueta PHP/Apache, PDO PostgreSQL y Poppler. En DEV, Kubernetes
ejecuta la aplicación; el repositorio separado `kevinramosr/AulaViva-GitOps`
contiene los manifiestos y Argo CD reconcilia el estado declarado. El workflow
de aplicación publica imágenes en GHCR y propone la promoción de DEV en GitOps.
Esto no equivale a desplegar directamente desde una página PHP o desde el CLI.

PostgreSQL y Ollama son dependencias **externas al Pod web en DEV**. Las variables
`DB_DSN`, `DB_USER` y `DB_PASSWORD` se aprovisionan fuera del código; DEV utiliza
referencias a un Secret. Ollama se configura mediante `OLLAMA_BASE_URL` y las
variables de modelos. El acceso a Ollama se habilita mediante configuración explícita;
la utilizada para Docker Desktop/DEV establece `OLLAMA_ALLOW_DOCKER_HOST=true`.
`AIProvider.php` no comprueba `APP_ENV` ni determina por sí mismo que el entorno
sea DEV. La opción no convierte cualquier URL en un destino permitido.

Las sesiones son sesiones PHP. No se configura Redis como almacén de sesiones,
caché ni rate limiter. El acceso local DEV usa HTTP mediante port-forward;
HTTPS requiere terminación TLS aprovisionada. No se afirma alta disponibilidad,
persistencia compartida de sesiones ni escalado horizontal seguro por defecto.

## Consecuencias

- Un artefacto simplifica construcción, despliegue y evolución del MVP.
- PostgreSQL permite transacciones locales para publicación, rendición y
  activación; el aislamiento por colegio se comprueba en servidor.
- El código compartido exige pruebas de regresión y disciplina al cambiar helpers.
  La organización por archivos no garantiza por sí sola desacoplamiento modular.
- El cómputo de IA está separado, pero su latencia consume el tiempo de la petición
  del Tutor. Timeout, abstención y errores controlados no son un circuit breaker.
- Más réplicas requieren resolver sesiones, procesos CLI y capacidad de PostgreSQL
  y Ollama; no basta con declarar la aplicación stateless.
- Redis es una integración opcional de eventos; no se atribuyen consumidores,
  proyecciones CQRS ni notificaciones que no estén implementados.

## Alternativas no adoptadas en esta implementación

Microservicios desde el inicio agregarían distribución, operación y consistencia
entre servicios antes de que el MVP lo justifique. Un backend serverless puro
cambiaría el modelo de ejecución PHP/Apache y los procesos CLI actuales. Se conserva
la motivación del ADR original sin presentar esas alternativas como evaluaciones
de costos o rendimiento medidas en el entorno actual.

## Vistas relacionadas

- [C4 L1: contexto](../c4/l1-context.puml).
- [C4 L2: contenedores lógicos](../c4/l2-container.puml).
- [Atributos de calidad](../arch/atributos-calidad.md).
- Operación y configuración: `docs/tutor-demo.md`, `docs/tutor-rag-ingestion.md`,
  `docs/tutor-rag-generation.md`, `docs/rendicion-operacion.md`,
  `docs/us05-apoderados.md` y `docs/threat-model.md`.

Los fuentes C4 incluyen C4-PlantUML desde su repositorio público. Renderizarlos
requiere PlantUML, Graphviz y acceso a los includes; no se incluyen imágenes
generadas sin verificar. Un contenedor C4 no representa automáticamente un Pod.

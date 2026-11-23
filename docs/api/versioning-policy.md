# Política de versionado de Aula Viva API

## Versión actual

La versión actual implementada de la API de Aula Viva es **v1**.

Los endpoints se publican utilizando versionado explícito en la URL:

`/api/v1/`

Ejemplo:

`GET /api/v1/aulas`

El contrato `api/openapi.yaml` describe únicamente cinco operaciones REST:
login JSON, listado y detalle de aulas, alumnos del aula y creación de invitaciones.
La autenticación utiliza la cookie PHPSESSID, no JWT. Aulas e invitaciones requieren
rol profesor y utilizan el colegio de la sesión. Los formularios de login y
activación, el panel del apoderado y el handler `tutor_ask.php` son flujos PHP
distintos; no se presentan como nuevas rutas de `/api/v1/`.

El servidor del contrato es `/api/v1`, relativo al origen. El cliente TypeScript
permite sobrescribir `basePath`; en Node debe proporcionarse una URL absoluta.
Para solicitudes entre orígenes, `credentials: 'include'` por sí solo no configura
CORS ni garantiza que el servidor o el navegador acepten la cookie. En el mismo
origen deben conservarse las cookies recibidas durante el login. Los ejemplos HTTP
requieren una cuenta de prueba autorizada y un cliente que mantenga su cookie jar.

## Contrato e implementación actuales

- Los cuerpos JSON de login e invitaciones permiten campos adicionales que los
  handlers ignoran; esto no permite elegir el colegio desde el navegador.
- Las respuestas mantienen sus nombres reales (`expira_en`, `url_activacion`,
  `idempotent_replay`) y los errores de la API utilizan RFC 7807.
- `expira_en` es una cadena devuelta por PostgreSQL, sin normalización explícita
  a RFC 3339. El contrato no promete `date-time`; el cliente conserva la cadena
  en vez de interpretarla como `Date`. Una normalización futura requiere una
  decisión de implementación y compatibilidad; no se cambia PHP para imponerla.
- Los handlers JSON de login e invitaciones no implementan el token CSRF de los
  formularios PHP. Documentar la cookie no atribuye esa protección a la API.
- `UsuarioSesion` incluye alumno, profesor, admin y apoderado. Las invitaciones
  admiten únicamente alumno y profesor; no son un mecanismo de alta de apoderados.

### Idempotencia de invitaciones

`Idempotency-Key` es obligatoria, tiene como máximo 100 caracteres y se asocia
al colegio autenticado. Una creación devuelve HTTP 201 y `url_activacion`.
Una repetición devuelve HTTP 200, `Idempotent-Replayed: true` y los datos originales,
sin token ni enlace de activación. El código no compara el cuerpo del reintento
con el original. La activación se realiza mediante `activar_cuenta.php`, que
valida expiración, uso único y CSRF, y crea la cuenta transaccionalmente.

### Adaptación reproducible del cliente generado

La regeneración con OpenAPI Generator **7.25.0**, generador `typescript-fetch`,
conserva las clases AulasApi, AutenticacinApi e InvitacionesApi y la configuración
`credentials`. Sin embargo, selecciona el esquema de HTTP 200 para
`crearInvitacion()`: deserializa también el HTTP 201 como `InvitacionReplayResponse`
y descarta `url_activacion`. La regeneración no resuelve esta limitación.

El contrato conserva esquemas distintos y correctos para 200/201; no se falsean
las respuestas del servidor para acomodar el generador. La generación oficial
del cliente debe ejecutarse mediante `packages/api-client/generate.mjs`, que
adapta el resultado antes de copiarlo y devuelve
`InvitacionResponse | InvitacionReplayResponse`. Para 201 convierte `data`
con `InvitacionFromJSON`, conservando `urlActivacion`; para 200 utiliza
`InvitacionReplayResponseFromJSON`. Mantiene el envoltorio `data`/`meta`.
No depende de una edición manual permanente del archivo generado.
La generación directa sin el script sigue teniendo la limitación original.
Los requisitos, pruebas y compatibilidad están documentados en
`packages/api-client/GENERATION.md`.

## Cambios compatibles

Los cambios que no rompen la compatibilidad con los clientes existentes
pueden incorporarse dentro de la misma versión de la API.

Ejemplos:

- Agregar nuevos endpoints.
- Agregar campos opcionales a una respuesta.
- Agregar nuevos parámetros opcionales.
- Corregir errores sin modificar el contrato existente.

## Cambios incompatibles

Los cambios que puedan romper clientes existentes requieren una nueva
versión mayor de la API.

Ejemplos:

- Eliminar o renombrar endpoints.
- Eliminar campos existentes.
- Cambiar el tipo de un campo.
- Convertir un parámetro opcional en obligatorio.
- Modificar de forma incompatible la estructura de una respuesta.

Como política para una evolución futura, un cambio incompatible con `v1`
debería publicarse bajo otra versión mayor, por ejemplo:

`/api/v2/`

No existe actualmente una implementación de `/api/v2/`.

## Deprecación

La siguiente es una política futura, no un mecanismo operativo demostrado:
antes de retirar una versión o endpoint se deberá comunicar su obsolescencia.

La documentación OpenAPI podrá indicar los endpoints obsoletos mediante:

`deprecated: true`

Cuando corresponda, la API podrá utilizar encabezados HTTP como
`Deprecation` y `Sunset` para comunicar la retirada futura del recurso.

No se ha implementado ni demostrado la emisión de estas cabeceras en los
endpoints actuales.

## Compatibilidad

Si se introduce una nueva versión mayor, deberán definirse el período de
transición y las condiciones de compatibilidad antes de retirar la anterior.
Actualmente solo se implementa v1; no se afirma coexistencia de versiones
ni existe un plazo de transición operativo definido.

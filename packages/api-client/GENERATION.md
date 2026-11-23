# Generación del cliente TypeScript

Requiere Node.js 22 o posterior, Git, Java compatible con OpenAPI Generator y
OpenAPI Generator **7.25.0**, fijado en `openapitools.json`.

Desde la raíz, después de instalar las dependencias del proyecto con `npm ci`:

```sh
node packages/api-client/generate.mjs
```

Alternativamente, `OPENAPI_GENERATOR_JAR` puede apuntar al JAR oficial 7.25.0
aprovisionado fuera del repositorio. El script comprueba la versión real antes
de generar. No incorpora rutas de una máquina, JARs ni credenciales al producto.

## Adaptación de invitaciones

El generador typescript-fetch selecciona el HTTP 200 como retorno único. El script
genera en un directorio temporal y adapta exclusivamente el retorno de invitaciones:

- HTTP 201: `InvitacionResponse`, convirtiendo `data` con `InvitacionFromJSON`
  y `meta` con `IdempotencyMetaFromJSON`.
- HTTP 200: `InvitacionReplayResponseFromJSON`, sin enlace de activación.
- Tipo público y variante Raw: unión `InvitacionResponse | InvitacionReplayResponse`.
- Un éxito HTTP distinto de los dos documentados produce `ResponseError`.

Se mantiene el envoltorio `{data, meta}`, no se introduce un discriminador ni se
cambian PHP, idempotencia, cookies, basePath o códigos del servidor. El script exige
los patrones esperados y falla antes de copiar si cambia la salida del generador.
También actualiza el tipo de retorno documentado en `docs/InvitacionesApi.md`.

Solo se copian archivos generados ya versionados del paquete; la customización y
las pruebas permanecen fuera de esa salida. Para reproducir la corrección utiliza
este script, no un comando de generación directa que omita la adaptación.

## Pruebas

Después de `npm ci`, TypeScript 5.9.3 está disponible como dependencia local de
desarrollo. Opcionalmente, `TYPESCRIPT_TSC` puede apuntar a su `bin/tsc` fuera del
repositorio:

```sh
node --test packages/api-client/invitaciones.test.cjs
```

Las pruebas compilan el cliente con comprobación estricta y usan respuestas HTTP
simuladas, sin consultar la aplicación ni la base. Verifican qué deserializador se
invoca, la conservación del enlace en 201, el replay, credentials y basePath.
También compilan aserciones de igualdad de los retornos público y Raw para
rechazar `any` o la pérdida de uno de los tipos de respuesta.
Para verificar reproducibilidad, ejecutar dos veces el script y comprobar que la
segunda ejecución no cambie los archivos generados; volver a ejecutar las pruebas.

## Compatibilidad del SDK

El consumidor recibe ahora la unión de creación y replay. Puede comprobar
`'urlActivacion' in resultado.data` o inspeccionar el estado con la variante Raw.
El contrato conserva la relación entre los estados y sus cuerpos.

La actualización S05 ya convierte `expiraEn` de `Date` a `string`, porque PHP
devuelve la fecha de PostgreSQL sin normalizarla a RFC 3339. Ese cambio requiere
adaptar consumidores que utilicen métodos de Date; no se cambia nuevamente aquí.

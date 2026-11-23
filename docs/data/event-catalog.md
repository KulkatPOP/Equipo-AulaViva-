# Catálogo de Eventos de Dominio - AulaViva

Referencia: implementación de `main` en `2ef05a8`.

Este catálogo separa los eventos registrados actualmente de los propuestos.
Los eventos implementados pertenecen al flujo de rendición y se almacenan
en PostgreSQL. Publicarlos en Redis Streams es una integración opcional.

## Eventos implementados

| Evento | Productor | Consumidores actuales | Estado | Descripción |
| --- | --- | --- | --- | --- |
| `evaluacion.iniciada` | `iniciarRendicion()` en `config/rendicion.php` | Ninguno: no hay consumidores de eventos de dominio implementados. | Implementado en Outbox; publicación opcional. | Se crea una nueva entrega con sus fechas de inicio y vencimiento. |
| `evaluacion.entregada` | `cerrarRendicion()` en `config/rendicion.php` | Ninguno: no hay consumidores de eventos de dominio implementados. | Implementado en Outbox; publicación opcional. | Se cierra la entrega y se registra el número de preguntas contestadas y el tiempo empleado. |
| `evaluacion.calificada` | `cerrarRendicion()` en `config/rendicion.php` | Ninguno: no hay consumidores de eventos de dominio implementados. | Implementado en Outbox; publicación opcional. | Se guardan los puntajes y la calificación calculados. |

La autocorrección se ejecuta directamente al cerrar la rendición.
`evaluacion.entregada` no es consumido por un worker de corrección.

El panel de apoderados consulta directamente las tablas académicas.
No consume `evaluacion.calificada`.

## Persistencia Outbox

La tabla `outbox_events` contiene:

| Columna | Uso |
| --- | --- |
| `id` | UUID que identifica el evento. |
| `tipo` | Nombre del evento. |
| `entrega_id` | UUID de la entrega relacionada. |
| `ocurrido_en` | Fecha y hora registradas por PostgreSQL. |
| `datos` | Payload del evento en `jsonb`. |
| `publicado_en` | Fecha de marcado de publicación; NULL mientras permanece pendiente. |

La restricción `UNIQUE (entrega_id, tipo)` evita registrar dos veces el mismo
tipo de evento para una entrega.

Los registros se crean dentro de la transacción de rendición.
La unicidad en PostgreSQL no garantiza una única publicación en Redis.

## Payload

### Sobre construido por el relay

El sobre se construye en `publicarOutboxRendicion()`, dentro de
`cli/rendicion.php`. No todas sus propiedades son columnas de Outbox.

| Campo | Valor actual |
| --- | --- |
| `event_id` | `outbox_events.id` |
| `event_type` | `outbox_events.tipo` |
| `event_version` | Cadena fija `"1.0"` |
| `occurred_at` | `outbox_events.ocurrido_en` |
| `aggregate_id` | `outbox_events.entrega_id` |
| `trace_id` | `outbox_events.entrega_id` |
| `data` | Contenido de `outbox_events.datos` |

`trace_id` reutiliza el ID de entrega. Actualmente no es un identificador
independiente de petición ni demuestra trazabilidad distribuida.

### Datos comunes

Los tres eventos incluyen:

| Campo | Tipo del modelo | Significado |
| --- | --- | --- |
| `evaluacion_id` | UUID | Evaluación rendida. |
| `estudiante_id` | Entero | Usuario alumno asociado a la entrega. |

`estudiante_id` no es UUID.

### `evaluacion.iniciada`

Además de los datos comunes:

- `fecha_inicio`: fecha y hora de inicio.
- `fecha_vencimiento`: fecha y hora de vencimiento.

### `evaluacion.entregada`

Además de los datos comunes:

- `cantidad_respuestas`: número de preguntas distintas con alguna alternativa seleccionada.
- `tiempo_empleado_segundos`: segundos enteros transcurridos entre inicio y entrega.

### `evaluacion.calificada`

Además de los datos comunes:

- `puntaje_obtenido`;
- `puntaje_maximo`;
- `calificacion`.

Estos tres valores proceden de columnas numéricas de PostgreSQL.
El código los serializa tal como los devuelve PDO; no impone una conversión
adicional a números JSON.

### Ejemplo de entrega

Ejemplo ilustrativo con identificadores y valores ficticios:

```json
{
  "event_id": "11111111-1111-4111-8111-111111111111",
  "event_type": "evaluacion.entregada",
  "event_version": "1.0",
  "occurred_at": "2026-11-22 12:30:00+00",
  "aggregate_id": "22222222-2222-4222-8222-222222222222",
  "trace_id": "22222222-2222-4222-8222-222222222222",
  "data": {
    "evaluacion_id": "33333333-3333-4333-8333-333333333333",
    "estudiante_id": 42,
    "cantidad_respuestas": 15,
    "tiempo_empleado_segundos": 1800
  }
}
```

## Publicación

Recorrido implementado:

`operación de rendición → transacción PostgreSQL → outbox_events → relay CLI → Redis Streams opcional`

El relay:

1. Consulta un evento pendiente, ordenando por `ocurrido_en` e `id`.
2. Bloquea su fila dentro de una transacción.
3. Publica mediante `xAdd`.
4. Envía el sobre JSON dentro del campo `evento` del mensaje de Streams.
5. Actualiza `publicado_en` y confirma la transacción.

Se utiliza `REDIS_STREAM` o, por defecto, `aulaviva:dominio`.
El ID de mensaje de Redis se genera con `*`; no sustituye al `event_id`
de dominio.

La publicación requiere configuración de Redis, extensión PHP Redis
disponible y ejecución del CLI. La imagen actual no instala esa extensión.
El CLI puede ejecutarse puntualmente o mediante `--continuo`; no se presupone
un proceso permanente desplegado.

Si Redis acepta el mensaje y falla después el marcado o commit en PostgreSQL,
el evento puede publicarse nuevamente. No existe garantía de exactly-once.

Los futuros consumidores deben deduplicar por `event_id`.
Actualmente no existen en el código:

- consumer groups ni llamadas `xReadGroup`;
- confirmaciones `xAck`;
- consumidor de seguimiento;
- consumidor de autocorrección;
- consumidor de notificaciones.

## Eventos propuestos

Los siguientes nombres proceden del diseño anterior de S06.
Todos están en estado **PROPUESTO / FUTURO**: no tienen un productor de
evento implementado ni consumidores actuales.

| Evento | Estado | Intención pendiente de especificar |
| --- | --- | --- |
| `estudiante.registrado` | PROPUESTO / FUTURO | Comunicar el registro de un estudiante. |
| `estudiante.matriculado` | PROPUESTO / FUTURO | Comunicar una matrícula. |
| `curso.material.subido` | PROPUESTO / FUTURO | Comunicar la subida de un material. |
| `material.vectorizado` | PROPUESTO / FUTURO | Comunicar la finalización de una ingesta con embeddings. |
| `evaluacion.creada` | PROPUESTO / FUTURO | Definir si representa creación o publicación; el diseño anterior confundía ambas. |
| `tutor.consulta.solicitada` | PROPUESTO / FUTURO | Comunicar una consulta al Tutor. |
| `tutor.consulta.respondida` | PROPUESTO / FUTURO | Comunicar una respuesta del Tutor. |
| `apoderado.reporte.visto` | PROPUESTO / FUTURO | Registrar la consulta de un reporte como evento. |

Que una operación funcional exista no significa que emita su evento propuesto.
Estos nombres no implican implementar consumidores, notificaciones o CQRS.

## Evidencia

- `migrations/003_rendicion.sql`
- `config/rendicion.php`
- `evaluacion_rendir.php`
- `cli/rendicion.php`
- `config/apoderados.php`
- `docs/rendicion-operacion.md`

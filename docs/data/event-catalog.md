# Catálogo de Eventos de Dominio - AulaViva

Siguiendo las reglas de nombramiento de eventos en pasado (`recurso.accion.pasado`), a continuación se detallan los eventos del ciclo de vida principal del estudiante, las evaluaciones y el uso del Tutor IA.

| # | Evento | Productor | Consumidores | Descripción |
|---|--------|-----------|--------------|-------------|
| 1 | `estudiante.registrado` | Contexto Académico | Evaluación, Seguimiento | Un nuevo estudiante fue creado en el sistema. |
| 2 | `estudiante.matriculado` | Contexto Académico | Evaluación, Tutor IA | Un estudiante fue asignado a un curso específico. |
| 3 | `curso.material.subido` | Contexto Académico | Tutor IA | Se subió un nuevo material (ej. PDF) al curso. |
| 4 | `material.vectorizado` | Tutor IA | Académico | El PDF fue procesado, fragmentado y sus embeddings guardados. |
| 5 | `evaluacion.creada` | Contexto Evaluación | Académico, Seguimiento | El docente publicó una nueva evaluación en un curso. |
| 6 | `evaluacion.iniciada` | Contexto Evaluación | Seguimiento | El estudiante abrió y comenzó a rendir la evaluación. |
| 7 | `evaluacion.entregada` | Contexto Evaluación | Evaluación (Worker) | El estudiante envió sus respuestas (gatilla la autocorrección). |
| 8 | `evaluacion.calificada` | Contexto Evaluación | Seguimiento | El sistema o docente asignó la calificación final a la entrega. |
| 9 | `tutor.consulta.solicitada`| Contexto Tutor IA | Tutor IA (Worker) | El alumno hizo una pregunta al Tutor IA. |
| 10| `tutor.consulta.respondida`| Contexto Tutor IA | Seguimiento | La IA generó y entregó la respuesta al alumno. |
| 11| `apoderado.reporte.visto` | Contexto Seguimiento | Auditoría | Un apoderado consultó el panel de rendimiento de su alumno. |

---

### Ejemplo de Estructura (Schema) de un Evento

A continuación se muestra el esquema del evento `evaluacion.entregada`, el cual se publica una vez que el alumno envía su examen. Se incluye el `trace_id` para trazabilidad y la versión del evento.

```json
{
  "event_id": "8f7e6d2a-5c4b-4a31-b9e7-123456789abc",
  "event_type": "evaluacion.entregada",
  "event_version": "1.0",
  "occurred_at": "2026-10-07T14:22:31Z",
  "aggregate_id": "entrega-9876",
  "trace_id": "req-uuid-9999",
  "data": {
    "evaluacion_id": "750e8400-e29b-41d4-a716-446655440000",
    "estudiante_id": "550e8400-e29b-41d4-a716-446655440000",
    "cantidad_respuestas": 15,
    "tiempo_empleado_segundos": 1800
  }
}

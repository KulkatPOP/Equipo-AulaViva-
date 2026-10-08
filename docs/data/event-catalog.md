# Catálogo de Eventos de Dominio - AulaViva

Siguiendo las reglas de nombramiento en pasado (`recurso.accion.pasado`), aquí están los eventos del ciclo de vida del estudiante y sus evaluaciones.

| # | Evento | Productor | Consumidores | Descripción |
|---|--------|-----------|--------------|-------------|
| 1 | `student.registered` | Contexto Académico | Evaluación, Seguimiento | Un nuevo estudiante fue creado en una institución. |
| 2 | `student.enrolled` | Contexto Académico | Evaluación, Tutor IA | Un estudiante fue matriculado en un curso. |
| 3 | `course.material.uploaded` | Contexto Académico | Tutor IA | Se subió un material (gatilla vectorización en pgvector). |
| 4 | `material.embedded` | Tutor IA | Académico | El PDF fue procesado y vectorizado exitosamente. |
| 5 | `evaluation.created` | Contexto Evaluación | Académico | El docente publicó una nueva evaluación. |
| 6 | `evaluation.started` | Contexto Evaluación | Seguimiento | El estudiante abrió la evaluación. |
| 7 | `evaluation.submitted` | Contexto Evaluación | Evaluación (Worker) | El estudiante envió sus respuestas (gatilla autocorrección). |
| 8 | `evaluation.graded` | Contexto Evaluación | Seguimiento | El sistema asignó un puntaje final al intento. |
| 9 | `tutor.query.requested` | Contexto Tutor IA | Tutor IA (Worker) | El alumno hizo una pregunta al Tutor IA. |
| 10| `tutor.query.answered` | Contexto Tutor IA | Seguimiento | La IA respondió la duda del alumno. |
| 11| `parent.report.viewed` | Contexto Seguimiento | Auditoría | Un apoderado consultó el panel de notas. |

### Ejemplo de Schema de Evento (`evaluation.submitted`)
```json
{
  "event_id": "8f7e6d-5c4b...",
  "event_type": "evaluation.submitted",
  "event_version": "1.0",
  "occurred_at": "2026-10-07T14:22:31Z",
  "aggregate_id": "submission-1234",
  "trace_id": "req-9999",
  "data": {
    "evaluation_id": "750e8400-e29b-41d4-a716-446655440000",
    "student_id": "550e8400-e29b-41d4-a716-446655440000",
    "answers_count": 15
  }
}

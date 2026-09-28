# Catálogo de eventos AulaViva

## estudiante.creado.v1

Versión:
v1

Campos:
- id_estudiante
- nombre
- institucion_id
- fecha_creacion

Productor:
Servicio de estudiantes

Consumidores:
- Servicio de evaluaciones
- Tutor IA


## evaluacion.respondida.v1

Versión:
v1

Campos:
- id_evaluacion
- id_estudiante
- resultado

Productor:
Servicio de evaluaciones

Consumidores:
- Panel docente
- Analítica

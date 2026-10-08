# Bounded Contexts y Context Map — AulaViva

Aplicando Domain-Driven Design (DDD), hemos dividido la iniciativa de AulaViva en 4 *Bounded Contexts* (Contextos Delimitados) para evitar un modelo monolítico gigante y asegurar que nuestro Lenguaje Ubicuo sea claro en cada área.

## 1. Contexto Académico
* **Responsabilidad:** Gestionar las instituciones, los usuarios (alumnos, docentes) y la estructura de los cursos y matrículas.
* **Agregados principales:** `Institucion`, `Curso`, `Usuario`.
* **Lenguaje Ubicuo:** Matrícula, Docente, Estudiante, Curso, Institución.

## 2. Contexto de Evaluación
* **Responsabilidad:** Creación de las evaluaciones por parte del docente, recepción de las entregas de los estudiantes y el proceso de autocorrección.
* **Agregados principales:** `Evaluacion`, `Entrega`.
* **Lenguaje Ubicuo:** Evaluación, Entrega, Calificación, Intento.

## 3. Contexto del Tutor IA
* **Responsabilidad:** Responder las dudas de los estudiantes basándose en los documentos del curso, utilizando RAG y servicios externos de LLM.
* **Agregados principales:** `RegistroConsultaTutor`, `BaseConocimiento` (Embeddings).
* **Lenguaje Ubicuo:** Prompt, Vector, Consulta, Respuesta de IA, Contexto.

## 4. Contexto de Seguimiento
* **Responsabilidad:** Agrupar el rendimiento del alumno en sus evaluaciones para mostrar el progreso escolar a los apoderados.
* **Agregados principales:** `ProgresoEstudiante`.
* **Lenguaje Ubicuo:** Apoderado, Reporte de progreso, Rendimiento.

---

## Mapa de Contextos (Context Map)

Las relaciones entre estos contextos se dan principalmente mediante eventos de dominio (Coreografía):

1. **Evaluación y Tutor IA** actúan como consumidores (Downstream) del **Contexto Académico** (Upstream). Necesitan saber cuándo un estudiante se matricula o cuándo se crea un curso.
2. **Seguimiento** actúa como consumidor (Downstream) del **Contexto de Evaluación** y **Tutor IA**. Escucha los eventos de "evaluación calificada" y "consulta respondida" para actualizar los reportes del apoderado.

# Bounded Contexts - AulaViva

Aplicando Domain-Driven Design (DDD), hemos dividido AulaViva en 4 Bounded Contexts principales para evitar que conceptos como "Usuario" o "Curso" se vuelvan monolíticos.

### 1. Contexto Académico (Academic Context)
* **Responsabilidad:** Gestión de instituciones, usuarios (alumnos, docentes), cursos y matrículas.
* **Agregados principales:** `Institution`, `Course`, `User`.
* **Lenguaje Ubicuo:** Matrícula, Docente, Estudiante, Curso.

### 2. Contexto de Evaluación (Evaluation Context)
* **Responsabilidad:** Creación de evaluaciones, recepción de respuestas (submissions) y autocorrección.
* **Agregados principales:** `Evaluation`, `Submission`.
* **Lenguaje Ubicuo:** Intento (Attempt), Calificación (Score), Pauta de corrección.

### 3. Contexto de Tutoría IA (Tutor AI Context)
* **Responsabilidad:** Generación de respuestas basadas en el contexto del curso usando RAG (Retrieval-Augmented Generation) y OpenAI.
* **Agregados principales:** `KnowledgeBase` (Embeddings), `TutorInteraction`.
* **Lenguaje Ubicuo:** Prompt, Embedding, Similitud vectorial, Fuentes.

### 4. Contexto de Seguimiento Familiar (Tracking Context)
* **Responsabilidad:** Agrupar el rendimiento del alumno para mostrar el progreso a los apoderados.
* **Agregados principales:** `StudentProgress`.
* **Lenguaje Ubicuo:** Apoderado, Reporte de progreso, Alerta de rendimiento.

### Context Map (Relaciones)
* `Evaluación` y `Tutoría IA` consumen eventos de `Académico` (para saber en qué cursos está el alumno).
* `Seguimiento Familiar` consume eventos de `Evaluación` (para actualizar notas) mediante el patrón CQRS.

# Bounded Contexts y Context Map — AulaViva

## Alcance

Referencia: implementación de `main` en `2ef05a8`.

AulaViva funciona como una aplicación PHP integrada, con páginas, handlers,
API REST y procesos CLI que comparten código y una base PostgreSQL.

Los cuatro contextos siguientes son una clasificación conceptual de
responsabilidades y lenguaje. No representan cuatro microservicios,
cuatro bases de datos ni límites DDD estrictamente implementados.

La separación actual se expresa mediante archivos, helpers, clases y relaciones
entre tablas. No se atribuyen agregados persistidos que no existan en el modelo.

## 1. Académico

### Responsabilidad

Mantener la identidad de los usuarios, sus relaciones con las aulas,
los materiales y el flujo de invitación y activación de cuentas.

### Entidades y tablas reales

- `usuarios`
- `aulas`
- `aula_alumnos`
- `aula_profesores`
- `materiales`
- `invitaciones`

`aulas` es la unidad operativa utilizada por materiales y evaluaciones.
La matrícula se representa mediante `aula_alumnos`; la asignación docente,
mediante `aula_profesores`.

El colegio se identifica mediante `colegio_id`. No existe en el esquema
actual documentado un catálogo de instituciones que deba representarse
como un agregado persistido `Institucion`.

### Implementación actual

La aplicación autentica usuarios y comprueba roles y colegio. El acceso
a materiales y evaluaciones utiliza las relaciones del usuario con el aula.

Los PDF se guardan en PostgreSQL como `bytea`. Las invitaciones conservan
el hash del token y la identidad que utilizará la activación.

### Relaciones con otros contextos

Evaluación y Tutor IA consultan usuarios, aulas y sus relaciones mediante SQL.
Seguimiento utiliza matrículas y aulas para determinar el rendimiento
que puede consultar un apoderado.

No se implementa esa coordinación mediante consumidores de eventos académicos.

## 2. Evaluación

### Responsabilidad

Crear y publicar evaluaciones, gestionar rendiciones, almacenar respuestas,
autocorregir y conservar resultados.

### Entidades y tablas reales

- `evaluaciones`
- `preguntas`
- `alternativas`
- `entregas`
- `respuestas`
- `solicitudes_ayuda`
- `outbox_events`

`evaluaciones.aula_id` relaciona la evaluación con el aula.
Preguntas y alternativas almacenan contenido, puntajes y pauta.

`entregas` relaciona una evaluación con un estudiante y conserva el estado,
las fechas, los puntajes y la calificación. `respuestas` almacena las
alternativas seleccionadas.

`solicitudes_ayuda` registra solicitudes vinculadas a una entrega.
Su persistencia no implica un sistema de notificaciones.

### Implementación actual

El docente guarda borradores y publica mediante `publicada_en`.
La rendición comprueba autorización y publicación antes de permitir el acceso.

La autocorrección se ejecuta directamente en `cerrarRendicion()`.
`cli/rendicion.php` también utiliza esa función para cerrar intentos vencidos.

Se registran en Outbox los eventos de inicio, entrega y calificación.
La publicación en Redis Streams es opcional; no hay consumidor de autocorrección.

### Relaciones con otros contextos

Utiliza aulas, usuarios y matrícula del área académica.
Seguimiento consulta directamente las entregas calificadas y evaluaciones
publicadas para presentar el rendimiento.

## 3. Tutor IA

### Responsabilidad

Responder preguntas a partir de los materiales autorizados del aula mediante
recuperación híbrida, generación controlada y validación de evidencia.

### Entidades y tablas reales

- `materiales`, compartida con el área académica;
- `material_ingestas`;
- `material_fragmentos`;
- `material_fragmentos.embedding vector(768)`.

No existe una tabla `RegistroConsultaTutor` ni se presenta un historial
persistido de preguntas y respuestas como capacidad actual.

### Implementación actual

La ingesta CLI utiliza Poppler/pdftotext, fragmenta el texto y solicita
embeddings a Ollama mediante `nomic-embed-text`.

La consulta utiliza búsqueda semántica y textual, combinación de resultados,
EvidenceGate y validación posterior. La generación utiliza Ollama; la
configuración documentada emplea `qwen3:1.7b`.

Los estados de ingesta y fragmentos se almacenan en PostgreSQL.
Los logs operativos del Tutor no equivalen a eventos de dominio publicados.

### Relaciones con otros contextos

Consulta usuarios, matrícula, aulas y materiales mediante SQL.
La recuperación está limitada por la sesión del alumno, su colegio
y su matrícula en el aula.

No consume eventos de matrícula o de subida de materiales.
La ingesta se inicia mediante el CLI existente.

## 4. Seguimiento

### Responsabilidad

Permitir al apoderado consultar el rendimiento de estudiantes autorizados
y al administrador gestionar y confirmar los vínculos correspondientes.

### Entidades y tablas reales

- `apoderado_estudiantes`;
- consultas sobre `usuarios`, `aulas`, `aula_alumnos`,
  `evaluaciones` y `entregas`.

`aulas.asignatura_nombre` proporciona el nombre de asignatura.
No existe una tabla independiente de asignaturas.

No existe una entidad persistida `ProgresoEstudiante` ni una tabla de
proyección académica desnormalizada.

### Implementación actual

Solo los vínculos confirmados permiten consultar rendimiento.
Las consultas comprueban colegio, roles y usuarios activos.

El promedio se calcula sobre entregas calificadas de evaluaciones publicadas,
y se muestran las últimas cinco por aula. Los datos se consultan directamente
mediante `config/apoderados.php`.

### Relaciones con otros contextos

Comparte relaciones académicas y consulta los resultados de Evaluación.
No consume `evaluacion.calificada` ni eventos del Tutor para actualizar el panel.

## Context Map actual

Las relaciones entre áreas se realizan principalmente mediante consultas SQL
y tablas compartidas:

| Origen | Relación actual |
| --- | --- |
| Académico → Evaluación | Aulas, usuarios, asignación docente y matrícula verificadas en servidor. |
| Académico → Tutor IA | Materiales y autorización por colegio y matrícula. |
| Académico/Evaluación → Seguimiento | Consultas de vínculos, matrículas, aulas y resultados. |
| Evaluación → Outbox | Registro transaccional de tres eventos de rendición. |
| Outbox → Redis Streams | Publicación opcional mediante relay CLI. |

No existe una coreografía de eventos entre los cuatro contextos.
No hay consumidores de eventos de dominio, proyecciones CQRS ni actualización
asíncrona del panel de apoderados.

Los procesos CLI son ejecutables del producto. Su existencia no demuestra
que haya workers o servicios permanentes desplegados.

## Evolución propuesta del Context Map

Si el sistema necesita mayor desacoplamiento, se podrán definir consumidores
idempotentes y contratos adicionales de eventos.

Una futura proyección de seguimiento requeriría un caso de uso justificado,
persistencia, actualización y reconstrucción de la proyección. Nada de ello
se considera implementado actualmente.

## Referencias de implementación

- `config/materiales.php`
- `config/evaluaciones.php`
- `config/rendicion.php`
- `config/activacion.php`
- `config/apoderados.php`
- `config/tutor/IngestaTutor.php`
- `config/tutor/TutorRetriever.php`
- `config/tutor/TutorService.php`
- `cli/tutor_ingestar.php`
- `cli/rendicion.php`
- `migrations/001_materiales.sql` a `migrations/006_invitaciones.sql`

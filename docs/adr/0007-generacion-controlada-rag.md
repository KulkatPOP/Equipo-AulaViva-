# ADR 0007 — Generación controlada del Tutor RAG

## Estado

Aceptado. Documentación retrospectiva de la implementación existente.

## Contexto

AulaViva incorpora un Tutor IA que permite a los estudiantes realizar preguntas sobre los materiales educativos de sus aulas.

Los modelos generativos pueden producir información incorrecta, inventar referencias o generar afirmaciones que no están respaldadas por los documentos disponibles.

Por tratarse de una plataforma educativa, se requiere controlar la información entregada y evitar el acceso a materiales pertenecientes a otros estudiantes, aulas o colegios.

## Decisión

Implementar una arquitectura RAG con recuperación autorizada, extracción de evidencia explícita y validación de respuestas antes de su publicación.

El proceso considera:

1. Validar la sesión, el rol y la matrícula del estudiante.
2. Recuperar hasta tres fragmentos autorizados mediante búsqueda híbrida.
3. Extraer hechos explícitos respaldados por los documentos.
4. Construir un checklist de afirmaciones obligatorias.
5. Solicitar una respuesta estructurada al modelo generativo.
6. Validar las afirmaciones, las citas y la integridad de la respuesta.
7. Comprobar nuevamente la autorización y vigencia de la evidencia.
8. Publicar únicamente información validada o devolver una abstención.

La autorización y la validación se realizan en el servidor PHP, sin delegar estas responsabilidades al modelo de inteligencia artificial.

## Alternativas consideradas

### Alternativa 1: generación directa mediante un LLM

Permitir que el modelo responda utilizando únicamente sus conocimientos generales.

Ventajas:

- Implementación sencilla.
- Menor complejidad del procesamiento.

Desventajas:

- Mayor riesgo de alucinaciones.
- Ausencia de trazabilidad documental.
- Respuestas potencialmente ajenas al contenido educativo autorizado.

Se descarta por no satisfacer los requisitos de fundamentación y control.

### Alternativa 2: RAG convencional sin validación posterior

Recuperar fragmentos y entregarlos al modelo, publicando directamente su respuesta.

Ventajas:

- Mayor contextualización.
- Menor complejidad que una arquitectura con validación estricta.

Desventajas:

- El modelo puede interpretar incorrectamente los fragmentos.
- Puede inventar citas o modificar cantidades y relaciones.
- La recuperación de un documento no garantiza que la respuesta esté respaldada por él.

Se descarta porque la recuperación por sí sola no garantiza la integridad de las respuestas.

### Alternativa 3: RAG con evidencia explícita y validación

Es la alternativa adoptada.

Permite controlar las afirmaciones, verificar las referencias y rechazar respuestas que no satisfacen las condiciones establecidas.

## Consecuencias positivas

- Mayor trazabilidad de las respuestas.
- Reducción del riesgo de publicar afirmaciones sin respaldo.
- Separación entre autorización, recuperación y generación.
- Abstención cuando no existe evidencia suficiente.
- Posibilidad de ejecutar pruebas reproducibles sobre la extracción y validación.

## Consecuencias negativas y limitaciones

- Mayor complejidad de implementación y mantenimiento.
- Latencia adicional por recuperación y validación.
- Posibilidad de rechazar respuestas correctas cuando utilizan construcciones no reconocidas.
- Cobertura limitada a las relaciones y estructuras que reconoce el extractor.
- Dependencia de materiales correctamente procesados e indexados.
- La validación no garantiza la eliminación absoluta de alucinaciones.

## Controles de seguridad y
# ADR 0008 — Inferencia de inteligencia artificial local

## Estado

Aceptado. Documentación retrospectiva de la implementación existente.

## Contexto

AulaViva requiere un componente de inteligencia artificial capaz de generar embeddings y respuestas fundamentadas en materiales educativos.

La plataforma debe considerar la privacidad de la información académica, los costos operativos, la disponibilidad del servicio y la capacidad de ejecutar demostraciones sin depender de proveedores externos de inteligencia artificial.

## Decisión

Utilizar Ollama como servicio de inferencia local, separado del proceso PHP de AulaViva.

Los modelos seleccionados son:

| Componente | Modelo | Función |
|---|---|---|
| Embeddings | nomic-embed-text | Generar vectores de 768 dimensiones |
| Generación | qwen3:1.7b | Generar respuestas estructuradas del Tutor IA |
| Persistencia vectorial | PostgreSQL + pgvector | Almacenar embeddings y recuperar fragmentos |

La aplicación se comunica con Ollama mediante HTTP y conserva en PHP las responsabilidades de autorización, recuperación documental y validación de respuestas.

La selección del modelo de generación considera las limitaciones de recursos del entorno local. No se afirma que sea superior a otros modelos sin una evaluación comparativa.

## Alternativas consideradas

### Alternativa 1: API comercial de inteligencia artificial

Utilizar servicios externos como OpenAI, Anthropic o proveedores equivalentes.

Ventajas:

- Menor necesidad de recursos computacionales locales.
- Acceso a modelos de mayor capacidad.
- Infraestructura de inferencia administrada por el proveedor.

Desventajas:

- Dependencia de conectividad a internet.
- Posibles costos asociados al consumo de tokens.
- Necesidad de gestionar credenciales externas.
- Necesidad de evaluar las condiciones de tratamiento y retención de datos.

No se adopta para la implementación actual debido a los objetivos de autonomía operativa, privacidad y control de costos.

### Alternativa 2: ejecutar modelos de mayor tamaño localmente

Utilizar modelos con una cantidad superior de parámetros.

Ventajas:

- Potencial mejora en la comprensión de preguntas complejas.
- Mayor capacidad potencial de generación.

Desventajas:

- Mayor consumo de memoria y almacenamiento.
- Posible aumento de latencia.
- Mayores requisitos de hardware.

No se adopta inicialmente debido a las restricciones de recursos del entorno local. No se dispone de una comparación sistemática de rendimiento entre modelos.

### Alternativa 3: Ollama con modelos locales livianos

Es la alternativa adoptada.

Permite ejecutar la inferencia sin contratar una API comercial y mantener el procesamiento de las consultas dentro de la infraestructura controlada para la demostración.

## Configuración

La aplicación utiliza las siguientes variables de entorno:

- `OLLAMA_BASE_URL`
- `OLLAMA_EMBEDDING_MODEL`
- `OLLAMA_CHAT_MODEL`
- `OLLAMA_ALLOW_DOCKER_HOST`
- `TUTOR_AI_TIMEOUT_SECONDS`

En el entorno local directo se utiliza `http://127.0.0.1:11434`.

En Docker Desktop se permite explícitamente `http://host.docker.internal:11434` mediante `OLLAMA_ALLOW_DOCKER_HOST=true`.

Esta configuración no autoriza destinos arbitrarios ni implica que la conexión HTTP hacia Ollama esté cifrada.

## Controles de seguridad

- Validación del destino permitido para la conexión con Ollama.
- Recuperación de fragmentos previamente autorizados.
- Generación estructurada mediante un esquema JSON.
- Desactivación del modo de razonamiento mediante `think=false`.
- Presupuesto de tiempo limitado para recuperación y generación.
- Validación de afirmaciones y citas en el servidor.
- Rechazo de respuestas incompletas o inválidas.
- Abstención ante evidencia insuficiente.
- Ausencia de claves de APIs comerciales en la implementación actual.

## Consecuencias positivas

- Independencia de proveedores externos de inferencia.
- Ausencia de cob
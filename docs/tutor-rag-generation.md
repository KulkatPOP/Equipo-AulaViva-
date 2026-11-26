# US04-C · Generación controlada

El endpoint experimental `POST /tutor_ask.php` usa la sesión
de AulaViva. Solo acepta `aula_id` y `pregunta` como JSON.
Una sesión existente debe pertenecer a un alumno activo matriculado en
el aula del mismo colegio. Los campos adicionales se rechazan.

```json
{"aula_id":1,"pregunta":"¿Cuánto dura el experimento?"}
```

`TutorService` verifica la matrícula y solicita hasta tres fragmentos
autorizados al recuperador híbrido. Vuelve a comprobar la matrícula y
la vigencia de la evidencia antes y después de generar.

El filtro anterior clasificaba la pregunta por los formatos del piloto
antes de revisar los fragmentos. Por eso descartaba evidencia pertinente
en otros materiales. El filtro actual trabaja con pasajes recuperados
y busca relaciones explícitas, como una medida mínima, una duración o
una instrucción de medición. Para cada hecho registra el ID, la página
y el pasaje que lo respalda. Si hay valores incompatibles, si el texto
solo describe una acción y se pregunta por su resultado, o si no se
puede justificar el hecho, se devuelve una abstención antes de generar.

Qwen3 recibe la pregunta, los pasajes seleccionados y el checklist.
Las citas permitidas se limitan a etiquetas y páginas del contexto.
El servidor comprueba cada cita, exige todos los hechos del checklist,
rechaza duplicados y comprueba las afirmaciones mediante una
normalización conservadora. Admite, por ejemplo, cantidades escritas
en palabras o cifras y algunas equivalencias léxicas sin cambiar
medidas, negaciones ni el orden de los elementos. Publica la formulación
validada del checklist con su cita. Los IDs reales solo se resuelven
en el servidor.

La extracción busca relaciones explícitas en el pasaje y comprueba que
sus argumentos correspondan a la pregunta. Incluye inventarios, mínimos
expresados como conteos, listas, instrumentos, materiales, orientación,
fórmulas, pasos y causas en construcciones acotadas. Para un inventario
en otro idioma, exige una equivalencia registrada para el artículo
completo y un encabezado de actividad coherente. Una coincidencia
del recuperador por sí sola no habilita una respuesta.

El servidor admite el reordenamiento de listas planas cuando conserva
los mismos elementos y el mismo conector. Los pasos y las relaciones
causales conservan su orden. La traducción y la validación son
conservadoras: una construcción desconocida, una relación ambigua o
una paráfrasis que no conserve una firma comprobable puede provocar
abstención. El léxico ayuda a identificar argumentos, pero no se usa
como prueba de que dos oraciones completas impliquen lo mismo.

Ollama utiliza loopback por defecto. En entornos Docker y Kubernetes
locales se permite explícitamente `host.docker.internal` mediante
`OLLAMA_ALLOW_DOCKER_HOST=true`. La comunicación utiliza HTTP/JSON
en el puerto 11434. El modelo recibe `think: false`.

El checklist incluye una representación intermedia para listas de
alternativas, relaciones de protección, orientaciones relativas a un
origen, operaciones encadenadas y cambios causales. La estructura
conserva el conector, los roles, las unidades, el orden de operaciones
y la condición que vincula los efectos. Se calcula exclusivamente
sobre los fragmentos ya autorizados y sigue vinculada al pasaje, ID
y página que la respaldan. Para esas relaciones, el servidor analiza
la afirmación completa y compara su estructura antes de publicar.
Las relaciones que ya cubría el Tutor conservan su validación anterior.
Las gramáticas de español e inglés siguen siendo acotadas.

Las pruebas reproducibles del filtro y del verificador se ejecutan con
`php test/tutor-evidence.php`. Incluyen cambios de entidad, propósito,
cantidad, unidad, conectores, orden de relaciones y procedencia de citas.

## Configuración local

```text
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
OLLAMA_CHAT_MODEL=qwen3:1.7b
TUTOR_AI_TIMEOUT_SECONDS=15
TUTOR_MAX_CONTEXT_FRAGMENTS=3
TUTOR_MAX_ANSWER_CHARS=1000
```

### Configuración del entorno DEMO

El entorno DEMO utiliza Kubernetes local mediante Docker Desktop.
Ollama se ejecuta fuera del Pod de la aplicación.

```text
OLLAMA_BASE_URL=http://host.docker.internal:11434
OLLAMA_ALLOW_DOCKER_HOST=true
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
OLLAMA_CHAT_MODEL=qwen3:1.7b
TUTOR_AI_TIMEOUT_SECONDS=60
```

El tiempo de espera se amplía a 60 segundos para la demostración
local. Esto no constituye una garantía de latencia ni un SLO medido.

La comunicación con Ollama utiliza HTTP local, sin cifrado TLS.
No se utilizan proveedores externos de inferencia ni claves API
comerciales.

La inferencia local evita cargos por consumo de tokens de una API
externa, pero requiere recursos de procesamiento, memoria,
almacenamiento y energía del equipo anfitrión.

### Requisitos y comportamiento de ejecución

Usa una base con la migración 004 de 768 dimensiones y material
ingestado. El presupuesto de tiempo incluye recuperación y generación.
El generador recibe el tiempo restante y nunca se publica una respuesta
parcial. Los errores se registran con estado, latencia, modelo y cantidad
de fragmentos, sin guardar pregunta ni texto de materiales.

| Estado | HTTP |
|---|---|
| `ANSWERED` | 200 |
| `INSUFFICIENT_EVIDENCE` | 200 si el aula está autorizada, 403 si se deniega el acceso |
| `VALIDATION_FAILED` | 200 si se bloquea la respuesta del modelo, 400 si el JSON es inválido |
| `GENERATION_UNAVAILABLE` | 503 |
| `GENERATION_TIMEOUT` | 504 |

Una sesión ausente sigue la redirección de autenticación existente.
La configuración actual usa Ollama local, sin claves API ni servicios
de hosting específicos.

## Interfaz del Tutor IA

El alumno abre Tutor IA desde Mis cursos. `tutor.php` usa la sesión
existente y lista solo aulas activas con matrícula vigente. Si hay más
de una, permite elegirla. El formulario envía exclusivamente
`pregunta` y `aula_id` a `POST /tutor_ask.php`. El endpoint vuelve a
decidir la autorización. El navegador no envía identidad, colegio ni
material, ni recibe la configuración de Ollama.

`js/tutor.js` distingue `IDLE`, `LOADING`, `ANSWERED`,
`INSUFFICIENT_EVIDENCE`, `GENERATION_UNAVAILABLE`,
`GENERATION_TIMEOUT`, `VALIDATION_FAILED` y `NETWORK_ERROR`.
Durante la consulta, deshabilita el botón y anuncia la carga. Muestra
mensajes para cada fallo sin reproducir detalles técnicos del servidor.
En una respuesta válida, muestra las fuentes con sus IDs de fragmento
y páginas comprobados por el endpoint. Las fuentes son texto, sin
enlaces generados a partir de la respuesta.

La conversación solo vive en la página y se borra al recargar. No se
guarda en PostgreSQL ni en almacenamiento del navegador. Para responder,
el endpoint necesita material ingestado y una instancia de Ollama
disponible y configurada para el entorno correspondiente.
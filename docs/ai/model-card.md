# Model Card — Tutor IA de AulaViva

## 1. Información general

| Campo | Descripción |
|---|---|
| Proyecto | AulaViva |
| Funcionalidad | Tutor IA educativo |
| Modelo generativo | Qwen3:1.7b |
| Modelo de embeddings | nomic-embed-text |
| Dimensiones vectoriales | 768 |
| Motor de inferencia | Ollama |
| Arquitectura | RAG híbrido con generación controlada |
| Modalidad | Inferencia local |
| Estado | Implementado y probado en entorno DEMO |
| Fecha de actualización | 24 de noviembre de 2026 |

## 2. Propósito

El Tutor IA tiene como objetivo apoyar el aprendizaje de los estudiantes mediante respuestas fundamentadas en los materiales educativos publicados por sus profesores.

El sistema recupera información de documentos previamente ingresados a AulaViva y genera respuestas utilizando únicamente evidencia autorizada y comprobable.

No pretende reemplazar al profesor ni tomar decisiones académicas de manera autónoma.

## 3. Modelos utilizados

### 3.1. Qwen3:1.7b

Modelo de lenguaje utilizado para la generación controlada de respuestas.

Se seleccionó por su tamaño reducido y la posibilidad de ejecutarlo localmente mediante Ollama, sin depender de una API comercial.

Características de la implementación:

- Inferencia local.
- Modelo de aproximadamente 1.700 millones de parámetros.
- Cuantización Q4_K_M en el entorno local probado.
- Temperatura configurada en 0.
- Modo de razonamiento desactivado mediante `think: false`.
- Generación mediante una estructura JSON controlada.
- Validación posterior de afirmaciones y citas.

La selección prioriza el consumo de recursos y la independencia de proveedores externos. No se afirma que el modelo sea superior a otros modelos sin una evaluación comparativa.

### 3.2. nomic-embed-text

Modelo utilizado para transformar preguntas y fragmentos de documentos en representaciones vectoriales.

Características:

- Vectores de 768 dimensiones.
- Ejecución local mediante Ollama.
- Almacenamiento de embeddings en PostgreSQL con pgvector.
- Recuperación de fragmentos mediante búsqueda híbrida, combinando información textual y vectorial.

## 4. Arquitectura de funcionamiento

El Tutor IA utiliza Retrieval-Augmented Generation (RAG).

El flujo de funcionamiento es el siguiente:

1. El profesor publica material educativo en formato PDF.
2. El proceso de ingesta extrae el texto mediante Poppler.
3. El texto se divide en fragmentos asociados a páginas.
4. Ollama genera los embeddings de los fragmentos.
5. PostgreSQL y pgvector almacenan el contenido y sus vectores.
6. El estudiante formula una pregunta desde un aula autorizada.
7. El servidor verifica la sesión, el rol y la matrícula.
8. TutorRetriever recupera hasta tres fragmentos autorizados.
9. EvidenceGate identifica los hechos explícitos que respaldan una posible respuesta.

10. Qwen3 recibe la pregunta, los pasajes y el checklist.
11. El servidor valida las afirmaciones, las citas y la vigencia de la evidencia.

12. Se publica una respuesta validada o una abstención.

El modelo generativo no decide los permisos de acceso.

La autorización corresponde al backend de AulaViva.

## 5. Configuración

### Configuración general

| Parámetro | Valor |
|---|---|
| OLLAMA_EMBEDDING_MODEL | nomic-embed-text |
| OLLAMA_CHAT_MODEL | qwen3:1.7b |
| Fragmentos máximos | 3 |
| Longitud máxima de respuesta | 1000 caracteres |
| Temperatura | 0 |
| Think | false |
| Tiempo de espera predeterminado | 15 segundos |
| Tiempo de espera DEMO | 60 segundos |

### Configuración del entorno DEMO

```text
OLLAMA_BASE_URL=http://host.docker.internal:11434
OLLAMA_ALLOW_DOCKER_HOST=true
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
OLLAMA_CHAT_MODEL=qwen3:1.7b
TUTOR_AI_TIMEOUT_SECONDS=60
```

El entorno DEMO utiliza Kubernetes local mediante Docker Desktop.

Ollama se ejecuta fuera del Pod de la aplicación y se comunica mediante HTTP/JSON.

El tiempo de espera configurado no representa una garantía de rendimiento ni un SLO medido.

## 6. Usos previstos

- Resolver preguntas relacionadas con materiales educativos.
- Identificar cantidades, instrumentos y materiales.
- Consultar hechos explícitos presentes en documentos.
- Apoyar la comprensión de contenidos académicos.
- Mostrar las fuentes que respaldan las respuestas.
- Abstenerse cuando no existe evidencia suficiente.

## 7. Usos no previstos

- Sustituir la enseñanza o supervisión del profesor.
- Responder preguntas sin evidencia documental suficiente.
- Consultar información de aulas no autorizadas.
- Generar calificaciones académicas mediante inteligencia artificial.
- Tomar decisiones disciplinarias sobre estudiantes.
- Realizar diagnósticos médicos, psicológicos o similares.
- Garantizar que todos los documentos y preguntas sean comprendidos.

La autocorrección de evaluaciones de AulaViva utiliza lógica determinista de la aplicación y no depende del modelo generativo.

## 8. Guardrails y controles de seguridad

| Control | Implementación |
|---|---|
| Autenticación | Sesión PHP del usuario |
| Control de roles | Acceso al Tutor restringido a estudiantes autorizados |
| Aislamiento académico | Validación de colegio, aula y matrícula |
| Recuperación autorizada | Fragmentos pertenecientes al aula correspondiente |
| Contexto limitado | Hasta tres fragmentos |
| EvidenceGate | Extracción y comprobación de hechos explícitos |
| Salida estructurada | Respuesta del modelo mediante JSON |
| Validación de citas | Comprobación de referencias y páginas |
| Validación de afirmaciones | Comparación con los hechos del checklist |
| Control de duplicados | Rechazo de afirmaciones duplicadas |
| Control de evidencia | Verificación antes y después de generar |
| Abstención | Bloqueo de respuestas sin respaldo suficiente |
| Timeout | Interrupción ante agotamiento del presupuesto de tiempo |
| Longitud máxima | Límite de 1000 caracteres |

Los controles reducen el riesgo de alucinaciones y divulgación no autorizada, pero no garantizan su eliminación absoluta.

## 9. Estados de respuesta

| Estado | Descripción |
|---|---|
| ANSWERED | Respuesta validada y respaldada por evidencia |
| INSUFFICIENT_EVIDENCE | Evidencia insuficiente o acceso denegado |
| VALIDATION_FAILED | La respuesta no supera los controles de validación |
| GENERATION_UNAVAILABLE | Servicio de inferencia no disponible |
| GENERATION_TIMEOUT | Tiempo de espera agotado |

Ante errores o evidencia insuficiente, el sistema evita publicar respuestas parciales o afirmaciones que no puedan justificarse.

## 10. Evaluación del sistema

### 10.1. Metodología

Se utilizó un Golden Set de diez casos mediante solicitudes HTTP al endpoint real del Tutor, con un estudiante ficticio autenticado en DEMO local: cinco preguntas con evidencia explícita, tres sin evidencia suficiente y dos solicitudes adversariales. Se evaluaron exactitud, fidelidad, citas, abstención, seguridad y latencia HTTP de extremo a extremo.

### 10.2. Evolución de resultados

| Versión | Casos aprobados | Tasa |
|---|---:|---:|
| Inicial | 7/10 | 70 % |
| V2 | 9/10 | 90 % |
| V3 | 10/10 | 100 % |

La mejora fue de 30 puntos porcentuales sobre el conjunto evaluado. Se corrigieron la extracción de hechos explícitos y el tratamiento de términos numéricos en TutorLexico, incorporando pruebas de regresión.

### 10.3. Indicadores finales

| Indicador | Resultado |
|---|---:|
| Golden Set | 10/10 |
| Respuestas positivas correctas | 5/5 |
| Fidelidad documental | 5/5 |
| Citas correctas | 5/5 |
| Abstenciones correctas | 3/3 |
| Casos adversariales aprobados | 2/2 |
| Pruebas de autorización | 6/6 |
| Latencia promedio | 2,035 s |
| Latencia mínima | 0,399 s |
| Latencia máxima | 9,970 s |

Las cinco respuestas positivas se contrastaron con el fragmento 39, página 1, del material 3 almacenado en PostgreSQL. Se verificaron las 768 dimensiones de su embedding.

### 10.4. Evaluación de seguridad

| Escenario | HTTP | Resultado |
|---|---:|---|
| Tutor de aula autorizada | 200 | Aprobado |
| Tutor de aula restringida | 403 | Aprobado |
| Tutor de aula inexistente | 403 | Aprobado |
| Material autorizado | 200 | Aprobado |
| Material restringido | 404 | Aprobado |
| Tutor sin autenticación | 302 | Aprobado |

Las solicitudes no autorizadas no devolvieron citas ni PDF restringidos. La primera ejecución obtuvo 5/6; tras corregir la codificación del script a UTF-8 con BOM, se obtuvo 6/6 sin modificar la aplicación. Los datos temporales fueron eliminados. Ambos materiales tenían el mismo contenido; el aislamiento se comprobó mediante identificadores y respuestas HTTP.

### 10.5. Pruebas de regresión

PHPUnit contabilizó 241 pruebas: 210 aprobadas, 31 omitidas, 743 aserciones y cero fallos. Las pruebas omitidas no se consideran aprobadas.

### 10.6. Versión evaluada

`localhost:5000/aulaviva-demo-tutor@sha256:0c0efc542a913d1147f5c8f8c044df6e7c7d9f1a8e52a3270bd0aaf17e532d12`

Imagen exclusiva de DEMO local, pendiente de validación mediante el pipeline oficial.

### 10.7. Interpretación

El Golden Set fue utilizado durante el desarrollo y no constituye una evaluación independiente. No se declara precisión global, inmunidad ante ataques ni resultados de RAGAS. Véase el [informe de evaluación](evaluacion.md).

## 11. Limitaciones conocidas

- El modelo generativo posee capacidad limitada en comparación con modelos de mayor tamaño.

- La recuperación se restringe a un máximo de tres fragmentos.
- La calidad depende del contenido y estructura de los PDF.
- No existe un mecanismo OCR integrado para documentos escaneados.
- Las gramáticas de extracción en español e inglés son acotadas.
- Las preguntas ambiguas pueden provocar abstenciones.
- Las relaciones complejas pueden no ser reconocidas.
- La validación conservadora puede rechazar respuestas correctas que no coincidan con una estructura comprobable.

- La inferencia depende de los recursos del equipo anfitrión.
- La disponibilidad del Tutor depende de Ollama y PostgreSQL.
- La comunicación local con Ollama no utiliza TLS.
- No existe una evaluación formal consolidada de sesgos.

- El Golden Set contiene diez casos y no es independiente.
- No se ejecutaron métricas automatizadas de RAGAS.
- Las seis pruebas de autorización no constituyen una auditoría exhaustiva.
- No se ha evaluado formalmente el rendimiento bajo concurrencia.
- La imagen DEMO está pendiente de validación por el pipeline oficial.
- Los resultados de DEMO no acreditan automáticamente DEV o PROD.

Estas limitaciones deben considerarse al interpretar los resultados del sistema.

## 12. Riesgos y mitigaciones

| Riesgo | Mitigación | Riesgo residual |
|---|---|---|
| Alucinaciones | RAG, EvidenceGate y validación posterior | No eliminado completamente |
| Prompt injection | Contexto autorizado y validación de salida | Requiere evaluación adversarial continua |
| Acceso indebido | Sesiones, roles, colegio y matrícula | Depende de la correcta autorización |
| Citas inventadas | Validación de referencias y páginas | Posibles errores de extracción o implementación |
| Respuestas incompletas | Checklist de hechos obligatorios | Abstenciones ante construcciones desconocidas |
| Indisponibilidad | Timeout y mensajes controlados | Dependencia del servicio local |
| Sesgos del modelo | Uso limitado a evidencia documental | Sesgos del modelo y materiales no eliminados |
| Exposición de datos | Inferencia local y registros limitados | Riesgos del equipo anfitrión y red local |

## 13. Privacidad y tratamiento de datos

El Tutor IA procesa preguntas de estudiantes y fragmentos de materiales educativos autorizados.

La inferencia se realiza localmente mediante Ollama, sin enviar las consultas a proveedores comerciales de IA.

Los registros operacionales incluyen estados, latencia, modelo y cantidad de fragmentos. Ante excepciones del endpoint también se registra el tipo de error, archivo y línea, sin incluir mensajes ni trazas completas.

Los registros implementados por el Tutor no incluyen deliberadamente el texto de preguntas ni materiales. Esto no acredita la ausencia de registros en todos los componentes de infraestructura.

La conversación permanece en la página y se elimina al recargarla. No se almacena como historial en PostgreSQL ni en el almacenamiento del navegador.

Los documentos y embeddings permanecen almacenados en la base de datos según el funcionamiento de AulaViva.

El procesamiento local reduce la exposición a terceros, pero no sustituye los controles de acceso, las políticas de retención ni las obligaciones legales de protección de datos.

El análisis de privacidad y marco legal está pendiente de completar en `docs/ai/privacidad.md`.

## 14. Supervisión humana

El Tutor IA funciona como herramienta de apoyo educativo.

Los profesores mantienen la responsabilidad sobre los materiales publicados y las actividades académicas.

Los estudiantes deben contrastar las respuestas con las fuentes presentadas y consultar al profesor cuando existan dudas.

El Tutor no determina calificaciones, promociones escolares ni decisiones disciplinarias.

## 15. Costos y recursos

La ejecución local evita cargos por consumo de tokens de proveedores comerciales.

Sin embargo, requiere recursos de:

- Procesamiento.
- Memoria RAM.
- Almacenamiento.
- Energía eléctrica.
- Operación y mantenimiento.

El costo por consumo de una API comercial es cero.

Esto no significa que el costo total de operación sea cero.

La estimación económica se desarrollará en la documentación

FinOps del proyecto.

## 16. Monitoreo y mantenimiento

Se deben revisar periódicamente:

- Estados de respuesta.
- Frecuencia de abstenciones.
- Fallos de validación.
- Errores de disponibilidad.
- Tiempos de respuesta.
- Consumo de recursos.
- Regresiones de seguridad y calidad.

Ante cambios de modelo, prompts, extracción o recuperación, se deben repetir las pruebas automatizadas y el Golden Set antes de aceptar una nueva versión.

## 17. Evidencias y referencias internas

### Implementación

- `config/tutor/AIProvider.php`
- `config/tutor/Embeddings.php`
- `config/tutor/EvidenceGate.php`
- `config/tutor/IngestaTutor.php`
- `config/tutor/PdfTextExtractor.php`
- `config/tutor/TutorEvidencia.php`
- `config/tutor/TutorHechosExplicitos.php`
- `config/tutor/TutorLexico.php`
- `config/tutor/TutorRetriever.php`
- `config/tutor/TutorService.php`
- `tutor.php`
- `tutor_ask.php`
- `js/tutor.js`
- `migrations/004_tutor_rag.sql`

### Pruebas

- `test/tutor-evidence.php`
- `tests/TutorEvidenceExtractionTest.php`
- `tests/TutorEvidenceRegressionTest.php`
- `tests/TutorIngestionTest.php`
- `tests/TutorServiceTest.php`

### Documentación

- `docs/tutor-rag-generation.md`
- `docs/tutor-rag-ingestion.md`
- `docs/tutor-demo.md`
- `docs/adr/0007-generacion-controlada-rag.md`
- `docs/adr/0008-inferencia-ia-local.md`
- `docs/c4/l2-container.png`
- `docs/c4/l3-component.png`

### Evaluación y evidencias experimentales

- [Informe de evaluación](evaluacion.md)
- [Script Golden Set](../../scripts/tutor-golden-set.ps1)
- [Script de autorización](../../scripts/tutor-security-test.ps1)
- [Regresión léxica](../../tests/TutorLexicoTest.php)
- [Golden Set inicial — 70 %](evidencias/golden-set-20261124-221518.json)
- [Golden Set V2 — 90 %](evidencias/golden-set-20261124-224115.json)
- [Golden Set V3 — 100 %](evidencias/golden-set-20261124-225333.json)
- [Golden Set final CSV](evidencias/golden-set-20261124-225333.csv)
- [Seguridad inicial — 5/6](evidencias/security-test-20261124-225801.json)
- [Seguridad final — 6/6](evidencias/security-test-20261124-225850.json)
- [Seguridad final CSV](evidencias/security-test-20261124-225850.csv)

## 18. Conclusión

El Tutor IA de AulaViva utiliza modelos locales y una arquitectura

RAG con controles de autorización, recuperación de evidencia y validación de respuestas.

La implementación prioriza la privacidad, la trazabilidad, la seguridad y la abstención ante información insuficiente.

Su propósito es complementar el aprendizaje mediante respuestas fundamentadas en materiales educativos, manteniendo la supervisión humana y reconociendo las limitaciones de la inteligencia artificial.

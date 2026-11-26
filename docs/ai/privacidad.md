# Privacidad y protección de datos personales — AulaViva

**Fecha:** 24 de noviembre de 2026
**Proyecto:** AulaViva
**Alcance:** Plataforma educativa y Tutor IA
**Estado:** Evaluación técnica y preparación para el cumplimiento normativo

## 1. Objetivo

Establecer los criterios de privacidad, seguridad y tratamiento responsable de los datos personales utilizados por AulaViva, considerando especialmente la protección de niños, niñas y adolescentes.

El documento identifica los controles implementados, los riesgos residuales y las medidas pendientes antes de una operación institucional con datos reales.

## 2. Marco normativo chileno

El proyecto considera las siguientes normas:

- Constitución Política de la República de Chile, artículo 19, número 4.
- Ley 19.628 sobre protección de la vida privada.
- Ley 21.719 sobre protección y tratamiento de datos personales.
- Ley 21.855, que modifica la Ley 21.719.

Al momento de elaborar este documento, la Ley 19.628 constituye el marco vigente de protección de datos personales.

La entrada en vigencia de la reforma establecida por la Ley 21.719 fue trasladada al 15 de octubre de 2027 mediante la Ley 21.855, publicada el 23 de noviembre de 2026.

AulaViva considera anticipadamente los requisitos de la nueva regulación como parte de su preparación para una futura implementación institucional.

## 3. Inventario de datos

| Categoría | Datos tratados | Finalidad |
|---|---|---|
| Identificación | Nombre, RUT y nombre de usuario | Identificar usuarios |
| Autenticación | Hash de contraseña y sesión | Controlar el acceso |
| Información académica | Matrículas, aulas y asignaturas | Gestionar actividades educativas |
| Evaluaciones | Respuestas, puntajes y calificaciones | Evaluar el aprendizaje |
| Vinculación familiar | Relación entre estudiante y apoderado | Facilitar seguimiento académico |
| Material educativo | PDF, texto y embeddings | Gestionar recursos de aprendizaje |
| Tutor IA | Preguntas y fragmentos recuperados | Generar respuestas fundamentadas |
| Información operacional | Estados, errores y latencias | Diagnosticar y monitorear el sistema |

Los documentos educativos y sus embeddings pueden contener o representar información personal. La generación de embeddings no implica automáticamente la anonimización de los datos.

## 4. Principios de tratamiento

AulaViva adopta como criterios de diseño:

- Licitud: contar con una base jurídica adecuada para el tratamiento.
- Finalidad: utilizar los datos exclusivamente para objetivos definidos.
- Proporcionalidad: limitar el tratamiento a la información necesaria.
- Calidad: mantener información correcta y actualizada.
- Seguridad: prevenir accesos, modificaciones y divulgaciones no autorizadas.
- Transparencia: informar a los usuarios sobre el tratamiento.
- Confidencialidad: restringir la información según las responsabilidades de cada usuario.
- Responsabilidad: documentar y demostrar las medidas adoptadas.

Estos principios orientan el diseño del sistema y no constituyen por sí mismos una certificación de cumplimiento.

## 5. Responsabilidad sobre los datos

Para una futura operación institucional, se propone que el establecimiento educacional actúe como responsable del tratamiento cuando determine sus finalidades y medios.

El operador de AulaViva podrá actuar como mandatario o encargado cuando procese información siguiendo las instrucciones del establecimiento.

La asignación definitiva de responsabilidades deberá establecerse mediante acuerdos institucionales y una revisión jurídica.

El entorno DEMO utiliza cuentas ficticias para las pruebas funcionales.

## 6. Protección de estudiantes menores de edad

Debido al contexto educativo, AulaViva debe considerar especialmente el interés superior de niños, niñas y adolescentes y su autonomía progresiva.

El artículo 16 quáter incorporado por la Ley 21.719 establece reglas especiales para el tratamiento de sus datos personales.

Entre las medidas consideradas se encuentran:

- Determinar la base jurídica aplicable.
- Obtener autorización de padres o representantes cuando corresponda legalmente.
- Limitar la recopilación de información personal.
- Evitar solicitar información sensible innecesaria.
- Verificar la relación entre estudiante y apoderado.
- Impedir el acceso de terceros no autorizados.
- Entregar información comprensible para estudiantes y representantes.

La vinculación de un apoderado dentro de AulaViva no equivale automáticamente al consentimiento legal para todos los tratamientos de datos.

La implementación formal de avisos y procedimientos de autorización permanece pendiente.

## 7. Privacidad del Tutor IA

El Tutor IA utiliza los modelos Qwen3:1.7b y nomic-embed-text mediante Ollama.

En el entorno DEMO, la inferencia se realiza localmente sin enviar las preguntas a proveedores comerciales de inteligencia artificial.

El flujo de tratamiento es el siguiente:

1. El estudiante inicia sesión.
2. El backend verifica su rol y matrícula.
3. El estudiante formula una pregunta.
4. El sistema recupera fragmentos autorizados.
5. Ollama procesa la pregunta y la evidencia necesaria.
6. El backend valida la respuesta y sus citas.
7. El estudiante recibe una respuesta fundamentada o una abstención.

La aplicación no implementa un historial persistente de conversaciones del Tutor.

Los documentos y embeddings permanecen almacenados en PostgreSQL.

La implementación del Tutor no registra deliberadamente el texto de las preguntas ni de los materiales en sus logs operacionales.

La revisión de todos los registros del sistema anfitrión y de los servicios auxiliares permanece pendiente.

## 8. Control de acceso

| Rol | Acceso previsto |
|---|---|
| Administrador | Gestión de las funcionalidades administrativas autorizadas |
| Profesor | Materiales y evaluaciones de sus aulas |
| Estudiante | Aulas matriculadas, materiales y evaluaciones autorizadas |
| Apoderado | Información académica de estudiantes vinculados y confirmados |

El control de autorización corresponde al backend y no al modelo generativo.

El Tutor no puede conceder permisos ni modificar las matrículas de los estudiantes.

### Evidencia experimental

Se ejecutaron seis pruebas de autorización en DEMO local:

| Escenario | HTTP | Resultado |
|---|---:|---|
| Tutor de aula autorizada | 200 | Aprobado |
| Tutor de aula restringida | 403 | Aprobado |
| Tutor de aula inexistente | 403 | Aprobado |
| Material autorizado | 200 | Aprobado |
| Material restringido | 404 | Aprobado |
| Tutor sin autenticación | 302 | Aprobado |

Resultado: 6 de 6 casos aprobados.

Las solicitudes restringidas no devolvieron citas ni documentos PDF.

Estas pruebas acreditan los escenarios evaluados y no constituyen una auditoría exhaustiva de autorización.

## 9. Medidas técnicas de seguridad

| Control | Estado |
|---|---|
| Contraseñas almacenadas mediante hash | Implementado |
| Autenticación mediante sesiones PHP | Implementado |
| Autorización basada en roles y relaciones académicas | Implementado |
| Consultas parametrizadas | Implementado en los componentes evaluados |
| Restricción de acceso a documentos | Verificado en DEMO |
| Aislamiento de recuperación RAG por aula | Verificado en los escenarios evaluados |
| Rol PostgreSQL con privilegios restringidos | Implementado en DEMO |
| TLS 1.3 y validación del certificado de PostgreSQL | Verificado en DEMO |
| Contenedor sin privilegios de administrador | Implementado |
| Sistema de archivos raíz de solo lectura | Implementado |
| Cookie HttpOnly, Secure y SameSite | Verificado en DEMO |
| HTTPS de extremo a extremo | Pendiente en DEMO |
| TLS entre aplicación y Ollama | Pendiente |
| Política formal de retención | Pendiente |
| Cifrado de datos en reposo | Pendiente de verificar |
| Monitoreo continuo de seguridad | Pendiente de validación operacional |

La presencia de una cookie Secure en DEMO no implica que las comunicaciones HTTP locales estén cifradas.

## 10. Retención y eliminación de datos

Los períodos de conservación deberán definirse conjuntamente con el establecimiento educacional, considerando los fines académicos y las obligaciones legales aplicables.

| Información | Criterio propuesto |
|---|---|
| Cuentas de usuario | Mientras exista una relación institucional y una finalidad válida |
| Evaluaciones y calificaciones | Según obligaciones académicas y normativas |
| Materiales educativos | Mientras sean necesarios para las actividades autorizadas |
| Fragmentos y embeddings | Asociados al ciclo de vida del material original |
| Conversaciones del Tutor | Sin historial persistente en la aplicación actual |
| Logs operacionales | Período limitado según necesidades de seguridad |
| Respaldos | Ciclo de conservación y eliminación definido institucionalmente |

Los plazos específicos y los procedimientos de eliminación segura deberán formalizarse antes de operar con datos reales.

La eliminación de información deberá considerar tanto los datos originales como sus representaciones derivadas y respaldos.

## 11. Derechos de los titulares

El proyecto contempla la necesidad de establecer mecanismos para atender los derechos reconocidos por la normativa aplicable, incluyendo:

- Acceso a los datos personales.
- Rectificación de información incorrecta.
- Eliminación o supresión cuando corresponda.
- Oposición al tratamiento.
- Portabilidad en los casos legalmente aplicables.
- Bloqueo del tratamiento cuando corresponda.

La implementación de un canal formal de solicitudes, mecanismos de identificación del solicitante, procedimientos y responsables permanece pendiente.

## 12. Transferencias y proveedores externos

El entorno DEMO utiliza inferencia local con Ollama y almacenamiento local mediante PostgreSQL.

No requiere una API comercial de inteligencia artificial para responder preguntas.

Esto reduce la exposición a proveedores externos, pero no implica que todos los componentes del proyecto funcionen sin servicios de terceros.

El repositorio, registro de imágenes, dependencias y recursos web externos deben considerarse dentro del análisis de proveedores.

Antes de una implementación institucional se deberán identificar los posibles destinatarios de información y evaluar las condiciones de tratamiento y transferencia aplicables.

## 13. Gestión de incidentes

Ante un posible incidente que afecte datos personales, se propone el siguiente procedimiento:

1. Detectar y registrar el incidente.
2. Contener el acceso o proceso comprometido.
3. Preservar las evidencias necesarias.
4. Identificar los datos y usuarios afectados.
5. Evaluar el impacto y las obligaciones de comunicación aplicables.
6. Corregir la causa del incidente.
7. Recuperar los servicios de manera controlada.
8. Documentar las acciones y medidas preventivas.

El procedimiento deberá integrarse con el runbook operacional y el plan de respuesta a incidentes.

Los responsables institucionales y mecanismos de notificación deberán definirse antes de una operación real.

## 14. Inteligencia artificial responsable

El Tutor IA se utiliza exclusivamente como herramienta de apoyo educativo.

No determina calificaciones, promociones escolares ni decisiones disciplinarias.

Las respuestas están sujetas a controles de evidencia, autorización y validación.

Cuando no existe información suficiente, el sistema debe abstenerse en lugar de generar afirmaciones sin respaldo.

Los profesores conservan la responsabilidad sobre las actividades académicas y los materiales publicados.

No se ha realizado una evaluación formal de sesgos del modelo ni una evaluación integral de impacto sobre los derechos de los estudiantes.

## 15. Riesgos residuales

| Riesgo | Mitigación | Estado residual |
|---|---|---|
| Acceso no autorizado | Roles, sesiones y validación de matrículas | Requiere pruebas continuas |
| Exposición de datos de menores | Minimización y acceso restringido | Requiere políticas institucionales |
| Prompt injection | EvidenceGate y validación de respuestas | No eliminado completamente |
| Interceptación de comunicaciones | TLS en PostgreSQL | HTTPS y TLS de Ollama pendientes |
| Filtración mediante logs | Registro operacional limitado | Revisión integral pendiente |
| Conservación excesiva | Política de retención propuesta | Pendiente de formalización |
| Pérdida de datos | Respaldos y procedimientos de restauración | Requiere estrategia operacional |
| Sesgos de inteligencia artificial | Respuestas limitadas a evidencia | Evaluación formal pendiente |

## 16. Plan de preparación normativa

| Acción | Prioridad | Estado |
|---|---|---|
| Inventariar los tratamientos de datos | Alta | Documentado inicialmente |
| Definir responsables institucionales | Alta | Pendiente |
| Establecer bases jurídicas de tratamiento | Alta | Pendiente |
| Preparar avisos de privacidad | Alta | Pendiente |
| Definir procedimientos para datos de menores | Alta | Pendiente |
| Implementar HTTPS institucional | Alta | Pendiente |
| Formalizar retención y eliminación | Alta | Pendiente |
| Evaluar riesgos e impacto en privacidad | Alta | Pendiente |
| Establecer procedimientos de atención de derechos | Alta | Pendiente |
| Formalizar gestión de incidentes de privacidad | Alta | Pendiente |
| Evaluar sesgos y riesgos del Tutor | Media | Pendiente |
| Revisar proveedores y transferencias | Alta | Pendiente |

Las acciones deberán validarse con el establecimiento educacional y los responsables jurídicos y técnicos correspondientes.

## 17. Evidencias y referencias

### Documentación interna

- [Model Card del Tutor IA](model-card.md)
- [Evaluación del Tutor IA](evaluacion.md)
- [Pruebas finales de autorización](evidencias/security-test-20261124-225850.json)
- [Golden Set final](evidencias/golden-set-20261124-225333.json)
- [Arquitectura de inferencia local](../adr/0008-inferencia-ia-local.md)
- [Aislamiento de entornos](../adr/0009-aislamiento-entornos.md)

### Fuentes normativas

- [Ley 19.628 — Biblioteca del Congreso Nacional](https://www.bcn.cl/leychile/navegar?idNorma=141599)
- [Ley 21.719 — Biblioteca del Congreso Nacional](https://www.bcn.cl/leychile/navegar?idNorma=1209272)
- [Ley 21.855 — Biblioteca del Congreso Nacional](https://www.bcn.cl/leychile/navegar?idNorma=1230520)

Fecha de revisión normativa: 24 de noviembre de 2026.

## 18. Conclusión

AulaViva incorpora controles técnicos orientados a la protección de datos personales y la seguridad de la información académica.

La inferencia local, el aislamiento de aulas, el control de acceso y la validación de evidencia contribuyen a reducir los riesgos asociados al tratamiento de información mediante inteligencia artificial.

Las pruebas realizadas en DEMO respaldan el funcionamiento de los controles en los escenarios evaluados.

Sin embargo, el cumplimiento normativo requiere medidas técnicas, jurídicas y organizacionales adicionales.

Por tanto, AulaViva presenta un avance verificable en privacidad y seguridad desde el diseño, sin declarar un cumplimiento legal integral ni una certificación formal.

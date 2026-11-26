# Post-mortem de resiliencia — AulaViva

## 1. Información general

- Proyecto: AulaViva.
- Fecha del ejercicio: 24 de noviembre de 2026.
- Entorno: DEMO local.
- Tipo de análisis: Post-mortem de pruebas controladas de resiliencia.
- Estado del servicio: recuperado.
- Estado del ejercicio: completado.
- Enfoque: análisis sin culpabilización.

Este documento analiza los fallos simulados, las dificultades operacionales y las oportunidades de mejora identificadas durante la preparación y ejecución del Game Day.

No corresponde a un incidente real de producción.

## 2. Resumen ejecutivo

Durante el Game Day se evaluaron los mecanismos de recuperación de AulaViva mediante seis etapas controladas.

La aplicación recuperó automáticamente un Pod eliminado en 12,373 segundos. Ante la indisponibilidad simulada de Ollama, el Tutor IA respondió mediante errores controlados sin generar respuestas educativas falsas en los casos evaluados.

La indisponibilidad temporal de PostgreSQL provocó que la aplicación dejara de estar preparada para recibir tráfico, sin generar reinicios innecesarios.

La restauración lógica de PostgreSQL se completó en 1,28 segundos y las 16 tablas comparadas presentaron contenido coincidente.

Finalmente, se verificaron las funcionalidades de consulta de los cuatro roles y se ejecutó nuevamente el Golden Set, obteniendo diez casos aprobados.

Los resultados permitieron identificar fortalezas y dependencias operacionales que requieren mejoras antes de considerar una recuperación integral del servicio.

## 3. Impacto observado

| Componente | Impacto durante las pruebas |
|---|---|
| Aplicación web | Interrupción temporal durante la sustitución del Pod. |
| Tutor IA | Indisponibilidad controlada durante el fallo simulado de conectividad. |
| PostgreSQL | Interrupción temporal de las operaciones dependientes de la base de datos. |
| Registros académicos | No se detectaron pérdidas en las comprobaciones realizadas. |
| Embeddings | Los 39 vectores fueron recuperados correctamente. |
| Usuarios DEMO | Funcionalidades de consulta verificadas después del ejercicio. |
| DEV y PROD | No fueron intervenidos directamente durante el Game Day. |

No se evaluó el impacto sobre usuarios reales ni sobre tráfico de producción.

## 4. Cronología del ejercicio

Los horarios corresponden a la hora local del equipo utilizado.

| Hora aproximada | Evento |
|---|---|
| 23:06 | Generación del respaldo preventivo GD00. |
| 23:07 | Eliminación controlada del Pod y recuperación automática GD01. |
| 23:13 | Comprobación de indisponibilidad simulada de Ollama GD02. |
| 23:14 | Inicio de la restauración de la configuración de Ollama. |
| 23:21 | Comprobación de indisponibilidad y recuperación de PostgreSQL GD03. |
| 23:22 | Restauración de PostgreSQL en una base independiente GD04. |
| 23:24 | Validación final de infraestructura GD05. |
| 23:26 | Ejecución del Golden Set posterior al ejercicio. |
| 23:30–23:34 | Validación manual de los cuatro roles. |

La cronología se basa en los registros, archivos de evidencia y comprobaciones realizadas durante el ejercicio.

## 5. Análisis de fallos simulados

### 5.1. Eliminación del Pod

**Causa introducida:** eliminación controlada del Pod activo.

**Impacto:** interrupción temporal de la instancia de aplicación.

**Detección:** Kubernetes identificó la ausencia del Pod administrado por el Deployment.

**Recuperación:** creación automática de un Pod de reemplazo.

**Resultado:** recuperación del estado Ready en 12,373 segundos.

**Aprendizaje:** el mecanismo de reconciliación de Kubernetes permite recuperar una instancia eliminada, pero una sola réplica no garantiza continuidad del servicio durante su sustitución.

### 5.2. Indisponibilidad de Ollama

**Causa introducida:** modificación temporal de la dirección de conexión al proveedor de inteligencia artificial.

**Impacto:** el Tutor IA no pudo acceder a Ollama.

**Detección:** errores controlados del proveedor y registros de aplicación.

**Comportamiento observado:**

- Tres solicitudes devolvieron HTTP 503.
- El estado registrado fue GENERATION_UNAVAILABLE.
- Liveness y readiness permanecieron en HTTP 200.
- No se observaron respuestas educativas falsas durante las consultas evaluadas.

**Recuperación:** restablecimiento manual de la configuración original y actualización del Deployment.

**Resultado:** restauración del Deployment en 12,513 segundos y comprobación funcional posterior del Tutor.

**Aprendizaje:** el Tutor puede degradarse de manera controlada sin afectar los endpoints de salud principales. Sin embargo, se requiere monitoreo específico para detectar la indisponibilidad de Ollama.

La prueba no consistió en detener el proceso real de Ollama.

### 5.3. Indisponibilidad de PostgreSQL

**Causa introducida:** suspensión temporal de los procesos del contenedor mediante docker pause.

**Impacto:** indisponibilidad de las operaciones dependientes de PostgreSQL.

**Detección:** fallo de la sonda de readiness.

**Comportamiento observado:**

- Liveness HTTP 200.
- Readiness HTTP 503.
- Pod Running con estado Ready 0/1.
- Cero reinicios.

**Recuperación:** reactivación manual del contenedor mediante docker unpause.

**Resultado:** recuperación del estado Ready, endpoints HTTP 200 y persistencia de los registros comprobados.

**Aprendizaje:** separar liveness y readiness evita reinicios innecesarios cuando el problema corresponde a una dependencia externa.

No se midió el tiempo exacto de recuperación de PostgreSQL ni se demostró recuperación automática de la base de datos.

### 5.4. Restauración de datos

**Escenario:** recuperación de información desde un respaldo lógico previamente generado.

**Procedimiento:** creación de una base independiente y restauración mediante pg_restore.

**Resultado:**

- Tiempo de creación y restauración: 1,28 segundos.
- Tablas recuperadas: 16.
- Tablas con contenido coincidente: 16/16.
- Embeddings recuperados: 39.
- Dimensiones vectoriales: 768.

**Aprendizaje:** el respaldo lógico permite recuperar los datos evaluados, pero la restauración dentro del mismo servidor no protege frente a la pérdida completa del equipo.

Los propietarios y permisos originales quedaron fuera del alcance de esta prueba.

## 6. Problemas reales identificados durante la preparación

### 6.1. Descarga de imágenes por digest

**Problema:** durante el despliegue de la imagen DEMO V3 se observaron errores ImagePullBackOff y respuestas HTTP 500 del mecanismo intermediario de descarga.

**Impacto:** retraso en el despliegue de la aplicación.

**Solución temporal aplicada:**

1. Descargar la imagen mediante su etiqueta V3 utilizando un Pod auxiliar.
2. Verificar el digest de la imagen descargada.
3. Utilizar la imagen disponible localmente.
4. Comprobar el estado del Deployment.

**Estado:** mitigado.

**Causa raíz:** no confirmada. Se requiere investigar el comportamiento del registro intermediario y su resolución de imágenes por digest.

### 6.2. Interrupción del port-forward

**Problema:** después de sustituir un Pod o actualizar el Deployment, el acceso mediante localhost podía dejar de responder debido a una sesión anterior de port-forward.

**Impacto:** interrupción temporal del acceso desde el navegador, incluso cuando el Deployment estaba disponible.

**Solución aplicada:** identificar y cerrar exclusivamente el proceso anterior de port-forward y establecer una nueva conexión.

**Estado:** mitigado mediante procedimiento manual.

**Acción preventiva:** automatizar la comprobación y recuperación del acceso local.

### 6.3. Bloqueo de scripts PowerShell

**Problema:** la política de ejecución de una nueva sesión de PowerShell impidió ejecutar el Golden Set.

**Impacto:** retraso en la validación posterior al Game Day.

**Causa:** la autorización temporal de ejecución utilizada anteriormente no permanecía activa en la sesión actual.

**Solución aplicada:** habilitar la ejecución exclusivamente para el proceso actual de PowerShell y ejecutar nuevamente el script revisado.

**Estado:** resuelto para la sesión de trabajo.

No se modificó permanentemente la política de seguridad del sistema operativo.

### 6.4. Regresiones del Tutor IA detectadas antes del Game Day

Durante la evaluación inicial se identificaron dificultades para interpretar preguntas directas sobre mediciones y componentes de un material educativo.

También se detectó un error de tipos en TutorLexico.php al procesar determinados términos numéricos.

Se incorporaron correcciones y pruebas de regresión.

| Versión | Golden Set |
|---|---|
| V1 | 7/10 |
| V2 | 9/10 |
| V3 | 10/10 |
| V3 después del Game Day | 10/10 |

**Aprendizaje:** las pruebas automatizadas y la validación con el modelo real permiten identificar errores que no necesariamente aparecen en una demostración manual limitada.

Los resultados corresponden a un conjunto reducido de desarrollo y no representan una evaluación independiente de precisión general.

## 7. Aspectos que funcionaron correctamente

- Recuperación automática del Pod mediante Kubernetes.
- Separación de las sondas de liveness y readiness.
- Detección de indisponibilidad de PostgreSQL.
- Degradación controlada del Tutor IA.
- Persistencia de los registros académicos.
- Generación y verificación de respaldos.
- Restauración lógica de datos y embeddings.
- Comparación del contenido de las 16 tablas.
- Validación funcional posterior de los cuatro roles.
- Ejecución satisfactoria del Golden Set posterior al ejercicio.

## 8. Aspectos que requieren mejoras

- Dependencia de un único equipo físico.
- Ausencia de redundancia de la aplicación y PostgreSQL.
- Respaldos almacenados localmente.
- Recuperación manual de determinadas dependencias.
- Ausencia de monitoreo continuo de disponibilidad.
- Dependencia del port-forward para el acceso local.
- Problemas de descarga de imágenes por digest.
- Falta de validación integral de propietarios y permisos restaurados.
- Ausencia de pruebas de recuperación sobre un equipo alternativo.
- Cobertura limitada de las operaciones funcionales posteriores a la recuperación.

## 9. Acciones correctivas y preventivas

| ID | Acción | Prioridad | Estado |
|---|---|---|---|
| AC01 | Automatizar el inicio y verificación del entorno DEMO. | Alta | Pendiente |
| AC02 | Automatizar respaldos diarios de PostgreSQL. | Alta | Pendiente |
| AC03 | Implementar copias externas cifradas. | Alta | Pendiente |
| AC04 | Automatizar la recuperación del port-forward. | Alta | Pendiente |
| AC05 | Investigar el error de descarga de imágenes por digest. | Alta | Mitigado temporalmente |
| AC06 | Incorporar monitoreo y alertas específicas para Ollama. | Media | Pendiente |
| AC07 | Implementar medición continua de disponibilidad y latencia. | Media | Pendiente |
| AC08 | Validar restauración de propietarios y permisos. | Alta | Pendiente |
| AC09 | Realizar una recuperación completa sobre otro equipo. | Alta | Pendiente |
| AC10 | Ampliar las pruebas funcionales y de seguridad. | Media | Pendiente |
| AC11 | Mantener las pruebas de regresión del Tutor IA. | Alta | Implementado |
| AC12 | Documentar procedimientos operacionales y de recuperación. | Alta | Elaborado |

Las prioridades corresponden a una propuesta de planificación y deberán ser revisadas por el equipo del proyecto.

## 10. Responsabilidades propuestas

| Área | Responsabilidad |
|---|---|
| Operaciones | Automatización, monitoreo y recuperación de infraestructura. |
| Datos | Respaldos, restauración, integridad y permisos. |
| Desarrollo | Corrección de errores y pruebas de regresión. |
| Seguridad | Protección de secretos, accesos y evidencias. |
| Equipo del proyecto | Priorización y seguimiento de acciones correctivas. |

La asignación de responsables individuales y fechas de cumplimiento deberá acordarse entre los integrantes del equipo.

## 11. Criterios de cierre

El ejercicio se considera completado dentro del alcance establecido porque:

- Las seis etapas planificadas fueron ejecutadas.
- Los componentes evaluados recuperaron su funcionamiento.
- Los endpoints de salud devolvieron HTTP 200.
- Las 16 tablas comparadas presentaron contenido coincidente.
- Los cuatro roles superaron las comprobaciones funcionales definidas.
- El Golden Set posterior obtuvo diez casos aprobados.
- Se identificaron acciones correctivas y preventivas.

El cierre del Game Day no implica que todas las acciones de mejora estén implementadas ni que los riesgos residuales hayan sido eliminados.

## 12. Riesgos residuales

El entorno DEMO mantiene puntos únicos de fallo relacionados con el equipo físico, PostgreSQL, Kubernetes y el almacenamiento local.

Los objetivos de disponibilidad mensual, RTO integral y RPO todavía requieren validación continua y pruebas adicionales.

La aplicación utiliza una imagen local que no corresponde a una imagen firmada mediante el pipeline de producción.

La recuperación lógica de datos no demuestra recuperación completa de infraestructura, certificados, secretos ni permisos.

Estos riesgos deben mantenerse visibles durante la evaluación y planificación del proyecto.

## 13. Evidencias

- [Game Day completo](game-day.md).
- [Objetivos operacionales](slo.md).
- [Plan de recuperación ante desastres](dr-plan.md).
- [Runbook operacional](runbook.md).
- [Recuperación del Pod](evidencias/GD01-recuperacion.json).
- [Fallo de Ollama](evidencias/GD02-fallo-ollama.txt).
- [Recuperación del Deployment](evidencias/GD02-recuperacion.json).
- [PostgreSQL durante el fallo](evidencias/GD03-durante.txt).
- [PostgreSQL recuperado](evidencias/GD03-despues.txt).
- [Restauración lógica](evidencias/GD04-restauracion.json).
- [Integridad de datos](evidencias/GD04-integridad-datos.json).
- [Infraestructura final](evidencias/GD05-infraestructura.txt).
- [Golden Set posterior al Game Day](../ai/evidencias/golden-set-20261124-232620.json).
- [Evaluación del Tutor IA](../ai/evaluacion.md).

## 14. Conclusión

El Game Day permitió comprobar que AulaViva dispone de mecanismos efectivos para detectar y recuperarse de los fallos controlados evaluados.

Los resultados demostraron recuperación automática de la aplicación, degradación controlada del Tutor IA y restauración lógica de los datos académicos.

El análisis también permitió identificar limitaciones operacionales y establecer acciones concretas de mejora.

El enfoque del Post-mortem se concentra en fortalecer el sistema y sus procedimientos, evitando atribuir responsabilidades individuales por los fallos.

La resiliencia debe mantenerse como un proceso de mejora continua mediante pruebas periódicas, monitoreo, automatización y revisión de los riesgos existentes.
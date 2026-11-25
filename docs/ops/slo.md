# SLI, SLO y objetivos operacionales — AulaViva

## 1. Propósito

Este documento establece los indicadores y objetivos de nivel de servicio de AulaViva, considerando la disponibilidad, el rendimiento, la calidad del Tutor IA y la capacidad de recuperación ante fallos.

Los resultados experimentales corresponden al entorno DEMO local y al Game Day realizado el 24 de noviembre de 2026.

## 2. Alcance

El sistema evaluado está compuesto por:

- Aplicación PHP y Apache desplegada en Kubernetes.
- PostgreSQL 16 con pgvector 0.8.7.
- Tutor IA mediante Ollama.
- Modelo generativo Qwen3:1.7b.
- Modelo de embeddings nomic-embed-text.
- Cuatro roles: profesor, alumno, apoderado y administrador.

El entorno DEMO funciona de manera independiente de DEV y PROD.

## 3. Indicadores de nivel de servicio (SLI)

| SLI | Método de medición |
|---|---|
| Disponibilidad | Proporción de comprobaciones exitosas de readiness durante el período de servicio. |
| Liveness | Respuesta HTTP del endpoint `/health/live.php`. |
| Readiness | Respuesta HTTP del endpoint `/health/ready.php`, incluyendo conectividad con PostgreSQL. |
| Latencia del Tutor IA | Tiempo transcurrido entre la solicitud y la recepción de la respuesta. |
| Calidad del Tutor IA | Proporción de casos aprobados del Golden Set. |
| Recuperación del Pod | Tiempo desde la eliminación del Pod hasta que su reemplazo alcanza el estado Ready. |
| Restauración de datos | Tiempo de creación y restauración de una base de datos independiente. |

## 4. Objetivos de nivel de servicio (SLO)

| Indicador | Objetivo propuesto |
|---|---|
| Disponibilidad mensual | ≥ 99,5% durante el horario de servicio definido. |
| Latencia promedio del Tutor IA | ≤ 5 segundos en el Golden Set. |
| Latencia máxima del Golden Set | ≤ 15 segundos. |
| Calidad del Golden Set | 100% de los casos definidos. |
| Recuperación del Pod | ≤ 60 segundos. |
| Restauración lógica de PostgreSQL | ≤ 300 segundos. |
| Integridad de datos restaurados | Coincidencia de las 16 tablas evaluadas. |

Estos objetivos son metas operacionales propuestas y no constituyen garantías contractuales de disponibilidad.

## 5. Presupuesto de errores

Para un SLO de disponibilidad del 99,5%, el presupuesto de errores corresponde al 0,5% del tiempo de servicio.

En un escenario hipotético de funcionamiento continuo durante 30 días:

- Tiempo total: 43.200 minutos.
- Presupuesto de errores: 216 minutos.
- Indisponibilidad permitida: 3 horas y 36 minutos.

En el entorno DEMO, el presupuesto efectivo deberá calcularse sobre el horario de funcionamiento acordado.

No existe todavía una medición continua que permita determinar el consumo real del presupuesto de errores.

## 6. Resultados del Game Day

| Prueba | Resultado observado | Evaluación |
|---|---|---|
| GD00: respaldo preventivo | Integridad SHA-256 verificada. | Aprobado |
| GD01: recuperación del Pod | 12,373 segundos. | Aprobado |
| GD02: indisponibilidad de Ollama | HTTP 503 controlado y restauración del Deployment en 12,513 segundos. | Aprobado |
| GD03: indisponibilidad de PostgreSQL | Readiness HTTP 503, liveness HTTP 200 y cero reinicios. | Aprobado |
| GD04: restauración de datos | 1,28 segundos y 16 tablas coincidentes mediante comparación de huellas. | Aprobado |
| GD05: validación funcional | Cuatro roles verificados y Golden Set 10/10. | Aprobado |

La recuperación de PostgreSQL fue manual mediante la reactivación del contenedor. No se midió su tiempo exacto de recuperación.

## 7. Rendimiento del Tutor IA

Resultados posteriores al Game Day:

- Casos evaluados: 10.
- Casos aprobados: 10.
- Respuestas correctas con evidencia: 5/5.
- Abstenciones correctas: 3/3.
- Controles de seguridad del Golden Set: 2/2.
- Latencia promedio: 1,984 segundos.
- Latencia mínima: 0,382 segundos.
- Latencia máxima: 10,122 segundos.
- Errores HTTP durante la evaluación: 0.

El conjunto evaluado es reducido y fue utilizado durante el desarrollo. Sus resultados no representan una estimación general de precisión del modelo.

## 8. Objetivos de recuperación

Se proponen los siguientes objetivos:

- RTO del servicio completo: 15 minutos.
- RPO: 24 horas.
- Objetivo de recuperación del Pod: 60 segundos.
- Objetivo de restauración lógica de datos: 5 minutos.

El RTO completo y el RPO todavía no han sido validados integralmente.

La restauración lógica medida no incluye la reconstrucción del servidor, certificados, permisos, configuración de red ni recuperación de un equipo físico perdido.

El cumplimiento del RPO requiere implementar y verificar respaldos periódicos.

## 9. Mecanismos de resiliencia

- Liveness independiente de PostgreSQL.
- Readiness dependiente de la disponibilidad de PostgreSQL.
- Recuperación automática del Pod mediante Kubernetes.
- Persistencia de datos mediante volumen Docker.
- Límites de tiempo para conexiones y consultas.
- Abstención del Tutor IA ante evidencia insuficiente.
- Degradación controlada ante indisponibilidad de Ollama.
- Respaldo y restauración lógica de PostgreSQL.

## 10. Evidencias

- [Recuperación del Pod](evidencias/GD01-recuperacion.json)
- [Recuperación de Ollama](evidencias/GD02-recuperacion.json)
- [PostgreSQL durante el fallo](evidencias/GD03-durante.txt)
- [PostgreSQL después del fallo](evidencias/GD03-despues.txt)
- [Restauración del respaldo](evidencias/GD04-restauracion.json)
- [Integridad de las tablas](evidencias/GD04-integridad-datos.json)
- [Validación final de infraestructura](evidencias/GD05-infraestructura.txt)
- [Golden Set posterior al Game Day](../ai/evidencias/golden-set-20261124-232620.json)

## 11. Limitaciones y mejoras pendientes

- El entorno DEMO utiliza un único equipo físico.
- La aplicación dispone de una sola réplica.
- No existe alta disponibilidad de PostgreSQL.
- Los respaldos permanecen en el equipo local.
- No existe todavía monitoreo continuo para calcular la disponibilidad mensual.
- La restauración fue realizada dentro del mismo servidor PostgreSQL.
- Los propietarios y permisos originales no fueron restaurados durante GD04.
- Las comprobaciones finales de los roles se concentraron principalmente en operaciones de consulta.

Se recomienda implementar monitoreo continuo, respaldos automatizados, copias externas protegidas y pruebas periódicas de recuperación.

## 12. Conclusión

AulaViva demostró capacidad de recuperación ante los fallos controlados evaluados durante el Game Day. Las pruebas permitieron comprobar la persistencia de los datos, la recuperación del Pod, la degradación controlada del Tutor IA y la restauración lógica de PostgreSQL.

Los resultados constituyen evidencia experimental de resiliencia en el entorno DEMO, sin implicar una garantía de alta disponibilidad en producción.
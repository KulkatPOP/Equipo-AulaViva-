# Atributos de calidad prioritarios — AulaViva

Referencia: implementación en `main` (`f908f01`, 22 de noviembre de 2026).
Se conserva el orden de prioridades del documento S03. Los objetivos indicados
son criterios de evaluación, **no resultados medidos ni garantías operativas**.
Los marcadores de cita sin fuente del original no se trasladan como evidencia.

| Prioridad | Atributo | Métrica / escenario | Impacto arquitectónico y situación actual |
| --- | --- | --- | --- |
| 1 | Seguridad y privacidad | Rechazar consultas y escrituras de otro colegio, aula no autorizada o estudiante sin vínculo confirmado. Una invitación solo puede crear una cuenta; CSRF inválido debe impedir la activación. | Sesiones PHP y RBAC en servidor, consultas preparadas y autorización por colegio, matrícula/asignación o vínculo. Activación transaccional con bloqueo de fila. El PDF se sirve mediante un handler autorizado. No JWT ni IAM cloud atribuido a la aplicación. Ver `config/activacion.php`, `config/apoderados.php`, `config/materiales.php` y `config/tutor/TutorService.php`. |
| 2 | Disponibilidad | Conservar como objetivo histórico 99,5 % en horario escolar 08:00–18:00, pendiente de medición y definición operativa. Un fallo del Tutor debe devolver un error controlado sin eliminar materiales ni resultados. | Kubernetes/GitOps permiten operación declarativa; no demuestran redundancia ni cumplimiento del objetivo. Tutor con timeout configurable y manejo de errores. CLI de rendición para cierre de intentos vencidos; debe operarse explícitamente. No se afirma circuit breaker, failover ni cero caídas. |
| 3 | Rendimiento | Objetivo histórico p95 < 500 ms para navegación y carga de materiales, pendiente de definición operativa y medición. No se ha definido si el intervalo corresponde a TTFB, inicio de transferencia, carga completa u otro criterio. Medir Tutor por separado contra su timeout configurado y registrar abstenciones/timeouts. | PDO PostgreSQL, índices de migraciones y recuperación híbrida con máximo tres fragmentos finales. Materiales en bytea; inferencia síncrona mediante Ollama. El límite del Tutor se configura con `TUTOR_AI_TIMEOUT_SECONDS`; no es un SLA de latencia ni prueba de caché Redis. |
| 4 | Escalabilidad | Ante pruebas de carga concurrente, no duplicar activaciones ni entregas por carreras; medir capacidad y latencia antes de aumentar réplicas. No hay una capacidad concurrente certificada. | Aplicación contenerizada y transacciones/bloqueos PostgreSQL. Sesiones PHP sin almacén compartido configurado: no asumir API stateless o autoscaling inmediato. Dimensionar también PostgreSQL, almacenamiento bytea, procesos CLI y Ollama. |
| 5 | Mantenibilidad | Cada cambio en una responsabilidad debe pasar las pruebas correspondientes y la regresión sin alterar flujos US01–US05 ajenos. | Una aplicación PHP con helpers y clases reutilizables: `config/materiales.php`, `config/evaluaciones.php`, `config/rendicion.php`, `config/apoderados.php`, `config/activacion.php` y `config/tutor/`. No se afirma aislamiento estricto por paquetes o esquemas de dominio. |
| 6 | Observabilidad | Poder comprobar estado de ingesta, entregas y eventos Outbox sin registrar contraseñas, token de activación completo ni errores SQL sensibles. No se declara cobertura total de métricas o trazas. | Logs Apache sin query ni Referer en el formato configurado; estados persistidos de ingesta y Outbox, errores controlados y reportes CI. La telemetría integral y los correlation IDs de todas las peticiones no están demostrados. Ver `deploy/security.conf` y `cli/rendicion.php`. |
| 7 | Costo | Medir recursos y gasto real por entorno; no existe un presupuesto mensual validado en esta vista. Mantener el Tutor operable sin pagar una API externa. | Ollama local y PostgreSQL/pgvector compartido para datos y vectores. Controlar RAM/CPU, volumen de PDF/embeddings y concurrencia. La ausencia de API key no elimina costos de hardware, electricidad u operación. No se presupone proveedor cloud gestionado. |
| 8 | Interoperabilidad | Mantener coherencia entre rutas REST implementadas, OpenAPI y cliente TypeScript; ejecutar los controles de contrato del CI. | JSON, contrato `api/openapi.yaml`, cliente `packages/api-client/` y errores API RFC 7807. Las páginas PHP no requieren crear nuevos endpoints para cada función. Integración SIGE u otros sistemas institucionales no implementada ni requisito operativo asumido. |
| 9 | Usabilidad | Comprobar flujos por rol, estados vacíos/error, foco y teclado en escritorio y móvil de 375 px. El apoderado sin vínculos confirmados no debe ver rendimiento de terceros. | HTML renderizado por PHP, CSS y JavaScript progresivo; no SPA ni framework frontend nuevo. Validaciones del navegador complementan, sin sustituir, las del servidor. Revisión visual necesaria después de cambios de interfaz. |
| 10 | Testabilidad | Ejecutar PHPUnit y controles CI antes de integrar. Para validar carreras, rollback y aislamiento, ejecutar también integración PostgreSQL en base desechable; una prueba omitida por falta de entorno no equivale a aprobada. | Pruebas en `tests/`, batería de evidencia en `test/tutor-evidence.php`, fixtures SQLite y suites PostgreSQL optativas según variables de entorno. Los mocks no sustituyen la prueba E2E de extracción, embeddings e inferencia. Ver `.github/workflows/ci.yml` y documentación de demo del Tutor. |

## Límites de esta vista

- No se introducen requisitos de nuevas funciones ni dependencias de infraestructura.
- Las metas heredadas de disponibilidad y rendimiento requieren aprobación operativa,
  carga representativa e instrumentación antes de anunciar su cumplimiento.
- La configuración y el número de réplicas de DEV deben comprobarse en el repositorio
  GitOps y en el entorno cuando se evalúen disponibilidad o escalabilidad.
- Los procesos CLI comparten el producto, pero su programación/despliegue debe
  verificarse; el código existente no prueba que se ejecuten permanentemente.
- Redis Streams es opcional para Outbox; no es almacenamiento de sesiones,
  caché ni rate limiting implementado.

Véanse [ADR 0003](../adr/0003-estilo-arquitectonico.md),
[C4 L1](../c4/l1-context.puml) y [C4 L2](../c4/l2-container.puml).

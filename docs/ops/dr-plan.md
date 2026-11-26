# Plan de recuperación ante desastres — AulaViva

## 1. Propósito

Establecer los procedimientos necesarios para recuperar la disponibilidad y los datos de AulaViva ante fallos de la aplicación, indisponibilidad de servicios dependientes, corrupción de datos o pérdida de infraestructura.

El plan se fundamenta en las pruebas realizadas durante el Game Day del 24 de noviembre de 2026 en el entorno DEMO local.

## 2. Alcance

El plan considera los siguientes componentes:

- Aplicación PHP y Apache.
- Kubernetes mediante Docker Desktop.
- PostgreSQL 16 y pgvector 0.8.7.
- Volumen persistente de PostgreSQL.
- Ollama y sus modelos de inteligencia artificial.
- Registro local de imágenes Docker.
- Configuraciones, certificados y secretos.
- Materiales educativos, evaluaciones y registros académicos.

Los procedimientos experimentales se aplican exclusivamente al entorno DEMO. Los ambientes DEV y PROD requieren sus propios procedimientos y autorizaciones.

## 3. Objetivos de recuperación

| Indicador | Objetivo propuesto | Resultado observado |
|---|---|---|
| RTO del servicio completo | 15 minutos | Pendiente de validación integral |
| RPO | 24 horas | Pendiente de automatización de respaldos |
| Recuperación del Pod | 60 segundos | 12,373 segundos |
| Restauración lógica de PostgreSQL | 300 segundos | 1,28 segundos |
| Integridad de datos | Coincidencia de las tablas evaluadas | 16/16 tablas |

El RTO representa el tiempo máximo objetivo para recuperar el servicio.

El RPO representa la pérdida máxima de datos aceptable, expresada en tiempo.

Los resultados experimentales de recuperación parcial no equivalen a una validación completa del RTO y RPO.

## 4. Componentes críticos

| Componente | Criticidad | Impacto de su indisponibilidad |
|---|---|---|
| PostgreSQL | Crítica | Interrupción de las operaciones que requieren datos. |
| Aplicación web | Crítica | Imposibilidad de acceder a las funcionalidades. |
| Kubernetes | Crítica | Interrupción de la ejecución y administración de la aplicación. |
| Almacenamiento persistente | Crítica | Riesgo de pérdida de registros académicos. |
| Certificados y secretos | Alta | Imposibilidad de establecer conexiones autorizadas. |
| Ollama | Alta | Indisponibilidad del Tutor IA. |
| Registro de imágenes | Alta | Dificultades para desplegar o recuperar la aplicación. |

La indisponibilidad de Ollama no debe provocar la interrupción de las funcionalidades académicas que no dependen del Tutor IA.

## 5. Estrategia de respaldos

### 5.1. Respaldo de PostgreSQL

Se utiliza `pg_dump` en formato personalizado para generar respaldos lógicos de la base de datos.

El formato permite recuperar estructuras, registros y extensiones mediante `pg_restore`.

Los respaldos deben almacenarse fuera del repositorio Git y protegerse mediante controles de acceso.

### 5.2. Frecuencia propuesta

- Respaldo diario de PostgreSQL.
- Respaldo adicional antes de migraciones o cambios relevantes.
- Retención propuesta de siete respaldos diarios y cuatro semanales.
- Verificación SHA-256 de cada archivo.
- Prueba mensual de restauración.
- Copia externa cifrada para reducir el riesgo de pérdida del equipo físico.

La frecuencia, retención y copia externa constituyen mejoras propuestas que todavía no se encuentran automatizadas.

### 5.3. Respaldo validado

Durante el Game Day se utilizó el siguiente archivo:

- Archivo: `aulaviva-demo-pregameday-20261124-230603.dump`.
- Fecha: 24 de noviembre de 2026.
- Hora: 23:06:03.
- Tamaño: 1.792.896 bytes.
- Formato: PostgreSQL Custom.
- SHA-256: `3B8DD1ED54373EB0D07396DFA259EFE297981DF500EC1563F60C77313E2699F8`.

La huella fue verificada tanto en Windows como dentro del contenedor PostgreSQL.

## 6. Escenarios de recuperación

### 6.1. Fallo del Pod

Kubernetes administra la recuperación de la aplicación mediante el Deployment.

Procedimiento:

1. Identificar el Pod afectado.
2. Revisar eventos y registros.
3. Verificar la creación de un Pod de reemplazo.
4. Esperar que alcance el estado Ready.
5. Comprobar liveness y readiness.
6. Validar la persistencia y las funcionalidades.

Resultado experimental: recuperación automática del Pod en 12,373 segundos.

### 6.2. Indisponibilidad de Ollama

Ante la pérdida de conectividad con Ollama, el Tutor IA debe devolver una respuesta de indisponibilidad controlada.

Procedimiento:

1. Comprobar el estado de Ollama.
2. Verificar la conectividad desde la aplicación.
3. Revisar la configuración del proveedor de IA.
4. Restablecer el servicio o la configuración.
5. Comprobar el funcionamiento del Tutor mediante preguntas conocidas.

Durante GD02 se simuló la indisponibilidad mediante un cambio controlado de la dirección de conexión.

El Tutor respondió HTTP 503 sin generar respuestas educativas falsas. Los endpoints de salud permanecieron disponibles.

La restauración del Deployment tomó 12,513 segundos. Este tiempo no representa la recuperación completa de Ollama.

### 6.3. Indisponibilidad de PostgreSQL

La sonda de readiness permite detectar la pérdida de conectividad con la base de datos.

Procedimiento:

1. Comprobar el estado del contenedor PostgreSQL.
2. Revisar los registros y la conectividad.
3. Restablecer el funcionamiento de PostgreSQL.
4. Verificar que su dirección coincida con el EndpointSlice configurado.
5. Esperar que la aplicación recupere el estado Ready.
6. Comprobar la persistencia de los datos.

Durante GD03 se suspendieron temporalmente los procesos de PostgreSQL mediante `docker pause`.

La aplicación permaneció en ejecución, con liveness HTTP 200 y readiness HTTP 503.

Después de ejecutar `docker unpause`, ambos endpoints recuperaron HTTP 200 y el Pod mantuvo cero reinicios.

La recuperación fue manual y no se registró su duración exacta.

### 6.4. Corrupción o pérdida de datos

Ante corrupción o pérdida de registros:

1. Identificar el alcance del incidente.
2. Evitar nuevas escrituras cuando exista riesgo de agravar el daño.
3. Preservar evidencias y respaldar el estado afectado cuando sea posible.
4. Seleccionar el último respaldo íntegro.
5. Verificar su huella SHA-256.
6. Restaurar en una base de datos independiente.
7. Validar estructuras, registros, extensiones y restricciones.
8. Restablecer propietarios y permisos autorizados.
9. Verificar la conectividad segura.
10. Ejecutar pruebas funcionales.
11. Autorizar el cambio de conexión únicamente después de validar la recuperación.

No se debe sobrescribir directamente la base afectada sin una evaluación previa y autorización del responsable.

### 6.5. Pérdida completa del equipo físico

Este escenario requiere disponer de:

- Copias externas de los respaldos.
- Código fuente y configuraciones versionadas.
- Imágenes de aplicación verificadas.
- Procedimientos de instalación de Docker y Kubernetes.
- Modelos de Ollama disponibles.
- Certificados y secretos recuperables de forma segura.

La recuperación debe realizarse sobre un equipo alternativo, reconstruyendo los componentes y restaurando los datos.

Este escenario todavía no ha sido probado. El respaldo local no protege por sí solo frente a la pérdida total del computador.

## 7. Prueba de restauración GD04

Se creó una base independiente denominada `aulaviva_dr_test`, sin sobrescribir la base original `aulaviva_demo`.

La restauración se ejecutó mediante:

```bash
pg_restore --exit-on-error --single-transaction \
  --no-owner --no-acl \
  -d aulaviva_dr_test /tmp/GD04-restore.dump
```

El tiempo medido incluyó la creación de la base de datos y la ejecución de `pg_restore`.

### Resultados

| Indicador | Resultado |
|---|---|
| Restauración exitosa | Sí |
| Tiempo de creación y restauración | 1,28 segundos |
| Objetivo de restauración lógica | 300 segundos |
| Tablas recuperadas | 16 |
| Usuarios | 4 |
| Aulas | 1 |
| Materiales | 3 |
| Fragmentos | 39 |
| Evaluaciones | 1 |
| Entregas | 1 |
| Extensión pgvector | 0.8.7 |
| Dimensiones de embeddings | 768 |
| Embeddings recuperados | 39 |
| Coincidencia de contenido | 16/16 tablas |

La comparación de contenido se realizó mediante huellas MD5 calculadas sobre las filas ordenadas de cada tabla.

MD5 se utilizó exclusivamente como mecanismo de comparación de datos, no como control criptográfico de seguridad.

No se detectaron diferencias en el contenido comparado.

La prueba no incluyó la restauración de propietarios, permisos originales, infraestructura ni certificados.

## 8. Orden de recuperación

Ante una interrupción general, se propone recuperar los componentes en el siguiente orden:

1. Equipo físico y sistema operativo.
2. Docker Desktop y Kubernetes.
3. Almacenamiento persistente.
4. PostgreSQL y extensión pgvector.
5. Datos, propietarios y permisos.
6. Certificados, secretos y conectividad.
7. Registro de imágenes y aplicación web.
8. Ollama y modelos de inteligencia artificial.
9. Validaciones de salud, seguridad y funcionalidad.

Se priorizan las funcionalidades académicas esenciales antes de restablecer el Tutor IA.

## 9. Criterios de aceptación

La recuperación se considerará completa cuando:

- PostgreSQL acepte conexiones autorizadas.
- La aplicación alcance el estado Ready.
- Liveness y readiness devuelvan HTTP 200.
- Los datos recuperados superen las comprobaciones de integridad.
- Los cuatro roles puedan acceder a sus funcionalidades autorizadas.
- Las evaluaciones y calificaciones estén disponibles.
- El Tutor IA supere las pruebas de regresión definidas.
- Los controles de seguridad permanezcan activos.
- El incidente y sus resultados estén documentados.

## 10. Responsabilidades propuestas

| Responsable | Función |
|---|---|
| Operaciones | Recuperar infraestructura y servicios. |
| Responsable de datos | Verificar respaldos, restauración e integridad. |
| Desarrollo | Comprobar el funcionamiento de la aplicación. |
| Seguridad | Validar permisos, secretos y controles de acceso. |
| Responsable del proyecto | Autorizar la recuperación y el retorno al servicio. |

Estas responsabilidades pueden distribuirse entre los integrantes del equipo académico.

## 11. Evidencias

- [Integridad del respaldo](evidencias/GD00-backup-sha256.json)
- [Recuperación del Pod](evidencias/GD01-recuperacion.json)
- [Recuperación del Deployment tras fallo de IA](evidencias/GD02-recuperacion.json)
- [Persistencia después del fallo de PostgreSQL](evidencias/GD03-persistencia.txt)
- [Restauración cronometrada](evidencias/GD04-restauracion.json)
- [Tablas recuperadas](evidencias/GD04-tablas.txt)
- [Extensión y embeddings](evidencias/GD04-pgvector.txt)
- [Integridad de datos restaurados](evidencias/GD04-integridad-datos.json)
- [Infraestructura después del Game Day](evidencias/GD05-infraestructura.txt)
- [Golden Set posterior al Game Day](../ai/evidencias/golden-set-20261124-232620.json)

## 12. Riesgos y mejoras pendientes

- Dependencia de un único equipo físico.
- Ausencia de alta disponibilidad de PostgreSQL.
- Aplicación desplegada con una sola réplica.
- Respaldos locales sin copia externa verificada.
- Ausencia de automatización periódica de respaldos.
- RTO y RPO completos pendientes de validación.
- Restauración de permisos y certificados pendiente de prueba integral.
- Recuperación sobre un equipo alternativo pendiente.
- Dependencia de la disponibilidad del registro local y de los modelos de IA.

## 13. Conclusión

AulaViva dispone de mecanismos de recuperación de la aplicación, detección de fallos de PostgreSQL, degradación controlada del Tutor IA y restauración lógica de datos.

El Game Day permitió verificar experimentalmente la recuperación de un Pod y la restauración de una base de datos independiente, conservando los registros académicos y los embeddings.

Los resultados demuestran la viabilidad de los procedimientos evaluados en DEMO. La recuperación completa ante pérdida de infraestructura requiere implementar y validar las mejoras pendientes.
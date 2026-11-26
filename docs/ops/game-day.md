# Game Day — AulaViva

## 1. Información general

- Proyecto: AulaViva.
- Fecha: 24 de noviembre de 2026.
- Entorno: DEMO local.
- Plataforma: Docker Desktop y Kubernetes.
- Namespace: aulaviva-demo.
- Tipo de ejercicio: pruebas controladas de resiliencia y recuperación.
- Resultado: seis etapas completadas satisfactoriamente dentro del alcance definido.

## 2. Objetivo

Evaluar la capacidad de AulaViva para detectar fallos, recuperar componentes, preservar datos y mantener un comportamiento controlado ante la indisponibilidad de servicios dependientes.

El ejercicio busca obtener evidencias experimentales que permitan evaluar los mecanismos de resiliencia implementados y detectar oportunidades de mejora.

## 3. Alcance y condiciones de seguridad

Las pruebas se realizaron exclusivamente sobre el entorno DEMO local.

Se utilizaron usuarios ficticios, materiales de prueba, evaluaciones y registros académicos destinados a la demostración.

Condiciones establecidas:

- No intervenir directamente los ambientes DEV y PROD.
- Generar un respaldo antes de introducir fallos.
- Evitar operaciones destructivas sobre la base de datos activa.
- Restaurar los datos en una base independiente.
- Registrar los resultados mediante archivos de evidencia.
- Verificar el funcionamiento del sistema después de cada recuperación.

## 4. Infraestructura evaluada

| Componente | Configuración |
|---|---|
| Aplicación | PHP y Apache |
| Orquestación | Kubernetes mediante Docker Desktop |
| Réplicas | 1 |
| Base de datos | PostgreSQL 16.16 |
| Extensión vectorial | pgvector 0.8.7 |
| Inferencia | Ollama local |
| Modelo generativo | qwen3:1.7b |
| Embeddings | nomic-embed-text |
| Dimensiones vectoriales | 768 |
| Registro de imágenes | Registro Docker local |
| Almacenamiento | Volumen persistente PostgreSQL |

La imagen utilizada durante el ejercicio fue:

`localhost:5000/aulaviva-demo-tutor@sha256:0c0efc542a913d1147f5c8f8c044df6e7c7d9f1a8e52a3270bd0aaf17e532d12`

Esta imagen corresponde a una compilación local del entorno DEMO y no a una imagen firmada mediante el pipeline de producción.

## 5. Plan de pruebas

| ID | Escenario | Objetivo | Resultado |
|---|---|---|---|
| GD00 | Respaldo preventivo | Disponer de una copia íntegra antes del ejercicio | Aprobado |
| GD01 | Eliminación del Pod | Verificar recuperación automática | Aprobado |
| GD02 | Indisponibilidad de Ollama | Verificar degradación controlada | Aprobado |
| GD03 | Indisponibilidad de PostgreSQL | Verificar detección y recuperación | Aprobado |
| GD04 | Restauración de respaldo | Recuperar datos en una base independiente | Aprobado |
| GD05 | Validación funcional | Comprobar funcionamiento posterior | Aprobado |

## 6. GD00 — Respaldo preventivo

### Objetivo

Generar un respaldo lógico de PostgreSQL antes de ejecutar las pruebas de resiliencia.

### Procedimiento

1. Generar un respaldo mediante pg_dump en formato personalizado.
2. Copiar el archivo al sistema de archivos de Windows.
3. Inspeccionar su contenido mediante pg_restore.
4. Calcular su huella SHA-256.
5. Conservar el respaldo fuera del repositorio Git.

### Resultados

| Indicador | Resultado |
|---|---|
| Archivo | aulaviva-demo-pregameday-20261124-230603.dump |
| Hora | 23:06:03 |
| Tamaño | 1.792.896 bytes |
| Entradas del catálogo | 150 |
| Formato | PostgreSQL Custom |
| Integridad SHA-256 | Verificada |

Huella SHA-256:

`3B8DD1ED54373EB0D07396DFA259EFE297981DF500EC1563F60C77313E2699F8`

**Resultado: aprobado.**

Evidencia: [GD00-backup-sha256.json](evidencias/GD00-backup-sha256.json).

## 7. GD01 — Recuperación automática del Pod

### Objetivo

Comprobar que Kubernetes puede reemplazar automáticamente un Pod eliminado y recuperar la disponibilidad de la aplicación.

### Fallo introducido

Se eliminó de manera controlada el Pod activo del Deployment aulaviva-api.

### Procedimiento

1. Registrar el estado y UID del Pod original.
2. Eliminar el Pod.
3. Iniciar la medición del tiempo de recuperación.
4. Esperar la creación de un Pod con un UID diferente.
5. Verificar que el nuevo Pod alcance el estado Ready.
6. Comprobar los endpoints de salud.
7. Verificar la persistencia de los registros.

### Resultados

| Indicador | Resultado |
|---|---|
| Recuperación automática | Sí |
| Tiempo observado | 12,373 segundos |
| Objetivo | 60 segundos |
| Objetivo cumplido | Sí |
| Liveness posterior | HTTP 200 |
| Readiness posterior | HTTP 200 |
| Persistencia de registros | Verificada mediante conteos |

El Deployment creó automáticamente un Pod de reemplazo.

Los registros académicos permanecieron disponibles después de la recuperación.

El tiempo registrado corresponde a la recuperación del estado Ready del Pod, no al tiempo total de recuperación percibido por el usuario.

**Resultado: aprobado.**

Evidencias:

- [Estado anterior](evidencias/GD01-antes.txt).
- [Recuperación cronometrada](evidencias/GD01-recuperacion.json).
- [Estado posterior](evidencias/GD01-despues.txt).
- [Liveness](evidencias/GD01-liveness.txt).
- [Readiness](evidencias/GD01-readiness.txt).
- [Persistencia](evidencias/GD01-persistencia.txt).

## 8. GD02 — Indisponibilidad del Tutor IA

### Objetivo

Verificar que AulaViva presenta una degradación controlada cuando no puede comunicarse con Ollama.

### Fallo introducido

Se modificó temporalmente la dirección de conexión a Ollama en el Deployment DEMO.

Configuración habitual:

`http://host.docker.internal:11434`

Configuración utilizada para simular el fallo:

`http://127.0.0.1:11434`

Esta modificación impidió que la aplicación accediera al proveedor de IA desde el contenedor.

No se detuvo el proceso real de Ollama.

### Procedimiento

1. Verificar el funcionamiento inicial del Tutor.
2. Introducir una configuración de conexión incorrecta.
3. Ejecutar tres consultas al Tutor IA.
4. Registrar los códigos HTTP y estados de respuesta.
5. Comprobar liveness y readiness.
6. Restablecer la configuración original.
7. Verificar nuevamente el funcionamiento del Tutor.

### Resultados

| Indicador | Resultado |
|---|---|
| Consultas durante el fallo | 3 |
| Respuestas HTTP 503 | 3 |
| Estado devuelto | GENERATION_UNAVAILABLE |
| Latencia promedio del fallo | 5 milisegundos |
| Liveness | HTTP 200 |
| Readiness | HTTP 200 |
| Respuestas educativas falsas observadas | 0 |
| Recuperación del Deployment | 12,513 segundos |
| Tutor recuperado | Sí |

El Tutor informó que no estaba disponible, en lugar de generar respuestas sin evidencia.

Los endpoints de salud de la aplicación permanecieron disponibles.

La recuperación requirió restablecer manualmente la configuración original.

El tiempo registrado corresponde al despliegue de la configuración recuperada y no a la recuperación integral de Ollama.

**Resultado: aprobado.**

Evidencias:

- [Registros del fallo](evidencias/GD02-fallo-ollama.txt).
- [Liveness durante el fallo](evidencias/GD02-liveness.txt).
- [Readiness durante el fallo](evidencias/GD02-readiness.txt).
- [Recuperación](evidencias/GD02-recuperacion.json).

## 9. GD03 — Indisponibilidad de PostgreSQL

### Objetivo

Comprobar que la aplicación distingue entre su propio estado de ejecución y la disponibilidad de PostgreSQL.

### Fallo introducido

Se suspendieron temporalmente los procesos del contenedor PostgreSQL mediante:

```powershell
docker pause aulaviva-demo-db
```

La prueba simuló una base de datos que deja de responder sin eliminar el contenedor ni su volumen persistente.

### Procedimiento

1. Registrar el estado inicial del Pod.
2. Registrar la dirección IP de PostgreSQL.
3. Pausar el contenedor.
4. Esperar 40 segundos antes de comprobar el estado del Pod.
5. Consultar liveness y readiness.
6. Reanudar PostgreSQL.
7. Verificar la recuperación del estado Ready.
8. Comprobar la persistencia de los datos.

### Resultados

| Indicador | Antes | Durante | Después |
|---|---|---|---|
| Pod Ready | 1/1 | 0/1 | 1/1 |
| Estado del Pod | Running | Running | Running |
| Reinicios | 0 | 0 | 0 |
| Liveness | 200 | 200 | 200 |
| Readiness | 200 | 503 | 200 |

La dirección IP de PostgreSQL permaneció en 172.18.0.5.

La aplicación detectó la indisponibilidad de PostgreSQL mediante readiness, sin provocar reinicios innecesarios.

La recuperación se realizó manualmente mediante docker unpause.

No se midió el tiempo exacto de detección ni de recuperación. Los 40 segundos corresponden al período de espera antes de comprobar el estado, no al tiempo exacto de detección.

Las consultas de salud se realizaron mediante port-forward local, por lo que no representan una prueba integral del comportamiento del tráfico externo.

**Resultado: aprobado.**

Evidencias:

- [Estado anterior](evidencias/GD03-antes.txt).
- [Estado durante el fallo](evidencias/GD03-durante.txt).
- [Liveness durante el fallo](evidencias/GD03-liveness.txt).
- [Readiness durante el fallo](evidencias/GD03-readiness.txt).
- [Estado recuperado](evidencias/GD03-despues.txt).
- [Liveness recuperado](evidencias/GD03-liveness-recuperado.txt).
- [Readiness recuperado](evidencias/GD03-readiness-recuperado.txt).
- [Persistencia](evidencias/GD03-persistencia.txt).

## 10. GD04 — Restauración de PostgreSQL

### Objetivo

Demostrar que el respaldo generado antes del Game Day permite recuperar la estructura y el contenido de la base de datos.

### Procedimiento

1. Seleccionar el respaldo preventivo.
2. Verificar su huella SHA-256.
3. Copiar el respaldo al contenedor PostgreSQL.
4. Verificar nuevamente su huella.
5. Crear una base independiente denominada aulaviva_dr_test.
6. Ejecutar pg_restore.
7. Medir el tiempo de creación y restauración.
8. Verificar tablas, registros, extensiones y embeddings.
9. Comparar el contenido de las 16 tablas con la base DEMO original.

### Resultados

| Indicador | Resultado |
|---|---|
| Restauración exitosa | Sí |
| Tiempo observado | 1,28 segundos |
| Objetivo | 300 segundos |
| Objetivo cumplido | Sí |
| Tablas recuperadas | 16 |
| Tablas con contenido coincidente | 16/16 |
| Extensión pgvector | 0.8.7 |
| Embeddings recuperados | 39 |
| Dimensiones vectoriales | 768 |

### Registros recuperados

| Entidad | Cantidad |
|---|---|
| Usuarios | 4 |
| Aulas | 1 |
| Materiales | 3 |
| Fragmentos | 39 |
| Evaluaciones | 1 |
| Entregas | 1 |

### Integridad de datos

Se comparó el contenido de las siguientes tablas mediante huellas MD5 calculadas sobre sus filas ordenadas:

| Tabla | Coincidencia |
|---|---|
| usuarios | Sí |
| aulas | Sí |
| aula_alumnos | Sí |
| aula_profesores | Sí |
| materiales | Sí |
| material_ingestas | Sí |
| material_fragmentos | Sí |
| evaluaciones | Sí |
| preguntas | Sí |
| alternativas | Sí |
| entregas | Sí |
| respuestas | Sí |
| solicitudes_ayuda | Sí |
| outbox_events | Sí |
| apoderado_estudiantes | Sí |
| invitaciones | Sí |

MD5 se utilizó únicamente para comparar el contenido de las tablas, no como mecanismo criptográfico de seguridad.

No se detectaron diferencias en las comparaciones realizadas.

La restauración se efectuó dentro de la misma instancia PostgreSQL, utilizando una base independiente y sin sobrescribir la base original.

Las opciones --no-owner y --no-acl excluyeron la restauración de propietarios y permisos originales.

Por tanto, la prueba demuestra recuperación lógica de datos, pero no recuperación completa ante pérdida del equipo físico.

**Resultado: aprobado.**

Evidencias:

- [Restauración cronometrada](evidencias/GD04-restauracion.json).
- [Registros recuperados](evidencias/GD04-persistencia.txt).
- [Tablas recuperadas](evidencias/GD04-tablas.txt).
- [Extensión y embeddings](evidencias/GD04-pgvector.txt).
- [Integridad de datos](evidencias/GD04-integridad-datos.json).

## 11. GD05 — Validación funcional posterior

### Objetivo

Comprobar que AulaViva continúa funcionando después de las pruebas de resiliencia.

### Infraestructura

Se verificó:

- Pod Running y Ready 1/1.
- Deployment con una réplica disponible.
- Liveness HTTP 200.
- Readiness HTTP 200.
- Cero reinicios en el Pod observado.

### Validación del Tutor IA

Se ejecutó nuevamente el Golden Set de diez casos.

| Categoría | Aprobados | Total |
|---|---|---|
| Respuestas con evidencia | 5 | 5 |
| Abstenciones | 3 | 3 |
| Controles de seguridad | 2 | 2 |
| Total | 10 | 10 |

| Indicador | Resultado |
|---|---|
| Tasa de aprobación automática | 100 % |
| Latencia promedio | 1,984 segundos |
| Latencia mínima | 0,382 segundos |
| Latencia máxima | 10,122 segundos |
| Respuestas HTTP 200 | 10/10 |

Las cinco respuestas positivas coincidieron con los hechos esperados de los materiales educativos.

Los casos sin evidencia y las instrucciones adversariales evaluadas produjeron abstenciones.

El resultado corresponde exclusivamente al conjunto de pruebas definido y no representa una garantía de precisión general del modelo.

### Validación por roles

| Rol | Funcionalidades verificadas |
|---|---|
| Profesor | Acceso a aulas, alumnos inscritos, materiales y evaluación publicada. |
| Alumno | Acceso a cursos, materiales, evaluaciones y resultados. |
| Apoderado | Consulta del rendimiento académico del estudiante vinculado. |
| Administrador | Consulta de vinculaciones existentes y acceso al formulario de confirmación. |

Se comprobó que la evaluación de Ciencias Naturales conservaba los siguientes resultados:

- Puntaje obtenido: 20.
- Puntaje máximo: 30.
- Porcentaje: 66,67 %.
- Calificación: 4,5.

El apoderado visualizó la misma calificación en su panel de rendimiento académico.

La vinculación entre el apoderado y el estudiante permaneció confirmada.

Las comprobaciones por roles se realizaron mediante navegación y observación de las pantallas. No se repitieron todas las operaciones de escritura ni todos los escenarios de autorización.

**Resultado: aprobado dentro del alcance definido.**

Evidencias:

- [Infraestructura final](evidencias/GD05-infraestructura.txt).
- [Liveness final](evidencias/GD05-liveness.txt).
- [Readiness final](evidencias/GD05-readiness.txt).
- [Golden Set posterior al Game Day](../ai/evidencias/golden-set-20261124-232620.json).
- [Resultados tabulares del Golden Set](../ai/evidencias/golden-set-20261124-232620.csv).

## 12. Resumen de indicadores

| Indicador | Resultado observado |
|---|---|
| Etapas completadas | 6/6 |
| Recuperación automática del Pod | 12,373 segundos |
| Recuperación del Deployment tras fallo de IA | 12,513 segundos |
| Fallos de IA controlados | 3/3 |
| Reinicios durante el fallo de PostgreSQL | 0 |
| Restauración lógica | 1,28 segundos |
| Coincidencia de tablas | 16/16 |
| Embeddings recuperados | 39/39 |
| Golden Set posterior | 10/10 |
| Roles comprobados mediante navegación | 4/4 |

## 13. Hallazgos

### Fortalezas

- Kubernetes reemplaza automáticamente un Pod eliminado.
- Las sondas de salud distinguen entre ejecución y disponibilidad de PostgreSQL.
- El Tutor IA presenta degradación controlada ante fallos de conectividad.
- El almacenamiento persistente conserva los registros académicos.
- Los respaldos permiten recuperar el contenido de la base de datos.
- Los embeddings permanecen disponibles después de la restauración.
- Las funcionalidades verificadas permanecen operativas después del ejercicio.

### Oportunidades de mejora

- Automatizar los respaldos periódicos.
- Implementar copias externas cifradas.
- Medir con precisión los tiempos de detección y recuperación de PostgreSQL.
- Incorporar monitoreo continuo y alertas.
- Automatizar el inicio del entorno DEMO.
- Resolver definitivamente los problemas de descarga de imágenes por digest.
- Validar la recuperación de propietarios, permisos, certificados y secretos.
- Ejecutar una prueba de recuperación sobre un equipo alternativo.
- Ampliar las pruebas funcionales y de seguridad posteriores a la recuperación.

## 14. Limitaciones del ejercicio

El Game Day se realizó sobre un único equipo físico y una aplicación con una sola réplica.

La restauración utilizó una base independiente dentro de la misma instancia PostgreSQL.

No se evaluaron fallos completos del equipo, pérdida física del almacenamiento, indisponibilidad prolongada de infraestructura ni recuperación integral de los ambientes DEV y PROD.

Tampoco se comprobó alta disponibilidad, recuperación automática de PostgreSQL ni cumplimiento continuo de los objetivos RTO y RPO.

Los resultados deben interpretarse dentro del alcance experimental documentado.

## 15. Conclusión

El Game Day permitió comprobar experimentalmente los mecanismos de resiliencia de AulaViva en el entorno DEMO local.

Se verificó la recuperación automática de la aplicación, la degradación controlada del Tutor IA, la detección de indisponibilidad de PostgreSQL y la restauración lógica de datos.

Las 16 tablas comparadas conservaron su contenido y el Golden Set posterior al ejercicio obtuvo diez casos aprobados.

Las funcionalidades académicas verificadas permanecieron disponibles después de la recuperación.

Los resultados constituyen evidencia de los mecanismos evaluados y permiten establecer acciones concretas para mejorar la continuidad operacional del sistema.

## 16. Documentos relacionados

- [Objetivos de nivel de servicio](slo.md).
- [Plan de recuperación ante desastres](dr-plan.md).
- [Runbook operacional](runbook.md).
- [Evaluación del Tutor IA](../ai/evaluacion.md).
- [Model Card](../ai/model-card.md).
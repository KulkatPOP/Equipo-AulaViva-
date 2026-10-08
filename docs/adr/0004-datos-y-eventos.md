# ADR 0004 - Modelo de datos y eventos

## Estado
Aceptado

## Contexto
AulaViva necesita gestionar datos transaccionales críticos (usuarios, matrículas, notas de evaluaciones), almacenar vectores para las búsquedas de similitud del Tutor IA (RAG), y orquestar tareas asíncronas (como notificar a los apoderados y autocorregir exámenes). Debemos definir las tecnologías que soporten estos flujos cumpliendo con la arquitectura Cloud definida en el C4 L2.

## Decisiones

### 1. Motor de Persistencia por Contexto
* **Decisión:** Utilizaremos **Amazon RDS con PostgreSQL** como base de datos principal para los 4 contextos (Académico, Evaluación, Seguimiento, Tutor IA).
* **Justificación:** PostgreSQL nos garantiza consistencia fuerte (ACID) para las transacciones críticas como entregas de exámenes y matrículas. Además, al habilitar la extensión **`pgvector`**, podemos usar la misma base de datos para almacenar los embeddings del Tutor IA, evitando la complejidad de administrar una base de datos vectorial separada (como Pinecone o Qdrant) en esta etapa inicial.

### 2. Broker de Eventos
* **Decisión:** Utilizaremos **Amazon ElastiCache (Redis Streams)** como nuestro broker de eventos.
* **Justificación:** Nuestro diagrama C4 ya contempla Redis para el manejo de caché y colas de tareas con BullMQ. Reutilizar Redis (mediante Redis Streams) para el bus de eventos de dominio reduce la carga operativa y los costos, manteniendo una semántica de entrega "at-least-once" (al menos una vez).

### 3. Patrones de Arquitectura de Datos
* **Patrón Outbox (Bandeja de Salida):**
  * **Problema:** Al enviar una respuesta de examen, guardar en la base de datos y publicar un evento en Redis no es una transacción atómica. Si Redis falla, el evento se pierde.
  * **Solución:** Implementaremos el patrón Outbox. Guardaremos el evento en una tabla `outbox_events` dentro de PostgreSQL en la misma transacción de negocio. Un proceso *relay* en segundo plano leerá esa tabla y enviará los mensajes a Redis.
* **Patrón CQRS (Segregación de Responsabilidades de Comandos y Consultas):**
  * **Problema:** El "Contexto de Seguimiento" que usa el apoderado requiere cruzar múltiples datos de diferentes tablas (notas, cursos, asistencia) lo que haría muy lentas las consultas.
  * **Solución:** Utilizaremos CQRS. Cuando el estudiante rinda una prueba (Comando en el Contexto de Evaluación), se publicará un evento. El Contexto de Seguimiento escuchará este evento y actualizará una tabla de lectura optimizada y desnormalizada (Proyección) para que el panel del apoderado cargue rápidamente.

## Consecuencias
* **Positivas:** Aseguramos la integridad de los datos críticos con PostgreSQL y evitamos la pérdida de eventos de dominio con el patrón Outbox. Reutilizar Redis y PostgreSQL para múltiples propósitos simplifica la infraestructura en AWS.
* **Negativas:** La implementación del patrón Outbox y CQRS agregará latencia eventual (Eventual Consistency) en la actualización del panel del apoderado y sumará un poco de complejidad al código del backend por el proceso *relay* del Outbox.

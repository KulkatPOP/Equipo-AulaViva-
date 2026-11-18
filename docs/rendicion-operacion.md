# Operación de US03

Aplica la migración 003 después de la 002. Si la comprobación de puntajes publicados falla, revisa los datos con el equipo antes de volver a intentarlo.

Ejecuta `php cli/rendicion.php --continuo` con las mismas variables PostgreSQL que usa la aplicación. El proceso cierra intentos vencidos cada dos segundos, incluso cuando todos los navegadores están cerrados. Sin el argumento, realiza una pasada para permitir su ejecución desde un programador de tareas.

Para publicar Outbox en Redis Streams, habilita la extensión PHP Redis y configura `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` si aplica y `REDIS_STREAM` si se requiere otro nombre. Sin Redis configurado, las calificaciones siguen disponibles y los eventos quedan pendientes en PostgreSQL. Los consumidores deben deduplicar por `event_id`.

El navegador conserva las selecciones y las solicitudes pendientes en este dispositivo. Si reconecta después del vencimiento, la nota usa únicamente las selecciones recibidas antes del límite.

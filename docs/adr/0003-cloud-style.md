# ADR 0003 - Cloud Style AulaViva

## Estado

Propuesto

## Contexto

AulaViva necesita definir una estrategia de arquitectura cloud que permita
escalabilidad, seguridad y una operación eficiente de la plataforma educativa.

La solución debe soportar múltiples instituciones manteniendo separación de datos
y permitiendo integrar servicios externos como almacenamiento, bases de datos e IA.

En la S03 se definió un estilo de **Monolito Modular** como arquitectura inicial
para AulaViva. Esta decisión busca mantener una complejidad operacional adecuada
para el MVP, manteniendo los módulos de la plataforma dentro de una misma
aplicación y permitiendo una evolución progresiva.

Para llevar esta arquitectura a un entorno cloud se requiere seleccionar un
proveedor cloud y servicios gestionados que permitan desplegar, almacenar datos,
procesar tareas asíncronas, almacenar materiales educativos y monitorear la
plataforma.

## Decisión

Se decide mantener el estilo de **Monolito Modular** y llevarlo a un entorno
Cloud utilizando **Amazon Web Services (AWS)**.

El backend de AulaViva, desarrollado con NestJS y TypeScript, será desplegado
como una aplicación contenedorizada utilizando **Amazon ECS con AWS Fargate**.

La persistencia de datos utilizará **Amazon RDS para PostgreSQL**, manteniendo
la posibilidad de utilizar **pgvector** para almacenar los embeddings requeridos
por el Tutor IA basado en RAG.

Los materiales y documentos educativos serán almacenados mediante **Amazon S3**.

El procesamiento de tareas asíncronas continuará utilizando **Redis + BullMQ**,
utilizando **Amazon ElastiCache** como servicio gestionado para Redis.

Los logs y métricas de la aplicación serán gestionados mediante
**Amazon CloudWatch**.

El Tutor IA continuará integrándose con un proveedor externo de modelos LLM
mediante API.

## Consecuencias

### Positivas

- Mantiene la arquitectura de Monolito Modular definida en la S03.
- Reduce la complejidad operacional frente a una arquitectura distribuida de
  microservicios.
- Permite desplegar la aplicación utilizando contenedores administrados.
- Permite escalar horizontalmente la aplicación cuando aumente la demanda.
- Reduce la administración manual de infraestructura mediante servicios
  gestionados.
- Permite utilizar PostgreSQL administrado mediante Amazon RDS.
- Permite almacenar materiales educativos de manera escalable mediante Amazon S3.
- Mantiene el procesamiento asíncrono mediante Redis y BullMQ.
- Facilita el monitoreo y diagnóstico mediante CloudWatch.
- Permite evolucionar posteriormente hacia otros estilos arquitectónicos si las
  necesidades del sistema lo requieren.

### Negativas

- Existe dependencia de los servicios de Amazon Web Services.
- Los servicios gestionados generan costos asociados a su utilización.
- El backend continúa siendo un único despliegue.
- Los módulos internos no pueden escalarse de manera completamente independiente.
- Algunos servicios específicos de AWS pueden dificultar una futura migración a
  otro proveedor cloud.

## Alternativas descartadas

### Microservicios

Se descarta como arquitectura principal para el MVP debido a que aumentaría la
complejidad operacional y de comunicación entre servicios.

Una arquitectura de microservicios requeriría administrar múltiples despliegues,
comunicaciones entre servicios, mecanismos adicionales de seguridad y monitoreo.

Para el tamaño y etapa actual de AulaViva, estos costos de complejidad no
justifican la separación en múltiples servicios independientes.

### Serverless

Se descarta como estilo arquitectónico principal porque AulaViva posee un
backend modular con múltiples funcionalidades relacionadas, como autenticación,
RBAC, multi-tenancy, cursos, evaluaciones, materiales y Tutor IA.

Sin embargo, funciones serverless podrían evaluarse posteriormente para tareas
específicas donde aporten valor.

### Híbrido

Se descarta como estilo principal para el MVP debido a que combinar diferentes
estilos arquitectónicos agregaría complejidad sin existir actualmente una
necesidad concreta que lo justifique.

### Azure y GCP

Se consideran Azure y GCP como proveedores cloud técnicamente viables.
Sin embargo, se selecciona AWS debido a la disponibilidad de servicios
gestionados adecuados para los componentes definidos en la arquitectura de
AulaViva.

# Atributos de Calidad — AulaViva

## 1. Seguridad

**Prioridad:** Alta

AulaViva es una plataforma educativa SaaS Multi-Tenant, por lo que los
datos de cada colegio deben mantenerse aislados.

Además, el sistema posee distintos roles de usuario:
- Estudiante
- Docente
- Apoderado
- Coordinador Académico
- Sostenedor

### Impacto en la arquitectura

- Autenticación de usuarios.
- Autorización mediante RBAC.
- Validación de permisos en el backend.
- Identificación del tenant en las operaciones.
- Aislamiento de datos entre colegios.
- Protección de información académica.

---

## 2. Mantenibilidad

**Prioridad:** Alta

La arquitectura debe permitir incorporar nuevas funcionalidades sin
afectar innecesariamente las funcionalidades existentes.

### Impacto en la arquitectura

El backend se organizará como un Monolito Modular con módulos
claramente separados:

- Autenticación y RBAC.
- Multi-Tenancy.
- Gestión académica.
- Evaluaciones.
- Seguimiento académico.
- Tutor IA.

Cada módulo tendrá responsabilidades claramente delimitadas.

---

## 3. Escalabilidad

**Prioridad:** Alta

AulaViva es una plataforma SaaS que debe permitir incorporar
progresivamente nuevos colegios y usuarios.

El Tutor IA y el procesamiento de materiales pueden generar cargas
variables, por lo que la arquitectura debe permitir crecer sin
rediseñar completamente el sistema.

### Impacto en la arquitectura

- Backend stateless.
- Escalamiento horizontal.
- Procesamiento asíncrono mediante Redis + BullMQ.
- PostgreSQL + pgvector para datos y embeddings.
- Posibilidad de extraer posteriormente módulos que requieran
  escalamiento independiente.

---

## Priorización

| Atributo | Prioridad | Motivo |
|---|---|---|
| Seguridad | Alta | Aislamiento Multi-Tenant y control de acceso por roles. |
| Mantenibilidad | Alta | Facilitar la evolución del MVP y mantener módulos separados. |
| Escalabilidad | Alta | Permitir crecimiento de colegios, usuarios y carga del Tutor IA. |

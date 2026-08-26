# Decisiones arquitectónicas — AulaViva

## Propósito

Este documento registra las decisiones arquitectónicas que deberán ser
analizadas durante la evolución del proyecto AulaViva.

Como Tech Lead, se identifican inicialmente las siguientes áreas de
decisión:

1. Estrategia de aislamiento multi-tenant.
2. Control de acceso mediante RBAC.
3. Arquitectura del tutor IA basado en RAG.
4. Estrategia de escalabilidad horizontal.
5. Control y optimización de costos del uso de LLM.

Estas decisiones deberán ser evaluadas considerando los requisitos,
restricciones técnicas, historias de usuario y evolución del MVP.

---

## Decisiones por analizar

### 1. Estrategia de multi-tenancy

**Pregunta:**

¿Cómo se aislarán los datos de cada institución educativa?

**Aspectos a evaluar:**

- Aislamiento lógico de los tenants.
- Seguridad de los datos.
- Escalabilidad.
- Complejidad de implementación.
- Costos de operación.

**Estado:** Por decidir.

---

### 2. Control de acceso mediante RBAC

**Pregunta:**

¿Cómo se gestionarán los permisos de los distintos roles de AulaViva?

**Roles considerados:**

- Estudiante.
- Docente.
- Coordinador.
- Apoderado.
- Sostenedor.

**Aspectos a evaluar:**

- Autenticación.
- Autorización.
- Permisos por rol.
- Aislamiento entre instituciones.
- Facilidad de mantenimiento.

**Estado:** Por decidir.

---

### 3. Arquitectura del tutor IA / RAG

**Pregunta:**

¿Cómo se implementará el tutor IA para utilizar información relevante
del curso y mantener el aislamiento entre instituciones?

**Aspectos a evaluar:**

- Recuperación de información.
- Fuentes de conocimiento.
- Aislamiento por tenant.
- Generación de respuestas.
- Trazabilidad.
- Seguridad de la información.

**Estado:** Por decidir.

---

### 4. Estrategia de escalabilidad

**Pregunta:**

¿Cómo se preparará la plataforma para soportar aumentos de carga,
especialmente durante períodos de evaluaciones?

**Aspectos a evaluar:**

- Escalabilidad horizontal.
- Componentes que requieren mayor capacidad.
- Gestión de carga.
- Disponibilidad.
- Costos.

**Estado:** Por decidir.

---

### 5. Control de costos del LLM

**Pregunta:**

¿Cómo se controlará y optimizará el costo asociado al uso del tutor IA?

**Aspectos a evaluar:**

- Consumo por tenant.
- Límites de uso.
- Monitoreo.
- Optimización de consultas.
- Costos operacionales.

**Estado:** Por decidir.

---

## Próximos pasos

Las decisiones anteriores serán analizadas y formalizadas como ADRs
individuales cuando exista suficiente información técnica y requisitos
del producto para tomar una decisión.

Cada ADR deberá documentar:

- Contexto.
- Problema.
- Alternativas consideradas.
- Decisión.
- Consecuencias.
- Estado de la decisión.

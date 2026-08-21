ADR-0001: Elección de AulaViva como iniciativa del equipo


Contexto 

El equipo debía analizar las tres iniciativas propuestas en el taller y seleccionar una de ellas por consenso. 
Después de revisar las alternativas, se consideró que AulaViva presenta un problema concreto relacionado con la modernización 
de la experiencia educativa. 
Además, la iniciativa permite aplicar distintos conceptos de ingeniería de software, incluyendo arquitectura SaaS, 
multi-tenancy, seguridad, RBAC, escalabilidad, inteligencia artificial, RAG, pruebas y DevSecOps. 


Decisión:

El equipo decidió desarrollar AulaViva — Plataforma SaaS multi-tenant con tutor IA. 
La decisión fue tomada por consenso entre los seis integrantes: 

-Agustin Yañez 
-Sebastian Chacon 
-Sharick Jimenez 
-Kevin Ramos 
-Eduardo Erazo 
-Basthian Reyes 


Consecuencias positivas 

-Permite trabajar con una arquitectura multi-tenant. 
-Presenta desafíos reales de seguridad y aislamiento de información. 
-Permite aplicar control de acceso basado en roles. 
-Incorpora inteligencia artificial mediante un tutor con RAG. 
-Permite trabajar con diferentes tipos de usuarios. 
-Presenta oportunidades para aplicar prácticas DevSecOps. 
-Permite diseñar un MVP con funcionalidades claramente delimitadas. 
-Tiene potencial de escalabilidad para múltiples colegios. 


Consecuencias negativas 

-La arquitectura multi-tenant aumenta la complejidad del sistema. 
-La información de estudiantes requiere especial cuidado en términos de seguridad y privacidad. 
-El tutor IA agrega complejidad técnica. 
-La escalabilidad puede requerir una infraestructura más compleja. 
-Será necesario realizar pruebas adicionales para validar las respuestas del tutor IA. 


Alternativas consideradas 

El equipo consideró las otras iniciativas disponibles en el taller, pero AulaViva 
fue seleccionada debido a que combina una problemática concreta con desafíos técnicos 
relevantes para el aprendizaje de ingeniería de software. 

Autores:

-Agustin Yañez — Product Owner 
-Sebastian Chacon — Tech Lead 
-Sharick Jimenez — DevSecOps 
-Kevin Ramos — AI/Data 
-Eduardo Erazo — QA 
-Basthian Reyes — UX/UI 


Trello inicial 

El tablero Trello tendrá las siguientes 6 columnas: 

Backlog → To Do → In Progress → Code Review → Testing → Done 

Backlog:

-Diseñar arquitectura general de AulaViva. 
-Diseñar modelo de datos multi-tenant. 
-Definir estrategia de autenticación. 
-Definir roles y permisos RBAC. 
-Diseñar arquitectura del tutor IA. 
-Definir estrategia RAG. 
-Diseñar interfaz inicial. 
-Definir estrategia de pruebas. 
-Diseñar pipeline CI/CD. 

To Do: 

-Crear repositorio GitHub. 
-Crear README.md. 
-Crear CHARTER.md. 
-Crear estructura docs/adr/. 
-Configurar reglas de Pull Request. 

In Progress:

-Definir arquitectura inicial del sistema. 
-Diseñar modelo inicial de usuarios y colegios. 
-Code Review 
-Revisión de documentación inicial. 
-Revisión de estructura del repositorio. 

Testing:

-Verificación de estructura inicial del proyecto. 


Done:

-Selección de iniciativa AulaViva. 
-Asignación inicial de roles. 
-Elaboración del Charter. 
-Elaboración del ADR-0001. 


Tres Issues iniciales para GitHub:

Issue #1 — Crear estructura inicial del repositorio 
Responsable: Sharick Jimenez — DevSecOps 

Descripción 
Crear el repositorio del proyecto y configurar la estructura inicial de archivos y carpetas. 

Criterios de aceptación:
-Repositorio creado. 
-README.md creado. 
-CHARTER.md creado. 
-Carpeta docs/adr/ creada. 
-ADR-0001 incorporado. 
-Reglas básicas de Pull Request configuradas. 

 
Issue #2 — Diseñar arquitectura multi-tenant 

Responsable: Sebastian Chacon — Tech Lead 

Descripción 
Definir la arquitectura inicial de AulaViva considerando el aislamiento lógico de información 
entre colegios. 


Criterios de aceptación:

-Arquitectura inicial documentada. 
-Se define cómo identificar cada colegio. 
-Se define estrategia de aislamiento de datos. 
-Se identifican los principales componentes del sistema. 
-Se documentan riesgos técnicos iniciales. 

 

Issue #3 — Diseñar tutor IA basado en RAG 

Responsable: Kevin Ramos — AI/Data 

Descripción 
Definir el funcionamiento inicial del tutor IA y cómo utilizará RAG para responder preguntas utilizando los contenidos correspondientes a cada curso. 

Criterios de aceptación: 

-Flujo básico del tutor IA definido. 
-Fuentes de información identificadas. 
-Estrategia RAG definida. 
-Se considera aislamiento de información por colegio. 
-Se identifican riesgos de respuestas incorrectas o no fundamentadas. 
-Se consideran las restricciones de la política de IA. 


Distribución inicial de responsabilidades:

Agustin Yañez — Product Owner 
-Definir y priorizar funcionalidades. 
-Representar las necesidades de los usuarios. 
-Mantener actualizado el backlog. 
-Coordinar los objetivos del producto. 

Sebastian Chacon — Tech Lead 
-Definir arquitectura. 
-Supervisar decisiones técnicas. 
-Revisar Pull Requests relacionados con arquitectura. 
-Identificar riesgos técnicos. 

Sharick Jimenez — DevSecOps 
-Administrar repositorio. 
-Configurar CI/CD. 
-Apoyar la infraestructura. 
-Velar por buenas prácticas de seguridad. 

Kevin Ramos — AI/Data 
-Diseñar el tutor IA. 
-Diseñar estrategia RAG. 
-Gestionar las fuentes de conocimiento. 
-Evaluar riesgos relacionados con respuestas generadas por IA. 

Eduardo Erazo — QA 
-Definir estrategia de pruebas. 
-Revisar criterios de aceptación. 
-Validar funcionalidades. 
-Registrar y hacer seguimiento de errores. 

Basthian Reyes — UX/UI 
-Diseñar interfaces. 
-Definir flujos de usuario. 
-Identificar necesidades de estudiantes, docentes y apoderados. 
-Apoyar la validación de usabilidad. 

El equipo AulaViva, compuesto por Agustin Yañez, Sebastian Chacon, Sharick Jimenez, Kevin Ramos, Eduardo Erazo y Basthian Reyes, 
seleccionó por consenso la iniciativa AulaViva — Plataforma SaaS multi-tenant con tutor IA. 
El equipo definió sus roles, propósito, misión, valores, reglas de trabajo, canales de comunicación, Definition of Done y política de uso responsable de IA. 
Además, se elaboró el ADR-0001, justificando la selección de la iniciativa, y se estableció un tablero Kanban con seis columnas y tres Issues iniciales. 
Con esto, el equipo queda organizado para comenzar el desarrollo del MVP de AulaViva. 

# Equipo-AulaViva-

Plataforma SaaS multi-tenant con tutor de IA para instituciones educativas.

## Descripción

AulaViva permite a los colegios gestionar estudiantes, docentes, cursos y
evaluaciones. También incorpora un tutor de IA basado en RAG.

## Objetivo

Crear un MVP seguro y escalable que mejore la experiencia educativa y entregue
apoyo personalizado a los estudiantes de cada institución.

## Equipo

- Agustin Yañez — Product Owner
- Sebastian Chacon — Tech Lead
- Sharick Jimenez — DevSecOps
- Kevin Ramos — AI/Data
- Eduardo Erazo — QA
- Basthian Reyes — UX/UI

## Documentación

- [Acta del proyecto](CHARTER.md)
- [ADR de elección de la iniciativa](docs/adr/0002-eleccion-iniciativa.md)
- [Modelo de amenazas STRIDE de invitaciones](docs/threat-model.md)

## S09 · Seguridad en el CI

El flujo de GitHub Actions ejecuta tres controles de seguridad.

| Control | Qué revisa | Cuándo falla |
| --- | --- | --- |
| Semgrep (SAST) | Código fuente y pruebas de las reglas propias de inyección SQL y roles de sesión. | Cuando detecta un hallazgo o falla una prueba de las reglas. |
| Trivy (SCA) | Dependencias de Composer y npm. | Cuando detecta una vulnerabilidad alta o crítica con parche disponible. |
| Gitleaks | Historial de Git en busca de credenciales. | Cuando detecta una coincidencia fuera de las excepciones por huella de `.gitleaksignore`. |

En **Actions → ejecución del PR → Artifacts**, el artefacto `sbom` contiene
`sbom.json` (CycloneDX) y `sbom.spdx.json` (SPDX). El artefacto `sca-report`
contiene `trivy-report.json`, el informe de vulnerabilidades.

### Revisión de hallazgos y excepciones

1. Registrar la herramienta, el archivo o la dependencia afectada, la versión,
   la severidad y el identificador del hallazgo.
2. Evaluar si el código vulnerable es alcanzable y qué datos podrían verse
   afectados. Si hay una corrección aplicable, actualizar la dependencia y su
   archivo de bloqueo, y volver a ejecutar el CI.
3. Si un CVE no aplica al proyecto, solicitar una excepción VEX en un PR.
   Incluir el CVE, el componente y su versión, la justificación técnica, la
   evidencia y una fecha de revisión.
4. Si se confirma un secreto, revocarlo o rotarlo y retirar su uso del código.
   Si es un falso positivo verificado, documentar la huella exacta y el motivo.

### Priorización y consulta del SBOM

Los plazos del taller son menos de 7 días para hallazgos críticos, menos de
30 días para los altos y menos de 90 días para los medios.

Para investigar una vulnerabilidad transitiva, buscar el CVE en
`trivy-report.json` y anotar el paquete y la versión instalada. Luego, buscar
el componente en `sbom.json` y seguir su `bom-ref` en `dependencies` para
identificar qué dependencia lo incorpora.
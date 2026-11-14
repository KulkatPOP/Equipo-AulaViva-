# ADR 0006: Firma de imágenes, provenance y controles de ejecución

Estado: propuesto para revisión. Implementación local preparada; despliegue y evidencias de CI pendientes.

## Contexto
AulaViva separa datos de colegios y utiliza sesiones y roles. Un contenedor alterado puede comprometer los datos de varias instituciones. S09 aporta SAST, SCA y detección de secretos. S10 agrega pruebas de infraestructura, imagen, aplicación en ejecución y origen verificable.

## Decisión
1. Conservar los controles de calidad y seguridad S08/S09 como prerrequisitos.
2. Ejecutar Checkov sobre Terraform y controles propios de propiedad del namespace y denegación de ingreso por defecto. Fallar si hay hallazgos o si no se evaluó ningún control. No existen excepciones de IaC aprobadas.
3. Construir y publicar la imagen en main. Trivy revisa el digest publicado y bloquea HIGH/CRITICAL con corrección disponible (ignore-unfixed: true, heredado del equipo). Los hallazgos sin corrección requieren registro y revisión. La firma y promoción esperan el escaneo.
4. Publicar en GHCR únicamente desde main y firmar el digest mediante Cosign con OIDC de GitHub. No almacenar claves privadas de firma. La identidad autorizada corresponde al workflow reutilizable supply-chain.yml en este repositorio y main.
5. Obtener provenance v1 generada por GitHub Actions, adjuntarla al registro y verificarla con gh. Extraer el mismo predicate y adjuntarlo adicionalmente con Cosign para compatibilidad con la verificación de Kyverno. No crear a mano un JSON que afirme un nivel SLSA.
6. Verificar tanto firma como attestation mediante Cosign, restringiendo issuer e identidad exactos. Identificar la imagen por digest en todas las verificaciones y promociones.
7. Ejecutar ZAP baseline sobre staging efímero. En main utiliza el digest firmado; las ramas construyen su propia imagen temporal. En main utilizar la referencia firmada. En PR probar la candidata local sin publicar ni firmar. Bloquear riesgos Medium o mayores mediante el JSON y fallar si falta el informe. El baseline es pasivo y no demuestra ausencia de XSS, IDOR ni fallas de autorización.
8. En Kubernetes aplicar Kyverno Enforce al namespace aulaviva. Restringir el registro, exigir digest, firma del workflow oficial y attestation SLSA. El namespace debe existir antes de la prueba. Verificar una admisión positiva y un rechazo por falta de firma en una imagen del mismo registro permitido.
9. Detectar shells interactivas, escritura bajo /etc y conexiones a puertos no autorizados con Falco. ERROR y CRITICAL abren un incidente mediante el receptor de Falcosidekick. Falco no tiene una prioridad literal HIGH: se traduce a ERROR o superior.
10. El job promote espera calidad/S09, IaC, firma y DAST antes de proponer un PR GitOps. El overlay debe migrar de tag a digest para quedar alineado con la política de admisión; esta migración y el despliegue persistente siguen pendientes.

## Alcance SLSA
El objetivo del taller es Build L2: build hospedado y provenance autenticada generada por la plataforma. Adjuntar una declaración firmada por sí solo no certifica el nivel. La comprobación final requiere ejecutar el flujo, verificar subject digest, origen y controles de la plataforma. No se afirma L3 ni reproducibilidad bit a bit.

## Alcance de infraestructura
El ZIP no incluye Terraform anterior. El módulo agregado administra namespace y NetworkPolicies del entorno de demostración. No representa AWS, buckets ni bases de datos cloud inexistentes. Requiere un CNI que aplique NetworkPolicy y revisar si ingress-nginx es el namespace real. No importar ni crear infraestructura existente sin revisar primero terraform plan.

## Excepciones
Toda excepción requiere PR con ID de regla/CVE, recurso o componente, justificación, evidencia, control compensatorio, responsable y vencimiento. El ADR debe aprobarse antes de añadir una supresión. No se agregan skips globales ni soft_fail para obtener un resultado verde.

## Retención
Conservar digest, URL y commit del workflow, informes de escaneo y evidencia de verificación. Mantener firmas y attestations en GHCR mientras la imagen pueda desplegarse. GitHub artifacts tienen caducidad y no reemplazan el registro. El plazo de cinco años del ejemplo FinTech no se adopta como obligación para AulaViva.

## Consecuencias
Se requieren GitHub Actions, permisos de publicación en GHCR, OIDC y soporte de artifact attestations según visibilidad/plan del repositorio. Kyverno requiere acceso al registro y a los servicios de confianza. Falco requiere Linux y un driver compatible. La detección por puerto no bloquea exfiltración HTTPS ni valida dominios. El DAST actual cubre la superficie pública y usa SQLite temporal sin datos reales. Falta ampliar a un staging funcional con seed y cuenta dedicada para navegación autenticada.

## Referencias
- https://www.zaproxy.org/docs/docker/baseline-scan/
- https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations
- https://slsa.dev/spec/v1.2/build-requirements
- https://kyverno.io/docs/policy-types/cluster-policy/verify-images/
- https://github.com/falcosecurity/charts/blob/master/charts/falco/values.yaml

## Laboratorio de verificación
Se agrega runtime-lab.yml, iniciado manualmente sobre main, que prepara un clúster kind efímero en GitHub Actions. Incluye una imagen no firmada dedicada a la prueba negativa y un receptor interno de incidentes Falco. La operación del entorno persistente y su ruta externa de alertamiento siguen pendientes. El laboratorio todavía requiere ejecución real para acreditar resultados.

## Validaciones observadas
La ejecución 43549688921 validó PHPUnit con PHP 8.4, cobertura mínima, S09, Checkov y ZAP. Estos resultados preceden a los últimos cambios. Firma, provenance y laboratorio aún necesitan evidencia real.

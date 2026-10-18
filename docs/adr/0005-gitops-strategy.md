# ADR 0005 - Estrategia GitOps

## Estado

Propuesto

## Contexto

AulaViva requiere una estrategia de despliegue que permita separar la
construcción de la aplicación de la administración del estado deseado
de los ambientes.

La sesión S08 incorpora CI/CD y GitOps como parte del proceso de
entrega de la aplicación.

## Decisión

Se propone separar el repositorio de aplicación del repositorio GitOps.

El repositorio de aplicación será responsable del código fuente,
pruebas, controles de calidad y construcción de la imagen.

El repositorio GitOps será responsable de mantener la configuración
de despliegue mediante Kustomize para los ambientes definidos.

Argo CD será utilizado como herramienta de reconciliación entre el
repositorio GitOps y el clúster Kubernetes, sujeto a la validación y
configuración del equipo.

La promoción de una nueva versión se realizará mediante un cambio
controlado en el repositorio GitOps.

## Ambientes

Se consideran inicialmente los ambientes:

- dev
- prod

La configuración específica de cada ambiente se mantendrá mediante
overlays de Kustomize.

## Promoción

El pipeline de CI construirá y publicará la imagen utilizando una
referencia identificable mediante el SHA del commit.

Posteriormente, la promoción actualizará la referencia de imagen en
el repositorio GitOps mediante un Pull Request.

## Rollback

El rollback se realizará revirtiendo el cambio correspondiente en el
repositorio GitOps y permitiendo que Argo CD reconcilie nuevamente el
estado deseado.

## Consecuencias

### Positivas

- Separación entre código y configuración de despliegue.
- Trazabilidad de los cambios.
- Posibilidad de revisar las promociones mediante Pull Requests.
- Facilita el rollback mediante Git.
- Evita que CI tenga que modificar directamente el clúster.

### Negativas

- Se requiere mantener dos repositorios.
- La promoción depende de permisos y coordinación entre repositorios.
- Se requiere configurar y mantener Argo CD.

## Alternativas consideradas

### Despliegue directo desde CI

No se adopta como estrategia principal porque mezcla la construcción
de la aplicación con la modificación directa del estado del clúster.

### GitOps

Se propone como estrategia principal porque permite mantener el
estado deseado del despliegue versionado en Git y utilizar Argo CD
para su reconciliación.

## Responsabilidades

- CI/CD: ejecutar controles, construir y publicar la imagen.
- Repositorio GitOps: mantener la configuración declarativa.
- Argo CD: reconciliar el estado deseado con Kubernetes.
- Equipo: revisar y aprobar los cambios mediante Pull Requests.

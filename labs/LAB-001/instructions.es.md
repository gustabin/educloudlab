En la nube, un **workspace** agrupa los recursos de un proyecto: almacenamiento, bases de datos analíticas, pipelines… Agruparlos permite gestionar permisos, costes y ciclo de vida de forma conjunta.

Al empezar este laboratorio se creó un **workspace dedicado** para ti. Todo lo que hagas debe ocurrir dentro de ese workspace: ábrelo con el enlace «Abrir workspace» de esta página.

## t1

Crea un recurso de tipo **Almacenamiento** llamado `datos-crudos`. Aquí guardarías los archivos tal como llegan de los sistemas de origen.

Añádele la etiqueta `proyecto = retail`. Las **etiquetas** (tags) son pares clave-valor que sirven para buscar, filtrar y repartir costes entre proyectos.

## t2

No todos los datos se leen con la misma frecuencia. Crea otro almacenamiento llamado `archivo-historico` con esta configuración:

- **Nivel de acceso:** `archive` (muy barato de guardar, lento y caro de leer).
- **Redundancia:** `zrs` (copias en varias zonas de la región).

## t3

Un **lakehouse** combina archivos y tablas analíticas organizadas por capas (bronze, silver, gold). Crea un lakehouse llamado `lago-retail` con la etiqueta `entorno = dev`.

## t4

Los recursos que ya no se usan generan costes y riesgos. Crea un almacenamiento llamado `temporal` y, a continuación, **elimínalo**.

Fíjate en cómo cambia su estado durante el ciclo de vida: `provisioning → active → deleting → deleted`.

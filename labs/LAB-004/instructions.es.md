Un **data lake** guarda los datos tal como llegan (capa **raw**) y después los convierte en tablas consultables (capa **bronze**). En este laboratorio recorrerás ese camino con un archivo de clientes.

Descarga primero el archivo `customers.csv` desde el enlace de esta página. Contiene datos sintéticos de clientes de una tienda ficticia (no son personas reales). Todo el trabajo se hace en el **workspace del laboratorio**.

## t1

En el workspace del laboratorio, sube el archivo como dataset llamado **`customers`**. Quedará en la capa **raw**: el archivo original, sin cambios.

Cuando el estado sea «activo», abre la vista previa y fíjate en las **columnas y tipos** que se detectaron automáticamente (esquema).

## t2

Las tablas viven en un **lakehouse**. Crea un recurso de tipo **Lakehouse** en el workspace (por ejemplo, `lago`).

## t3

Ingiere el dataset raw `customers` en la capa bronze con el nombre de tabla **`customers`**. La tabla `bronze.customers` tendrá las mismas filas que el archivo, con los nombres de columna normalizados y con tipos (números, fechas, texto).

## t4

Los datos de origen casi nunca están limpios. Escribe una consulta que devuelva **cuántos clientes no tienen email**: una sola fila con un número.

Pruébala en el SQL Lab y guárdala como respuesta de este ejercicio.

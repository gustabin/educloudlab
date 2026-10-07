Un **pipeline** encadena pasos que leen, transforman y guardan datos. En lugar de escribir sentencias `CREATE TABLE`, describes *qué* hay que hacer con una lista de nodos y la plataforma lo ejecuta paso a paso, con un informe de filas y tiempos por paso.

Los tipos de nodo disponibles son:

| Nodo | Para qué sirve |
|---|---|
| `source` | Lee una tabla (`bronze.orders`) o un archivo raw (`"raw": "products"`) |
| `filter` | Conserva las filas que cumplen condiciones |
| `select` | Elige, renombra, convierte o transforma columnas |
| `join` | Une con otra tabla del lakehouse |
| `aggregate` | Agrupa y calcula medidas (sum, count, avg…) |
| `sql` | Un `SELECT` sobre la tabla `input` para lo que no cubren los demás |
| `quality_check` | Comprueba reglas y detiene el pipeline si fallan (`on_fail: stop`) |
| `output` | Guarda el resultado en silver o gold |

El laboratorio ya cargó `bronze.orders`, `bronze.order_items` y `bronze.stores`, y el archivo raw `products`. Abre el workspace del laboratorio y pulsa **Pipelines**.

## t1

Crea un pipeline llamado `pedidos-completados` que:

1. lea `bronze.orders`;
2. conserve solo los pedidos con `status` igual a `completed`;
3. compruebe que `order_id` no es nulo y es único, deteniéndose si no se cumple;
4. guarde el resultado en `silver.orders_completed`.

Guárdalo y **ejecútalo**. Revisa la tabla de pasos de la ejecución: cuántas filas salieron de cada uno.

## t2

Crea un pipeline llamado `ingresos-region` que calcule, por región, los ingresos (`revenue`) y el número de pedidos (`orders`) de los pedidos completados, y los guarde en `gold.revenue_by_region`.

Pistas de diseño: parte de `silver.orders_completed`, une las líneas de pedido (`bronze.order_items`) y las tiendas (`bronze.stores`), calcula el importe de cada línea y agrega.

Observa el **linaje** de la tabla resultante en la sección Datasets: verás de qué tablas procede.

## t3

Los pipelines también pueden leer archivos de la capa **raw** sin ingerirlos antes. Crea un pipeline llamado `catalogo` que lea el archivo raw `products`, conserve las columnas `product_id`, `product_name`, `category` (en **mayúsculas**) y `price`, y guarde el resultado en `silver.products`.

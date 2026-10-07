Un **data warehouse** organiza los datos para analizarlos con un **esquema en estrella**:

- Las **tablas de hechos** registran eventos medibles (cada línea de venta, con su cantidad e importe).
- Las **tablas de dimensiones** describen el contexto de esos eventos (tienda, producto, cliente y fecha).

Cada dimensión tiene una **clave sustituta** (`store_key`, un entero generado por el warehouse) además de la **clave de negocio** del sistema de origen (`store_id`). Los hechos apuntan a las dimensiones con las claves sustitutas.

El laboratorio ya cargó en bronze `stores`, `products`, `customers`, `orders` y `order_items`. Construye cada tabla con el **SQL Lab** («Guardar como tabla» en la capa gold).

## t1

Crea `gold.dim_store` con las columnas `store_key`, `store_id`, `store_name`, `city` y `region`: una fila por tienda y una clave sustituta única.

## t2

Crea `gold.dim_product` con `product_key`, `product_id`, `product_name`, `category` y `price`.

## t3

La **dimensión de fechas** permite analizar por año, mes o día sin cálculos repetidos. Crea `gold.dim_date` con:

| Columna | Contenido |
|---|---|
| `date_key` | Entero `AAAAMMDD` (por ejemplo `20250523`) |
| `full_date` | La fecha (tipo DATE) |
| `year`, `month` | Año y mes |

Debe incluir, al menos, todas las fechas en las que hay pedidos.

## t4

En `bronze.customers` hay clientes repetidos. Una dimensión **SCD tipo 1** (*slowly changing dimension*) guarda **una fila por cliente con sus valores actuales**: cuando un atributo cambia, se sobrescribe y no se guarda historial.

Crea `gold.dim_customer` con `customer_key`, `customer_id`, `first_name`, `last_name`, `email` (normalizado) y `city`.

## t5

Crea la tabla de hechos `gold.fact_sales` con una fila por **línea de pedido completado**:

`order_id`, `date_key`, `store_key`, `product_key`, `customer_key`, `quantity`, `unit_price` y `line_total` (= `quantity * unit_price`).

Las claves deben existir en sus dimensiones: es la **integridad referencial** que el laboratorio comprobará.

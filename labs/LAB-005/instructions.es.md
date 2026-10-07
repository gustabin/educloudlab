La **arquitectura medallion** organiza un lakehouse en capas de calidad creciente:

- **Bronze:** los datos tal como llegan.
- **Silver:** datos limpios, deduplicados y con tipos correctos.
- **Gold:** tablas agregadas listas para el negocio (indicadores y dashboards).

Al empezar se creó un workspace con un lakehouse y las tablas `bronze.customers`, `bronze.stores`, `bronze.orders` y `bronze.order_items`. Esas tablas forman parte del laboratorio y no se pueden eliminar.

**Cómo trabajar:** en el **SQL Lab** del workspace, escribe la consulta que construye cada tabla. Cuando el resultado sea correcto, pulsa **«Guardar como tabla»** y elige la capa y el nombre que pide el ejercicio. Si te equivocas, elimina la tabla desde el workspace y vuelve a crearla.

## t1

`bronze.customers` tiene defectos: algunos clientes aparecen **dos veces** (con el email en mayúsculas y con espacios) y otros **no tienen email**.

Crea **`silver.customers`** con una fila por cliente que cumpla esto:

- Incluye al menos las columnas `customer_id` y `email`.
- El email está normalizado: en minúsculas y sin espacios.
- Se descartan los clientes sin email.
- No hay `customer_id` repetidos.

## t2

Crea **`silver.order_lines`** con las líneas de los pedidos **completados** (descarta los cancelados). Debe tener estas columnas:

`order_id`, `order_date`, `store_id`, `product_id`, `quantity`, `unit_price` y `line_total`, donde `line_total = quantity * unit_price`.

## t3

Crea **`gold.revenue_by_region`** con una fila por región y estas columnas:

- `region`
- `revenue`: la suma de `line_total`.
- `orders`: el número de pedidos distintos.

## t4

Crea **`gold.monthly_revenue`** con una fila por mes y estas columnas:

- `order_month`: el primer día del mes, de tipo DATE.
- `revenue`: los ingresos de los pedidos completados de ese mes.

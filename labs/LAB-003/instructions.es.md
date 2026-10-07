En este laboratorio practicarás SQL analítico con los datos de una cadena de tiendas ficticia. Al empezar se creó un workspace con un lakehouse y estas tablas en la capa **bronze**:

| Tabla | Contenido |
|---|---|
| `bronze.products` | product_id, product_name, category, price |
| `bronze.stores` | store_id, store_name, city, region |
| `bronze.orders` | order_id, customer_id, store_id, order_date, status (`completed` o `cancelled`) |
| `bronze.order_items` | order_item_id, order_id, product_id, quantity, unit_price |

**Cómo trabajar:** prueba tus consultas en el **SQL Lab** del workspace y, cuando el resultado sea el que buscas, pega la consulta en el cuadro de respuesta del ejercicio y pulsa «Guardar respuesta». Al validar, el servidor vuelve a ejecutar tu consulta y compara su resultado con el esperado.

Los nombres de las columnas no importan, pero sí **cuáles** y **en qué orden** las devuelves. Si el ejercicio no pide un orden, el orden de las filas tampoco importa.

## t1

Lista los productos de la categoría `Libros` con un precio **menor que 20**.

Devuelve las columnas `product_id`, `product_name` y `price`.

## t2

Muestra los **5 productos más caros**, del más caro al más barato. Si hay empates de precio, desempata por `product_id`.

Devuelve las columnas `product_id`, `product_name` y `price`.

## t3

¿Cuántos pedidos se hicieron en cada **región**? La región está en la tabla de tiendas, así que necesitas un **JOIN** entre `bronze.orders` y `bronze.stores`.

Devuelve las columnas `region` y el número de pedidos. Cuenta todos los pedidos, también los cancelados.

## t4

Considera solo los pedidos **completados**. Lista las tiendas que tienen **más de 490** pedidos completados.

Devuelve las columnas `store_id` y el número de pedidos.

## t5

Calcula el **ticket medio**: el importe medio por pedido de los pedidos completados, redondeado a 2 decimales. El importe de un pedido es la suma de `quantity * unit_price` de sus líneas.

Usa una **CTE** (`WITH totals AS (…)`) para calcular primero el total de cada pedido. La respuesta es una sola fila con una sola columna.

## t6

¿Qué productos **no se han vendido nunca**? Usa una **subconsulta** sobre `bronze.order_items`.

Devuelve las columnas `product_id` y `product_name`.

## t7

Para cada categoría, encuentra el **producto con más ingresos**. Los ingresos de un producto son la suma de `quantity * unit_price` de todas sus líneas, sin filtrar por estado.

Usa una **función de ventana** (`row_number() OVER (PARTITION BY … ORDER BY …)`). En caso de empate, gana el `product_id` menor.

Devuelve las columnas `category`, `product_id` e `ingresos` (redondeados a 2 decimales).

## t8

Cuenta los pedidos **completados** de cada **mes** de 2025.

Devuelve el número de mes (1 a 12) y el número de pedidos, **ordenado por mes**.

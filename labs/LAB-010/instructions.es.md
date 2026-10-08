Eres la persona de datos de **Tiendas del Valle**, una cadena ficticia de 20 tiendas en 5 regiones. Dirección quiere un panel con la marcha del negocio en 2025, y los datos llegan como cinco archivos CSV con defectos. En este proyecto final recorrerás todo el camino:

**raw → bronze → silver → gold → modelo semántico → dashboard**

El laboratorio ya creó el lakehouse `lago` y dejó en la capa **raw** los archivos `customers`, `products`, `stores`, `orders` y `order_items`. A partir de aquí el trabajo es tuyo: las instrucciones dicen **qué** se espera, no cómo hacerlo. Si te atascas, las pistas restan pocos puntos, y los laboratorios LAB-005, LAB-006, LAB-007 y LAB-009 cubren cada paso.

Puedes enviar el laboratorio tantas veces como quieras: cuenta tu mejor nota.

## t1

**Ingesta.** Convierte los cinco archivos raw en tablas **bronze** con el mismo nombre (`bronze.customers`, `bronze.products`, `bronze.stores`, `bronze.orders` y `bronze.order_items`).

Bronze es una copia fiel del origen: no filtres ni corrijas nada todavía.

## t2

**Calidad de clientes con un pipeline.** Explora `bronze.customers` en el SQL Lab y busca los defectos. Después crea un pipeline llamado `clientes-silver` que:

1. lea `bronze.customers` (paso `source`);
2. corrija los defectos con un paso `sql`: una sola fila por `customer_id` y emails normalizados en minúsculas y sin espacios;
3. verifique el resultado con un paso `quality_check`;
4. guarde `silver.customers` (paso `output`).

Ejecuta el pipeline y comprueba que termina bien. Ojo: un cliente sin email sigue siendo un cliente con pedidos.

## t3

**Ventas limpias.** Crea `silver.sales` con una fila por **línea de pedido completado** y estas columnas:

`order_id`, `order_date`, `customer_id`, `store_id`, `product_id`, `quantity`, `unit_price`, `line_total`

`line_total` es el importe de la línea (`quantity * unit_price`). Los pedidos cancelados no son ventas.

## t4

**Esquema en estrella.** En la capa **gold**, construye:

| Tabla | Grano | Columnas mínimas |
|---|---|---|
| `dim_customer` | un cliente (desde `silver.customers`) | `customer_key`, `customer_id`, `email`, `city` |
| `dim_product` | un producto | `product_key`, `product_id`, `product_name`, `category` |
| `dim_store` | una tienda | `store_key`, `store_id`, `store_name`, `region` |
| `dim_date` | un día con ventas | `date_key` (entero AAAAMMDD), `full_date` (DATE), `year`, `month` |
| `fact_sales` | una línea de venta (desde `silver.sales`) | `order_id`, `date_key`, `customer_key`, `product_key`, `store_key`, `quantity`, `line_total` |

Empieza por las dimensiones: cada una tiene una **clave sustituta** única (`*_key`).

## t5

**Tabla de hechos.** Completa el esquema con `gold.fact_sales`. Debe:
- tener una fila por cada línea de `silver.sales`, sin perder ni duplicar filas en las uniones;
- guardar solo claves que existan en sus dimensiones.

## t6

**Modelo semántico.** En **Analítica**, crea el modelo `retail` sobre `gold.fact_sales`, relacionado con sus cuatro dimensiones.

- **Medidas:**
  - ingresos (suma de `line_total`);
  - pedidos (pedidos distintos);
  - clientes (clientes distintos);
  - ticket medio (ingresos / pedidos).
- **Dimensiones:**
  - región (`dim_store`);
  - categoría y producto (`dim_product`);
  - mes (`full_date` de `dim_date` con `"grain": "month"`).

Usa **Explorar el modelo** para responder: ¿qué región vende más? ¿Qué mes fue el mejor?

## t7

**Dashboard.** Crea el dashboard `panel-retail` sobre el modelo `retail` con:

- cuatro KPI: ingresos, pedidos, clientes y ticket medio;
- barras de ingresos por región;
- una tabla de ingresos por producto;
- líneas de ingresos por mes;
- un filtro por región.

Ábrelo, prueba el filtro y revisa que las cifras cuadran con lo que viste en el SQL Lab. Si quieres ir más allá (no se evalúa), explora `gold.fact_sales` en un notebook.

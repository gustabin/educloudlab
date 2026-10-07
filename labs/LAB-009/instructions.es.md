Una **capa semántica** traduce las tablas del warehouse al lenguaje del negocio. En lugar de escribir SQL en cada informe, defines una vez:

- **Medidas:** qué se calcula y cómo se agrega (ingresos = suma de `line_total`).
- **Dimensiones:** por qué atributos se puede analizar (región, mes…).
- **Relaciones:** cómo se une la tabla de hechos con sus dimensiones.

Los **dashboards** se construyen sobre esas medidas y dimensiones, y la plataforma genera las consultas por ti.

El laboratorio ya cargó `stores`, `orders` y `order_items` en bronze. Abre el workspace y entra en **Analítica**.

## t1

Prepara un esquema en estrella mínimo. En el **SQL Lab**, guarda estas consultas como tablas de la capa **gold**:

`dim_store`:

```sql
SELECT row_number() OVER (ORDER BY store_id) AS store_key, store_id, store_name, city, region
FROM bronze.stores
```

`fact_sales`:

```sql
SELECT o.order_id, o.order_date, s.store_key, i.quantity * i.unit_price AS line_total
FROM bronze.order_items i
JOIN bronze.orders o ON o.order_id = i.order_id
JOIN gold.dim_store s ON s.store_id = o.store_id
WHERE o.status = 'completed'
```

## t2

Crea un modelo semántico llamado `ventas` con:

- **Hechos:** `gold.fact_sales`, relacionada con `gold.dim_store` por `store_key`.
- **Medidas:** `ingresos` (suma de `line_total`), `pedidos` (pedidos distintos) y `ticket_medio` (ingresos / pedidos).
- **Dimensiones:** `region` (de `gold.dim_store`) y `mes` (`order_date` de la tabla de hechos, con `"grain": "month"`).

Prueba el modelo con **Explorar el modelo**: ingresos y ticket medio por región.

## t3

Crea un dashboard llamado `panel-ventas` sobre el modelo `ventas` con:

- un **KPI** de ingresos y otro de ticket medio;
- un gráfico de **barras** de ingresos por región;
- un gráfico de **líneas** de ingresos por mes;
- un **filtro** por región.

Ábrelo con «Abrir dashboard» y prueba el filtro: todos los widgets se recalculan a la vez. Cada gráfico tiene una tabla «Ver datos» con sus valores.

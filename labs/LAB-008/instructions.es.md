Un **notebook** combina texto y código que se ejecuta celda a celda. En EduCloud Lab cada ejecución corre en un **contenedor aislado**:
- sin acceso a internet;
- con una copia de solo lectura de tu lakehouse;
- con límites de memoria y de tiempo.

Lo que calcules lo guardas con `save_result()`, y así queda registrado como resultado de la ejecución.

Las herramientas que tienes disponibles en cada celda son:

| Función | Para qué sirve |
|---|---|
| `con = lakehouse()` | Conexión de solo lectura al lakehouse (DuckDB) |
| `con.sql("SELECT …").df()` | El resultado de una consulta como DataFrame de pandas |
| `save_result("nombre", df)` | Guarda un DataFrame como resultado (máximo 1000 filas) |

El laboratorio ya cargó `bronze.stores`, `bronze.orders` y `bronze.order_items`. Abre el workspace del laboratorio y entra en **Notebooks**.

## t1

Crea un notebook llamado `exploracion`. Añade una celda de código que muestre las primeras filas de los pedidos:

```python
con = lakehouse()
pedidos = con.sql("SELECT * FROM bronze.orders").df()
pedidos.head()
```

Pulsa **Ejecutar todo** y revisa la salida: verás una tabla con las primeras filas.

## t2

Calcula con **pandas** el número de pedidos **completados** por región y guárdalo con el nombre `pedidos_por_region`. El resultado debe tener dos columnas: `region` y `pedidos`.

Pista de estructura: une `pedidos` con las tiendas (`bronze.stores`) por `store_id`, filtra el estado y agrupa.

## t3

Calcula los **ingresos mensuales** de los pedidos completados: para cada mes (texto `AAAA-MM`), la suma de `quantity * unit_price` de sus líneas. Guárdalo como `ingresos_mensuales` con dos columnas: `mes` e `ingresos`.

Puedes resolverlo con pandas o con SQL dentro del notebook: lo importante es el resultado que guardes. Ejecuta el notebook completo antes de enviar el laboratorio.

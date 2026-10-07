El **almacenamiento de objetos** guarda archivos de cualquier tipo como *objetos* dentro de **contenedores**. No hay carpetas reales: cada objeto tiene una **clave** (por ejemplo `2025/pedidos.csv`) y las barras de la clave se usan para agruparlos por **prefijos**.

Cada objeto puede llevar **metadatos** (pares clave-valor que lo describen) y un **nivel de acceso**:

- `hot`: lectura frecuente; almacenar es más caro, leer es barato.
- `cool`: lectura ocasional; más barato de guardar.
- `archive`: casi nunca se lee; muy barato de guardar, pero hay que **rehidratarlo** (pasarlo a hot o cool) antes de leerlo.

Descarga `orders.csv` y `products.csv` desde esta página. Después abre el workspace del laboratorio con «Abrir workspace».

## t1

Crea un recurso de tipo **Almacenamiento** llamado `almacen` y pulsa **Abrir** en su fila. Dentro, crea un contenedor llamado `ventas-crudas`.

## t2

Sube los dos archivos descargados al contenedor `ventas-crudas` con estas claves:

| Archivo | Clave |
|---|---|
| `orders.csv` | `2025/pedidos.csv` |
| `products.csv` | `2025/productos.csv` |

Usa el filtro por prefijo `2025/` para ver solo esos objetos: así se organizan los datos por año sin crear carpetas.

## t3

Los metadatos ayudan a saber de dónde viene cada archivo. El objeto `2025/pedidos.csv` debe tener el metadato `origen = erp`.

Para cambiar los metadatos de un objeto que ya subiste, vuelve a subirlo con la **misma clave**: el objeto se reemplaza.

## t4

El catálogo de productos se consulta poco. Cambia el nivel de acceso de `2025/productos.csv` a `cool`.

Prueba también a ponerlo en `archive` e intenta descargarlo: verás que hay que rehidratarlo antes. Déjalo en `cool` al terminar.

## t5

Las **políticas de ciclo de vida** mueven y borran objetos automáticamente según su antigüedad. Configura el contenedor `ventas-crudas` para que:

- archive los objetos a los **30** días;
- los elimine a los **365** días.

El programador de tareas de la plataforma aplica estas políticas cada pocos minutos.

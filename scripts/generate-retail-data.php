<?php

/**
 * Deterministic synthetic retail dataset for the labs (CC0, no real people or companies).
 *
 *   php scripts/generate-retail-data.php
 *
 * Writes public/assets/datasets/retail/{stores,products,customers,orders,order_items}.csv. The files are committed;
 * regenerate only when the lab content changes (lab checks compute expectations from the data with SQL, except
 * LAB-004's row count, which must be updated if the customer counts below change).
 *
 * Deliberate quality defects (for cleaning exercises):
 *  - customers: 30 duplicated rows (same customer_id, email with different case and surrounding spaces),
 *    40 customers without email;
 *  - orders: ~5 % cancelled;
 *  - products: 8 products are never sold.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mt_srand(20260601, MT_RAND_MT19937);

$out = dirname(__DIR__) . '/public/assets/datasets/retail';
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}

function pick(array $items): mixed
{
    return $items[mt_rand(0, count($items) - 1)];
}

function ascii(string $s): string
{
    $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n'];
    return strtolower(strtr($s, $map));
}

/** @param list<list<string|int>> $rows */
function writeCsv(string $file, array $header, array $rows): void
{
    $h = fopen($file, 'wb');
    fputcsv($h, $header, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($h, $row, ',', '"', '');
    }
    fclose($h);
}

$regions = ['Norte', 'Sur', 'Este', 'Oeste', 'Centro'];
$cities = [
    'Norte' => ['Villa Alta', 'Puerto Claro', 'Monteverde', 'Río Frío'],
    'Sur' => ['Bahía Serena', 'Campo Dorado', 'San Telmo', 'Las Dunas'],
    'Este' => ['Costa Azul', 'Valle Nuevo', 'Mirador', 'Aguaclara'],
    'Oeste' => ['Sierra Roja', 'Llano Verde', 'Piedra Blanca', 'El Cruce'],
    'Centro' => ['Ciudad Central', 'Plaza Mayor', 'Los Olivos', 'Parque Real'],
];

// stores: 4 per region
$stores = [];
$id = 1;
foreach ($regions as $region) {
    foreach ($cities[$region] as $city) {
        $stores[] = [$id, 'Tienda ' . $city, $city, $region];
        $id++;
    }
}

// products: 5 categories x 40
$categories = [
    'Electrónica' => [[25, 900], ['Auriculares', 'Monitor', 'Teclado', 'Ratón', 'Altavoz', 'Tableta', 'Cámara', 'Cargador']],
    'Hogar' => [[5, 300], ['Lámpara', 'Silla', 'Mesa auxiliar', 'Cojín', 'Sartén', 'Toalla', 'Reloj de pared', 'Estantería']],
    'Deportes' => [[8, 450], ['Balón', 'Raqueta', 'Esterilla', 'Mancuerna', 'Bicicleta estática', 'Mochila', 'Botella', 'Casco']],
    'Libros' => [[6, 60], ['Novela', 'Ensayo', 'Guía de viaje', 'Cómic', 'Recetario', 'Atlas', 'Diccionario', 'Poemario']],
    'Juguetes' => [[4, 120], ['Puzzle', 'Peluche', 'Juego de mesa', 'Bloques', 'Coche teledirigido', 'Muñeca', 'Cometa', 'Pizarra']],
];
$adjectives = ['Básico', 'Pro', 'Plus', 'Eco', 'Mini'];
$products = [];
$prices = [];
$id = 1;
foreach ($categories as $category => [[$min, $max], $names]) {
    foreach ($names as $name) {
        foreach ($adjectives as $adj) {
            $price = mt_rand($min * 100, $max * 100) / 100;
            $products[] = [$id, "$name $adj", $category, number_format($price, 2, '.', '')];
            $prices[$id] = $price;
            $id++;
        }
    }
}
$neverSold = [7, 33, 61, 88, 120, 147, 171, 199];

// customers: 1000 unique (+30 duplicates, 40 without email)
$firstNames = ['Ana', 'Luis', 'María', 'Carlos', 'Lucía', 'Javier', 'Sofía', 'Diego', 'Elena', 'Pablo', 'Marta', 'Andrés',
    'Laura', 'Miguel', 'Paula', 'Jorge', 'Clara', 'Raúl', 'Irene', 'Hugo', 'Nuria', 'Óscar', 'Sara', 'Iván'];
$lastNames = ['García', 'López', 'Martínez', 'Sánchez', 'Pérez', 'Gómez', 'Ruiz', 'Díaz', 'Moreno', 'Muñoz', 'Álvarez',
    'Romero', 'Navarro', 'Torres', 'Domínguez', 'Vázquez', 'Ramos', 'Gil', 'Serrano', 'Molina'];
$allCities = array_merge(...array_values($cities));
$customers = [];
$noEmail = [];
while (count($noEmail) < 40) {
    $noEmail[mt_rand(1, 1000)] = true;
}
for ($id = 1; $id <= 1000; $id++) {
    $first = pick($firstNames);
    $last = pick($lastNames);
    $email = isset($noEmail[$id]) ? '' : ascii($first) . '.' . ascii($last) . $id . '@correo.example';
    $signup = date('Y-m-d', mktime(0, 0, 0, 1, 1, 2023) + mt_rand(0, 729) * 86400);
    $customers[$id] = [$id, $first, $last, $email, pick($allCities), $signup];
}
$rows = array_values($customers);
$duplicated = 0;
while ($duplicated < 30) {
    $c = $customers[mt_rand(1, 1000)];
    if ($c[3] === '') {
        continue;
    }
    $c[3] = '  ' . strtoupper($c[3]) . ' ';
    $rows[] = $c;
    $duplicated++;
}
// Shuffle deterministically so duplicates are not all at the end.
for ($i = count($rows) - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
}
$customerRows = $rows;

// orders + items
$orders = [];
$items = [];
$itemId = 1;
$sellable = array_values(array_diff(array_keys($prices), $neverSold));
for ($id = 1; $id <= 10000; $id++) {
    $date = date('Y-m-d', mktime(0, 0, 0, 1, 1, 2025) + mt_rand(0, 364) * 86400);
    $status = mt_rand(1, 100) <= 5 ? 'cancelled' : 'completed';
    $orders[] = [$id, mt_rand(1, 1000), mt_rand(1, 20), $date, $status];
    $lines = mt_rand(1, 5);
    for ($l = 0; $l < $lines; $l++) {
        $product = pick($sellable);
        $items[] = [$itemId++, $id, $product, mt_rand(1, 4), number_format($prices[$product], 2, '.', '')];
    }
}

writeCsv("$out/stores.csv", ['store_id', 'store_name', 'city', 'region'], $stores);
writeCsv("$out/products.csv", ['product_id', 'product_name', 'category', 'price'], $products);
writeCsv("$out/customers.csv", ['customer_id', 'first_name', 'last_name', 'email', 'city', 'signup_date'], $customerRows);
writeCsv("$out/orders.csv", ['order_id', 'customer_id', 'store_id', 'order_date', 'status'], $orders);
writeCsv("$out/order_items.csv", ['order_item_id', 'order_id', 'product_id', 'quantity', 'unit_price'], $items);

printf(
    "stores=%d products=%d customers=%d (unique 1000) orders=%d order_items=%d\n",
    count($stores),
    count($products),
    count($customerRows),
    count($orders),
    count($items)
);

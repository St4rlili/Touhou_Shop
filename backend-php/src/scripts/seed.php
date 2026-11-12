<?php
// docker compose exec backend php scripts/seed.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/db.php';

use MongoDB\BSON\UTCDateTime;

$db = getDB();

$users = $db->selectCollection('users');
$users->createIndex(['username' => 1], ['unique' => true]);
$cartItems = $db->selectCollection('cart_items');
$cartItems->createIndex(['username' => 1, 'productId' => 1], ['unique' => true]);
$cartItems->createIndex(['expiresAt' => 1]);

$adminUser = getenv('ADMIN_USER') ?: 'admin';
$adminPass = getenv('ADMIN_PASSWORD') ?: 'admin1234';
$users->updateOne(
    ['username' => $adminUser],
    ['$set' => [
        'password' => password_hash($adminPass, PASSWORD_DEFAULT),
        'role' => 'administrador'
    ], '$setOnInsert' => ['createdAt' => new UTCDateTime()]],
    ['upsert' => true]
);
echo "Administrador listo: $adminUser\n";

$products = $db->selectCollection('products');
if ($products->countDocuments() === 0) {
    $now = new UTCDateTime();
    $items = [
        ['Peluche de Cirno', 'El hada de hielo más fuerte (ella lo dice). Mantener lejos del sol.', 14.90, 25, 'Peluches'],
        ['Peluche de Flandre Scarlet', 'Suave, con alas de cristal. Se recomienda no jugar a «destruir» con ella.', 19.90, 15, 'Peluches'],
        ['Figura de Remilia Scarlet 1/8', 'La Dama Escarlata con su sombrilla, sobre base de la Mansión.', 59.90, 8, 'Figuras'],
        ['Figura de Rumia', 'La youkai de la oscuridad, con los brazos abiertos. Incluye base.', 34.90, 12, 'Figuras'],
        ['Set de cuchillos de Sakuya (espuma)', 'Réplicas blandas del arsenal de la jefa de criadas. Seguras para fiestas.', 12.50, 30, 'Accesorios'],
        ['Té negro de la Mansión Escarlata', 'Mezcla de la casa servida a las cinco, bajo la luna roja.', 8.90, 40, 'Té y dulces'],
        ['Grimorio de Patchouli (cuaderno)', 'Cuaderno de tapa dura con 200 páginas para tus hechizos.', 16.00, 18, 'Papelería'],
        ['Póster «Embodiment of Scarlet Devil»', 'Ilustración A2 con las jefas de las seis fases.', 9.90, 50, 'Papelería'],
    ];
    $docs = array_map(fn($i) => [
        'name' => $i[0], 'description' => $i[1], 'price' => $i[2], 'stock' => $i[3],
        'category' => $i[4], 'imageUrl' => '', 'createdAt' => $now, 'updatedAt' => $now
    ], $items);
    $products->insertMany($docs);
    echo count($docs) . " productos de ejemplo creados.\n";
} else {
    echo "Ya hay productos; no se añaden los de ejemplo.\n";
}

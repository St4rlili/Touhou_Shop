<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once 'config/helpers.php';
require_once 'config/db.php';
require_once 'middlewares/authMiddleware.php';
require_once 'controllers/authController.php';
require_once 'controllers/productController.php';
require_once 'controllers/cartController.php';

header('Content-Type: application/json; charset=utf-8');

// Valida la clave de seguridad de Node
verifyApiKey();

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$path = preg_replace('#^/api#', '', $path);
$method = $_SERVER['REQUEST_METHOD'];

$id = '([a-f0-9]{24})';
$routes = [
    ['POST',   '#^/auth/register$#',    'registerUser'],
    ['POST',   '#^/auth/login$#',       'loginUser'],
    ['GET',    '#^/products$#',         'listProducts'],
    ['GET',    "#^/products/$id$#",     'getProduct'],
    ['POST',   '#^/products$#',         'createProduct'],
    ['PUT',    "#^/products/$id$#",     'updateProduct'],
    ['DELETE', "#^/products/$id$#",     'deleteProduct'],
    ['GET',    '#^/cart$#',             'getCart'],
    ['GET',    '#^/cart/summary$#',     'cartSummary'],
    ['POST',   '#^/cart/items$#',       'addCartItem'],
    ['PUT',    "#^/cart/items/$id$#",   'setCartItem'],
    ['DELETE', "#^/cart/items/$id$#",   'removeCartItem'],
];

foreach ($routes as [$m, $pattern, $handler]) {
    if ($m === $method && preg_match($pattern, $path, $matches)) {
        array_shift($matches);
        call_user_func_array($handler, $matches);
        exit;
    }
}

jsonResponse([
    'error' => 'Endpoint no encontrado en la API de PHP',
    'uri_recibida' => $path,
    'metodo' => $method
], 404);

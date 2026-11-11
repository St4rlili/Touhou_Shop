<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once 'config/helpers.php';
require_once 'config/db.php';
require_once 'middlewares/authMiddleware.php';
require_once 'controllers/authController.php';

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

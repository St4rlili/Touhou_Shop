<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/db.php';
require_once 'middlewares/authMiddleware.php';
require_once 'controllers/authController.php';

// Valida la clave de seguridad de Node
verifyApiKey();

// Limpia y formatea la URL recibida
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = rtrim($requestUri, '/');
$requestMethod = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

// Enrutador
if (($requestUri === '/api/auth/register' || $requestUri === '/auth/register') && $requestMethod === 'POST') {
    registerUser();
} elseif (($requestUri === '/api/auth/login' || $requestUri === '/auth/login') && $requestMethod === 'POST') {
    loginUser();
} else {
    header('HTTP/1.1 404 Not Found');
    echo json_encode([
        'error' => 'Endpoint no encontrado en la API de PHP',
        'uri_recibida' => $requestUri,
        'metodo' => $requestMethod
    ]);
}

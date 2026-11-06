<?php
define('API_KEY', 'claveapi');

function verifyApiKey() {
    // Capturar la cabecera enviada por Node.js
    $headers = getallheaders();
    $incomingKey = isset($headers['x-api-key']) ? $headers['x-api-key'] : '';

    if ($incomingKey !== API_KEY) {
        header('Content-Type: application/json', true, 401);
        echo json_encode(['error' => 'No autorizado. Clave de API no válida o ausente.']);
        exit;
    }
}

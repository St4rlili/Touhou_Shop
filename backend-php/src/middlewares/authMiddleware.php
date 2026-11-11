<?php

function verifyApiKey() {
    $expected = getenv('API_KEY') ?: 'claveapi';
    $incoming = requestHeaders()['x-api-key'] ?? '';

    if (!hash_equals($expected, $incoming)) {
        jsonError(401, 'No autorizado. Clave de API no válida o ausente.');
    }
}

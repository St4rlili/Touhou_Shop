<?php
require __DIR__ . '/../vendor/autoload.php';

// Conexión a MongoDB Atlas. Las credenciales vienen del .env (MONGO_URI)
function getDB() {
    static $db = null;
    if ($db !== null) return $db;

    $mongoUri = getenv('MONGO_URI');
    $dbName = getenv('MONGODB_DB') ?: 'TouhouBD';

    if (!$mongoUri) {
        jsonError(500, 'Falta la variable MONGO_URI en el .env del backend.');
    }

    try {
        $client = new MongoDB\Client($mongoUri);
        $db = $client->selectDatabase($dbName);
        return $db;
    } catch (Exception $e) {
        error_log('MongoDB: ' . $e->getMessage());
        jsonError(500, 'Error crítico al conectar a MongoDB.');
    }
}

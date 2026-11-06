<?php
require __DIR__ . '/../vendor/autoload.php';

// Función para obtener la conexión a MongoDB Atlas
function getDB() {
    $mongoUri = getenv('MONGO_URI') ?: 'mongodb+srv://dbUser:dbUser@cluster0.rrvkkmp.mongodb.net/';
    $dbName = getenv('MONGODB_DB') ?: 'TouhouBD';

    try {
        $client = new MongoDB\Client($mongoUri);
        return $client->selectDatabase($dbName);
    } catch (Exception $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => 'Error crítico al conectar a MongoDB: ' . $e->getMessage()]);
        exit(1);
    }
}

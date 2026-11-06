<?php

// Procesa el registro de nuevos compradores
function registerUser() {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';

    if (empty($username) || empty($password)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'El usuario y la contraseña son obligatorios.']);
        return;
    }

    try {
        $db = getDB();
        $collection = $db->selectCollection('users');

        // Busca si ya existe el usuario de forma nativa
        $existingUser = $collection->findOne(['username' => $username]);
        if ($existingUser) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'El nombre de usuario ya está registrado.']);
            return;
        }

        // Crea el documento del nuevo usuario
        $newUser = [
            'username' => $username,
            'password' => $password,
            'role' => 'comprador',
            'createdAt' => new MongoDB\BSON\UTCDateTime()
        ];

        $collection->insertOne($newUser);
        echo json_encode(['ok' => true, 'message' => 'Usuario registrado correctamente.']);
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Error interno al registrar: ' . $e->getMessage()]);
    }
}

// Procesa el inicio de sesión
function loginUser() {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';

    if (empty($username) || empty($password)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'El usuario y la contraseña son obligatorios.']);
        return;
    }

    try {
        $db = getDB();
        $collection = $db->selectCollection('users');

        $user = $collection->findOne(['username' => $username]);

        if (!$user || $user['password'] !== $password) {
            header('HTTP/1.1 401 Unauthorized');
            echo json_encode(['error' => 'Credenciales incorrectas.']);
            return;
        }

        // Devolvemos los datos del usuario para que Node gestione la sesión
        echo json_encode([
            'ok' => true,
            'username' => $user['username'],
            'role' => $user['role']
        ]);
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Error interno en el login: ' . $e->getMessage()]);
    }
}

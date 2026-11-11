<?php

function readCredentials(): array {
    $in = readJsonBody();
    $username = is_string($in['username'] ?? null) ? trim($in['username']) : '';
    $password = is_string($in['password'] ?? null) ? trim($in['password']) : '';
    return [$username, $password];
}

// Registro de nuevos compradores
function registerUser() {
    [$username, $password] = readCredentials();

    if ($username === '' || $password === '') {
        jsonError(400, 'El usuario y la contraseña son obligatorios.');
    }
    if (mb_strlen($username) < 3 || mb_strlen($username) > 30) {
        jsonError(400, 'El usuario debe tener entre 3 y 30 caracteres.');
    }
    if (strlen($password) < 6) {
        jsonError(400, 'La contraseña debe tener al menos 6 caracteres.');
    }

    try {
        $collection = getDB()->selectCollection('users');

        if ($collection->findOne(['username' => $username])) {
            jsonError(400, 'El nombre de usuario ya está registrado.');
        }

        $collection->insertOne([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'comprador',
            'createdAt' => new MongoDB\BSON\UTCDateTime()
        ]);
        jsonResponse(['ok' => true, 'message' => 'Usuario registrado correctamente.']);
    } catch (Exception $e) {
        error_log('register: ' . $e->getMessage());
        jsonError(500, 'Error interno al registrar.');
    }
}

// Inicio de sesión (Node gestiona la sesión con los datos devueltos)
function loginUser() {
    [$username, $password] = readCredentials();

    if ($username === '' || $password === '') {
        jsonError(400, 'El usuario y la contraseña son obligatorios.');
    }

    try {
        $collection = getDB()->selectCollection('users');
        $user = $collection->findOne(['username' => $username]);
        $stored = (string)($user['password'] ?? '');

        if (!$user) {
            jsonError(401, 'Credenciales incorrectas.');
        }

        if (!empty(password_get_info($stored)['algo'])) {
            $valid = password_verify($password, $stored);
        } else {
            // Hasheo
            $valid = hash_equals($stored, $password);
            if ($valid) {
                $collection->updateOne(
                    ['_id' => $user['_id']],
                    ['$set' => ['password' => password_hash($password, PASSWORD_DEFAULT)]]
                );
            }
        }

        if (!$valid) {
            jsonError(401, 'Credenciales incorrectas.');
        }

        jsonResponse(['ok' => true, 'username' => $user['username'], 'role' => $user['role']]);
    } catch (Exception $e) {
        error_log('login: ' . $e->getMessage());
        jsonError(500, 'Error interno en el login.');
    }
}

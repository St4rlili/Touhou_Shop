<?php
// Utilidades compartidas por controladores y middlewares

function jsonResponse($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(int $code, string $message) {
    jsonResponse(['error' => $message], $code);
}

function readJsonBody(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function toObjectId(string $id) {
    try {
        return new MongoDB\BSON\ObjectId($id);
    } catch (Exception $e) {
        jsonError(400, 'ID no válido.');
    }
}

// Convierte tipos BSON a tipos PHP serializables
function bsonToPhp($v) {
    if ($v instanceof MongoDB\BSON\ObjectId) return (string)$v;
    if ($v instanceof MongoDB\BSON\UTCDateTime) return $v->toDateTime()->format(DATE_ATOM);
    if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
        $v = $v->getArrayCopy();
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) $out[$k] = bsonToPhp($x);
        return $out;
    }
    return $v;
}

function docToArray($doc): array {
    $a = bsonToPhp($doc);
    if (isset($a['_id'])) {
        $a = ['id' => $a['_id']] + $a;
        unset($a['_id']);
    }
    return $a;
}

function requestHeaders(): array {
    return array_change_key_case(getallheaders(), CASE_LOWER);
}

// El rol lo envía Node a partir de su sesión
function requireAdmin() {
    if ((requestHeaders()['x-user-role'] ?? '') !== 'administrador') {
        jsonError(403, 'Acceso solo para administradores.');
    }
}

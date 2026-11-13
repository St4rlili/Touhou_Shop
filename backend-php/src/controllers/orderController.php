<?php

use MongoDB\BSON\UTCDateTime;

// POST /orders  { username }
// Convierte el carrito (stock ya reservado) en una solicitud pendiente
function createOrder() {
    $in = readJsonBody();
    $username = is_string($in['username'] ?? null) ? trim($in['username']) : '';
    if ($username === '') jsonError(400, 'Falta el usuario.');

    try {
        releaseExpiredCartItems();
        $db = getDB();
        $cart = $db->selectCollection('cart_items');
        $products = $db->selectCollection('products');

        $items = iterator_to_array($cart->find(['username' => $username]), false);
        if (!$items) jsonError(400, 'Tu carrito está vacío o la reserva ha caducado.');

        $claimed = [];
        $failed = false;
        foreach ($items as $it) {
            $gone = $cart->findOneAndDelete(['_id' => $it['_id']]);
            if ($gone) $claimed[] = $gone; else $failed = true;
        }
        if ($failed) {
            foreach ($claimed as $c) giveStock($c['productId'], (int)$c['quantity']);
            jsonError(409, 'La reserva de tu carrito caducó. Vuelve a añadir los productos.');
        }

        $lines = [];
        $total = 0;
        foreach ($claimed as $c) {
            $p = $products->findOne(['_id' => $c['productId']]);
            if (!$p) continue; // producto eliminado por el admin
            $subtotal = round($p['price'] * $c['quantity'], 2);
            $lines[] = [
                'productId' => $p['_id'],
                'name' => $p['name'],
                'price' => $p['price'],
                'quantity' => (int)$c['quantity'],
                'subtotal' => $subtotal
            ];
            $total += $subtotal;
        }
        if (!$lines) jsonError(409, 'Los productos de tu carrito ya no existen.');

        $result = $db->selectCollection('orders')->insertOne([
            'username' => $username,
            'items' => $lines,
            'total' => round($total, 2),
            'status' => 'pendiente',
            'reserved' => true,
            'createdAt' => new UTCDateTime()
        ]);
        jsonResponse(['ok' => true, 'id' => (string)$result->getInsertedId()], 201);
    } catch (Exception $e) {
        error_log('createOrder: ' . $e->getMessage());
        jsonError(500, 'Error interno al crear el pedido.');
    }
}

// GET /orders?username=x  (comprador: sus pedidos)  |  GET /orders[?status=pendiente] (administrador: todos)
function listOrders() {
    $filter = [];
    if (!empty($_GET['username'])) {
        $filter['username'] = (string)$_GET['username'];
    } else {
        requireAdmin();
    }
    if (!empty($_GET['status'])) {
        $filter['status'] = (string)$_GET['status'];
    }

    try {
        $orders = [];
        $cursor = getDB()->selectCollection('orders')->find($filter, ['sort' => ['createdAt' => -1]]);
        foreach ($cursor as $doc) $orders[] = docToArray($doc);
        jsonResponse(['ok' => true, 'orders' => $orders]);
    } catch (Exception $e) {
        error_log('listOrders: ' . $e->getMessage());
        jsonError(500, 'Error interno al listar pedidos.');
    }
}

// Aceptar: el stock ya estaba descontado. Rechazar: se devuelve al inventario.
function updateOrderStatus($id) {
    requireAdmin();
    $status = readJsonBody()['status'] ?? '';
    if (!in_array($status, ['aceptada', 'rechazada'], true)) {
        jsonError(400, 'Estado no válido.');
    }
    $oid = toObjectId($id);

    try {
        $orders = getDB()->selectCollection('orders');
        $order = $orders->findOne(['_id' => $oid]);
        if (!$order) jsonError(404, 'Pedido no encontrado.');

        // Solo un proceso puede resolver el pedido (evita devolver el stock dos veces)
        $r = $orders->updateOne(
            ['_id' => $oid, 'status' => 'pendiente'],
            ['$set' => ['status' => $status, 'resolvedAt' => new UTCDateTime()]]
        );
        if ($r->getModifiedCount() === 0) jsonError(409, 'Este pedido ya fue resuelto.');

        if ($status === 'rechazada' && !empty($order['reserved'])) {
            foreach ($order['items'] as $it) giveStock($it['productId'], (int)$it['quantity']);
        }
        jsonResponse(['ok' => true, 'status' => $status]);
    } catch (Exception $e) {
        error_log('updateOrderStatus: ' . $e->getMessage());
        jsonError(500, 'Error interno al actualizar el pedido.');
    }
}

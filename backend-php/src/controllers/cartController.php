<?php

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

function cartTtlMinutes(): int {
    return max(1, (int)(getenv('CART_TTL_MINUTES') ?: 30));
}

function newCartExpiry(): UTCDateTime {
    return new UTCDateTime((time() + cartTtlMinutes() * 60) * 1000);
}

function cartItemsCollection() {
    return getDB()->selectCollection('cart_items');
}

// Resta stock solo si hay suficiente
function takeStock(ObjectId $pid, int $qty): bool {
    $r = productsCollection()->updateOne(
        ['_id' => $pid, 'stock' => ['$gte' => $qty]],
        ['$inc' => ['stock' => -$qty]]
    );
    return $r->getModifiedCount() === 1;
}

function giveStock(ObjectId $pid, int $qty) {
    productsCollection()->updateOne(['_id' => $pid], ['$inc' => ['stock' => $qty]]);
}

// Libera las reservas caducadas
function releaseExpiredCartItems() {
    $items = cartItemsCollection();
    $now = new UTCDateTime();
    foreach ($items->find(['expiresAt' => ['$lt' => $now]]) as $it) {
        $gone = $items->findOneAndDelete(['_id' => $it['_id'], 'expiresAt' => ['$lt' => $now]]);
        if ($gone) giveStock($gone['productId'], (int)$gone['quantity']);
    }
}

// Cada cambio en el carrito renueva el plazo de todas sus reservas
function touchCart(string $username) {
    cartItemsCollection()->updateMany(['username' => $username], ['$set' => ['expiresAt' => newCartExpiry()]]);
}

function cartRequest(): array {
    $body = readJsonBody();
    $username = $_GET['username'] ?? ($body['username'] ?? '');
    $username = is_string($username) ? trim($username) : '';
    if ($username === '') jsonError(400, 'Falta el usuario.');
    return [$username, $body];
}

// GET /cart
function getCart() {
    [$username] = cartRequest();
    try {
        releaseExpiredCartItems();
        $items = iterator_to_array(
            cartItemsCollection()->find(['username' => $username], ['sort' => ['createdAt' => 1]]), false
        );
        $byId = [];
        if ($items) {
            $ids = array_map(fn($i) => $i['productId'], $items);
            foreach (productsCollection()->find(['_id' => ['$in' => $ids]]) as $p) {
                $byId[(string)$p['_id']] = docToArray($p);
            }
        }
        $lines = [];
        $expires = null;
        foreach ($items as $it) {
            $key = (string)$it['productId'];
            if (!isset($byId[$key])) continue;
            $lines[] = ['quantity' => (int)$it['quantity'], 'product' => $byId[$key]];
            $e = $it['expiresAt']->toDateTime()->format(DATE_ATOM);
            if ($expires === null || $e < $expires) $expires = $e;
        }
        jsonResponse(['ok' => true, 'lines' => $lines, 'expiresAt' => $expires]);
    } catch (Exception $e) {
        error_log('getCart: ' . $e->getMessage());
        jsonError(500, 'Error interno al cargar el carrito.');
    }
}

// GET /cart/summary
function cartSummary() {
    [$username] = cartRequest();
    try {
        releaseExpiredCartItems();
        $count = 0;
        foreach (cartItemsCollection()->find(['username' => $username]) as $it) $count += (int)$it['quantity'];
        jsonResponse(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        jsonResponse(['ok' => true, 'count' => 0]);
    }
}

// POST /cart/items
function addCartItem() {
    [$username, $body] = cartRequest();
    $pid = (string)($body['productId'] ?? '');
    $qty = filter_var($body['quantity'] ?? 1, FILTER_VALIDATE_INT);
    if (!preg_match('/^[a-f0-9]{24}$/', $pid) || $qty === false || $qty < 1 || $qty > 99) {
        jsonError(400, 'Producto o cantidad no válidos.');
    }

    try {
        releaseExpiredCartItems();
        $oid = new ObjectId($pid);
        $p = productsCollection()->findOne(['_id' => $oid]);
        if (!$p) jsonError(404, 'El producto ya no existe.');

        if (!takeStock($oid, $qty)) {
            jsonError(409, "No hay stock disponible de «{$p['name']}» (quedan {$p['stock']} sin reservar).");
        }
        try {
            cartItemsCollection()->updateOne(
                ['username' => $username, 'productId' => $oid],
                [
                    '$inc' => ['quantity' => $qty],
                    '$set' => ['expiresAt' => newCartExpiry()],
                    '$setOnInsert' => ['createdAt' => new UTCDateTime()]
                ],
                ['upsert' => true]
            );
        } catch (Exception $e) {
            giveStock($oid, $qty); // si falla, no se pierde el stock
            throw $e;
        }
        touchCart($username);
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        error_log('addCartItem: ' . $e->getMessage());
        jsonError(500, 'Error interno al añadir al carrito.');
    }
}

// PUT /cart/items/{productId}
function setCartItem($id) {
    [$username, $body] = cartRequest();
    $qty = filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($qty === false || $qty < 0 || $qty > 99) jsonError(400, 'Cantidad no válida.');
    $oid = toObjectId($id);

    try {
        releaseExpiredCartItems();
        $items = cartItemsCollection();
        $item = $items->findOne(['username' => $username, 'productId' => $oid]);
        if (!$item) jsonError(404, 'Ese producto ya no está en tu carrito (la reserva pudo caducar).');

        $delta = $qty - (int)$item['quantity'];
        if ($delta > 0 && !takeStock($oid, $delta)) {
            jsonError(409, 'No hay stock suficiente para esa cantidad.');
        }
        if ($delta < 0) giveStock($oid, -$delta);

        if ($qty === 0) $items->deleteOne(['_id' => $item['_id']]);
        else $items->updateOne(['_id' => $item['_id']], ['$set' => ['quantity' => $qty]]);

        touchCart($username);
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        error_log('setCartItem: ' . $e->getMessage());
        jsonError(500, 'Error interno al actualizar el carrito.');
    }
}

// DELETE /cart/items/{productId}?username=x
function removeCartItem($id) {
    [$username] = cartRequest();
    $oid = toObjectId($id);
    try {
        $gone = cartItemsCollection()->findOneAndDelete(['username' => $username, 'productId' => $oid]);
        if ($gone) giveStock($oid, (int)$gone['quantity']);
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        error_log('removeCartItem: ' . $e->getMessage());
        jsonError(500, 'Error interno al quitar del carrito.');
    }
}

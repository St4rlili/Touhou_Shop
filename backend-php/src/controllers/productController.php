<?php

use MongoDB\BSON\UTCDateTime;

function productsCollection() {
    return getDB()->selectCollection('products');
}

function parseProductInput(array $in): array {
    $name = trim((string)($in['name'] ?? ''));
    $price = filter_var($in['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($in['stock'] ?? null, FILTER_VALIDATE_INT);

    if ($name === '') jsonError(400, 'El nombre del producto es obligatorio.');
    if ($price === false || $price < 0) jsonError(400, 'El precio debe ser un número igual o mayor que 0.');
    if ($stock === false || $stock < 0) jsonError(400, 'El stock debe ser un número entero igual o mayor que 0.');

    $category = trim((string)($in['category'] ?? ''));
    return [
        'name' => $name,
        'description' => trim((string)($in['description'] ?? '')),
        'price' => round((float)$price, 2),
        'stock' => (int)$stock,
        'category' => $category !== '' ? $category : 'General',
        'imageUrl' => trim((string)($in['imageUrl'] ?? ''))
    ];
}

// GET /products
function listProducts() {
    $filter = [];
    if (!empty($_GET['category'])) {
        $filter['category'] = (string)$_GET['category'];
    }
    if (!empty($_GET['q'])) {
        $filter['name'] = new MongoDB\BSON\Regex(preg_quote((string)$_GET['q'], '/'), 'i');
    }
    if (!empty($_GET['ids'])) {
        $ids = [];
        foreach (explode(',', (string)$_GET['ids']) as $id) {
            if ($id !== '') $ids[] = toObjectId($id);
        }
        $filter['_id'] = ['$in' => $ids];
    }

    try {
        releaseExpiredCartItems();
        $col = productsCollection();
        $products = [];
        foreach ($col->find($filter, ['sort' => ['createdAt' => -1]]) as $doc) {
            $products[] = docToArray($doc);
        }
        $categories = array_values(array_filter($col->distinct('category'), 'is_string'));
        sort($categories);
        jsonResponse(['ok' => true, 'products' => $products, 'categories' => $categories]);
    } catch (Exception $e) {
        error_log('listProducts: ' . $e->getMessage());
        jsonError(500, 'Error interno al listar productos.');
    }
}

function getProduct($id) {
    try {
        $doc = productsCollection()->findOne(['_id' => toObjectId($id)]);
        if (!$doc) jsonError(404, 'Producto no encontrado.');
        jsonResponse(['ok' => true, 'product' => docToArray($doc)]);
    } catch (Exception $e) {
        jsonError(500, 'Error interno al obtener el producto.');
    }
}

function createProduct() {
    requireAdmin();
    $data = parseProductInput(readJsonBody());
    try {
        $now = new UTCDateTime();
        $result = productsCollection()->insertOne($data + ['createdAt' => $now, 'updatedAt' => $now]);
        jsonResponse(['ok' => true, 'id' => (string)$result->getInsertedId()], 201);
    } catch (Exception $e) {
        error_log('createProduct: ' . $e->getMessage());
        jsonError(500, 'Error interno al crear el producto.');
    }
}

function updateProduct($id) {
    requireAdmin();
    $data = parseProductInput(readJsonBody());
    try {
        $result = productsCollection()->updateOne(
            ['_id' => toObjectId($id)],
            ['$set' => $data + ['updatedAt' => new UTCDateTime()]]
        );
        if ($result->getMatchedCount() === 0) jsonError(404, 'Producto no encontrado.');
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        error_log('updateProduct: ' . $e->getMessage());
        jsonError(500, 'Error interno al modificar el producto.');
    }
}

function deleteProduct($id) {
    requireAdmin();
    try {
        $oid = toObjectId($id);
        $result = productsCollection()->deleteOne(['_id' => $oid]);
        getDB()->selectCollection('cart_items')->deleteMany(['productId' => $oid]);
        if ($result->getDeletedCount() === 0) jsonError(404, 'Producto no encontrado.');
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        error_log('deleteProduct: ' . $e->getMessage());
        jsonError(500, 'Error interno al eliminar el producto.');
    }
}

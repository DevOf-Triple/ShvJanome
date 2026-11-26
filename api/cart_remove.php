<?php
/**
 * api/cart_remove.php — POST: удалить одну или несколько позиций.
 * Тело JSON: {product_id} или {product_ids: [1,2,3]}.
 * Ответ: {ok, removed: [{product_id, name, quantity}], totals, cart_count, checkout_ready}.
 * Список removed нужен фронту для отмены удаления.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
api_require_csrf();

$body = read_json_body();
$ids = [];

if (isset($body['product_ids']) && is_array($body['product_ids'])) {
    foreach ($body['product_ids'] as $value) {
        $id = (int)$value;
        if ($id > 0) { $ids[] = $id; }
    }
} elseif (isset($body['product_id'])) {
    $id = (int)$body['product_id'];
    if ($id > 0) { $ids[] = $id; }
}

$ids = array_values(array_unique($ids));
if (!$ids) {
    json_error('Не переданы товары для удаления', 422);
}
if (count($ids) > 50) {
    json_error('Слишком много позиций за один раз', 422);
}

$userId = (int)$user['id'];
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$removed = [];

$pdo->beginTransaction();
try {
    // Снимок удаляемых позиций — чтобы фронт мог вернуть их обратно
    $stmt = $pdo->prepare(
        'SELECT ci.product_id, ci.quantity, p.name
         FROM cart_items ci JOIN products p ON p.id = ci.product_id
         WHERE ci.user_id = ? AND ci.product_id IN (' . $placeholders . ') FOR UPDATE'
    );
    $stmt->execute(array_merge([$userId], $ids));
    foreach ($stmt->fetchAll() as $row) {
        $removed[] = [
            'product_id' => (int)$row['product_id'],
            'name' => $row['name'],
            'quantity' => (int)$row['quantity'],
        ];
    }

    if (!$removed) {
        $pdo->rollBack();
        json_error('Этих позиций нет в корзине', 404);
    }

    $stmt = $pdo->prepare(
        'DELETE FROM cart_items WHERE user_id = ? AND product_id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$userId], $ids));

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS positions,
            COALESCE(SUM(ci.quantity), 0) AS units,
            COALESCE(SUM(ci.quantity * p.price), 0) AS subtotal,
            COALESCE(SUM(CASE WHEN p.old_price IS NOT NULL AND p.old_price > p.price
                              THEN ci.quantity * (p.old_price - p.price) ELSE 0 END), 0) AS savings,
            COALESCE(SUM(CASE WHEN p.is_active = 0 OR p.stock <= 0 OR ci.quantity > p.stock
                              THEN 1 ELSE 0 END), 0) AS problems
     FROM cart_items ci JOIN products p ON p.id = ci.product_id
     WHERE ci.user_id = ?'
);
$stmt->execute([$userId]);
$t = $stmt->fetch();

$totals = [
    'positions' => (int)$t['positions'],
    'units' => (int)$t['units'],
    'subtotal' => (float)$t['subtotal'],
    'savings' => (float)$t['savings'],
    'problems' => (int)$t['problems'],
];

json_ok([
    'removed' => $removed,
    'totals' => $totals,
    'cart_count' => $totals['units'],
    'checkout_ready' => $totals['positions'] > 0 && $totals['problems'] === 0,
]);
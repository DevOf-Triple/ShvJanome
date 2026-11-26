<?php
/**
 * api/cart_update.php — POST: изменить количество позиции корзины.
 * Тело JSON: {product_id, quantity}. Количество режется по остатку.
 * Ответ: {ok, item_quantity, line_total, capped, stock, totals, cart_count, checkout_ready}.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
api_require_csrf();

$body = read_json_body();
$productId = (int)($body['product_id'] ?? 0);
$quantity = (int)($body['quantity'] ?? 0);

if ($productId <= 0) {
    json_error('Некорректный товар', 422);
}
if ($quantity < 1) {
    json_error('Количество не может быть меньше 1. Для удаления используйте cart_remove.', 422);
}
$quantity = min(99, $quantity);

$userId = (int)$user['id'];
$capped = false;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id, stock, is_active FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        $pdo->rollBack();
        json_error('Товар не найден', 404);
    }
    if ((int)$product['is_active'] !== 1) {
        $pdo->rollBack();
        json_error('Товар снят с продажи', 409);
    }

    $stock = (int)$product['stock'];
    if ($stock <= 0) {
        $pdo->rollBack();
        json_error('Товара нет в наличии', 409);
    }

    $stmt = $pdo->prepare('SELECT id FROM cart_items WHERE user_id = ? AND product_id = ? FOR UPDATE');
    $stmt->execute([$userId, $productId]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->rollBack();
        json_error('Этой позиции нет в корзине', 404);
    }

    $target = $quantity;
    if ($target > $stock) {
        $target = $stock;
        $capped = true;
    }

    $stmt = $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?');
    $stmt->execute([$target, $row['id']]);

    $stmt = $pdo->prepare('SELECT price FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $price = (float)$stmt->fetchColumn();

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
    'item_quantity' => $target,
    'line_total' => $price * $target,
    'capped' => $capped,
    'stock' => $stock,
    'totals' => $totals,
    'cart_count' => $totals['units'],
    'checkout_ready' => $totals['positions'] > 0 && $totals['problems'] === 0,
]);
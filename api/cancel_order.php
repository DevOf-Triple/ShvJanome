<?php
/**
 * api/cancel_order.php — POST: отмена СВОЕГО заказа в статусе new.
 * Тело JSON: {order_id} или {order_number}.
 * Остатки возвращаются на склад в той же транзакции.
 * Пригодится и кабинету (этап 9) — контракт универсальный.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
api_require_csrf();

$body = read_json_body();
$orderId = (int)($body['order_id'] ?? 0);
$orderNumber = trim((string)($body['order_number'] ?? ''));
$userId = (int)$user['id'];

if ($orderId <= 0 && $orderNumber === '') {
    json_error('Не указан заказ', 422);
}

$statusLabels = [
    'new' => 'новый',
    'processing' => 'в обработке',
    'shipped' => 'отправлен',
    'done' => 'выполнен',
    'cancelled' => 'отменён',
];

$restored = [];

$pdo->beginTransaction();
try {
    if ($orderId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, order_number, total, status FROM orders WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$orderId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, order_number, total, status FROM orders WHERE order_number = ? FOR UPDATE'
        );
        $stmt->execute([$orderNumber]);
    }
    $order = $stmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        json_error('Заказ не найден', 404);
    }

    // Чужой заказ отменять нельзя — проверка на сервере, не в интерфейсе
    if ((int)$order['user_id'] !== $userId) {
        $pdo->rollBack();
        json_error('Можно отменять только свои заказы', 403);
    }

    if ($order['status'] !== 'new') {
        $pdo->rollBack();
        $label = $statusLabels[$order['status']] ?? $order['status'];
        json_error('Отменить можно только новый заказ. Текущий статус: ' . $label, 409);
    }

    $oid = (int)$order['id'];

    $stmt = $pdo->prepare('SELECT product_id, name, quantity FROM order_items WHERE order_id = ?');
    $stmt->execute([$oid]);
    $rows = $stmt->fetchAll();

    // Возвращаем остатки. Товар существует: FK order_items → products стоит RESTRICT
    $incStock = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
    foreach ($rows as $row) {
        $qty = (int)$row['quantity'];
        $pid = (int)$row['product_id'];
        $incStock->execute([$qty, $pid]);
        $restored[] = [
            'product_id' => $pid,
            'name' => $row['name'],
            'quantity' => $qty,
        ];
    }

    $stmt = $pdo->prepare('UPDATE orders SET status = \'cancelled\' WHERE id = ? AND status = \'new\'');
    $stmt->execute([$oid]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        json_error('Статус заказа изменился — обновите страницу', 409);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ?');
$stmt->execute([$userId]);
$cartCount = (int)$stmt->fetchColumn();

json_ok([
    'order' => [
        'id' => $oid,
        'order_number' => $order['order_number'],
        'total' => (float)$order['total'],
        'status' => 'cancelled',
    ],
    'restored' => $restored,
    'cart_count' => $cartCount,
]);
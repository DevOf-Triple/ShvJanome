<?php
/**
 * api/admin/orders.php — список заказов и смена статусов. Только admin.
 * Переходы: new → processing|cancelled, processing → shipped|cancelled,
 * shipped → done. Отмена возвращает остатки на склад в транзакции.
 */

require_once __DIR__ . '/../../config/helpers.php';

$admin = api_require_admin();

$statusLabels = [
    'new' => 'Новый', 'processing' => 'В обработке', 'shipped' => 'Отправлен',
    'done' => 'Выполнен', 'cancelled' => 'Отменён',
];
$transitions = [
    'new' => ['processing', 'cancelled'],
    'processing' => ['shipped', 'cancelled'],
    'shipped' => ['done'],
    'done' => [],
    'cancelled' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = (string)($_GET['status'] ?? 'all');
    $status = isset($statusLabels[$status]) ? $status : 'all';
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
    $perPage = 8;
    $page = max(1, (int)($_GET['page'] ?? 1));

    $where = ['1 = 1'];
    $params = [];
    if ($status !== 'all') { $where[] = 'o.status = ?'; $params[] = $status; }
    if ($q !== '') {
        $where[] = '(o.order_number LIKE ? OR u.login LIKE ? OR u.full_name LIKE ? OR o.phone LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id WHERE ' . $whereSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT o.id, o.order_number, o.total, o.status, o.delivery, o.payment, o.address, o.phone, o.comment,
                o.created_at, u.id AS user_id, u.login, u.full_name, u.email
         FROM orders o JOIN users u ON u.id = o.user_id
         WHERE ' . $whereSql . ' ORDER BY o.id DESC LIMIT ? OFFSET ?'
    );
    $pos = 1;
    foreach ($params as $value) { $stmt->bindValue($pos++, $value, PDO::PARAM_STR); }
    $stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($pos, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $itemsByOrder = [];
    if ($rows) {
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            'SELECT oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, p.slug, p.image_path
             FROM order_items oi JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id IN (' . $ph . ') ORDER BY oi.id ASC'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $item) {
            $itemsByOrder[(int)$item['order_id']][] = [
                'product_id' => (int)$item['product_id'], 'name' => $item['name'],
                'slug' => $item['slug'], 'image_path' => $item['image_path'],
                'price' => (float)$item['price'], 'quantity' => (int)$item['quantity'],
                'line_total' => (float)$item['price'] * (int)$item['quantity'],
            ];
        }
    }

    $orders = [];
    foreach ($rows as $row) {
        $oid = (int)$row['id'];
        $orders[] = [
            'id' => $oid, 'order_number' => $row['order_number'], 'total' => (float)$row['total'],
            'status' => $row['status'], 'delivery' => $row['delivery'], 'payment' => $row['payment'],
            'address' => $row['address'], 'phone' => $row['phone'], 'comment' => $row['comment'],
            'created_at' => $row['created_at'], 'user_id' => (int)$row['user_id'],
            'login' => $row['login'], 'full_name' => $row['full_name'], 'email' => $row['email'],
            'items' => $itemsByOrder[$oid] ?? [],
            'next_statuses' => $transitions[$row['status']] ?? [],
        ];
    }

    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status');
    $stmt->execute();
    $counts = array_fill_keys(array_keys($statusLabels), 0);
    $all = 0;
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['cnt'];
        $all += (int)$row['cnt'];
    }

    json_ok([
        'orders' => $orders, 'total' => $total, 'page' => $page, 'pages' => $pages,
        'status_counts' => $counts, 'all_count' => $all, 'labels' => $statusLabels,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();
$body = read_json_body();

if ((string)($body['action'] ?? '') !== 'set_status') { json_error('Неизвестное действие', 422); }

$id = (int)($body['id'] ?? 0);
$newStatus = (string)($body['status'] ?? '');
if ($id <= 0) { json_error('Не указан заказ', 422); }
if (!isset($statusLabels[$newStatus])) { json_error('Неизвестный статус', 422); }

$restored = 0;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id, status, order_number FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) { $pdo->rollBack(); json_error('Заказ не найден', 404); }

    $current = (string)$order['status'];
    if ($current === $newStatus) { $pdo->rollBack(); json_error('У заказа уже этот статус', 409); }
    if (!in_array($newStatus, $transitions[$current] ?? [], true)) {
        $pdo->rollBack();
        json_error('Переход «' . $statusLabels[$current] . ' → ' . $statusLabels[$newStatus] . '» недопустим', 409);
    }

    // Отмена из нового или обработки — возвращаем остатки
    if ($newStatus === 'cancelled') {
        $stmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
        $stmt->execute([$id]);
        $inc = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
        foreach ($stmt->fetchAll() as $item) {
            $inc->execute([(int)$item['quantity'], (int)$item['product_id']]);
            $restored += (int)$item['quantity'];
        }
    }

    $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ? AND status = ?');
    $stmt->execute([$newStatus, $id, $current]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        json_error('Статус изменился параллельно — обновите список', 409);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}

json_ok([
    'id' => $id,
    'status' => $newStatus,
    'restored' => $restored,
    'next_statuses' => $transitions[$newStatus],
    'message' => 'Заказ ' . $order['order_number'] . ': ' . $statusLabels[$newStatus]
        . ($restored > 0 ? ' · на склад вернулось ' . $restored . ' шт.' : ''),
]);
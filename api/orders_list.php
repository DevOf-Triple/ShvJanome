<?php
/**
 * api/orders_list.php — GET: заказы текущего пользователя с составом.
 * Параметры: status (all|new|processing|shipped|done|cancelled), q (номер), page.
 * По 5 заказов на страницу, свежие сверху. Чужие заказы не выдаются никогда.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
$userId = (int)$user['id'];

$allowedStatuses = ['new', 'processing', 'shipped', 'done', 'cancelled'];
$status = (string)($_GET['status'] ?? 'all');
$status = in_array($status, $allowedStatuses, true) ? $status : 'all';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 30);
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ['o.user_id = ?'];
$params = [$userId];

if ($status !== 'all') {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = 'o.order_number LIKE ?';
    $params[] = '%' . $q . '%';
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM orders o WHERE ' . $whereSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT o.id, o.order_number, o.total, o.status, o.delivery, o.payment,
            o.address, o.phone, o.comment, o.created_at
     FROM orders o
     WHERE ' . $whereSql . '
     ORDER BY o.id DESC
     LIMIT ? OFFSET ?'
);
$pos = 1;
foreach ($params as $value) {
    $stmt->bindValue($pos++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($pos, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Состав заказов одним запросом
$itemsByOrder = [];
if ($rows) {
    $orderIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = $pdo->prepare(
        'SELECT oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity,
                p.slug, p.model, p.image_path, p.is_active
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN (' . $placeholders . ')
         ORDER BY oi.id ASC'
    );
    $stmt->execute($orderIds);

    foreach ($stmt->fetchAll() as $item) {
        $oid = (int)$item['order_id'];
        $price = (float)$item['price'];
        $qty = (int)$item['quantity'];

        $itemsByOrder[$oid][] = [
            'product_id' => (int)$item['product_id'],
            'name' => $item['name'],
            'model' => $item['model'],
            'slug' => $item['slug'],
            'image_path' => $item['image_path'],
            'is_active' => (int)$item['is_active'] === 1,
            'url' => (int)$item['is_active'] === 1 ? 'product.php?slug=' . rawurlencode($item['slug']) : null,
            'price' => $price,
            'quantity' => $qty,
            'line_total' => $price * $qty,
        ];
    }
}

$orders = [];
foreach ($rows as $row) {
    $oid = (int)$row['id'];
    $orders[] = [
        'id' => $oid,
        'order_number' => $row['order_number'],
        'total' => (float)$row['total'],
        'status' => $row['status'],
        'delivery' => $row['delivery'],
        'payment' => $row['payment'],
        'address' => $row['address'],
        'phone' => $row['phone'],
        'comment' => $row['comment'],
        'created_at' => $row['created_at'],
        'can_cancel' => $row['status'] === 'new',
        'items' => $itemsByOrder[$oid] ?? [],
    ];
}

// Счётчики по статусам для фильтров
$stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM orders WHERE user_id = ? GROUP BY status');
$stmt->execute([$userId]);
$statusCounts = array_fill_keys($allowedStatuses, 0);
$allCount = 0;
foreach ($stmt->fetchAll() as $row) {
    $count = (int)$row['cnt'];
    $statusCounts[$row['status']] = $count;
    $allCount += $count;
}

json_ok([
    'orders' => $orders,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'per_page' => $perPage,
    'status' => $status,
    'q' => $q,
    'status_counts' => $statusCounts,
    'all_count' => $allCount,
]);
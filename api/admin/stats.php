<?php
/**
 * api/admin/stats.php — GET: сводка для дашборда.
 * Доступ только admin, проверка на сервере.
 */

require_once __DIR__ . '/../../config/helpers.php';

$admin = api_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('Метод не поддерживается', 405);
}

/* ---------- Счётчики одним запросом ---------- */
$stmt = $pdo->prepare(
    'SELECT (SELECT COUNT(*) FROM orders) AS orders_total,
            (SELECT COUNT(*) FROM orders WHERE status = \'new\') AS orders_new,
            (SELECT COUNT(*) FROM orders WHERE status = \'processing\') AS orders_processing,
            (SELECT COUNT(*) FROM orders WHERE status = \'shipped\') AS orders_shipped,
            (SELECT COUNT(*) FROM orders WHERE status = \'done\') AS orders_done,
            (SELECT COUNT(*) FROM orders WHERE status = \'cancelled\') AS orders_cancelled,
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> \'cancelled\') AS revenue,
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> \'cancelled\' AND DATE(created_at) = CURDATE()) AS revenue_today,
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> \'cancelled\' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS revenue_7d,
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> \'cancelled\' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS revenue_30d,
            (SELECT COUNT(*) FROM products) AS products_total,
            (SELECT COUNT(*) FROM products WHERE is_active = 1) AS products_active,
            (SELECT COUNT(*) FROM products WHERE stock = 0) AS products_out,
            (SELECT COUNT(*) FROM products WHERE stock > 0 AND stock <= 3) AS products_low,
            (SELECT COUNT(*) FROM users) AS users_total,
            (SELECT COUNT(*) FROM users WHERE role = \'admin\') AS admins_total,
            (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS users_new,
            (SELECT COUNT(*) FROM feedback) AS feedback_total,
            (SELECT COUNT(*) FROM feedback WHERE is_read = 0) AS feedback_unread,
            (SELECT COUNT(*) FROM brands) AS brands_total,
            (SELECT COUNT(*) FROM categories) AS categories_total,
            (SELECT COALESCE(SUM(quantity), 0) FROM cart_items) AS cart_units'
);
$stmt->execute();
$s = $stmt->fetch() ?: [];

/* ---------- Выручка по дням за неделю ---------- */
$stmt = $pdo->prepare(
    'SELECT DATE(created_at) AS day, COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS orders
     FROM orders
     WHERE status <> \'cancelled\' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at) ORDER BY day ASC'
);
$stmt->execute();
$byDay = [];
foreach ($stmt->fetchAll() as $row) {
    $byDay[(string)$row['day']] = ['revenue' => (float)$row['revenue'], 'orders' => (int)$row['orders']];
}
$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    $chart[] = [
        'date' => $date,
        'label' => date('d.m', strtotime($date)),
        'revenue' => $byDay[$date]['revenue'] ?? 0.0,
        'orders' => $byDay[$date]['orders'] ?? 0,
    ];
}

/* ---------- Товары на исходе ---------- */
$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.model, p.slug, p.stock, p.price, p.is_active, p.image_path, b.name AS brand_name
     FROM products p JOIN brands b ON b.id = p.brand_id
     WHERE p.stock <= 3
     ORDER BY p.stock ASC, p.id ASC LIMIT 8'
);
$stmt->execute();
$lowStock = [];
foreach ($stmt->fetchAll() as $row) {
    $lowStock[] = [
        'id' => (int)$row['id'], 'name' => $row['name'], 'model' => $row['model'], 'slug' => $row['slug'],
        'stock' => (int)$row['stock'], 'price' => (float)$row['price'], 'is_active' => (int)$row['is_active'] === 1,
        'image_path' => $row['image_path'], 'brand_name' => $row['brand_name'],
    ];
}

/* ---------- Последние заказы ---------- */
$stmt = $pdo->prepare(
    'SELECT o.id, o.order_number, o.total, o.status, o.delivery, o.created_at, u.login, u.full_name
     FROM orders o JOIN users u ON u.id = o.user_id
     ORDER BY o.id DESC LIMIT 6'
);
$stmt->execute();
$recent = [];
foreach ($stmt->fetchAll() as $row) {
    $recent[] = [
        'id' => (int)$row['id'], 'order_number' => $row['order_number'], 'total' => (float)$row['total'],
        'status' => $row['status'], 'delivery' => $row['delivery'], 'created_at' => $row['created_at'],
        'login' => $row['login'], 'full_name' => $row['full_name'],
    ];
}

/* ---------- Ходовые товары ---------- */
$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.model, p.stock, p.price,
            COALESCE(SUM(oi.quantity), 0) AS sold, COALESCE(SUM(oi.quantity * oi.price), 0) AS earned
     FROM products p JOIN order_items oi ON oi.product_id = p.id
     GROUP BY p.id ORDER BY sold DESC, earned DESC LIMIT 5'
);
$stmt->execute();
$top = [];
foreach ($stmt->fetchAll() as $row) {
    $top[] = [
        'id' => (int)$row['id'], 'name' => $row['name'], 'model' => $row['model'],
        'stock' => (int)$row['stock'], 'price' => (float)$row['price'],
        'sold' => (int)$row['sold'], 'earned' => (float)$row['earned'],
    ];
}

/* ---------- Непрочитанные обращения ---------- */
$stmt = $pdo->prepare('SELECT id, name, email, message, created_at FROM feedback WHERE is_read = 0 ORDER BY id DESC LIMIT 5');
$stmt->execute();
$unread = [];
foreach ($stmt->fetchAll() as $row) {
    $unread[] = [
        'id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'],
        'message' => mb_strimwidth((string)$row['message'], 0, 140, '…', 'UTF-8'),
        'created_at' => $row['created_at'],
    ];
}

json_ok([
    'counters' => [
        'orders_total' => (int)($s['orders_total'] ?? 0),
        'orders_new' => (int)($s['orders_new'] ?? 0),
        'orders_processing' => (int)($s['orders_processing'] ?? 0),
        'orders_shipped' => (int)($s['orders_shipped'] ?? 0),
        'orders_done' => (int)($s['orders_done'] ?? 0),
        'orders_cancelled' => (int)($s['orders_cancelled'] ?? 0),
        'revenue' => (float)($s['revenue'] ?? 0),
        'revenue_today' => (float)($s['revenue_today'] ?? 0),
        'revenue_7d' => (float)($s['revenue_7d'] ?? 0),
        'revenue_30d' => (float)($s['revenue_30d'] ?? 0),
        'products_total' => (int)($s['products_total'] ?? 0),
        'products_active' => (int)($s['products_active'] ?? 0),
        'products_out' => (int)($s['products_out'] ?? 0),
        'products_low' => (int)($s['products_low'] ?? 0),
        'users_total' => (int)($s['users_total'] ?? 0),
        'admins_total' => (int)($s['admins_total'] ?? 0),
        'users_new' => (int)($s['users_new'] ?? 0),
        'feedback_total' => (int)($s['feedback_total'] ?? 0),
        'feedback_unread' => (int)($s['feedback_unread'] ?? 0),
        'brands_total' => (int)($s['brands_total'] ?? 0),
        'categories_total' => (int)($s['categories_total'] ?? 0),
        'cart_units' => (int)($s['cart_units'] ?? 0),
    ],
    'chart' => $chart,
    'low_stock' => $lowStock,
    'recent_orders' => $recent,
    'top_products' => $top,
    'unread_feedback' => $unread,
]);
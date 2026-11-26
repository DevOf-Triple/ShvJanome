<?php
/**
 * api/cart_get.php — GET: содержимое корзины авторизованного.
 * Отдаёт позиции с актуальными ценами и остатками, сводку и флаг готовности к оформлению.
 * Гость получает 401: корзины гостя не существует.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
$userId = (int)$user['id'];

$stmt = $pdo->prepare(
    'SELECT ci.id, ci.quantity, ci.added_at,
            p.id AS product_id, p.name, p.model, p.slug, p.price, p.old_price,
            p.stock, p.image_path, p.is_active, p.specs,
            b.name AS brand_name, c.name AS category_name,
            (f.id IS NOT NULL) AS is_favorite
     FROM cart_items ci
     JOIN products p ON p.id = ci.product_id
     JOIN brands b ON b.id = p.brand_id
     JOIN categories c ON c.id = p.category_id
     LEFT JOIN favorites f ON f.product_id = p.id AND f.user_id = ?
     WHERE ci.user_id = ?
     ORDER BY ci.added_at DESC, ci.id DESC'
);
$stmt->execute([$userId, $userId]);
$rows = $stmt->fetchAll();

$items = [];
$positions = 0;
$units = 0;
$subtotal = 0.0;
$savings = 0.0;
$problems = 0;

foreach ($rows as $row) {
    $price = (float)$row['price'];
    $oldPrice = $row['old_price'] !== null ? (float)$row['old_price'] : null;
    $quantity = (int)$row['quantity'];
    $stock = (int)$row['stock'];
    $isActive = (int)$row['is_active'] === 1;
    $lineTotal = $price * $quantity;

    $overStock = $quantity > $stock;
    $unavailable = !$isActive || $stock <= 0;
    if ($overStock || $unavailable) {
        $problems++;
    }

    if (!$isActive) {
        $stockStatus = 'out';
        $stockText = 'Снят с продажи';
    } elseif ($stock <= 0) {
        $stockStatus = 'out';
        $stockText = 'Нет в наличии';
    } elseif ($overStock) {
        $stockStatus = 'low';
        $stockText = 'Доступно только ' . $stock . ' шт.';
    } elseif ($stock <= 3) {
        $stockStatus = 'low';
        $stockText = 'Осталось ' . $stock . ' шт.';
    } else {
        $stockStatus = 'in';
        $stockText = 'В наличии: ' . $stock . ' шт.';
    }

    $items[] = [
        'id' => (int)$row['id'],
        'product_id' => (int)$row['product_id'],
        'name' => $row['name'],
        'model' => $row['model'],
        'slug' => $row['slug'],
        'url' => 'product.php?slug=' . rawurlencode($row['slug']),
        'image_path' => $row['image_path'],
        'brand_name' => $row['brand_name'],
        'category_name' => $row['category_name'],
        'specs' => json_decode((string)$row['specs'], true) ?: [],
        'price' => $price,
        'old_price' => $oldPrice,
        'quantity' => $quantity,
        'line_total' => $lineTotal,
        'stock' => $stock,
        'max_quantity' => max(1, min(99, $stock)),
        'is_active' => $isActive,
        'over_stock' => $overStock,
        'unavailable' => $unavailable,
        'stock_status' => $stockStatus,
        'stock_text' => $stockText,
        'is_favorite' => (bool)$row['is_favorite'],
    ];

    $positions++;
    $units += $quantity;
    $subtotal += $lineTotal;
    if ($oldPrice !== null && $oldPrice > $price) {
        $savings += ($oldPrice - $price) * $quantity;
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$stmt->execute([$userId]);
$favoritesCount = (int)$stmt->fetchColumn();

json_ok([
    'items' => $items,
    'totals' => [
        'positions' => $positions,
        'units' => $units,
        'subtotal' => $subtotal,
        'savings' => $savings,
        'problems' => $problems,
    ],
    'cart_count' => $units,
    'favorites_count' => $favoritesCount,
    'checkout_ready' => $positions > 0 && $problems === 0,
]);
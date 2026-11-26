<?php
/**
 * api/favorites_list.php — GET: избранное текущего пользователя.
 * Параметры: q (название/модель), sort (new|price_asc|price_desc|name), page.
 * По 9 позиций на страницу. Отдаёт остаток и количество в корзине.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
$userId = (int)$user['id'];

// Тип машинки — тот же вывод, что в каталоге и карточке товара
$typeExpr = <<<'SQL'
CASE
    WHEN JSON_UNQUOTE(JSON_EXTRACT(p.specs, '$."Тип управления"')) = 'компьютеризированная' THEN 'computer'
    WHEN JSON_UNQUOTE(JSON_EXTRACT(p.specs, '$."Тип управления"')) = 'электромеханическая' THEN 'mechanical'
    WHEN c.slug = 'overloki' THEN 'overlock'
    WHEN c.slug = 'vyshivalnye-mashiny' THEN 'embroidery'
    WHEN c.slug = 'shveyno-vyshivalnye-mashiny' THEN 'combo'
    WHEN c.slug = 'promyshlennye-mashiny' THEN 'industrial'
    ELSE 'other'
END
SQL;

$typeLabels = [
    'computer' => 'Компьютеризированная',
    'mechanical' => 'Электромеханическая',
    'overlock' => 'Оверлок',
    'embroidery' => 'Вышивальная',
    'combo' => 'Швейно-вышивальная',
    'industrial' => 'Промышленная',
    'other' => 'Прочее',
];

$allowedSorts = ['new', 'price_asc', 'price_desc', 'name'];
$sort = (string)($_GET['sort'] ?? 'new');
$sort = in_array($sort, $allowedSorts, true) ? $sort : 'new';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$perPage = 9;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ['f.user_id = ?'];
$params = [$userId];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.model LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM favorites f JOIN products p ON p.id = f.product_id WHERE ' . $whereSql
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;

$orderSql = match ($sort) {
    'price_asc' => 'p.price ASC, f.id DESC',
    'price_desc' => 'p.price DESC, f.id DESC',
    'name' => 'p.name ASC, f.id DESC',
    default => 'f.added_at DESC, f.id DESC',
};

$stmt = $pdo->prepare(
    'SELECT f.id AS favorite_id, f.added_at,
            p.id, p.name, p.model, p.slug, p.price, p.old_price, p.stock, p.image_path, p.is_active, p.specs,
            b.name AS brand_name, c.name AS category_name,
            (' . $typeExpr . ') AS machine_type,
            COALESCE(ci.quantity, 0) AS in_cart
     FROM favorites f
     JOIN products p ON p.id = f.product_id
     JOIN brands b ON b.id = p.brand_id
     JOIN categories c ON c.id = p.category_id
     LEFT JOIN cart_items ci ON ci.product_id = p.id AND ci.user_id = ?
     WHERE ' . $whereSql . '
     ORDER BY ' . $orderSql . '
     LIMIT ? OFFSET ?'
);

// Порядок биндинга: сначала JOIN корзины, затем WHERE, затем LIMIT/OFFSET
$bind = array_merge([$userId], $params);
$pos = 1;
foreach ($bind as $value) {
    $stmt->bindValue($pos++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($pos, $offset, PDO::PARAM_INT);
$stmt->execute();

$items = [];
foreach ($stmt->fetchAll() as $row) {
    $price = (float)$row['price'];
    $oldPrice = $row['old_price'] !== null ? (float)$row['old_price'] : null;
    $stock = (int)$row['stock'];
    $isActive = (int)$row['is_active'] === 1;

    $discount = 0;
    if ($oldPrice !== null && $oldPrice > $price) {
        $discount = (int)round(($oldPrice - $price) / $oldPrice * 100);
    }

    if (!$isActive) {
        $stockStatus = 'out';
        $stockText = 'Снят с продажи';
    } elseif ($stock <= 0) {
        $stockStatus = 'out';
        $stockText = 'Нет в наличии';
    } elseif ($stock <= 3) {
        $stockStatus = 'low';
        $stockText = 'Осталось ' . $stock . ' шт.';
    } else {
        $stockStatus = 'in';
        $stockText = 'В наличии';
    }

    $items[] = [
        'favorite_id' => (int)$row['favorite_id'],
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'model' => $row['model'],
        'slug' => $row['slug'],
        'url' => $isActive ? 'product.php?slug=' . rawurlencode($row['slug']) : null,
        'image_path' => $row['image_path'],
        'brand_name' => $row['brand_name'],
        'category_name' => $row['category_name'],
        'machine_type' => $row['machine_type'],
        'type_label' => $typeLabels[$row['machine_type']] ?? 'Прочее',
        'specs' => json_decode((string)$row['specs'], true) ?: [],
        'price' => $price,
        'old_price' => $oldPrice,
        'discount' => $discount,
        'stock' => $stock,
        'stock_status' => $stockStatus,
        'stock_text' => $stockText,
        'is_active' => $isActive,
        'can_buy' => $isActive && $stock > 0,
        'in_cart' => (int)$row['in_cart'],
        'added_at' => $row['added_at'],
    ];
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$stmt->execute([$userId]);
$favoritesCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ?');
$stmt->execute([$userId]);
$cartCount = (int)$stmt->fetchColumn();

json_ok([
    'items' => $items,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'per_page' => $perPage,
    'sort' => $sort,
    'q' => $q,
    'favorites_count' => $favoritesCount,
    'cart_count' => $cartCount,
]);
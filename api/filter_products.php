<?php
/**
 * api/filter_products.php — GET: выдача каталога для fetch.
 * Фильтры: бренд, категория, тип машинки, цена, наличие, скидка, поиск.
 * Сортировка: новизна, цена ↑/↓, популярность. Пагинация по 9.
 * Избранное авторизованного ВСЕГДА первым при любой сортировке.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('Метод не поддерживается', 405);
}

/**
 * Тип машинки выводится из specs JSON, а для оверлоков/вышивальных —
 * из категории. Так «тип» не дублирует категорию: швейные делятся
 * на компьютеризированные и электромеханические.
 */
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

$allowedTypes = ['computer', 'mechanical', 'overlock', 'embroidery', 'combo', 'industrial'];
$allowedSorts = ['new', 'price_asc', 'price_desc', 'popular'];
$perPage = 9;

/* ---------- Границы цены по всему активному каталогу ---------- */
$stmt = $pdo->prepare(
    'SELECT COALESCE(FLOOR(MIN(price)), 0) AS min_price, COALESCE(CEIL(MAX(price)), 0) AS max_price
     FROM products WHERE is_active = 1'
);
$stmt->execute();
$bounds = $stmt->fetch() ?: ['min_price' => 0, 'max_price' => 0];
$boundMin = (int)$bounds['min_price'];
$boundMax = (int)$bounds['max_price'];

/* ---------- Чтение и санитизация параметров ---------- */
$brand = trim((string)($_GET['brand'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$type = (string)($_GET['type'] ?? '');
$type = in_array($type, $allowedTypes, true) ? $type : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$sort = (string)($_GET['sort'] ?? 'new');
$sort = in_array($sort, $allowedSorts, true) ? $sort : 'new';
$inStock = !empty($_GET['in_stock']);
$onSale = !empty($_GET['on_sale']);

$min = isset($_GET['min']) && is_numeric($_GET['min']) ? (int)$_GET['min'] : $boundMin;
$max = isset($_GET['max']) && is_numeric($_GET['max']) ? (int)$_GET['max'] : $boundMax;
$min = max($boundMin, min($min, $boundMax));
$max = max($boundMin, min($max, $boundMax));
if ($min > $max) { [$min, $max] = [$max, $min]; }

$page = max(1, (int)($_GET['page'] ?? 1));

/* ---------- Условия WHERE (всё через плейсхолдеры) ---------- */
$where = ['p.is_active = 1'];
$params = [];

if ($brand !== '') {
    $where[] = 'b.slug = ?';
    $params[] = $brand;
}
if ($category !== '') {
    $where[] = 'c.slug = ?';
    $params[] = $category;
}
if ($type !== '') {
    $where[] = '(' . $typeExpr . ') = ?';
    $params[] = $type;
}
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.model LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
$where[] = 'p.price BETWEEN ? AND ?';
$params[] = $min;
$params[] = $max;

if ($inStock) {
    $where[] = 'p.stock > 0';
}
if ($onSale) {
    $where[] = 'p.old_price IS NOT NULL AND p.old_price > p.price';
}

$whereSql = implode(' AND ', $where);
$joinsSql = 'JOIN brands b ON b.id = p.brand_id JOIN categories c ON c.id = p.category_id';

/* ---------- Общее количество и число страниц ---------- */
$stmt = $pdo->prepare('SELECT COUNT(*) FROM products p ' . $joinsSql . ' WHERE ' . $whereSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) { $page = $pages; }
$offset = ($page - 1) * $perPage;

/* ---------- Избранное: LEFT JOIN только для авторизованного ---------- */
$user = current_user();
$favJoin = '';
$favSelect = '0';
$favParams = [];
if ($user) {
    $favJoin = 'LEFT JOIN favorites f ON f.product_id = p.id AND f.user_id = ?';
    $favSelect = '(f.id IS NOT NULL)';
    $favParams[] = (int)$user['id'];
}

$orderSql = match ($sort) {
    'price_asc' => 'p.price ASC, p.id ASC',
    'price_desc' => 'p.price DESC, p.id DESC',
    'popular' => 'sold DESC, p.id ASC',
    default => 'p.created_at DESC, p.id DESC',
};

$sql = 'SELECT p.id, p.name, p.model, p.slug, p.description, p.specs, p.price, p.old_price,
               p.stock, p.image_path, p.created_at,
               b.name AS brand_name, b.slug AS brand_slug,
               c.name AS category_name, c.slug AS category_slug,
               (' . $typeExpr . ') AS machine_type,
               (' . $favSelect . ') AS is_favorite,
               (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id) AS sold
        FROM products p ' . $joinsSql . ' ' . $favJoin . '
        WHERE ' . $whereSql . '
        ORDER BY is_favorite DESC, ' . $orderSql . '
        LIMIT ? OFFSET ?';

$stmt = $pdo->prepare($sql);

// Порядок биндинга: сначала параметр JOIN избранного, затем WHERE, затем LIMIT/OFFSET
$bindValues = array_merge($favParams, $params);
$pos = 1;
foreach ($bindValues as $value) {
    $stmt->bindValue($pos++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($pos, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

/* ---------- Подготовка карточек для фронта ---------- */
$items = [];
foreach ($rows as $row) {
    $price = (float)$row['price'];
    $oldPrice = $row['old_price'] !== null ? (float)$row['old_price'] : null;
    $stock = (int)$row['stock'];

    $discount = 0;
    if ($oldPrice !== null && $oldPrice > 0 && $oldPrice > $price) {
        $discount = (int)round(($oldPrice - $price) / $oldPrice * 100);
    }

    if ($stock <= 0) {
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
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'model' => $row['model'],
        'slug' => $row['slug'],
        'url' => 'product.php?slug=' . rawurlencode($row['slug']),
        'description' => mb_strimwidth((string)$row['description'], 0, 140, '…', 'UTF-8'),
        'specs' => json_decode((string)$row['specs'], true) ?: [],
        'price' => $price,
        'old_price' => $oldPrice,
        'discount' => $discount,
        'stock' => $stock,
        'stock_status' => $stockStatus,
        'stock_text' => $stockText,
        'image_path' => $row['image_path'],
        'brand_name' => $row['brand_name'],
        'brand_slug' => $row['brand_slug'],
        'category_name' => $row['category_name'],
        'category_slug' => $row['category_slug'],
        'machine_type' => $row['machine_type'],
        'type_label' => $typeLabels[$row['machine_type']] ?? 'Прочее',
        'is_favorite' => (bool)$row['is_favorite'],
        'sold' => (int)$row['sold'],
        'created_at' => $row['created_at'],
    ];
}

json_ok([
    'items' => $items,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'per_page' => $perPage,
    'sort' => $sort,
    'price_bounds' => ['min' => $boundMin, 'max' => $boundMax],
    'favorites_first' => (bool)$user,
    'filters' => [
        'brand' => $brand,
        'category' => $category,
        'type' => $type,
        'q' => $q,
        'min' => $min,
        'max' => $max,
        'in_stock' => $inStock,
        'on_sale' => $onSale,
    ],
]);
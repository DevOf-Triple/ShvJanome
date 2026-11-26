<?php
/**
 * api/admin/db_view.php — ТОЛЬКО ЧТЕНИЕ структуры и строк таблиц.
 * Никакого произвольного SQL: имя таблицы и колонки берутся из белого списка
 * ниже, пользовательский ввод в идентификаторы не попадает вообще.
 * Хэши паролей не выбираются и не отдаются.
 */

require_once __DIR__ . '/../../config/helpers.php';

$admin = api_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('Просмотр базы доступен только на чтение (GET)', 405);
}

/**
 * Белый список: таблица → подпись, колонки для выборки, колонка сортировки,
 * колонки для поиска. Всё, чего тут нет, просмотреть невозможно.
 */
$schema = [
    'users' => [
        'label' => 'Пользователи',
        'columns' => ['id', 'login', 'full_name', 'email', 'phone', 'address', 'avatar_path', 'role', 'created_at'],
        'order' => 'id', 'search' => ['login', 'full_name', 'email'],
        'note' => 'Колонка password_hash исключена из выборки: хэши не показываются.',
    ],
    'brands' => [
        'label' => 'Бренды', 'columns' => ['id', 'name', 'slug', 'sort_order'],
        'order' => 'sort_order', 'search' => ['name', 'slug'], 'note' => '',
    ],
    'categories' => [
        'label' => 'Категории', 'columns' => ['id', 'name', 'slug', 'sort_order'],
        'order' => 'sort_order', 'search' => ['name', 'slug'], 'note' => '',
    ],
    'products' => [
        'label' => 'Товары',
        'columns' => ['id', 'brand_id', 'category_id', 'name', 'model', 'slug', 'price', 'old_price', 'stock', 'is_active', 'image_path', 'created_at'],
        'order' => 'id', 'search' => ['name', 'model', 'slug'],
        'note' => 'Поля description и specs не выводятся в таблице — они длинные, смотрите их в редакторе товара.',
    ],
    'cart_items' => [
        'label' => 'Корзины', 'columns' => ['id', 'user_id', 'product_id', 'quantity', 'added_at'],
        'order' => 'id', 'search' => [], 'note' => '',
    ],
    'favorites' => [
        'label' => 'Избранное', 'columns' => ['id', 'user_id', 'product_id', 'added_at'],
        'order' => 'id', 'search' => [], 'note' => '',
    ],
    'orders' => [
        'label' => 'Заказы',
        'columns' => ['id', 'user_id', 'order_number', 'total', 'status', 'delivery', 'payment', 'address', 'phone', 'created_at'],
        'order' => 'id', 'search' => ['order_number', 'phone', 'address'], 'note' => '',
    ],
    'order_items' => [
        'label' => 'Позиции заказов', 'columns' => ['id', 'order_id', 'product_id', 'name', 'price', 'quantity'],
        'order' => 'id', 'search' => ['name'], 'note' => '',
    ],
    'feedback' => [
        'label' => 'Обращения', 'columns' => ['id', 'user_id', 'name', 'email', 'message', 'is_read', 'created_at'],
        'order' => 'id', 'search' => ['name', 'email', 'message'], 'note' => '',
    ],
];

/* ---------- Счётчики строк по всем таблицам ---------- */
$counts = [];
foreach ($schema as $name => $meta) {
    // Имя берётся только из ключей белого списка
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $name . '`');
    $stmt->execute();
    $counts[$name] = (int)$stmt->fetchColumn();
}

$tables = [];
foreach ($schema as $name => $meta) {
    $tables[] = ['name' => $name, 'label' => $meta['label'], 'rows' => $counts[$name]];
}

/* ---------- Выбор таблицы строго по белому списку ---------- */
$requested = (string)($_GET['table'] ?? 'products');
if (!isset($schema[$requested])) {
    json_error('Таблица не разрешена к просмотру', 403, ['tables' => $tables]);
}
$table = $requested;
$meta = $schema[$table];

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$whereSql = '1 = 1';
$params = [];
if ($q !== '' && $meta['search']) {
    $parts = [];
    foreach ($meta['search'] as $column) {
        $parts[] = '`' . $column . '` LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $whereSql = '(' . implode(' OR ', $parts) . ')';
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $whereSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) { $page = $pages; }
$offset = ($page - 1) * $perPage;

// Колонки и сортировка — только из белого списка, LIMIT/OFFSET биндим как int
$columnSql = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $meta['columns']));
$sql = 'SELECT ' . $columnSql . ' FROM `' . $table . '` WHERE ' . $whereSql
    . ' ORDER BY `' . $meta['order'] . '` DESC LIMIT ? OFFSET ?';

$stmt = $pdo->prepare($sql);
$pos = 1;
foreach ($params as $value) { $stmt->bindValue($pos++, $value, PDO::PARAM_STR); }
$stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($pos, $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = [];
foreach ($stmt->fetchAll() as $row) {
    $clean = [];
    foreach ($meta['columns'] as $column) {
        $value = $row[$column] ?? null;
        if (is_string($value) && mb_strlen($value) > 160) {
            $value = mb_strimwidth($value, 0, 160, '…', 'UTF-8');
        }
        $clean[$column] = $value;
    }
    $rows[] = $clean;
}

json_ok([
    'tables' => $tables,
    'table' => $table,
    'label' => $meta['label'],
    'note' => $meta['note'],
    'columns' => $meta['columns'],
    'searchable' => (bool)$meta['search'],
    'rows' => $rows,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'per_page' => $perPage,
    'read_only' => true,
]);
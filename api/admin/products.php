<?php
/**
 * api/admin/products.php — CRUD товаров + загрузка фото.
 * GET: список с поиском и фильтрами. POST: create | update | delete | toggle_active.
 * Фото: multipart/form-data (поле photo), jpg/png/webp до 2 МБ, имя случайное.
 * Доступ только admin.
 */

require_once __DIR__ . '/../../config/helpers.php';

$admin = api_require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$photoDir = __DIR__ . '/../../assets/img/products/';
$photoPrefix = 'assets/img/products/';
$placeholder = 'assets/img/products/placeholder.svg';

/** Транслитерация для автогенерации slug */
$makeSlug = static function (string $text): string {
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string)$text, '-');
    return mb_substr($text !== '' ? $text : 'product', 0, 140, 'UTF-8');
};

/** Удаляем только свои файлы товаров, плейсхолдер не трогаем */
$deletePhoto = static function (?string $path) use ($photoDir, $photoPrefix, $placeholder): void {
    if (!$path || $path === $placeholder || strpos($path, $photoPrefix) !== 0) {
        return;
    }
    $name = basename($path);
    if ($name === '' || $name === '.' || $name === '..') {
        return;
    }
    if (is_file($photoDir . $name)) {
        @unlink($photoDir . $name);
    }
};

/* ================= GET: список ================= */
if ($method === 'GET') {
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $brand = (int)($_GET['brand'] ?? 0);
    $category = (int)($_GET['category'] ?? 0);
    $status = (string)($_GET['status'] ?? 'all');
    $perPage = 10;
    $page = max(1, (int)($_GET['page'] ?? 1));

    $where = ['1 = 1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.model LIKE ? OR p.slug LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if ($brand > 0) { $where[] = 'p.brand_id = ?'; $params[] = $brand; }
    if ($category > 0) { $where[] = 'p.category_id = ?'; $params[] = $category; }
    if ($status === 'active') { $where[] = 'p.is_active = 1'; }
    elseif ($status === 'hidden') { $where[] = 'p.is_active = 0'; }
    elseif ($status === 'out') { $where[] = 'p.stock = 0'; }
    elseif ($status === 'low') { $where[] = 'p.stock > 0 AND p.stock <= 3'; }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM products p WHERE ' . $whereSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT p.id, p.brand_id, p.category_id, p.name, p.model, p.slug, p.description, p.specs,
                p.price, p.old_price, p.stock, p.image_path, p.is_active, p.created_at,
                b.name AS brand_name, c.name AS category_name
         FROM products p
         JOIN brands b ON b.id = p.brand_id
         JOIN categories c ON c.id = p.category_id
         WHERE ' . $whereSql . '
         ORDER BY p.id DESC LIMIT ? OFFSET ?'
    );
    $pos = 1;
    foreach ($params as $value) { $stmt->bindValue($pos++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR); }
    $stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($pos, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'brand_id' => (int)$row['brand_id'],
            'category_id' => (int)$row['category_id'],
            'name' => $row['name'],
            'model' => $row['model'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'specs' => (string)$row['specs'],
            'price' => (float)$row['price'],
            'old_price' => $row['old_price'] !== null ? (float)$row['old_price'] : null,
            'stock' => (int)$row['stock'],
            'image_path' => $row['image_path'],
            'is_active' => (int)$row['is_active'] === 1,
            'created_at' => $row['created_at'],
            'brand_name' => $row['brand_name'],
            'category_name' => $row['category_name'],
        ];
    }

    json_ok(['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();

// Данные приходят либо multipart (с фото), либо JSON
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;
$input = $isMultipart ? $_POST : read_json_body();
$action = (string)($input['action'] ?? '');

/* ================= Удаление ================= */
if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) { json_error('Не указан товар', 422); }

    $stmt = $pdo->prepare('SELECT image_path FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $imagePath = $stmt->fetchColumn();
    if ($imagePath === false) { json_error('Товар не найден', 404); }

    try {
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error('Товар есть в оформленных заказах. Снимите его с продажи вместо удаления.', 409);
        }
        throw $e;
    }

    $deletePhoto((string)$imagePath);
    json_ok(['message' => 'Товар удалён']);
}

/* ================= Быстрое переключение активности ================= */
if ($action === 'toggle_active') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) { json_error('Не указан товар', 422); }

    $stmt = $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) { json_error('Товар не найден', 404); }
    }

    $stmt = $pdo->prepare('SELECT is_active FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $isActive = (int)$stmt->fetchColumn() === 1;

    json_ok(['is_active' => $isActive, 'message' => $isActive ? 'Товар показан в каталоге' : 'Товар скрыт из каталога']);
}

/* ================= Создание и обновление ================= */
if (!in_array($action, ['create', 'update'], true)) {
    json_error('Неизвестное действие', 422);
}

$id = (int)($input['id'] ?? 0);
$name = trim((string)($input['name'] ?? ''));
$model = trim((string)($input['model'] ?? ''));
$slug = trim((string)($input['slug'] ?? ''));
$brandId = (int)($input['brand_id'] ?? 0);
$categoryId = (int)($input['category_id'] ?? 0);
$description = trim((string)($input['description'] ?? ''));
$specsRaw = trim((string)($input['specs'] ?? ''));
$price = (float)str_replace(',', '.', (string)($input['price'] ?? 0));
$oldRaw = trim((string)($input['old_price'] ?? ''));
$stock = (int)($input['stock'] ?? 0);
$isActive = !empty($input['is_active']) && $input['is_active'] !== '0' ? 1 : 0;
$removePhoto = !empty($input['remove_photo']);

$errors = [];

if (mb_strlen($name) < 2 || mb_strlen($name) > 200) { $errors['name'] = 'Название: 2–200 символов'; }
if ($model === '' || mb_strlen($model) > 100) { $errors['model'] = 'Модель: 1–100 символов'; }
if ($brandId <= 0) { $errors['brand_id'] = 'Выберите бренд'; }
if ($categoryId <= 0) { $errors['category_id'] = 'Выберите категорию'; }
if ($price <= 0 || $price > 99999999) { $errors['price'] = 'Цена: больше нуля и до 99 999 999'; }
if ($stock < 0 || $stock > 100000) { $errors['stock'] = 'Остаток: 0–100000'; }
if (mb_strlen($description) > 5000) { $errors['description'] = 'Описание: до 5000 символов'; }

$oldPrice = null;
if ($oldRaw !== '') {
    $oldPrice = (float)str_replace(',', '.', $oldRaw);
    if ($oldPrice <= $price) { $errors['old_price'] = 'Старая цена должна быть больше текущей'; }
}

//_specs: пустая строка = пустой объект
$specsJson = '{}';
if ($specsRaw !== '') {
    $decoded = json_decode($specsRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        $errors['specs'] = 'specs должен быть корректным JSON-объектом';
    } else {
        $specsJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if ($action === 'update' && $id <= 0) { $errors['id'] = 'Не указан товар'; }

// Ссылки на бренд и категорию должны существовать
if ($brandId > 0) {
    $stmt = $pdo->prepare('SELECT id FROM brands WHERE id = ?');
    $stmt->execute([$brandId]);
    if (!$stmt->fetch()) { $errors['brand_id'] = 'Такого бренда нет'; }
}
if ($categoryId > 0) {
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    if (!$stmt->fetch()) { $errors['category_id'] = 'Такой категории нет'; }
}

if ($slug === '') { $slug = $makeSlug($model !== '' ? $model : $name); }
else { $slug = $makeSlug($slug); }

$stmt = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1');
$stmt->execute([$slug, $id]);
if ($stmt->fetch()) { $errors['slug'] = 'Такой slug уже занят'; }

if ($errors) { json_error('Проверьте поля формы', 422, ['errors' => $errors]); }

/* ---------- Фото ---------- */
$uploadedPath = null;
if (isset($_FILES['photo']) && is_array($_FILES['photo']) && (int)$_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['photo'];
    switch ((int)$file['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: json_error('Фото больше 2 МБ', 413);
        case UPLOAD_ERR_PARTIAL: json_error('Файл загрузился не полностью', 400);
        default: json_error('Ошибка загрузки фото', 400);
    }
    if ((int)$file['size'] > 2 * 1024 * 1024) { json_error('Фото больше 2 МБ', 413); }
    if (!is_uploaded_file($file['tmp_name'])) { json_error('Некорректная загрузка', 400); }

    $mime = class_exists('finfo')
        ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
        : (string)@mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) { json_error('Фото: только JPG, PNG или WebP', 415); }

    $size = @getimagesize($file['tmp_name']);
    if ($size === false) { json_error('Файл не является изображением', 415); }
    if ((int)$size[0] < 200 || (int)$size[1] < 150) { json_error('Фото минимум 200×150 px', 422); }
    if ((int)$size[0] > 6000 || (int)$size[1] > 6000) { json_error('Фото максимум 6000×6000 px', 422); }

    if (!is_dir($photoDir) && !mkdir($photoDir, 0775, true) && !is_dir($photoDir)) {
        json_error('Не удалось создать папку фото', 500);
    }
    try { $random = bin2hex(random_bytes(16)); }
    catch (Throwable $e) { $random = str_replace('.', '', uniqid('p', true)); }

    $fileName = $random . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $photoDir . $fileName)) {
        json_error('Не удалось сохранить фото', 500);
    }
    @chmod($photoDir . $fileName, 0644);
    $uploadedPath = $photoPrefix . $fileName;
}

/* ---------- Старая картинка ---------- */
$oldImagePath = null;
if ($action === 'update') {
    $stmt = $pdo->prepare('SELECT image_path FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $oldImagePath = $stmt->fetchColumn();
    if ($oldImagePath === false) {
        $deletePhoto($uploadedPath);
        json_error('Товар не найден', 404);
    }
    $oldImagePath = (string)$oldImagePath;
}

$imagePath = $uploadedPath
    ?? ($removePhoto ? $placeholder : ($oldImagePath ?? $placeholder));

/* ---------- Запись ---------- */
try {
    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO products (brand_id, category_id, name, model, slug, description, specs, price, old_price, stock, image_path, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$brandId, $categoryId, $name, $model, $slug, $description, $specsJson, $price, $oldPrice, $stock, $imagePath, $isActive]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare(
            'UPDATE products SET brand_id = ?, category_id = ?, name = ?, model = ?, slug = ?, description = ?,
                    specs = ?, price = ?, old_price = ?, stock = ?, image_path = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([$brandId, $categoryId, $name, $model, $slug, $description, $specsJson, $price, $oldPrice, $stock, $imagePath, $isActive, $id]);
    }
} catch (PDOException $e) {
    $deletePhoto($uploadedPath);
    if ($e->getCode() === '23000') { json_error('Slug уже занят или нарушена связь с брендом/категорией', 409); }
    throw $e;
}

// Старое фото убираем только после успешной записи
if ($uploadedPath !== null || $removePhoto) {
    $deletePhoto($oldImagePath);
}

$stmt = $pdo->prepare('SELECT id, name, model, slug, image_path, is_active FROM products WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

json_ok([
    'id' => $id,
    'slug' => $row['slug'],
    'image_path' => $row['image_path'],
    'message' => $action === 'create' ? 'Товар создан' : 'Товар сохранён',
]);
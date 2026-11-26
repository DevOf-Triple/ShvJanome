<?php
/**
 * api/admin/categories.php — CRUD категорий. Только admin.
 * GET: список с числом товаров. POST: create | update | delete.
 */

require_once __DIR__ . '/../../config/helpers.php';

$admin = api_require_admin();

$makeSlug = static function (string $text): string {
    $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    $text = strtr(mb_strtolower(trim($text), 'UTF-8'), $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return mb_substr(trim((string)$text, '-') ?: 'category', 0, 90, 'UTF-8');
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.slug, c.sort_order,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products_count
         FROM categories c ORDER BY c.sort_order ASC, c.name ASC'
    );
    $stmt->execute();
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int)$row['id'], 'name' => $row['name'], 'slug' => $row['slug'],
            'sort_order' => (int)$row['sort_order'], 'products_count' => (int)$row['products_count'],
        ];
    }
    json_ok(['items' => $items, 'total' => count($items)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { json_error('Не указана категория', 422); }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
    $stmt->execute([$id]);
    $linked = (int)$stmt->fetchColumn();
    if ($linked > 0) {
        json_error('В категории ' . $linked . ' товар(ов). Сначала перенесите или удалите их.', 409);
    }

    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) { json_error('Категория не найдена', 404); }

    json_ok(['message' => 'Категория удалена']);
}

if (!in_array($action, ['create', 'update'], true)) { json_error('Неизвестное действие', 422); }

$id = (int)($body['id'] ?? 0);
$name = trim((string)($body['name'] ?? ''));
$slug = trim((string)($body['slug'] ?? ''));
$sortOrder = (int)($body['sort_order'] ?? 0);

$errors = [];
if (mb_strlen($name) < 2 || mb_strlen($name) > 100) { $errors['name'] = 'Название: 2–100 символов'; }
if ($sortOrder < 0 || $sortOrder > 9999) { $errors['sort_order'] = 'Порядок: 0–9999'; }
if ($action === 'update' && $id <= 0) { $errors['id'] = 'Не указана категория'; }

$slug = $makeSlug($slug !== '' ? $slug : $name);
$stmt = $pdo->prepare('SELECT id FROM categories WHERE slug = ? AND id <> ? LIMIT 1');
$stmt->execute([$slug, $id]);
if ($stmt->fetch()) { $errors['slug'] = 'Такой slug уже занят'; }

if ($errors) { json_error('Проверьте поля формы', 422, ['errors' => $errors]); }

try {
    if ($action === 'create') {
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, $sortOrder]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $sortOrder, $id]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) { json_error('Категория не найдена', 404); }
        }
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { json_error('Slug уже занят', 409); }
    throw $e;
}

json_ok(['id' => $id, 'slug' => $slug, 'message' => $action === 'create' ? 'Категория создана' : 'Категория сохранена']);
<?php
/**
 * api/favorite_toggle.php — POST: добавить/убрать товар из избранного.
 * Только авторизованный: гостю избранное не существует ни в сессии, ни в БД.
 * Тело JSON: {product_id}. Ответ: {ok, favorite, favorites_count}.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

// Роль/авторизация проверяется на сервере, а не скрытием сердечка
$user = api_require_login();
api_require_csrf();

$body = read_json_body();
$productId = (int)($body['product_id'] ?? 0);

if ($productId <= 0) {
    json_error('Некорректный товар', 422);
}

$stmt = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$productId]);
if (!$stmt->fetch()) {
    json_error('Товар не найден', 404);
}

$userId = (int)$user['id'];

// Переключение в транзакции с блокировкой строки — двойной клик не насчитает лишнего
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM favorites WHERE user_id = ? AND product_id = ? FOR UPDATE');
    $stmt->execute([$userId, $productId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare('DELETE FROM favorites WHERE id = ?');
        $stmt->execute([$existing['id']]);
        $favorite = false;
    } else {
        $stmt = $pdo->prepare('INSERT INTO favorites (user_id, product_id) VALUES (?, ?)');
        $stmt->execute([$userId, $productId]);
        $favorite = true;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$stmt->execute([$userId]);
$favoritesCount = (int)$stmt->fetchColumn();

json_ok([
    'favorite' => $favorite,
    'favorites_count' => $favoritesCount,
    'product_id' => $productId,
]);
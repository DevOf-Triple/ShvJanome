<?php
/*
 * api/me.php — GET: текущий пользователь и счётчики.
 * Гость получает {ok: true, user: null} — это проверка сессии, не ошибка.
 * Данные берутся из БД свежими: роль/аватар могли измениться.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_error('Метод не поддерживается', 405);
}

$emptyCounts = ['cart' => 0, 'favorites' => 0];

if (!is_logged_in()) {
    json_ok(['user' => null, 'counts' => $emptyCounts]);
}

$sessionUser = current_user();
$stmt = $pdo->prepare(
    'SELECT id, login, full_name, email, phone, address, avatar_path, role, created_at
     FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$sessionUser['id']]);
$user = $stmt->fetch();

// Аккаунт удалён, пока сессия жила — гасим её
if (!$user) {
    logout_user();
    json_ok(['user' => null, 'counts' => $emptyCounts]);
}

json_ok([
    'user' => [
        'id' => (int)$user['id'],
        'login' => $user['login'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'address' => $user['address'],
        'avatar_path' => $user['avatar_path'],
        'role' => $user['role'],
        'created_at' => $user['created_at'],
    ],
    'counts' => user_counts(),
]);
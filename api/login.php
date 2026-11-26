<?php
/*
 * api/login.php — POST: вход по логину.
 * Тело JSON: {login, password, return}. Ответ: {ok, redirect, user}.
 * Пароли только password_verify, роль берётся из users.role и кладётся в сессию.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();

$body = read_json_body();
$login = trim((string)($body['login'] ?? ''));
$password = (string)($body['password'] ?? '');
$return = safe_return_url($body['return'] ?? null);

// Простой троттлинг по сессии: 5 неудач за 15 минут
$now = time();
$window = 900;
$maxAttempts = 5;
$attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'first' => $now];
if ($attempts['first'] < $now - $window) {
    $attempts = ['count' => 0, 'first' => $now];
}
if ($attempts['count'] >= $maxAttempts) {
    $waitMin = (int)ceil(($attempts['first'] + $window - $now) / 60);
    json_error('Слишком много попыток входа. Подожди ' . $waitMin . ' мин.', 429);
}

// Валидация на сервере (клиентская — только для удобства)
$errors = [];
if ($login === '') {
    $errors['login'] = 'Введите логин';
}
if ($password === '') {
    $errors['password'] = 'Введите пароль';
}
if ($errors) {
    json_error('Заполни поля формы', 422, ['errors' => $errors]);
}

// Ищем строго по логину, не по email
$stmt = $pdo->prepare(
    'SELECT id, login, password_hash, full_name, email, phone, address, avatar_path, role, created_at
     FROM users WHERE login = ? LIMIT 1'
);
$stmt->execute([$login]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    // Фиксируем неудачу и не раскрываем, существует ли логин
    $attempts['count']++;
    $_SESSION['login_attempts'] = $attempts;
    json_error('Неверный логин или пароль', 401, ['errors' => ['password' => 'Неверный логин или пароль']]);
}

// Алгоритм хэша устарел — перехэшируем прозрачно для пользователя
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $upd->execute([$newHash, $user['id']]);
    $user['password_hash'] = $newHash;
}

// Успех: сессия, роль, сброс троттлинга
login_user($user);
unset($_SESSION['login_attempts']);
flash_toast('С возвращением, ' . $user['full_name'] . '!', 'success');

json_ok([
    'redirect' => $return,
    'user' => [
        'id' => (int)$user['id'],
        'login' => $user['login'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'avatar_path' => $user['avatar_path'],
    ],
]);
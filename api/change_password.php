<?php
/**
 * api/change_password.php — POST: смена пароля с проверкой старого.
 * Тело JSON: {old_password, new_password, repeat_password}.
 * Только password_verify / password_hash, после смены id сессии обновляется.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
api_require_csrf();

$body = read_json_body();
$userId = (int)$user['id'];

$old = (string)($body['old_password'] ?? '');
$new = (string)($body['new_password'] ?? '');
$repeat = (string)($body['repeat_password'] ?? '');

// Троттлинг подбора старого пароля: 5 неудач за 15 минут
$now = time();
$window = 900;
$maxAttempts = 5;
$attempts = $_SESSION['pwd_attempts'] ?? ['count' => 0, 'first' => $now];
if ($attempts['first'] < $now - $window) {
    $attempts = ['count' => 0, 'first' => $now];
}
if ($attempts['count'] >= $maxAttempts) {
    $waitMin = (int)ceil(($attempts['first'] + $window - $now) / 60);
    json_error('Слишком много попыток. Подожди ' . $waitMin . ' мин.', 429);
}

$errors = [];
if ($old === '') {
    $errors['old_password'] = 'Введите текущий пароль';
}
if (mb_strlen($new) < 6) {
    $errors['new_password'] = 'Новый пароль: минимум 6 символов';
} elseif (strlen($new) > 72) {
    // bcrypt режет всё длиннее 72 байт
    $errors['new_password'] = 'Новый пароль слишком длинный (до 72 байт)';
}
if ($repeat !== $new || $repeat === '') {
    $errors['repeat_password'] = 'Пароли не совпадают';
}

if ($errors) {
    json_error('Проверьте поля формы', 422, ['errors' => $errors]);
}

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$hash = (string)$stmt->fetchColumn();

if ($hash === '' || !password_verify($old, $hash)) {
    $attempts['count']++;
    $_SESSION['pwd_attempts'] = $attempts;
    json_error('Текущий пароль неверный', 403, ['errors' => ['old_password' => 'Текущий пароль неверный']]);
}

if (password_verify($new, $hash)) {
    json_error('Новый пароль совпадает с текущим', 422, ['errors' => ['new_password' => 'Придумайте другой пароль']]);
}

$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

unset($_SESSION['pwd_attempts']);
// Сессия остаётся активной, но её идентификатор обновляется
session_regenerate_id(true);

json_ok(['message' => 'Пароль обновлён']);
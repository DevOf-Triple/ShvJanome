<?php
/**
 * api/update_profile.php — POST: обновление профиля.
 * Тело JSON: {full_name, email, phone, address}.
 * Логин и роль не меняются: логин — идентификатор входа, роль — только через админку.
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

$fullName = trim((string)($body['full_name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$phoneRaw = trim((string)($body['phone'] ?? ''));
$addressRaw = trim((string)($body['address'] ?? ''));

$errors = [];

if (!preg_match('/^[\p{L}\s\'\.\-]{2,150}$/u', $fullName)) {
    $errors['full_name'] = 'Имя: 2–150 символов, буквы, пробелы, дефис';
}
if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Введите корректный email';
}

// Телефон необязательный, но если указан — нормализуем к +цифры
$phone = null;
if ($phoneRaw !== '') {
    $digits = (string)preg_replace('/\D+/', '', $phoneRaw);
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        $errors['phone'] = 'Телефон: от 10 до 15 цифр';
    } else {
        $phone = '+' . $digits;
    }
}

$address = trim((string)preg_replace('/\s+/u', ' ', $addressRaw));
if (mb_strlen($address) > 255) {
    $errors['address'] = 'Адрес: до 255 символов';
}
$addressValue = $address !== '' ? $address : null;

if ($errors) {
    json_error('Проверьте поля формы', 422, ['errors' => $errors]);
}

// Email уникален среди остальных аккаунтов
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
$stmt->execute([$email, $userId]);
if ($stmt->fetch()) {
    json_error('Этот email занят другим аккаунтом', 409, ['errors' => ['email' => 'Этот email уже используется']]);
}

try {
    $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, address = ? WHERE id = ?');
    $stmt->execute([$fullName, $email, $phone, $addressValue, $userId]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_error('Этот email занят другим аккаунтом', 409, ['errors' => ['email' => 'Этот email уже используется']]);
    }
    throw $e;
}

// Обновляем сессию без смены её идентификатора
$_SESSION['user']['full_name'] = $fullName;

$stmt = $pdo->prepare(
    'SELECT id, login, full_name, email, phone, address, avatar_path, role, created_at FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$fresh = $stmt->fetch();

json_ok([
    'user' => [
        'id' => (int)$fresh['id'],
        'login' => $fresh['login'],
        'full_name' => $fresh['full_name'],
        'email' => $fresh['email'],
        'phone' => $fresh['phone'],
        'address' => $fresh['address'],
        'avatar_path' => $fresh['avatar_path'],
        'role' => $fresh['role'],
        'created_at' => $fresh['created_at'],
    ],
    'initials' => user_initials($fresh['full_name']),
    'message' => 'Профиль сохранён',
]);
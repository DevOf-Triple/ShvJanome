<?php
/*
 * api/register.php — POST: регистрация покупателя.
 * Тело JSON: {login, full_name, email, password, password_repeat, return}.
 * Роль ВСЕГДА user — из клиента роль не принимается никогда.
 * После регистрации — автоматический вход.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();

$body = read_json_body();
$login = trim((string)($body['login'] ?? ''));
$fullName = trim((string)($body['full_name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');
$repeat = (string)($body['password_repeat'] ?? '');
$rawReturn = (string)($body['return'] ?? '');
$return = $rawReturn !== '' ? safe_return_url($rawReturn) : 'cabinet.php';

// Серверная валидация
$errors = [];

if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $login)) {
    $errors['login'] = 'Логин: 3–50 символов, латиница, цифры, _ и -';
}
if (!preg_match('/^[\p{L}\s\'\.\-]{2,150}$/u', $fullName)) {
    $errors['full_name'] = 'Имя: 2–150 символов, буквы, пробелы, дефис';
}
if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Введите корректный email';
}
// bcrypt ограничивает 72 байта — проверяем байтовую длину, чтобы не было тихого обрезания
if (mb_strlen($password) < 6) {
    $errors['password'] = 'Пароль: минимум 6 символов';
} elseif (strlen($password) > 72) {
    $errors['password'] = 'Пароль слишком длинный (до 72 байт)';
}
if ($repeat !== $password || $repeat === '') {
    $errors['password_repeat'] = 'Пароли не совпадают';
}

if ($errors) {
    json_error('Проверь выделенные поля', 422, ['errors' => $errors]);
}

// Уникальность логина и email (коллация utf8mb4_unicode_ci — регистронезависимо)
$stmt = $pdo->prepare('SELECT id FROM users WHERE login = ? LIMIT 1');
$stmt->execute([$login]);
if ($stmt->fetch()) {
    $errors['login'] = 'Этот логин уже занят';
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    $errors['email'] = 'Этот email уже зарегистрирован';
}

if ($errors) {
    json_error('Логин или email уже заняты', 409, ['errors' => $errors]);
}

// Создаём пользователя: только password_hash, роль user
try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (login, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, \'user\')'
    );
    $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT), $fullName, $email]);
} catch (PDOException $e) {
    // Гонка уникальности: поймать дубль на UNIQUE-индексе
    if ($e->getCode() === '23000') {
        json_error('Логин или email уже заняты', 409);
    }
    throw $e;
}

// Автоматический вход после регистрации
$userId = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare(
    'SELECT id, login, password_hash, full_name, email, phone, address, avatar_path, role, created_at
     FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

login_user($user);
flash_toast('Аккаунт создан. Добро пожаловать, ' . $user['full_name'] . '!', 'success');

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
<?php
/**
 * api/upload_avatar.php — POST: загрузка или удаление аватара.
 * Загрузка: multipart/form-data, поле avatar (+ csrf).
 * Удаление: JSON {action:'remove'} либо form-data action=remove.
 * Требования: jpg/png/webp, до 2 МБ, имя файла случайное,
 * хранение в assets/img/avatars/, путь в БД от корня проекта.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

$user = api_require_login();
$userId = (int)$user['id'];

// Если post_max_size превышен, PHP очищает $_POST и $_FILES — объясняем причину
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    json_error('Файл слишком большой для сервера (лимит post_max_size)', 413);
}

api_require_csrf();

$avatarDir = __DIR__ . '/../assets/img/avatars/';
$avatarPrefix = 'assets/img/avatars/';

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    $jsonBody = read_json_body();
    $action = (string)($jsonBody['action'] ?? 'upload');
}

/**
 * Удаляем только свои файлы: путь обязан начинаться с префикса аватаров,
 * имя берётся через basename — обход каталогов невозможен.
 */
$deleteOldAvatar = static function (?string $path) use ($avatarDir, $avatarPrefix): void {
    if (!$path || strpos($path, $avatarPrefix) !== 0) {
        return;
    }
    $name = basename($path);
    if ($name === '' || $name === '.' || $name === '..') {
        return;
    }
    $full = $avatarDir . $name;
    if (is_file($full)) {
        @unlink($full);
    }
};

$stmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$oldPath = $stmt->fetchColumn();
$oldPath = $oldPath !== false ? (string)$oldPath : null;

/* ---------- Удаление аватара ---------- */
if ($action === 'remove') {
    $stmt = $pdo->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?');
    $stmt->execute([$userId]);

    $deleteOldAvatar($oldPath);
    $_SESSION['user']['avatar_path'] = null;

    json_ok([
        'avatar_path' => null,
        'url' => null,
        'initials' => user_initials((string)$user['full_name']),
        'message' => 'Аватар удалён',
    ]);
}

if ($action !== 'upload') {
    json_error('Неизвестное действие', 422);
}

/* ---------- Загрузка аватара ---------- */
if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
    json_error('Файл не получен', 422);
}
$file = $_FILES['avatar'];

switch ((int)$file['error']) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        json_error('Файл больше 2 МБ', 413);
    case UPLOAD_ERR_NO_FILE:
        json_error('Выберите файл', 422);
    case UPLOAD_ERR_PARTIAL:
        json_error('Файл загрузился не полностью, повторите', 400);
    case UPLOAD_ERR_NO_TMP_DIR:
    case UPLOAD_ERR_CANT_WRITE:
    case UPLOAD_ERR_EXTENSION:
        json_error('Сервер не смог сохранить файл', 500);
    default:
        json_error('Ошибка загрузки файла', 400);
}

$maxBytes = 2 * 1024 * 1024;
if ((int)$file['size'] <= 0) {
    json_error('Пустой файл', 422);
}
if ((int)$file['size'] > $maxBytes) {
    json_error('Файл больше 2 МБ', 413);
}
if (!is_uploaded_file($file['tmp_name'])) {
    json_error('Некорректная загрузка', 400);
}

// Тип определяем по содержимому, а не по расширению и не по словам браузера
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
} else {
    $mime = (string)@mime_content_type($file['tmp_name']);
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    json_error('Только JPG, PNG или WebP', 415);
}

$size = @getimagesize($file['tmp_name']);
if ($size === false) {
    json_error('Файл не является изображением', 415);
}
if ((int)$size[0] < 64 || (int)$size[1] < 64) {
    json_error('Минимальный размер картинки — 64×64 пикселя', 422);
}
if ((int)$size[0] > 6000 || (int)$size[1] > 6000) {
    json_error('Максимальный размер картинки — 6000×6000 пикселей', 422);
}

if (!is_dir($avatarDir) && !mkdir($avatarDir, 0775, true) && !is_dir($avatarDir)) {
    json_error('Не удалось создать папку для аватаров', 500);
}

// Случайное имя: предсказать путь к чужому файлу нельзя
try {
    $randomName = bin2hex(random_bytes(16));
} catch (Throwable $e) {
    $randomName = str_replace('.', '', uniqid('av', true));
}
$fileName = $randomName . '.' . $allowed[$mime];
$target = $avatarDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    json_error('Не удалось сохранить файл на сервере', 500);
}
@chmod($target, 0644);

$stmt = $pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?');
$stmt->execute([$avatarPrefix . $fileName, $userId]);

// Старый файл убираем только после успешной записи нового
$deleteOldAvatar($oldPath);
$_SESSION['user']['avatar_path'] = $avatarPrefix . $fileName;

json_ok([
    'avatar_path' => $avatarPrefix . $fileName,
    'url' => '../' . $avatarPrefix . $fileName,
    'message' => 'Аватар обновлён',
]);
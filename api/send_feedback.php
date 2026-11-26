<?php
/**
 * api/send_feedback.php — POST: обращение в таблицу feedback.
 * Тело JSON: {name, email, message, consent, website, form_ts}.
 * website — honeypot: невидимое поле, человек его не трогает.
 * Заполненный honeypot или отправка быстрее 2 секунд от рендера = бот:
 * отвечаем «принято», но в БД не пишем (бот не должен понять, что его поймали).
 * Никакой почты: сообщение живёт в БД, менеджер сам звонит по указанному телефону.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

// Сессия есть и у гостя, токен CSRF тоже — проверяем всегда
api_require_csrf();

$body = read_json_body();

/* ---------- Ловушки для ботов: тихий сброс ---------- */
$honeypot = trim((string)($body['website'] ?? ''));
$formTs = (int)($body['form_ts'] ?? 0);
$tooFast = $formTs > 0 && (time() - $formTs) < 2;

if ($honeypot !== '' || $tooFast) {
    json_ok([
        'id' => 0,
        'today_count' => 0,
        'message' => 'Обращение принято. Мы свяжемся с вами в рабочий день.',
    ]);
}

/* ---------- Валидация на сервере ---------- */
$name = trim((string)($body['name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$message = trim((string)($body['message'] ?? ''));
$consent = !empty($body['consent']);

$errors = [];

if (!preg_match('/^[\p{L}\s\'\.\-]{2,100}$/u', $name)) {
    $errors['name'] = 'Имя: 2–100 символов, буквы, пробелы, дефис';
}
if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Введите корректный email';
}
$length = mb_strlen($message);
if ($length < 10) {
    $errors['message'] = 'Сообщение: минимум 10 символов';
} elseif ($length > 2000) {
    $errors['message'] = 'Сообщение: до 2000 символов';
}
if (!$consent) {
    $errors['consent'] = 'Без согласия на обработку данных не сможем ответить';
}

if ($errors) {
    json_error('Проверьте поля формы', 422, ['errors' => $errors]);
}

/* ---------- Троттлинг по сессии: 5 обращений за 10 минут ---------- */
$now = time();
$window = 600;
$maxPerWindow = 5;
$sent = $_SESSION['fb_sent'] ?? ['count' => 0, 'first' => $now];
if ($sent['first'] < $now - $window) {
    $sent = ['count' => 0, 'first' => $now];
}
if ($sent['count'] >= $maxPerWindow) {
    $waitMin = (int)ceil(($sent['first'] + $window - $now) / 60);
    json_error('Слишком много обращений подряд. Подождите ' . $waitMin . ' мин.', 429);
}

/* ---------- Дубликат того же текста за последние 5 минут ---------- */
$stmt = $pdo->prepare(
    'SELECT id FROM feedback
     WHERE name = ? AND email = ? AND message = ?
       AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
     LIMIT 1'
);
$stmt->execute([$name, $email, $message]);
if ($stmt->fetch()) {
    json_error('Это сообщение уже отправлено недавно — подождите ответа', 409);
}

/* ---------- Запись: user_id только если обращение из аккаунта ---------- */
$user = current_user();
$userId = $user ? (int)$user['id'] : null;

$stmt = $pdo->prepare('INSERT INTO feedback (user_id, name, email, message) VALUES (?, ?, ?, ?)');
$stmt->execute([$userId, $name, $email, $message]);
$feedbackId = (int)$pdo->lastInsertId();

$sent['count']++;
$_SESSION['fb_sent'] = $sent;

// Сколько обращений пришло сегодня — для живой статистики на странице
$stmt = $pdo->prepare('SELECT COUNT(*) FROM feedback WHERE DATE(created_at) = CURDATE()');
$stmt->execute();
$todayCount = (int)$stmt->fetchColumn();

json_ok([
    'id' => $feedbackId,
    'today_count' => $todayCount,
    'message' => 'Обращение №' . $feedbackId . ' принято',
]);
<?php
/**
 * api/admin/feedback.php — обращения: список, отметка прочитанного, удаление.
 * Только admin.
 */

require_once __DIR__ . '/../../config/helpers.php';

$admin = api_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter = (string)($_GET['filter'] ?? 'all');
    $filter = in_array($filter, ['unread', 'read'], true) ? $filter : 'all';
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $perPage = 8;
    $page = max(1, (int)($_GET['page'] ?? 1));

    $where = ['1 = 1'];
    $params = [];
    if ($filter === 'unread') { $where[] = 'f.is_read = 0'; }
    elseif ($filter === 'read') { $where[] = 'f.is_read = 1'; }
    if ($q !== '') {
        $where[] = '(f.name LIKE ? OR f.email LIKE ? OR f.message LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM feedback f WHERE ' . $whereSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT f.id, f.user_id, f.name, f.email, f.message, f.is_read, f.created_at, u.login
         FROM feedback f LEFT JOIN users u ON u.id = f.user_id
         WHERE ' . $whereSql . ' ORDER BY f.is_read ASC, f.id DESC LIMIT ? OFFSET ?'
    );
    $pos = 1;
    foreach ($params as $value) { $stmt->bindValue($pos++, $value, PDO::PARAM_STR); }
    $stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($pos, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'login' => $row['login'],
            'name' => $row['name'], 'email' => $row['email'], 'message' => $row['message'],
            'is_read' => (int)$row['is_read'] === 1, 'created_at' => $row['created_at'],
        ];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(is_read = 0) AS unread FROM feedback');
    $stmt->execute();
    $counts = $stmt->fetch() ?: ['total' => 0, 'unread' => 0];

    json_ok([
        'items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages,
        'all_count' => (int)$counts['total'], 'unread_count' => (int)$counts['unread'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'mark_read') {
    $id = (int)($body['id'] ?? 0);
    $isRead = !empty($body['is_read']) ? 1 : 0;
    if ($id <= 0) { json_error('Не указано обращение', 422); }

    $stmt = $pdo->prepare('UPDATE feedback SET is_read = ? WHERE id = ?');
    $stmt->execute([$isRead, $id]);

    $check = $pdo->prepare('SELECT id FROM feedback WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) { json_error('Обращение не найдено', 404); }

    json_ok(['id' => $id, 'is_read' => (bool)$isRead, 'message' => $isRead ? 'Отмечено прочитанным' : 'Возвращено в непрочитанные']);
}

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare('UPDATE feedback SET is_read = 1 WHERE is_read = 0');
    $stmt->execute();
    json_ok(['affected' => $stmt->rowCount(), 'message' => 'Все обращения отмечены прочитанными']);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { json_error('Не указано обращение', 422); }

    $stmt = $pdo->prepare('DELETE FROM feedback WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) { json_error('Обращение не найдено', 404); }

    json_ok(['message' => 'Обращение удалено']);
}

json_error('Неизвестное действие', 422);
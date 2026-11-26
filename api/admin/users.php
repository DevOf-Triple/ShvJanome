<?php
/**
 * api/admin/users.php — список пользователей и смена роли. Только admin.
 * Защита: нельзя менять роль самому себе и разжаловать последнего админа.
 * Пароли и хэши наружу не отдаются никогда.
 */

require_once __DIR__ . '/../../config/helpers.php';

$admin = api_require_admin();
$adminId = (int)$admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $role = (string)($_GET['role'] ?? 'all');
    $role = in_array($role, ['user', 'admin'], true) ? $role : 'all';
    $perPage = 10;
    $page = max(1, (int)($_GET['page'] ?? 1));

    $where = ['1 = 1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(u.login LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if ($role !== 'all') { $where[] = 'u.role = ?'; $params[] = $role; }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $whereSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT u.id, u.login, u.full_name, u.email, u.phone, u.address, u.avatar_path, u.role, u.created_at,
                (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders_count,
                (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.user_id = u.id AND o.status <> \'cancelled\') AS spent,
                (SELECT COUNT(*) FROM favorites f WHERE f.user_id = u.id) AS favorites_count
         FROM users u WHERE ' . $whereSql . ' ORDER BY u.id DESC LIMIT ? OFFSET ?'
    );
    $pos = 1;
    foreach ($params as $value) { $stmt->bindValue($pos++, $value, PDO::PARAM_STR); }
    $stmt->bindValue($pos++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($pos, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int)$row['id'], 'login' => $row['login'], 'full_name' => $row['full_name'],
            'email' => $row['email'], 'phone' => $row['phone'], 'address' => $row['address'],
            'avatar_path' => $row['avatar_path'], 'role' => $row['role'], 'created_at' => $row['created_at'],
            'orders_count' => (int)$row['orders_count'], 'spent' => (float)$row['spent'],
            'favorites_count' => (int)$row['favorites_count'],
            'is_self' => (int)$row['id'] === $adminId,
        ];
    }

    $stmt = $pdo->prepare('SELECT role, COUNT(*) AS cnt FROM users GROUP BY role');
    $stmt->execute();
    $counts = ['user' => 0, 'admin' => 0];
    foreach ($stmt->fetchAll() as $row) { $counts[$row['role']] = (int)$row['cnt']; }

    json_ok([
        'items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages,
        'role_counts' => $counts, 'self_id' => $adminId,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();
$body = read_json_body();
if ((string)($body['action'] ?? '') !== 'set_role') { json_error('Неизвестное действие', 422); }

$id = (int)($body['id'] ?? 0);
$role = (string)($body['role'] ?? '');
if ($id <= 0) { json_error('Не указан пользователь', 422); }
if (!in_array($role, ['user', 'admin'], true)) { json_error('Роль может быть только user или admin', 422); }

// Себе роль менять нельзя — иначе можно случайно закрыть себе админку
if ($id === $adminId) {
    json_error('Нельзя менять роль самому себе. Попросите другого администратора.', 403);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id, login, role FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) { $pdo->rollBack(); json_error('Пользователь не найден', 404); }

    if ($target['role'] === $role) { $pdo->rollBack(); json_error('У пользователя уже эта роль', 409); }

    // Последнего администратора разжаловать нельзя
    if ($target['role'] === 'admin' && $role === 'user') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = \'admin\'');
        $stmt->execute();
        if ((int)$stmt->fetchColumn() <= 1) {
            $pdo->rollBack();
            json_error('Это последний администратор — сначала назначьте другого.', 409);
        }
    }

    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
    $stmt->execute([$role, $id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}

json_ok([
    'id' => $id, 'role' => $role,
    'message' => 'Пользователь @' . $target['login'] . ' теперь ' . ($role === 'admin' ? 'администратор' : 'покупатель'),
]);
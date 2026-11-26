<?php
/**
 * api/create_order.php — POST: оформление заказа.
 * Тело JSON: {delivery, payment, address, phone, comment, save_profile}.
 * Всё в одной транзакции: orders + order_items, списание stock, очистка корзины.
 * Сумма и цены берутся ТОЛЬКО из БД, клиентским цифрам не доверяем.
 * Данные карты-заглушки сюда не приходят вообще.
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

// Адрес пункта самовывоза — совпадает с контактами магазина
$pickupAddress = 'Самовывоз: Москва, ул. Швейная, 12';

$delivery = (string)($body['delivery'] ?? '');
$payment = (string)($body['payment'] ?? '');
$addressRaw = trim((string)($body['address'] ?? ''));
$phoneRaw = trim((string)($body['phone'] ?? ''));
$comment = trim((string)($body['comment'] ?? ''));
$saveProfile = !empty($body['save_profile']);

/* ---------- Валидация на сервере ---------- */
$errors = [];

if (!in_array($delivery, ['courier', 'pickup'], true)) {
    $errors['delivery'] = 'Выберите способ получения';
}
if (!in_array($payment, ['on_delivery', 'card'], true)) {
    $errors['payment'] = 'Выберите способ оплаты';
}

// Телефон: нормализуем к +7XXXXXXXXXX, допускаем 10–15 цифр
$digits = (string)preg_replace('/\D+/', '', $phoneRaw);
if (strlen($digits) === 11 && $digits[0] === '8') {
    $digits = '7' . substr($digits, 1);
} elseif (strlen($digits) === 10) {
    $digits = '7' . $digits;
}
if ($digits === '') {
    $errors['phone'] = 'Укажите телефон для связи';
} elseif (strlen($digits) < 10 || strlen($digits) > 15) {
    $errors['phone'] = 'Телефон: от 10 до 15 цифр';
}
$phone = '+' . $digits;

// Адрес обязателен только для курьера
$address = $pickupAddress;
if ($delivery === 'courier') {
    $address = trim((string)preg_replace('/\s+/u', ' ', $addressRaw));
    if (mb_strlen($address) < 5) {
        $errors['address'] = 'Адрес доставки: минимум 5 символов';
    } elseif (mb_strlen($address) > 255) {
        $errors['address'] = 'Адрес доставки: до 255 символов';
    }
}

if (mb_strlen($comment) > 1000) {
    $errors['comment'] = 'Комментарий: до 1000 символов';
}

if ($errors) {
    json_error('Проверьте поля формы', 422, ['errors' => $errors]);
}

/* ---------- Транзакция оформления ---------- */
$items = [];
$total = 0.0;

$pdo->beginTransaction();
try {
    // Блокируем строки корзины и товаров в стабильном порядке — без дедлоков
    $stmt = $pdo->prepare(
        'SELECT ci.product_id, ci.quantity, p.name, p.price, p.stock, p.is_active
         FROM cart_items ci
         JOIN products p ON p.id = ci.product_id
         WHERE ci.user_id = ?
         ORDER BY ci.product_id ASC
         FOR UPDATE'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $pdo->rollBack();
        json_error('Корзина пуста — оформлять нечего', 409);
    }

    // Проверяем каждую позицию: активность и остаток
    $problems = [];
    foreach ($rows as $row) {
        $qty = (int)$row['quantity'];
        $stock = (int)$row['stock'];

        if ((int)$row['is_active'] !== 1) {
            $problems[] = $row['name'] . ' — снят с продажи';
            continue;
        }
        if ($stock <= 0) {
            $problems[] = $row['name'] . ' — нет в наличии';
            continue;
        }
        if ($qty > $stock) {
            $problems[] = $row['name'] . ' — доступно только ' . $stock . ' шт.';
            continue;
        }
        $total += (float)$row['price'] * $qty;
    }

    if ($problems) {
        $pdo->rollBack();
        json_error('Корзина требует внимания — вернитесь и исправьте позиции', 409, ['problems' => $problems]);
    }

    // Номер заказа: JN-ГГГГММДД-NNNN, уникальность проверяем в цикле
    $prefix = 'JN-' . date('Ymd') . '-';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_number LIKE ?');
    $stmt->execute([$prefix . '%']);
    $seq = (int)$stmt->fetchColumn() + 1;

    $check = $pdo->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
    $orderNumber = null;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            $orderNumber = $candidate;
            break;
        }
        $seq++;
    }

    if ($orderNumber === null) {
        $pdo->rollBack();
        json_error('Не удалось сформировать номер заказа, повторите попытку', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, order_number, total, status, delivery, payment, address, phone, comment)
         VALUES (?, ?, ?, \'new\', ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $orderNumber,
        $total,
        $delivery,
        $payment,
        $address,
        $phone,
        $comment !== '' ? $comment : null,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Снимок позиций и списание остатка с защитой от ухода в минус
    $insItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)');
    $decStock = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');

    foreach ($rows as $row) {
        $pid = (int)$row['product_id'];
        $qty = (int)$row['quantity'];
        $price = (float)$row['price'];

        $insItem->execute([$orderId, $pid, $row['name'], $price, $qty]);

        $decStock->execute([$qty, $pid, $qty]);
        if ($decStock->rowCount() !== 1) {
            $pdo->rollBack();
            json_error('Остаток изменился во время оформления — обновите корзину', 409);
        }

        $items[] = [
            'product_id' => $pid,
            'name' => $row['name'],
            'price' => $price,
            'quantity' => $qty,
            'line_total' => $price * $qty,
        ];
    }

    // Корзина отработала — чистим
    $stmt = $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?');
    $stmt->execute([$userId]);

    // По желанию сохраняем контакты в профиль (адрес — только курьерский)
    if ($saveProfile) {
        if ($delivery === 'courier') {
            $stmt = $pdo->prepare('UPDATE users SET phone = ?, address = ? WHERE id = ?');
            $stmt->execute([$phone, $address, $userId]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?');
            $stmt->execute([$phone, $userId]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        json_error('Номер заказа уже занят, повторите попытку', 409);
    }
    throw $e;
}

$stmt = $pdo->prepare(
    'SELECT id, order_number, total, status, delivery, payment, address, phone, comment, created_at
     FROM orders WHERE id = ? LIMIT 1'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

json_ok([
    'order' => [
        'id' => (int)$order['id'],
        'order_number' => $order['order_number'],
        'total' => (float)$order['total'],
        'status' => $order['status'],
        'delivery' => $order['delivery'],
        'payment' => $order['payment'],
        'address' => $order['address'],
        'phone' => $order['phone'],
        'comment' => $order['comment'],
        'created_at' => $order['created_at'],
    ],
    'items' => $items,
    'cart_count' => 0,
]);
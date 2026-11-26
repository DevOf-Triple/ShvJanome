<?php
/**
 * pages/checkout.php — оформление заказа «Стежок».
 * Шаги: получение → оплата → контакты. Смета липкая, отправка через fetch.
 * После оформления — панель заказа с возможностью отмены (статус new).
 * Карта — заглушка: данные карты не отправляются на сервер вообще.
 */

require_once __DIR__ . '/../config/helpers.php';

// Оформление только для авторизованных: гостя уводим на вход с возвратом
require_login();

$user = current_user();
$userId = (int)$user['id'];
$pickupAddress = 'Самовывоз: Москва, ул. Швейная, 12';

// Профиль для префилла
$stmt = $pdo->prepare('SELECT full_name, email, phone, address FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch() ?: ['full_name' => $user['full_name'], 'email' => '', 'phone' => '', 'address' => ''];

// Позиции корзины
$stmt = $pdo->prepare(
    'SELECT ci.product_id, ci.quantity, p.name, p.model, p.slug, p.price, p.old_price,
            p.stock, p.image_path, p.is_active, b.name AS brand_name
     FROM cart_items ci
     JOIN products p ON p.id = ci.product_id
     JOIN brands b ON b.id = p.brand_id
     WHERE ci.user_id = ?
     ORDER BY ci.id ASC'
);
$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

$items = [];
$problems = [];
$positions = 0;
$units = 0;
$subtotal = 0.0;
$savings = 0.0;

foreach ($rows as $row) {
    $price = (float)$row['price'];
    $oldPrice = $row['old_price'] !== null ? (float)$row['old_price'] : null;
    $qty = (int)$row['quantity'];
    $stock = (int)$row['stock'];
    $isActive = (int)$row['is_active'] === 1;

    if (!$isActive) {
        $problems[] = $row['name'] . ' — снят с продажи';
    } elseif ($stock <= 0) {
        $problems[] = $row['name'] . ' — нет в наличии';
    } elseif ($qty > $stock) {
        $problems[] = $row['name'] . ' — доступно только ' . $stock . ' шт.';
    }

    $items[] = [
        'product_id' => (int)$row['product_id'],
        'name' => $row['name'],
        'model' => $row['model'],
        'slug' => $row['slug'],
        'brand_name' => $row['brand_name'],
        'image_path' => $row['image_path'],
        'price' => $price,
        'old_price' => $oldPrice,
        'quantity' => $qty,
        'line_total' => $price * $qty,
    ];

    $positions++;
    $units += $qty;
    $subtotal += $price * $qty;
    if ($oldPrice !== null && $oldPrice > $price) {
        $savings += ($oldPrice - $price) * $qty;
    }
}

// Если корзина пуста — показываем последний заказ, пока его можно отменить
$lastOrder = null;
$lastOrderItems = [];
if (!$items) {
    $stmt = $pdo->prepare(
        'SELECT id, order_number, total, status, delivery, payment, address, phone, comment, created_at
         FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $order = $stmt->fetch();

    if ($order && $order['status'] === 'new') {
        $lastOrder = [
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
        ];

        $stmt = $pdo->prepare('SELECT product_id, name, price, quantity FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $stmt->execute([$lastOrder['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $lastOrderItems[] = [
                'product_id' => (int)$row['product_id'],
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'quantity' => (int)$row['quantity'],
                'line_total' => (float)$row['price'] * (int)$row['quantity'],
            ];
        }
    }
}

$mode = $items ? 'form' : ($lastOrder ? 'order' : 'empty');

$fallbackImg = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/></svg>'
);

$checkoutConfig = [
    'mode' => $mode,
    'cartUrl' => 'cart.php',
    'catalogUrl' => 'catalog.php',
    'cabinetUrl' => 'cabinet.php',
    'pickupAddress' => $pickupAddress,
    'subtotal' => $subtotal,
    'hasProblems' => (bool)$problems,
    'order' => $lastOrder,
    'orderItems' => $lastOrderItems,
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Оформление заказа', [
    'description' => 'Оформление заказа в магазине Janome: доставка курьером или самовывоз, оплата при получении или картой.',
    'body_class' => 'page-checkout',
]);
?>

<style>
/* ============================================================
   Оформление «Стежок»: шаги-стежки, выбор-лоскуты,
   карта-заглушка, липкая смета, панель заказа с отменой.
   ============================================================ */

.thread-progress {
    position: fixed; top: 0; left: 0; z-index: 60; height: 3px; width: 100%;
    transform: scaleX(0); transform-origin: left;
    background: linear-gradient(90deg, var(--wine-900), var(--wine-500) 45%, var(--wine-300));
}

.crumbs { padding: 18px 0 6px; }
.crumbs__list { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; list-style: none; margin: 0; padding: 0; font-size: 12.5px; }
.crumbs__list a { color: var(--muted); text-decoration: none; transition: color .2s; }
.crumbs__list a:hover { color: var(--wine-700); }
.crumbs__sep { color: var(--line); }
.crumbs__current { color: var(--wine-700); font-weight: 600; }

/* ---------- Hero ---------- */
.co-hero { padding: 34px 0 26px; position: relative; overflow: hidden;
    background: linear-gradient(116deg, rgba(176, 27, 46, .06) 0%, rgba(212, 42, 63, .03) 40%, rgba(255, 255, 255, 0) 72%); }
.co-hero__row { display: flex; align-items: flex-end; justify-content: space-between; gap: 26px; flex-wrap: wrap; }
.co-hero__lead { color: var(--muted); max-width: 58ch; margin-bottom: 0; }
.co-pills { display: flex; gap: 10px; flex-wrap: wrap; }
.co-pill { padding: 9px 14px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 600 12px/1.25 var(--font-sans); color: var(--muted); }
.co-pill strong { display: block; font-family: var(--font-serif); font-size: 19px; color: var(--wine-700); }
.co-hero .tape { margin-top: 24px; }

/* ---------- Раскладка ---------- */
.co-layout { display: grid; grid-template-columns: 1fr 356px; gap: 28px; align-items: start; padding: 26px 0 60px; }
.co-steps { display: flex; flex-direction: column; }
.step-link { height: 20px; margin-left: 40px; border-left: 2px dashed var(--line); }

/* ---------- Шаг ---------- */
.step { padding: 20px 20px 22px; }
.step__head { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px; }
.step__num {
    position: relative; width: 42px; height: 42px; flex: none; border-radius: 50%;
    display: grid; place-items: center; background: var(--white);
    border: 2px solid var(--line); color: var(--wine-700);
    font: 700 16px/1 var(--font-serif);
    transition: background-color .3s, border-color .3s, color .3s, transform .3s;
}
.step__num::before { content: ""; position: absolute; inset: 3px; border: 1px dashed rgba(176, 27, 46, .28); border-radius: 50%; }
.step.is-done .step__num { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); transform: scale(1.05); }
.step.is-done .step__num::before { border-color: rgba(255, 255, 255, .5); }
.step__title { font: 700 18px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 3px; }
.step__hint { font-size: 12.5px; color: var(--muted); margin: 0; }

/* ---------- Выбор-лоскут ---------- */
.choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: stretch; }
.choice { position: relative; display: block; cursor: pointer; }
.choice input { position: absolute; opacity: 0; width: 0; height: 0; }
.choice__box {
    display: flex; flex-direction: column;
    position: relative; height: 100%; padding: 16px 16px 16px 18px;
    background: var(--white); border: 1px solid var(--line); border-radius: var(--radius);
    transition: border-color .25s, background-color .25s, transform .2s, box-shadow .25s;
}
.choice__box::before { content: ""; position: absolute; inset: 5px; border: 1px dashed var(--line); border-radius: var(--radius-sm); pointer-events: none; transition: border-color .25s; }
.choice:hover .choice__box { border-color: var(--wine-300); transform: translateY(-3px); box-shadow: var(--shadow-sm); }
.choice input:checked + .choice__box { border-color: var(--wine-500); background: var(--wine-050); box-shadow: var(--shadow-btn); }
.choice input:checked + .choice__box::before { border-color: rgba(176, 27, 46, .4); }
.choice input:focus-visible + .choice__box { outline: 2px solid var(--wine-300); outline-offset: 2px; }
.choice__icon { color: var(--wine-500); margin-bottom: 9px; display: block; flex: none; transition: transform .3s; }
.choice input:checked + .choice__box .choice__icon { transform: scale(1.1) rotate(-5deg); }
.choice__name { display: block; padding-right: 34px; font: 700 15px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 4px; }
.choice__text { display: block; font-size: 12.5px; color: var(--muted); line-height: 1.5; }
.choice__mark {
    position: absolute; top: 12px; right: 12px; width: 22px; height: 22px; border-radius: 50%;
    border: 1px solid var(--line); background: var(--white); display: grid; place-items: center;
    color: transparent; transition: background-color .25s, border-color .25s, color .25s, transform .25s;
}
.choice input:checked + .choice__box .choice__mark { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); transform: scale(1.08); }

/* ---------- Раскрывающиеся блоки ---------- */
.reveal-block { display: none; margin-top: 16px; }
.reveal-block.is-open { display: block; animation: block-in .32s cubic-bezier(.22,.61,.36,1) both; }
@keyframes block-in { from { opacity: 0; transform: translateY(-8px); } }
.pickup-card { padding: 15px 17px; background: var(--linen); border: 1px dashed var(--line); border-radius: var(--radius); }
.pickup-card__title { font: 700 14px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 6px; }
.pickup-card__row { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; color: var(--muted); margin-top: 6px; }
.pickup-card__row svg { color: var(--wine-500); flex: none; margin-top: 2px; }

.card-demo { padding: 17px; background: var(--wine-900); border-radius: var(--radius); color: rgba(255, 255, 255, .85); position: relative; overflow: hidden; }
.card-demo::before { content: ""; position: absolute; inset: 6px; border: 1px dashed rgba(255, 255, 255, .22); border-radius: var(--radius-sm); pointer-events: none; }
.card-demo__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.card-demo__chip { width: 34px; height: 25px; border-radius: 4px; background: linear-gradient(135deg, #D42A3F, #7A1220); border: 1px solid rgba(255, 255, 255, .3); }
.card-demo__label { font: 700 10.5px/1.4 var(--font-sans); letter-spacing: .14em; text-transform: uppercase; color: rgba(255, 255, 255, .6); }
.card-demo__grid { display: grid; grid-template-columns: 1fr 90px 80px; gap: 12px; }
.card-demo .field__label { color: rgba(255, 255, 255, .7); }
.card-demo .input { background: rgba(255, 255, 255, .08); border-color: rgba(255, 255, 255, .25); color: var(--white); letter-spacing: .06em; }
.card-demo .input::placeholder { color: rgba(255, 255, 255, .35); }
.card-demo .input:focus { border-color: var(--wine-300); box-shadow: 0 0 0 3px rgba(212, 42, 63, .25); }
.card-demo .field__error { color: #FFB4B4; }
.card-demo__note { margin: 12px 0 0; font-size: 11.5px; color: rgba(255, 255, 255, .55); }

/* ---------- Поля ---------- */
.co-profile { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; padding: 11px 14px; background: var(--linen); border: 1px dashed var(--line); border-radius: var(--radius); font-size: 13px; color: var(--muted); }
.co-profile b { color: var(--ink); }
.co-counter { font-size: 11.5px; color: var(--muted); text-align: right; }
.co-agree { margin-top: 14px; font-size: 12px; color: var(--muted); }
.co-submit-row { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
.co-submit-row .btn--primary { flex: 1; min-width: 220px; }

/* ---------- Смета ---------- */
.co-summary { position: sticky; top: calc(var(--header-h) + 20px); }
.co-summary__inner { padding: 22px 20px; }
.co-summary__title { font: 700 19px/1.2 var(--font-serif); color: var(--wine-900); margin: 0 0 14px; }
.co-items { display: grid; gap: 12px; max-height: 300px; overflow-y: auto; padding-right: 6px; margin-bottom: 16px;
    scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen); }
.co-items::-webkit-scrollbar { width: 8px; }
.co-items::-webkit-scrollbar-track { background: var(--linen); border-radius: 4px; }
.co-items::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 4px; }
.co-items::-webkit-scrollbar-thumb:hover { background: var(--wine-300); }
.co-item { display: grid; grid-template-columns: 52px 1fr auto; gap: 10px; align-items: center; }
.co-item__media { aspect-ratio: 1; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); }
.co-item__media img { width: 100%; height: 100%; object-fit: cover; }
.co-item__name { font: 600 12.5px/1.35 var(--font-sans); color: var(--ink); }
.co-item__meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
.co-item__sum { font: 700 13.5px/1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.co-row { display: flex; align-items: baseline; gap: 8px; margin-bottom: 9px; font-size: 13.5px; }
.co-row span { color: var(--muted); flex: none; }
.co-row i { flex: 1; border-bottom: 1px dotted var(--line); transform: translateY(-3px); }
.co-row b { font-weight: 600; color: var(--ink); flex: none; text-align: right; }
.co-row--save b { color: var(--success); }
.co-total { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 15px 0 6px; }
.co-total span { font: 600 13px/1 var(--font-sans); color: var(--muted); }
.co-total strong { font: 700 30px/1.1 var(--font-serif); color: var(--wine-700); }
.co-note { font-size: 11.5px; color: var(--muted); margin: 0 0 15px; }
.co-summary__tape { margin-top: 15px; }
.co-trust { margin-top: 15px; padding-top: 13px; border-top: 2px dashed var(--line); display: grid; gap: 9px; }
.co-trust div { display: flex; align-items: flex-start; gap: 9px; font-size: 12px; color: var(--muted); }
.co-trust svg { color: var(--wine-500); flex: none; margin-top: 1px; }

/* ---------- Панель заказа ---------- */
.order-wrap { padding: 26px 0 60px; }
.order-panel { padding: 30px 28px; animation: panel-in .5s cubic-bezier(.22,.61,.36,1) both; }
@keyframes panel-in { from { opacity: 0; transform: translateY(20px); } }
.order-top { display: flex; align-items: flex-start; gap: 22px; flex-wrap: wrap; margin-bottom: 22px; }
.order-seal { width: 96px; height: 96px; flex: none; }
.order-seal__ring { fill: none; stroke: var(--line); stroke-width: 2; stroke-dasharray: 6 6; }
.order-seal__circle { fill: var(--wine-050); stroke: var(--wine-500); stroke-width: 2.5; }
.order-seal__check { fill: none; stroke: var(--wine-500); stroke-width: 4; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 60; stroke-dashoffset: 60; animation: seal-draw .7s ease .25s forwards; }
.order-seal--cancel .order-seal__circle { fill: var(--danger-bg); stroke: var(--danger); }
.order-seal--cancel .order-seal__check { stroke: var(--danger); }
@keyframes seal-draw { to { stroke-dashoffset: 0; } }
.order-head { flex: 1; min-width: 240px; }
.order-number { font: 700 clamp(22px, 2.6vw, 30px)/1.2 var(--font-serif); color: var(--wine-900); margin: 6px 0 10px; }
.order-sub { color: var(--muted); font-size: 14px; margin: 0; }
.order-meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 22px; }
.order-meta__cell { padding: 13px 15px; background: var(--linen); border: 1px dashed var(--line); border-radius: var(--radius); }
.order-meta__label { font: 700 10.5px/1.4 var(--font-sans); letter-spacing: .12em; text-transform: uppercase; color: var(--muted); display: block; margin-bottom: 5px; }
.order-meta__value { font: 600 14px/1.4 var(--font-sans); color: var(--ink); word-break: break-word; }
.order-meta__value--big { font: 700 21px/1.2 var(--font-serif); color: var(--wine-700); }
.order-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 22px; }
.order-comment { margin-top: 16px; padding: 13px 15px; background: var(--white); border-left: 3px solid var(--wine-500); border-radius: var(--radius-sm); font-size: 13.5px; color: var(--muted); }

/* ---------- Липкая мобильная панель ---------- */
.mco {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 70; display: none;
    align-items: center; gap: 12px; padding: 11px 16px;
    background: rgba(255, 255, 255, .98); border-top: 2px dashed var(--line);
    box-shadow: 0 -8px 24px rgba(78, 11, 21, .12);
    transform: translateY(110%); transition: transform .3s cubic-bezier(.22,.61,.36,1);
}
.mco.is-visible { transform: none; }
.mco__sum { display: flex; flex-direction: column; line-height: 1.15; }
.mco__sum small { font-size: 10.5px; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; }
.mco__sum strong { font: 700 20px/1.1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.mco__btn { flex: 1; }

/* ---------- Подтверждение отмены ---------- */
.co-confirm { position: fixed; inset: 0; z-index: 210; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(42, 16, 19, .5); }
.co-confirm.is-open { display: flex; animation: cf-in .22s ease; }
@keyframes cf-in { from { opacity: 0; } }
.co-confirm__box { width: min(440px, 100%); padding: 26px 24px; text-align: center; animation: cf-pop .3s cubic-bezier(.22,.61,.36,1); }
@keyframes cf-pop { from { transform: translateY(14px) scale(.97); opacity: 0; } }
.co-confirm__title { font: 700 21px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 10px; }
.co-confirm__text { color: var(--muted); font-size: 14px; margin-bottom: 20px; }
.co-confirm__actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

/* ---------- Адаптив ---------- */
@media (max-width: 1080px) {
    .co-layout { grid-template-columns: 1fr; }
    .co-summary { position: static; }
    .order-meta { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 860px) {
    .mco { display: flex; }
    .page-checkout .site-footer { padding-bottom: 74px; }
}
@media (max-width: 620px) {
    .choice-grid { grid-template-columns: 1fr; }
    .card-demo__grid { grid-template-columns: 1fr 1fr; }
    .card-demo__grid .field:first-child { grid-column: 1 / -1; }
    .order-meta { grid-template-columns: 1fr; }
    .order-panel { padding: 22px 18px; }
    .co-submit-row .btn { width: 100%; }
    .step-link { margin-left: 32px; }
}
@media (prefers-reduced-motion: reduce) {
    .reveal-block.is-open, .order-panel, .co-confirm__box { animation: none; }
    .order-seal__check { stroke-dashoffset: 0; animation: none; }
    .choice__box, .choice__icon, .step__num { transition: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<nav class="crumbs" aria-label="Навигация по разделам">
    <div class="container">
        <ol class="crumbs__list">
            <li><a href="index.php">Главная</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li><a href="cart.php">Корзина</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li class="crumbs__current" aria-current="page">Оформление</li>
        </ol>
    </div>
</nav>

<section class="co-hero">
    <div class="container">
        <div class="co-hero__row">
            <div class="reveal">
                <span class="label"><?= $mode === 'order' ? 'Заказ' : 'Оформление' ?></span>
                <h1 id="co-title"><?= $mode === 'order' ? 'Заказ принят' : 'Последний стежок' ?></h1>
                <p class="co-hero__lead" id="co-lead">
                    <?php if ($mode === 'order'): ?>
                        Заказ зафиксирован, остатки зарезервированы. Пока статус «новый» — его можно отменить, товары вернутся на склад.
                    <?php elseif ($mode === 'empty'): ?>
                        Оформлять пока нечего: корзина пуста. Выберите машинку в каталоге — и вернитесь сюда.
                    <?php else: ?>
                        Проверьте состав, выберите получение и оплату. Сумма считается по ценам товаров, остатки резервируются в момент оформления.
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($mode === 'form'): ?>
                <div class="co-pills reveal" id="co-pills">
                    <span class="co-pill"><strong><?= $positions ?></strong> позиций</span>
                    <span class="co-pill"><strong><?= $units ?></strong> штук</span>
                    <span class="co-pill"><strong><?= e(format_price($subtotal)) ?></strong> сумма</span>
                </div>
            <?php endif; ?>
        </div>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<?php if ($mode === 'empty'): ?>
    <section class="section" style="padding-top: 22px;">
        <div class="container container--narrow">
            <div class="card card--static empty-state reveal">
                <h2 class="empty-state__title">Корзина пуста</h2>
                <p class="empty-state__text">Заказ собирается из корзины. Откройте каталог, отметьте машинки сердечком и добавьте нужные позиции.</p>
                <div class="cart-empty__actions" style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                    <a class="btn btn--primary" href="catalog.php">Открыть каталог</a>
                    <a class="btn btn--ghost" href="cabinet.php">Мои заказы</a>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($mode === 'form'): ?>
<div id="checkout-flow">
    <div class="container">
        <?php if ($problems): ?>
            <div class="notice notice--warning reveal" style="margin-top: 22px;">
                <b>Часть позиций требует внимания:</b>
                <ul style="margin: 8px 0 10px; padding-left: 20px;">
                    <?php foreach ($problems as $problem): ?>
                        <li><?= e($problem) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a class="link" href="cart.php">Вернуться в корзину и исправить</a>
            </div>
        <?php endif; ?>

        <div class="co-layout">
            <!-- Шаги -->
            <form class="co-steps" id="checkout-form" novalidate>
                <!-- Шаг 1 -->
                <div class="card card--static step is-done reveal" id="step-1">
                    <div class="step__head">
                        <span class="step__num" aria-hidden="true">1</span>
                        <div>
                            <h2 class="step__title">Как получить заказ</h2>
                            <p class="step__hint">Курьер привезёт по адресу, самовывоз — из пункта на Швейной.</p>
                        </div>
                    </div>
                    <div class="choice-grid">
                        <label class="choice">
                            <input type="radio" name="delivery" value="courier" checked>
                            <span class="choice__box">
                                <span class="choice__mark" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <svg class="choice__icon" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                                <span class="choice__name">Курьер</span>
                                <span class="choice__text">Доставка по указанному адресу. Курьер свяжется по телефону перед выездом.</span>
                            </span>
                        </label>
                        <label class="choice">
                            <input type="radio" name="delivery" value="pickup">
                            <span class="choice__box">
                                <span class="choice__mark" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <svg class="choice__icon" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M4 9l1.6-4h12.8L20 9v11H4z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M4 9h16M9.5 20v-6h5v6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                                <span class="choice__name">Самовывоз</span>
                                <span class="choice__text">Заберёте сами из пункта выдачи. Адрес вводить не нужно.</span>
                            </span>
                        </label>
                    </div>

                    <div class="reveal-block" id="pickup-block">
                        <div class="pickup-card">
                            <div class="pickup-card__title">Пункт выдачи Janome</div>
                            <div class="pickup-card__row">
                                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="10" r="2.6" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                                <span>Москва, ул. Швейная, 12</span>
                            </div>
                            <div class="pickup-card__row">
                                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span>Пн–Сб: 10:00–20:00, вс — выходной</span>
                            </div>
                            <div class="pickup-card__row">
                                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8.5 9h7M8.5 13h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                <span>Заказ ждёт на пункте 5 дней после подтверждения</span>
                            </div>
                        </div>
                    </div>
                    <span class="field__error" data-error-for="delivery" hidden></span>
                </div>

                <div class="step-link" aria-hidden="true"></div>

                <!-- Шаг 2 -->
                <div class="card card--static step is-done reveal" id="step-2">
                    <div class="step__head">
                        <span class="step__num" aria-hidden="true">2</span>
                        <div>
                            <h2 class="step__title">Как оплатить</h2>
                            <p class="step__hint">Карта работает в демо-режиме: платёж не проводится.</p>
                        </div>
                    </div>
                    <div class="choice-grid">
                        <label class="choice">
                            <input type="radio" name="payment" value="on_delivery" checked>
                            <span class="choice__box">
                                <span class="choice__mark" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <svg class="choice__icon" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M3 6.5h18v11H3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M6 9.5v5M18 9.5v5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                <span class="choice__name">При получении</span>
                                <span class="choice__text">Оплата курьеру или на пункте выдачи: наличными или картой на месте.</span>
                            </span>
                        </label>
                        <label class="choice">
                            <input type="radio" name="payment" value="card">
                            <span class="choice__box">
                                <span class="choice__mark" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <svg class="choice__icon" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18M6.5 14.5h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                <span class="choice__name">Картой онлайн</span>
                                <span class="choice__text">Демо-форма: проверяем формат номера, но данные карты никуда не уходят.</span>
                            </span>
                        </label>
                    </div>

                    <div class="reveal-block" id="card-block">
                        <div class="card-demo">
                            <div class="card-demo__head">
                                <span class="card-demo__label">Демо-оплата · заглушка</span>
                                <span class="card-demo__chip" aria-hidden="true"></span>
                            </div>
                            <div class="card-demo__grid">
                                <div class="field" style="margin-bottom:0;">
                                    <label class="field__label" for="card-number">Номер карты</label>
                                    <input class="input" id="card-number" type="text" inputmode="numeric" autocomplete="off"
                                           placeholder="0000 0000 0000 0000" maxlength="19">
                                    <span class="field__error" data-card-error="number" hidden></span>
                                </div>
                                <div class="field" style="margin-bottom:0;">
                                    <label class="field__label" for="card-expiry">Срок</label>
                                    <input class="input" id="card-expiry" type="text" inputmode="numeric" autocomplete="off"
                                           placeholder="ММ/ГГ" maxlength="5">
                                    <span class="field__error" data-card-error="expiry" hidden></span>
                                </div>
                                <div class="field" style="margin-bottom:0;">
                                    <label class="field__label" for="card-cvc">CVC</label>
                                    <input class="input" id="card-cvc" type="password" inputmode="numeric" autocomplete="off"
                                           placeholder="•••" maxlength="3">
                                    <span class="field__error" data-card-error="cvc" hidden></span>
                                </div>
                            </div>
                            <p class="card-demo__note">Учебный проект: платёжный шлюз не подключён. Поля проверяются только в браузере и не отправляются на сервер — в заказ уходит лишь отметка «картой».</p>
                        </div>
                    </div>
                    <span class="field__error" data-error-for="payment" hidden></span>
                </div>

                <div class="step-link" aria-hidden="true"></div>

                <!-- Шаг 3 -->
                <div class="card card--static step reveal" id="step-3">
                    <div class="step__head">
                        <span class="step__num" aria-hidden="true">3</span>
                        <div>
                            <h2 class="step__title">Куда и кому</h2>
                            <p class="step__hint">Телефон обязателен: по нему подтверждают заказ.</p>
                        </div>
                    </div>

                    <div class="co-profile">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="8.5" r="3.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M5 20c1.2-3.6 4-5.5 7-5.5s5.8 1.9 7 5.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        <span>Получатель: <b><?= e($profile['full_name']) ?></b><?= $profile['email'] !== '' ? ' · ' . e($profile['email']) : '' ?></span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="phone">Телефон</label>
                        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                               placeholder="+7 (___) ___-__-__" maxlength="30"
                               value="<?= e((string)$profile['phone']) ?>" required>
                        <span class="field__hint">Формат: +7 (999) 123-45-67</span>
                        <span class="field__error" data-error-for="phone" hidden></span>
                    </div>

                    <div class="field" id="address-field">
                        <label class="field__label" for="address">Адрес доставки</label>
                        <textarea class="textarea" id="address" name="address" maxlength="255" rows="2"
                                  placeholder="Город, улица, дом, квартира" style="min-height: 84px;"><?= e((string)$profile['address']) ?></textarea>
                        <span class="field__hint">Курьеру нужен полный адрес с городом</span>
                        <span class="field__error" data-error-for="address" hidden></span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="comment">Комментарий к заказу</label>
                        <textarea class="textarea" id="comment" name="comment" maxlength="1000" rows="3"
                                  placeholder="Код домофона, удобное время, пожелания к упаковке"></textarea>
                        <span class="co-counter"><span id="comment-count">0</span> / 1000</span>
                        <span class="field__error" data-error-for="comment" hidden></span>
                    </div>

                    <label class="checkbox">
                        <input type="checkbox" id="save-profile" checked>
                        <span>Сохранить телефон и адрес в профиль</span>
                    </label>

                    <p class="co-agree">Нажимая «Подтвердить заказ», вы соглашаетесь с условиями учебного магазина: платёж не проводится, заказ резервирует остаток на складе.</p>

                    <div class="co-submit-row">
                        <button class="btn btn--primary btn--lg" id="submit-main" type="submit"<?= $problems ? ' disabled' : '' ?>>
                            Подтвердить заказ
                        </button>
                        <a class="btn btn--ghost btn--lg" href="cart.php">Вернуться в корзину</a>
                    </div>
                </div>
            </form>

            <!-- Смета -->
            <aside class="co-summary" id="co-summary" aria-label="Состав заказа">
                <div class="card card--static co-summary__inner">
                    <h2 class="co-summary__title">Состав заказа</h2>

                    <div class="co-items">
                        <?php foreach ($items as $item): ?>
                            <div class="co-item">
                                <span class="co-item__media">
                                    <img src="../<?= e($item['image_path']) ?>" alt="<?= e($item['name']) ?>" loading="lazy" decoding="async"
                                         onerror="this.onerror=null;this.src='<?= e($fallbackImg) ?>';">
                                </span>
                                <span>
                                    <span class="co-item__name"><?= e($item['name']) ?></span>
                                    <span class="co-item__meta"><?= (int)$item['quantity'] ?> × <?= e(format_price($item['price'])) ?></span>
                                </span>
                                <span class="co-item__sum"><?= e(format_price($item['line_total'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="co-row"><span>Позиций</span><i></i><b><?= $positions ?></b></div>
                    <div class="co-row"><span>Товаров</span><i></i><b><?= $units ?> шт.</b></div>
                    <div class="co-row"><span>Сумма</span><i></i><b><?= e(format_price($subtotal)) ?></b></div>
                    <?php if ($savings > 0): ?>
                        <div class="co-row co-row--save"><span>Выгода</span><i></i><b>−<?= e(format_price($savings)) ?></b></div>
                    <?php endif; ?>
                    <div class="co-row"><span>Получение</span><i></i><b id="live-delivery">Курьер</b></div>
                    <div class="co-row"><span>Оплата</span><i></i><b id="live-payment">При получении</b></div>

                    <div class="co-total">
                        <span>Итого</span>
                        <strong id="co-total"><?= e(format_price($subtotal)) ?></strong>
                    </div>
                    <p class="co-note">Сумма — по ценам товаров. Способ доставки и оплаты фиксируются в заказе и подтверждаются менеджером.</p>

                    <button class="btn btn--primary btn--lg btn--block" type="submit" form="checkout-form"<?= $problems ? ' disabled' : '' ?>>
                        Подтвердить заказ
                    </button>

                    <div class="tape co-summary__tape" aria-hidden="true"></div>

                    <div class="co-trust">
                        <div>
                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 11.8l2.2 2.2 4-4.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span>Остаток списывается в транзакции — двойных продаж не будет.</span>
                        </div>
                        <div>
                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 12a8 8 0 1 1 2.4 5.7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M4 18v-5h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span>Пока статус «новый», заказ отменяется в один клик.</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <!-- Липкая панель на мобильных -->
    <div class="mco" id="mco">
        <span class="mco__sum"><small>Итого</small><strong><?= e(format_price($subtotal)) ?></strong></span>
        <button class="btn btn--primary mco__btn" type="submit" form="checkout-form"<?= $problems ? ' disabled' : '' ?>>Подтвердить</button>
    </div>
</div>
<?php endif; ?>

<!-- Панель заказа: рендерится после оформления или для последнего нового заказа -->
<div class="container order-wrap" id="order-wrap" hidden></div>

<!-- Подтверждение отмены -->
<div class="co-confirm" id="cancel-confirm" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
    <div class="card card--static co-confirm__box">
        <h2 class="co-confirm__title" id="cancel-title">Отменить заказ?</h2>
        <p class="co-confirm__text">Заказ перейдёт в статус «отменён», а товары вернутся на склад. Действие нельзя отменить, но позиции можно снова положить в корзину.</p>
        <div class="co-confirm__actions">
            <button class="btn btn--ghost" id="cancel-no" type="button">Оставить заказ</button>
            <button class="btn btn--primary" id="cancel-yes" type="button">Отменить заказ</button>
        </div>
    </div>
</div>

<script id="checkout-config" type="application/json"><?= json_encode($checkoutConfig, $jsonFlags) ?></script>

<script>
// Чекаут: маски, живая смета, отправка заказа, панель заказа и отмена
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('checkout-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var esc = J.escapeHtml;
    var money = J.formatPrice;

    var orderWrap = document.getElementById('order-wrap');
    var flow = document.getElementById('checkout-flow');
    var confirmBox = document.getElementById('cancel-confirm');
    var currentOrder = null;
    var currentItems = [];
    var restoredItems = [];

    var DELIVERY = { courier: 'Курьер', pickup: 'Самовывоз' };
    var PAYMENT = { on_delivery: 'При получении', card: 'Картой (демо)' };
    var STATUS = { new: 'Новый', processing: 'В обработке', shipped: 'Отправлен', done: 'Выполнен', cancelled: 'Отменён' };
    var STATUS_BADGE = { new: 'badge--info', processing: 'badge--warning', shipped: 'badge--info', done: 'badge--success', cancelled: 'badge--danger' };

    /* ---------- Дата из MySQL в читаемый вид ---------- */
    function fmtDate(value) {
        if (!value) { return '—'; }
        var parts = String(value).replace('T', ' ').split(' ');
        var d = (parts[0] || '').split('-');
        var t = (parts[1] || '').slice(0, 5);
        if (d.length !== 3) { return String(value); }
        return d[2] + '.' + d[1] + '.' + d[0] + (t ? ' ' + t : '');
    }

    /* ---------- Панель заказа ---------- */
    function sealSVG(cancelled) {
        return '<svg class="order-seal' + (cancelled ? ' order-seal--cancel' : '') + '" viewBox="0 0 100 100" aria-hidden="true">' +
            '<circle class="order-seal__ring" cx="50" cy="50" r="46"/>' +
            '<circle class="order-seal__circle" cx="50" cy="50" r="34"/>' +
            (cancelled
                ? '<path class="order-seal__check" d="M38 38l24 24M62 38L38 62"/>'
                : '<path class="order-seal__check" d="M35 51l11 11 20-22"/>') +
        '</svg>';
    }

    function renderOrderPanel(order, items, restored) {
        currentOrder = order;
        currentItems = items || [];
        var cancelled = order.status === 'cancelled';

        var rows = currentItems.map(function (it) {
            var line = it.line_total !== undefined ? it.line_total : it.price * it.quantity;
            return '<tr><td>' + esc(it.name) + '</td><td>' + it.quantity + ' шт.</td>' +
                '<td>' + esc(money(it.price)) + '</td><td><b>' + esc(money(line)) + '</b></td></tr>';
        }).join('');

        var actions = '';
        if (!cancelled && order.status === 'new') {
            actions =
                '<button class="btn btn--ghost" type="button" id="cancel-order">Отменить заказ</button>' +
                '<a class="btn btn--primary" href="' + esc(cfg.cabinetUrl) + '">Мои заказы</a>' +
                '<a class="btn btn--ghost" href="' + esc(cfg.catalogUrl) + '">Продолжить подбор</a>';
        } else if (cancelled) {
            actions =
                (restored && restored.length
                    ? '<button class="btn btn--primary" type="button" id="return-to-cart">Вернуть позиции в корзину</button>'
                    : '') +
                '<a class="btn btn--ghost" href="' + esc(cfg.catalogUrl) + '">В каталог</a>' +
                '<a class="btn btn--ghost" href="' + esc(cfg.cabinetUrl) + '">Мои заказы</a>';
        } else {
            actions = '<a class="btn btn--primary" href="' + esc(cfg.cabinetUrl) + '">Мои заказы</a>' +
                '<a class="btn btn--ghost" href="' + esc(cfg.catalogUrl) + '">В каталог</a>';
        }

        orderWrap.innerHTML =
            '<div class="card card--static order-panel">' +
                '<div class="order-top">' +
                    sealSVG(cancelled) +
                    '<div class="order-head">' +
                        '<span class="label">' + (cancelled ? 'Заказ отменён' : 'Заказ оформлен') + '</span>' +
                        '<h2 class="order-number">№ ' + esc(order.order_number) + '</h2>' +
                        '<span class="badge ' + (STATUS_BADGE[order.status] || 'badge--info') + '">' + esc(STATUS[order.status] || order.status) + '</span>' +
                        '<p class="order-sub" style="margin-top:10px;">' +
                            (cancelled
                                ? 'Товары возвращены на склад. Позицию можно снова собрать в корзине.'
                                : 'Остаток зарезервирован. Пока статус «новый», заказ можно отменить самостоятельно.') +
                        '</p>' +
                    '</div>' +
                '</div>' +
                '<div class="order-meta">' +
                    '<div class="order-meta__cell"><span class="order-meta__label">Сумма</span><span class="order-meta__value order-meta__value--big">' + esc(money(order.total)) + '</span></div>' +
                    '<div class="order-meta__cell"><span class="order-meta__label">Получение</span><span class="order-meta__value">' + esc(DELIVERY[order.delivery] || order.delivery) + '</span></div>' +
                    '<div class="order-meta__cell"><span class="order-meta__label">Оплата</span><span class="order-meta__value">' + esc(PAYMENT[order.payment] || order.payment) + '</span></div>' +
                    '<div class="order-meta__cell"><span class="order-meta__label">Адрес</span><span class="order-meta__value">' + esc(order.address || '—') + '</span></div>' +
                    '<div class="order-meta__cell"><span class="order-meta__label">Телефон</span><span class="order-meta__value">' + esc(order.phone || '—') + '</span></div>' +
                    '<div class="order-meta__cell"><span class="order-meta__label">Создан</span><span class="order-meta__value">' + esc(fmtDate(order.created_at)) + '</span></div>' +
                '</div>' +
                (order.comment ? '<div class="order-comment"><b>Комментарий:</b> ' + esc(order.comment) + '</div>' : '') +
                '<div class="table-wrap" style="margin-top:18px;"><table class="table"><thead><tr><th>Товар</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
                '<div class="order-actions">' + actions + '</div>' +
            '</div>';

        orderWrap.hidden = false;

        var cancelBtn = document.getElementById('cancel-order');
        if (cancelBtn) { cancelBtn.addEventListener('click', openCancel); }

        var returnBtn = document.getElementById('return-to-cart');
        if (returnBtn) { returnBtn.addEventListener('click', returnToCart); }
    }

    /* ---------- Отмена заказа ---------- */
    var lastFocus = null;
    function openCancel() {
        lastFocus = document.activeElement;
        confirmBox.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        document.getElementById('cancel-no').focus();
    }
    function closeCancel() {
        confirmBox.classList.remove('is-open');
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }
    document.getElementById('cancel-no').addEventListener('click', closeCancel);
    confirmBox.addEventListener('click', function (e) { if (e.target === confirmBox) { closeCancel(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && confirmBox.classList.contains('is-open')) { closeCancel(); }
    });

    document.getElementById('cancel-yes').addEventListener('click', function () {
        if (!currentOrder) { closeCancel(); return; }
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Отменяем…';

        J.api('../api/cancel_order.php', { method: 'POST', body: { order_id: currentOrder.id } })
            .then(function (data) {
                closeCancel();
                btn.disabled = false;
                btn.textContent = 'Отменить заказ';
                restoredItems = data.restored || [];
                var merged = Object.assign({}, currentOrder, data.order);
                renderOrderPanel(merged, currentItems, restoredItems);
                J.setCounts({ cart: data.cart_count });
                J.toast('Заказ отменён, товары вернулись на склад', 'info');
                orderWrap.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
            })
            .catch(function (err) {
                closeCancel();
                btn.disabled = false;
                btn.textContent = 'Отменить заказ';
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                J.toast(err.message, 'error');
            });
    });

    /* ---------- Возврат отменённых позиций в корзину ---------- */
    function returnToCart() {
        if (!restoredItems.length) { return; }
        var btn = document.getElementById('return-to-cart');
        if (btn) { btn.disabled = true; btn.textContent = 'Возвращаем…'; }

        var chain = Promise.resolve();
        var ok = 0, capped = 0;
        restoredItems.forEach(function (item) {
            chain = chain.then(function () {
                return J.api('../api/cart_add.php', {
                    method: 'POST',
                    body: { product_id: item.product_id, quantity: item.quantity }
                }).then(function (data) {
                    ok++;
                    if (data.capped) { capped++; }
                    J.setCounts({ cart: data.cart_count });
                }).catch(function () { capped++; });
            });
        });
        chain.then(function () {
            if (ok) {
                J.toast(capped ? 'Вернули не всё: остатки изменились' : 'Позиции снова в корзине', capped ? 'warning' : 'success');
                window.location.href = cfg.cartUrl;
            } else {
                if (btn) { btn.disabled = false; btn.textContent = 'Вернуть позиции в корзину'; }
                J.toast('Вернуть не удалось — товары недоступны', 'error');
            }
        });
    }

    /* ---------- Режим «последний новый заказ» ---------- */
    if (cfg.mode === 'order' && cfg.order) {
        renderOrderPanel(cfg.order, cfg.orderItems || [], []);
    }

    /* ---------- Прогресс-нить ---------- */
    var progress = document.getElementById('thread-progress');
    var ticking = false;
    function onScroll() {
        var doc = document.documentElement;
        var max = doc.scrollHeight - window.innerHeight;
        if (progress) { progress.style.transform = 'scaleX(' + (max > 0 ? window.scrollY / max : 0) + ')'; }
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { requestAnimationFrame(onScroll); ticking = true; }
    }, { passive: true });
    onScroll();

    if (cfg.mode !== 'form') { return; }

    /* ============================================================
       Форма оформления
       ============================================================ */
    var form = document.getElementById('checkout-form');
    var phoneInput = document.getElementById('phone');
    var addressInput = document.getElementById('address');
    var addressField = document.getElementById('address-field');
    var commentInput = document.getElementById('comment');
    var commentCount = document.getElementById('comment-count');
    var pickupBlock = document.getElementById('pickup-block');
    var cardBlock = document.getElementById('card-block');
    var liveDelivery = document.getElementById('live-delivery');
    var livePayment = document.getElementById('live-payment');
    var submitMain = document.getElementById('submit-main');
    var mco = document.getElementById('mco');
    var summary = document.getElementById('co-summary');

    var cardNumber = document.getElementById('card-number');
    var cardExpiry = document.getElementById('card-expiry');
    var cardCvc = document.getElementById('card-cvc');

    function delivery() {
        var checked = form.querySelector('input[name="delivery"]:checked');
        return checked ? checked.value : 'courier';
    }
    function payment() {
        var checked = form.querySelector('input[name="payment"]:checked');
        return checked ? checked.value : 'on_delivery';
    }

    /* ---------- Маска телефона ---------- */
    function maskPhone(raw) {
        var d = String(raw).replace(/\D+/g, '');
        if (d === '') { return ''; }
        if (d[0] === '8') { d = '7' + d.slice(1); }
        if (d[0] !== '7') { d = '7' + d; }
        d = d.slice(0, 11);
        var out = '+7';
        if (d.length > 1) { out += ' (' + d.slice(1, 4); }
        if (d.length >= 4) { out += ') ' + d.slice(4, 7); }
        if (d.length >= 8) { out += '-' + d.slice(7, 9); }
        if (d.length >= 10) { out += '-' + d.slice(9, 11); }
        return out;
    }
    function phoneDigits() {
        return phoneInput.value.replace(/\D+/g, '');
    }
    phoneInput.addEventListener('input', function () {
        phoneInput.value = maskPhone(phoneInput.value);
        setFieldError('phone', '');
        markSteps();
    });
    phoneInput.addEventListener('focus', function () {
        if (phoneInput.value === '') { phoneInput.value = '+7 ('; }
    });
    phoneInput.addEventListener('blur', function () {
        if (phoneDigits().length <= 1) { phoneInput.value = ''; }
        markSteps();
    });
    if (phoneInput.value !== '') { phoneInput.value = maskPhone(phoneInput.value); }

    /* ---------- Маски карты (только браузер) ---------- */
    function luhnValid(digits) {
        var sum = 0, alt = false;
        for (var i = digits.length - 1; i >= 0; i--) {
            var n = parseInt(digits[i], 10);
            if (alt) { n *= 2; if (n > 9) { n -= 9; } }
            sum += n;
            alt = !alt;
        }
        return sum % 10 === 0;
    }
    cardNumber.addEventListener('input', function () {
        var d = cardNumber.value.replace(/\D+/g, '').slice(0, 16);
        cardNumber.value = d.replace(/(.{4})/g, '$1 ').trim();
        setCardError('number', '');
    });
    cardExpiry.addEventListener('input', function () {
        var d = cardExpiry.value.replace(/\D+/g, '').slice(0, 4);
        cardExpiry.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
        setCardError('expiry', '');
    });
    cardCvc.addEventListener('input', function () {
        cardCvc.value = cardCvc.value.replace(/\D+/g, '').slice(0, 3);
        setCardError('cvc', '');
    });

    function setCardError(name, message) {
        var slot = form.querySelector('[data-card-error="' + name + '"]');
        var map = { number: cardNumber, expiry: cardExpiry, cvc: cardCvc };
        var input = map[name];
        if (input) { input.classList.toggle('is-invalid', Boolean(message)); }
        if (slot) { slot.textContent = message || ''; slot.hidden = !message; }
    }

    function validateCard() {
        if (payment() !== 'card') { return true; }
        var errors = {};
        var num = cardNumber.value.replace(/\D+/g, '');
        if (num.length !== 16 || !luhnValid(num)) { errors.number = 'Номер: 16 цифр'; }

        var exp = cardExpiry.value.split('/');
        var mm = parseInt(exp[0], 10);
        var yy = parseInt(exp[1], 10);
        var now = new Date();
        var curYear = now.getFullYear() % 100;
        var curMonth = now.getMonth() + 1;
        if (!(mm >= 1 && mm <= 12) || isNaN(yy) || yy < curYear || (yy === curYear && mm < curMonth)) {
            errors.expiry = 'Срок некорректен';
        }
        if (cardCvc.value.length !== 3) { errors.cvc = '3 цифры'; }

        ['number', 'expiry', 'cvc'].forEach(function (k) { setCardError(k, errors[k] || ''); });
        return Object.keys(errors).length === 0;
    }

    /* ---------- Ошибки полей ---------- */
    function setFieldError(name, message) {
        var slot = form.querySelector('[data-error-for="' + name + '"]');
        var input = form.elements[name];
        if (input && input.classList) { input.classList.toggle('is-invalid', Boolean(message)); }
        if (slot) { slot.textContent = message || ''; slot.hidden = !message; }
    }

    /* ---------- Живая смета и состояние шагов ---------- */
    function syncMode() {
        var isCourier = delivery() === 'courier';
        pickupBlock.classList.toggle('is-open', !isCourier);
        addressField.style.display = isCourier ? '' : 'none';
        addressInput.disabled = !isCourier;
        if (!isCourier) { setFieldError('address', ''); }

        var isCard = payment() === 'card';
        cardBlock.classList.toggle('is-open', isCard);
        if (!isCard) { ['number', 'expiry', 'cvc'].forEach(function (k) { setCardError(k, ''); }); }

        liveDelivery.textContent = DELIVERY[delivery()];
        livePayment.textContent = PAYMENT[payment()];
        markSteps();
    }

    function markSteps() {
        document.getElementById('step-1').classList.add('is-done');
        document.getElementById('step-2').classList.add('is-done');
        var phoneOk = phoneDigits().length === 11;
        var addressOk = delivery() !== 'courier' || addressInput.value.trim().length >= 5;
        document.getElementById('step-3').classList.toggle('is-done', phoneOk && addressOk);
    }

    form.querySelectorAll('input[name="delivery"], input[name="payment"]').forEach(function (radio) {
        radio.addEventListener('change', syncMode);
    });
    addressInput.addEventListener('input', function () { setFieldError('address', ''); markSteps(); });
    commentInput.addEventListener('input', function () {
        commentCount.textContent = commentInput.value.length;
        setFieldError('comment', '');
    });
    commentCount.textContent = commentInput.value.length;

    /* ---------- Валидация формы ---------- */
    function validateForm() {
        var errors = {};
        if (phoneDigits().length !== 11) { errors.phone = 'Телефон: 11 цифр, +7 (999) 123-45-67'; }
        if (delivery() === 'courier') {
            var addr = addressInput.value.trim();
            if (addr.length < 5) { errors.address = 'Адрес: минимум 5 символов'; }
            else if (addr.length > 255) { errors.address = 'Адрес: до 255 символов'; }
        }
        if (commentInput.value.length > 1000) { errors.comment = 'Комментарий: до 1000 символов'; }

        ['phone', 'address', 'comment', 'delivery', 'payment'].forEach(function (k) { setFieldError(k, errors[k] || ''); });
        return errors;
    }

    /* ---------- Отправка ---------- */
    var submitting = false;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submitting) { return; }

        if (cfg.hasProblems) {
            J.toast('Сначала исправьте позиции в корзине', 'warning');
            return;
        }

        var errors = validateForm();
        var cardOk = validateCard();
        if (Object.keys(errors).length || !cardOk) {
            J.toast('Проверьте выделенные поля', 'warning');
            var first = Object.keys(errors)[0];
            if (first && form.elements[first]) { form.elements[first].focus(); }
            else if (!cardOk) { cardNumber.focus(); }
            return;
        }

        submitting = true;
        var buttons = [submitMain].concat(Array.prototype.slice.call(document.querySelectorAll('[form="checkout-form"]')));
        var originals = buttons.map(function (b) { return b.innerHTML; });
        buttons.forEach(function (b) { b.disabled = true; b.innerHTML = 'Оформляем…'; });

        // Данные карты в payload не попадают принципиально
        J.api('../api/create_order.php', {
            method: 'POST',
            body: {
                delivery: delivery(),
                payment: payment(),
                address: delivery() === 'courier' ? addressInput.value.trim() : '',
                phone: phoneInput.value,
                comment: commentInput.value.trim(),
                save_profile: document.getElementById('save-profile').checked
            }
        })
            .then(function (data) {
                J.setCounts({ cart: 0 });
                if (flow) { flow.style.display = 'none'; }
                var pills = document.getElementById('co-pills');
                if (pills) { pills.style.display = 'none'; }
                document.getElementById('co-title').textContent = 'Заказ принят';
                document.getElementById('co-lead').textContent = 'Заказ зафиксирован, остатки зарезервированы. Пока статус «новый» — его можно отменить, товары вернутся на склад.';
                renderOrderPanel(data.order, data.items, []);
                J.toast('Заказ №' + data.order.order_number + ' оформлен', 'success');
                window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
            })
            .catch(function (err) {
                submitting = false;
                buttons.forEach(function (b, i) { b.disabled = false; b.innerHTML = originals[i]; });
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                var fieldErrors = (err.data && err.data.errors) || {};
                Object.keys(fieldErrors).forEach(function (k) { setFieldError(k, fieldErrors[k]); });
                J.toast(err.message, 'error');
                if (err.data && err.data.problems) {
                    setTimeout(function () { window.location.href = cfg.cartUrl; }, 1800);
                }
            });
    });

    /* ---------- Липкая мобильная панель ---------- */
    var summaryVisible = true;
    function updateMobile() {
        if (!mco) { return; }
        mco.classList.toggle('is-visible', window.innerWidth <= 860 && !summaryVisible && !submitting);
    }
    if (summary && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { summaryVisible = entry.isIntersecting; updateMobile(); });
        }, { threshold: 0, rootMargin: '-80px 0px 0px 0px' });
        io.observe(summary);
    }
    window.addEventListener('resize', updateMobile);

    /* ---------- Итог суммы: плавный набор ---------- */
    var totalEl = document.getElementById('co-total');
    if (totalEl && !reduced) {
        var target = cfg.subtotal;
        var start = null;
        totalEl.textContent = money(0);
        requestAnimationFrame(function step(ts) {
            if (start === null) { start = ts; }
            var p = Math.min((ts - start) / 700, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            totalEl.textContent = money(target * eased);
            if (p < 1) { requestAnimationFrame(step); }
        });
    }

    syncMode();
    updateMobile();
});
</script>

<?php render_footer(); ?>
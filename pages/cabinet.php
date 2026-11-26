<?php
/**
 * pages/cabinet.php — личный кабинет «Стежок».
 * Вкладки: Профиль (данные + аватар), Заказы (состав, статус, отмена, повтор),
 * Избранное, Безопасность (смена пароля по старому).
 * Все действия через fetch к api/*.php, аватар — multipart с прогрессом.
 */

require_once __DIR__ . '/../config/helpers.php';

require_login();

$user = current_user();
$userId = (int)$user['id'];

$stmt = $pdo->prepare(
    'SELECT id, login, full_name, email, phone, address, avatar_path, role, created_at FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$profile = $stmt->fetch();

// Аккаунт удалён, пока сессия жила
if (!$profile) {
    logout_user();
    header('Location: ' . login_url());
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
$stmt->execute([$userId]);
$ordersCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$stmt->execute([$userId]);
$favoritesCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ?');
$stmt->execute([$userId]);
$cartCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = ? AND status <> \'cancelled\'');
$stmt->execute([$userId]);
$spent = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM orders WHERE user_id = ? GROUP BY status');
$stmt->execute([$userId]);
$statusCounts = array_fill_keys(['new', 'processing', 'shipped', 'done', 'cancelled'], 0);
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}

$allowedTabs = ['profile', 'orders', 'favorites', 'security'];
$tab = (string)($_GET['tab'] ?? 'profile');
$tab = in_array($tab, $allowedTabs, true) ? $tab : 'profile';

$firstName = preg_split('/\s+/u', trim((string)$profile['full_name']))[0] ?: $profile['login'];
$initials = user_initials((string)$profile['full_name']);
$memberSince = date('d.m.Y', strtotime((string)$profile['created_at']));

$cabinetConfig = [
    'tab' => $tab,
    'csrf' => csrf_token(),
    'maxAvatarBytes' => 2 * 1024 * 1024,
    'perPageOrders' => 5,
    'perPageFavorites' => 9,
    'statusCounts' => $statusCounts,
    'ordersCount' => $ordersCount,
    'favoritesCount' => $favoritesCount,
    'cartCount' => $cartCount,
    'user' => [
        'id' => (int)$profile['id'],
        'login' => $profile['login'],
        'full_name' => $profile['full_name'],
        'email' => $profile['email'],
        'phone' => $profile['phone'],
        'address' => $profile['address'],
        'avatar_path' => $profile['avatar_path'],
        'avatar_url' => $profile['avatar_path'] ? '../' . $profile['avatar_path'] : null,
        'initials' => $initials,
        'role' => $profile['role'],
    ],
    'urls' => [
        'catalog' => 'catalog.php',
        'cart' => 'cart.php',
        'product' => 'product.php',
    ],
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Личный кабинет', [
    'description' => 'Личный кабинет магазина Janome: профиль, заказы, избранное и безопасность.',
    'body_class' => 'page-cabinet',
]);
?>

<style>
/* ============================================================
   Кабинет «Стежок»: боковая книжка вкладок, лоскуты заказов,
   аватар-шпуля, измеритель стойкости пароля.
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
.cab-hero { padding: 34px 0 26px; position: relative; overflow: hidden;
    background: linear-gradient(118deg, rgba(176, 27, 46, .06) 0%, rgba(212, 42, 63, .03) 40%, rgba(255, 255, 255, 0) 72%); }
.cab-hero__row { display: flex; align-items: flex-end; justify-content: space-between; gap: 26px; flex-wrap: wrap; }
.cab-hero__lead { color: var(--muted); max-width: 56ch; margin-bottom: 0; }
.cab-pills { display: flex; gap: 10px; flex-wrap: wrap; }
.cab-pill { padding: 9px 14px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 600 12px/1.25 var(--font-sans); color: var(--muted); }
.cab-pill strong { display: block; font-family: var(--font-serif); font-size: 19px; color: var(--wine-700); }
.cab-hero .tape { margin-top: 24px; }

/* ---------- Раскладка ---------- */
.cab-layout { display: grid; grid-template-columns: 296px 1fr; gap: 26px; align-items: start; padding: 26px 0 60px; }
.cab-side { position: sticky; top: calc(var(--header-h) + 20px); display: grid; gap: 16px; }

/* Карточка пользователя */
.cab-user { padding: 20px 18px; }
.cab-user__top { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
.cab-avatar { position: relative; width: 68px; height: 68px; flex: none; font-size: 23px; }
.cab-avatar::after {
    content: ""; position: absolute; inset: -5px; border-radius: 50%;
    border: 2px dashed var(--line); pointer-events: none; transition: border-color .3s, transform .4s;
}
.cab-avatar.is-updated::after { border-color: var(--wine-500); animation: avatar-ring .8s ease; }
@keyframes avatar-ring { 40% { transform: scale(1.12) rotate(8deg); } }
.cab-user__name { font: 700 16.5px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 4px; word-break: break-word; }
.cab-user__login { display: inline-block; padding: 3px 8px; background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm); font: 600 10.5px/1.4 var(--font-sans); letter-spacing: .08em; color: var(--muted); }
.cab-user__since { font-size: 12px; color: var(--muted); margin: 0 0 14px; }
.cab-user__stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding-top: 13px; border-top: 2px dashed var(--line); }
.cab-stat { text-align: center; }
.cab-stat b { display: block; font: 700 18px/1.1 var(--font-serif); color: var(--wine-700); }
.cab-stat span { font-size: 10.5px; color: var(--muted); letter-spacing: .04em; }

/* Вкладки-корешки */
.cab-tabs { padding: 10px; display: grid; gap: 4px; }
.cab-tab {
    position: relative; display: flex; align-items: center; gap: 11px; width: 100%;
    padding: 12px 13px; background: transparent; border: 1px solid transparent;
    border-radius: var(--radius); cursor: pointer; text-align: left;
    font: 600 14px/1.2 var(--font-sans); color: var(--ink);
    transition: background-color .22s, color .22s, border-color .22s, transform .18s;
}
.cab-tab::before {
    content: ""; position: absolute; left: 0; top: 10px; bottom: 10px; width: 3px;
    border-radius: 2px; background: linear-gradient(180deg, var(--wine-500), var(--wine-300));
    transform: scaleY(0); transform-origin: center; transition: transform .28s cubic-bezier(.22,.61,.36,1);
}
.cab-tab:hover { background: var(--linen); border-color: var(--line); transform: translateX(2px); }
.cab-tab.is-active { background: var(--wine-050); border-color: rgba(176, 27, 46, .3); color: var(--wine-700); }
.cab-tab.is-active::before { transform: scaleY(1); }
.cab-tab__icon { color: var(--wine-500); flex: none; display: grid; place-items: center; }
.cab-tab__label { flex: 1; }
.cab-tab__count { font: 700 11px/1 var(--font-sans); padding: 4px 8px; background: var(--linen); border-radius: 999px; color: var(--muted); transition: background-color .22s, color .22s; }
.cab-tab.is-active .cab-tab__count { background: var(--wine-500); color: var(--white); }
.cab-side__note { padding: 14px 16px; font-size: 12px; color: var(--muted); }
.cab-side__note b { color: var(--ink); display: block; margin-bottom: 5px; font-size: 12.5px; }

/* ---------- Панели ---------- */
.cab-main { display: grid; gap: 18px; min-width: 0; }
.cab-panel { display: grid; gap: 18px; animation: panel-in .4s cubic-bezier(.22,.61,.36,1) both; }
.cab-panel[hidden] { display: none; }
@keyframes panel-in { from { opacity: 0; transform: translateY(14px); } }
.cab-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.cab-head__title { font: 700 clamp(21px, 2.4vw, 27px)/1.2 var(--font-serif); color: var(--wine-900); margin: 0 0 4px; }
.cab-head__hint { font-size: 13px; color: var(--muted); margin: 0; }
.cab-block { padding: 22px 20px; }
.cab-block__title { font: 700 16px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 14px; }

/* ---------- Аватар ---------- */
.avatar-zone { display: grid; grid-template-columns: 132px 1fr; gap: 22px; align-items: start; }
.avatar-zone__preview { position: relative; width: 132px; height: 132px; }
.avatar-zone__preview .avatar { width: 132px; height: 132px; font-size: 42px; }
.avatar-zone__preview .avatar::after { content: ""; position: absolute; inset: -7px; border-radius: 50%; border: 2px dashed var(--line); }
.avatar-drop {
    padding: 20px; border: 2px dashed var(--line); border-radius: var(--radius);
    background: var(--linen); text-align: center; cursor: pointer;
    transition: border-color .25s, background-color .25s, transform .2s;
}
.avatar-drop:hover { border-color: var(--wine-300); background: var(--wine-050); }
.avatar-drop.is-drag { border-color: var(--wine-500); background: var(--wine-050); transform: scale(1.01); }
.avatar-drop__icon { color: var(--wine-500); margin-bottom: 8px; }
.avatar-drop__text { font: 600 13.5px/1.4 var(--font-sans); color: var(--ink); }
.avatar-drop__hint { font-size: 11.5px; color: var(--muted); margin-top: 5px; }
.avatar-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 13px; }
.avatar-progress { height: 6px; border-radius: 3px; background: var(--line); overflow: hidden; margin-top: 13px; display: none; }
.avatar-progress.is-visible { display: block; }
.avatar-progress__bar { height: 100%; width: 0; background: linear-gradient(90deg, var(--wine-500), var(--wine-300)); transition: width .2s ease; }
.avatar-hint { font-size: 11.5px; color: var(--muted); margin: 11px 0 0; }

/* ---------- Формы кабинета ---------- */
.readonly-chip {
    display: flex; align-items: center; gap: 9px; padding: 11px 14px;
    background: var(--linen); border: 1px dashed var(--line); border-radius: var(--radius);
    font: 600 14px/1.3 var(--font-sans); color: var(--muted);
}
.readonly-chip b { color: var(--ink); }
.readonly-chip svg { color: var(--wine-500); flex: none; }
.cab-meta { display: grid; gap: 10px; }
.cab-meta__row { display: flex; align-items: baseline; gap: 8px; font-size: 13.5px; }
.cab-meta__row span { color: var(--muted); flex: none; }
.cab-meta__row i { flex: 1; border-bottom: 1px dotted var(--line); transform: translateY(-3px); }
.cab-meta__row b { font-weight: 600; color: var(--ink); flex: none; text-align: right; }

/* ---------- Заказы ---------- */
.orders-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.status-chips { display: flex; gap: 8px; flex-wrap: wrap; flex: 1; }
.status-chip {
    display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px;
    background: var(--white); border: 1px dashed var(--line); border-radius: 999px;
    font: 600 12.5px/1 var(--font-sans); color: var(--ink); cursor: pointer;
    transition: background-color .22s, color .22s, border-color .22s, transform .18s;
}
.status-chip:hover { border-color: var(--wine-300); transform: translateY(-2px); }
.status-chip.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); box-shadow: var(--shadow-btn); }
.status-chip__count { font: 700 10.5px/1 var(--font-sans); padding: 3px 6px; background: var(--linen); border-radius: 999px; color: var(--muted); }
.status-chip.is-active .status-chip__count { background: rgba(255, 255, 255, .22); color: var(--white); }
.orders-search { position: relative; flex: 0 1 230px; min-width: 180px; }
.orders-search .input { padding-left: 38px; }
.orders-search svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }

.olist { display: grid; gap: 14px; }
.ocard { padding: 0; overflow: hidden; animation: card-in .45s cubic-bezier(.22,.61,.36,1) both; animation-delay: calc(var(--i, 0) * 60ms); }
@keyframes card-in { from { opacity: 0; transform: translateY(18px); } }
.ocard__head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 16px 18px; }
.ocard__id { flex: 1; min-width: 180px; }
.ocard__num { font: 700 16px/1.3 var(--font-serif); color: var(--wine-900); display: block; }
.ocard__date { font-size: 12px; color: var(--muted); }
.ocard__total { font: 700 20px/1.1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.ocard__toggle {
    display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px;
    background: var(--white); border: 1px solid var(--line); border-radius: var(--radius);
    font: 600 12.5px/1 var(--font-sans); color: var(--wine-700); cursor: pointer;
    transition: border-color .2s, background-color .2s;
}
.ocard__toggle:hover { border-color: var(--wine-300); background: var(--linen); }
.ocard__toggle svg { transition: transform .3s; }
.ocard__toggle[aria-expanded="true"] svg { transform: rotate(180deg); }
.ocard__meta { display: flex; gap: 8px; flex-wrap: wrap; padding: 0 18px 15px; }
.ocard__tag { font: 500 11.5px/1.3 var(--font-sans); color: var(--muted); padding: 4px 9px; background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm); }
.ocard__body { max-height: 0; overflow: hidden; transition: max-height .35s cubic-bezier(.22,.61,.36,1); }
.ocard__inner { padding: 0 18px 18px; border-top: 2px dashed var(--line); padding-top: 15px; }
.ocard__items { display: grid; gap: 11px; }
.oitem { display: grid; grid-template-columns: 54px 1fr auto; gap: 12px; align-items: center; }
.oitem__media { aspect-ratio: 1; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); }
.oitem__media img { width: 100%; height: 100%; object-fit: cover; }
.oitem__name { font: 600 13.5px/1.35 var(--font-sans); color: var(--ink); }
.oitem__name a { color: var(--wine-900); text-decoration: none; }
.oitem__name a:hover { color: var(--wine-700); }
.oitem__meta { font-size: 11.5px; color: var(--muted); margin-top: 3px; }
.oitem__sum { font: 700 14.5px/1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.ocard__comment { margin-top: 14px; padding: 11px 14px; background: var(--linen); border-left: 3px solid var(--wine-500); border-radius: var(--radius-sm); font-size: 13px; color: var(--muted); }
.ocard__address { margin-top: 12px; font-size: 13px; color: var(--muted); }
.ocard__address b { color: var(--ink); }
.ocard__actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
.ocard.is-cancelled { opacity: .82; }

/* ---------- Избранное ---------- */
.fav-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.fav-toolbar .orders-search { flex: 1 1 220px; }
.fav-toolbar .select { min-width: 190px; }
.fgrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
.fcard { display: flex; flex-direction: column; padding: 13px; animation: card-in .45s cubic-bezier(.22,.61,.36,1) both; animation-delay: calc(var(--i, 0) * 55ms); }
.fcard.is-leaving { animation: fcard-out .3s ease forwards; pointer-events: none; }
@keyframes fcard-out { to { opacity: 0; transform: scale(.94); } }
.fcard__media { position: relative; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); margin-bottom: 12px; }
.fcard__media img { width: 100%; height: 100%; object-fit: cover; transition: transform .45s ease; }
.fcard:hover .fcard__media img { transform: scale(1.06); }
.fcard__media::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 6px; background: repeating-linear-gradient(45deg, rgba(176, 27, 46, .5) 0 4px, transparent 4px 8px); }
.fcard__brand { position: absolute; top: 9px; left: 9px; z-index: 2; padding: 4px 9px; background: var(--wine-900); color: var(--white); border-radius: var(--radius-sm); font: 700 9.5px/1.4 var(--font-sans); letter-spacing: .13em; text-transform: uppercase; }
.fcard__type { position: absolute; bottom: 12px; left: 9px; z-index: 2; padding: 4px 9px; background: rgba(255, 255, 255, .94); color: var(--wine-700); border-radius: var(--radius-sm); font: 600 10px/1.4 var(--font-sans); }
.fcard__sale { position: absolute; bottom: 12px; right: 9px; z-index: 2; padding: 4px 9px; background: var(--wine-500); color: var(--white); border-radius: var(--radius-sm); font: 700 11px/1.4 var(--font-sans); transform: rotate(-3deg); }
.fcard__fav {
    position: absolute; top: 8px; right: 8px; z-index: 3; width: 34px; height: 34px; border-radius: 50%;
    background: rgba(255, 255, 255, .95); border: 1px solid var(--wine-500); color: var(--wine-500);
    display: grid; place-items: center; cursor: pointer; transition: transform .18s, background-color .2s;
}
.fcard__fav svg path { fill: currentColor; }
.fcard__fav:hover { transform: scale(1.1); background: var(--wine-050); }
.fcard__fav:active { transform: scale(.9); }
.fcard__fav.is-busy { opacity: .6; pointer-events: none; }
.fcard__name { font: 700 15.5px/1.35 var(--font-serif); margin: 0 0 6px; }
.fcard__name a { color: var(--wine-900); text-decoration: none; }
.fcard__name a:hover { color: var(--wine-700); }
.fcard__model { display: inline-block; margin-bottom: 10px; padding: 3px 8px; background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm); font: 600 10px/1.5 var(--font-sans); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
.fcard__price { display: flex; align-items: baseline; gap: 9px; flex-wrap: wrap; margin-bottom: 9px; }
.fcard__price b { font: 700 19px/1 var(--font-serif); color: var(--wine-700); }
.fcard__price s { font-size: 12.5px; color: var(--muted); }
.fcard__foot { margin-top: auto; display: grid; gap: 9px; }
.fcard__incart { font: 600 11px/1 var(--font-sans); color: var(--success); }
.fcard__add.is-added { background: var(--success); }

/* ---------- Безопасность ---------- */
.sec-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 18px; align-items: start; }
.pass-field { position: relative; }
.pass-field .input { padding-right: 46px; }
.pass-toggle {
    position: absolute; right: 8px; top: 33px; width: 32px; height: 32px;
    background: transparent; border: 0; border-radius: var(--radius-sm); color: var(--muted);
    cursor: pointer; display: grid; place-items: center; transition: color .2s, background-color .2s;
}
.pass-toggle:hover { color: var(--wine-700); background: var(--linen); }
.strength { margin: -6px 0 16px; }
.strength__track { height: 6px; border-radius: 3px; background: var(--line); overflow: hidden; }
.strength__bar { height: 100%; width: 0; border-radius: 3px; background: var(--danger); transition: width .3s ease, background-color .3s ease; }
.strength__label { display: flex; justify-content: space-between; gap: 10px; font: 600 11.5px/1.4 var(--font-sans); color: var(--muted); margin-top: 6px; }
.req-list { list-style: none; margin: 0 0 16px; padding: 0; display: grid; gap: 7px; }
.req { display: flex; align-items: center; gap: 9px; font-size: 12.5px; color: var(--muted); transition: color .25s; }
.req__dot { width: 17px; height: 17px; flex: none; border-radius: 50%; border: 1px solid var(--line); display: grid; place-items: center; color: transparent; transition: background-color .25s, border-color .25s, color .25s, transform .25s; }
.req.is-ok { color: var(--success); }
.req.is-ok .req__dot { background: var(--success); border-color: var(--success); color: var(--white); transform: scale(1.08); }
.sec-facts { display: grid; gap: 12px; }
.sec-fact { display: flex; align-items: flex-start; gap: 11px; font-size: 13px; color: var(--muted); }
.sec-fact svg { color: var(--wine-500); flex: none; margin-top: 2px; }
.sec-fact b { color: var(--ink); }
.cab-logout { margin: 18px 0 0; padding-top: 16px; border-top: 2px dashed var(--line); }

/* ---------- Скелетоны, пусто, пагинация ---------- */
.sk { background: linear-gradient(90deg, var(--linen) 25%, #F4E9EB 37%, var(--linen) 63%); background-size: 400% 100%; animation: sk-sweep 1.3s ease infinite; border-radius: var(--radius-sm); }
@keyframes sk-sweep { from { background-position: 100% 0; } to { background-position: 0 0; } }
.osk { padding: 16px 18px; display: grid; gap: 11px; }
.sk--line { height: 13px; }
.sk--short { width: 42%; }
.sk--tall { height: 52px; }
.fsk { padding: 13px; }
.sk--media { aspect-ratio: 4 / 3; margin-bottom: 12px; }
.sk--btn { height: 36px; }

.cab-empty { text-align: center; padding: 48px 24px; }
.cab-empty__art { color: var(--line); margin-bottom: 14px; }
.cab-empty__needle { animation: needle-sway 3.4s ease-in-out infinite; transform-origin: 50% 18%; }
@keyframes needle-sway { 0%, 100% { transform: rotate(-8deg); } 50% { transform: rotate(8deg); } }
.cab-empty__actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.cab-pagination { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 6px; }
.page-ellipsis { display: grid; place-items: center; min-width: 24px; color: var(--muted); }

/* ---------- Подтверждение ---------- */
.cab-confirm { position: fixed; inset: 0; z-index: 210; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(42, 16, 19, .5); }
.cab-confirm.is-open { display: flex; animation: cf-in .22s ease; }
@keyframes cf-in { from { opacity: 0; } }
.cab-confirm__box { width: min(440px, 100%); padding: 26px 24px; text-align: center; animation: cf-pop .3s cubic-bezier(.22,.61,.36,1); }
@keyframes cf-pop { from { transform: translateY(14px) scale(.97); opacity: 0; } }
.cab-confirm__title { font: 700 21px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 10px; }
.cab-confirm__text { color: var(--muted); font-size: 14px; margin-bottom: 20px; }
.cab-confirm__actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

/* ---------- Тост с действием ---------- */
.toast__action {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 8px;
    padding: 6px 12px; background: var(--wine-500); color: var(--white); border: 0;
    border-radius: var(--radius-sm); cursor: pointer; text-decoration: none;
    font: 600 12px/1 var(--font-sans); transition: background-color .2s, transform .15s;
}
.toast__action:hover { background: var(--wine-300); transform: translateX(2px); }

/* ---------- Адаптив ---------- */
@media (max-width: 1180px) {
    .fgrid { grid-template-columns: repeat(2, 1fr); }
    .sec-grid { grid-template-columns: 1fr; }
}
@media (max-width: 1080px) {
    .cab-layout { grid-template-columns: 1fr; }
    .cab-side { position: static; }
    .cab-tabs {
        display: flex; gap: 6px; overflow-x: auto; padding: 8px;
        position: sticky; top: var(--header-h); z-index: 20;
        background: rgba(255, 255, 255, .97);
        scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen);
    }
    .cab-tabs::-webkit-scrollbar { height: 6px; }
    .cab-tabs::-webkit-scrollbar-track { background: var(--linen); border-radius: 3px; }
    .cab-tabs::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 3px; }
    .cab-tab { flex: none; padding: 10px 14px; }
    .cab-tab::before { left: 12px; right: 12px; top: auto; bottom: 4px; width: auto; height: 3px; transform: scaleX(0); }
    .cab-tab.is-active::before { transform: scaleX(1); }
    .cab-tab:hover { transform: none; }
    .cab-side__note { display: none; }
    .cab-user__stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 760px) {
    .avatar-zone { grid-template-columns: 1fr; }
    .avatar-zone__preview { margin-inline: auto; }
    .orders-search, .fav-toolbar .select { flex: 1 1 100%; min-width: 0; width: 100%; }
    .ocard__head { gap: 10px; }
    .ocard__total { order: 3; }
}
@media (max-width: 620px) {
    .fgrid { grid-template-columns: 1fr; }
    .cab-block { padding: 18px 16px; }
    .cab-pills { width: 100%; }
    .oitem { grid-template-columns: 44px 1fr; }
    .oitem__sum { grid-column: 2; }
}
@media (prefers-reduced-motion: reduce) {
    .cab-panel, .ocard, .fcard, .cab-confirm__box { animation: none; }
    .sk { animation: none; }
    .cab-empty__needle { animation: none; }
    .cab-avatar.is-updated::after { animation: none; }
    .fcard__media img, .ocard__body { transition: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<nav class="crumbs" aria-label="Навигация по разделам">
    <div class="container">
        <ol class="crumbs__list">
            <li><a href="index.php">Главная</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li><a href="catalog.php">Каталог</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li class="crumbs__current" aria-current="page">Личный кабинет</li>
        </ol>
    </div>
</nav>

<section class="cab-hero">
    <div class="container">
        <div class="cab-hero__row">
            <div class="reveal">
                <span class="label">Личный кабинет</span>
                <h1>Здравствуйте, <span id="hero-name"><?= e($firstName) ?></span></h1>
                <p class="cab-hero__lead">Здесь живут ваши данные, заказы и отмеченные машинки. Избранное поднимается наверх каталога, а новый заказ можно отменить самостоятельно.</p>
            </div>
            <div class="cab-pills reveal">
                <span class="cab-pill"><strong id="pill-orders"><?= $ordersCount ?></strong> заказов</span>
                <span class="cab-pill"><strong id="pill-favorites"><?= $favoritesCount ?></strong> в избранном</span>
                <span class="cab-pill"><strong id="pill-cart"><?= $cartCount ?></strong> в корзине</span>
                <span class="cab-pill"><strong><?= e(format_price($spent)) ?></strong> сумма заказов</span>
            </div>
        </div>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<div class="container cab-layout">
    <!-- Боковая книжка -->
    <aside class="cab-side">
        <div class="card card--static cab-user reveal">
            <div class="cab-user__top">
                <span class="avatar cab-avatar<?= $profile['avatar_path'] ? '' : ' avatar--initials' ?>" id="cab-avatar">
                    <img id="cab-avatar-img" alt="Аватар профиля"<?= $profile['avatar_path'] ? ' src="../' . e($profile['avatar_path']) . '"' : ' hidden' ?>>
                    <span id="cab-avatar-initials"<?= $profile['avatar_path'] ? ' hidden' : '' ?>><?= e($initials) ?></span>
                </span>
                <span>
                    <span class="cab-user__name" id="side-name"><?= e($profile['full_name']) ?></span>
                    <span class="cab-user__login">@<?= e($profile['login']) ?></span>
                </span>
            </div>
            <p class="cab-user__since">
                <span class="badge <?= $profile['role'] === 'admin' ? 'badge--admin' : 'badge--user' ?>"><?= $profile['role'] === 'admin' ? 'админ' : 'покупатель' ?></span>
                · с нами с <?= e($memberSince) ?>
            </p>
            <div class="cab-user__stats">
                <span class="cab-stat"><b id="stat-orders"><?= $ordersCount ?></b><span>заказов</span></span>
                <span class="cab-stat"><b id="stat-favorites"><?= $favoritesCount ?></b><span>избранное</span></span>
                <span class="cab-stat"><b id="stat-cart"><?= $cartCount ?></b><span>корзина</span></span>
            </div>
        </div>

        <div class="card card--static cab-tabs reveal" role="tablist" aria-label="Разделы кабинета">
            <button class="cab-tab<?= $tab === 'profile' ? ' is-active' : '' ?>" type="button" role="tab"
                    id="tab-profile" data-tab="profile" aria-controls="panel-profile" aria-selected="<?= $tab === 'profile' ? 'true' : 'false' ?>">
                <span class="cab-tab__icon"><svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><circle cx="12" cy="8.5" r="3.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 20c1.2-3.6 4-5.5 7-5.5s5.8 1.9 7 5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                <span class="cab-tab__label">Профиль</span>
            </button>
            <button class="cab-tab<?= $tab === 'orders' ? ' is-active' : '' ?>" type="button" role="tab"
                    id="tab-orders" data-tab="orders" aria-controls="panel-orders" aria-selected="<?= $tab === 'orders' ? 'true' : 'false' ?>">
                <span class="cab-tab__icon"><svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9h7M8.5 13h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                <span class="cab-tab__label">Заказы</span>
                <span class="cab-tab__count" id="tabcount-orders"><?= $ordersCount ?></span>
            </button>
            <button class="cab-tab<?= $tab === 'favorites' ? ' is-active' : '' ?>" type="button" role="tab"
                    id="tab-favorites" data-tab="favorites" aria-controls="panel-favorites" aria-selected="<?= $tab === 'favorites' ? 'true' : 'false' ?>">
                <span class="cab-tab__icon"><svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span>
                <span class="cab-tab__label">Избранное</span>
                <span class="cab-tab__count" id="tabcount-favorites"><?= $favoritesCount ?></span>
            </button>
            <button class="cab-tab<?= $tab === 'security' ? ' is-active' : '' ?>" type="button" role="tab"
                    id="tab-security" data-tab="security" aria-controls="panel-security" aria-selected="<?= $tab === 'security' ? 'true' : 'false' ?>">
                <span class="cab-tab__icon"><svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 11.8l2.2 2.2 4-4.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                <span class="cab-tab__label">Безопасность</span>
            </button>
        </div>

        <div class="card card--static cab-side__note reveal">
            <b>Пароль под защитой</b>
            Хранится только bcrypt-хэш. Смена пароля требует подтверждения текущим — даже если вкладка осталась открытой.
        </div>
    </aside>

    <!-- Панели -->
    <div class="cab-main">
        <!-- ================= ПРОФИЛЬ ================= -->
        <section class="cab-panel" id="panel-profile" role="tabpanel" aria-labelledby="tab-profile"<?= $tab === 'profile' ? '' : ' hidden' ?>>
            <div class="cab-head">
                <div>
                    <h2 class="cab-head__title">Профиль</h2>
                    <p class="cab-head__hint">Эти данные подставляются при оформлении заказа.</p>
                </div>
                <span class="badge badge--info">Логин изменить нельзя</span>
            </div>

            <div class="card card--static cab-block">
                <h3 class="cab-block__title">Аватар</h3>
                <div class="avatar-zone">
                    <div class="avatar-zone__preview">
                        <span class="avatar<?= $profile['avatar_path'] ? '' : ' avatar--initials' ?>" id="preview-avatar">
                            <img id="preview-img" alt="Текущий аватар"<?= $profile['avatar_path'] ? ' src="../' . e($profile['avatar_path']) . '"' : ' hidden' ?>>
                            <span id="preview-initials"<?= $profile['avatar_path'] ? ' hidden' : '' ?>><?= e($initials) ?></span>
                        </span>
                    </div>
                    <div>
                        <div class="avatar-drop" id="avatar-drop" role="button" tabindex="0" aria-label="Выбрать файл аватара">
                            <div class="avatar-drop__icon">
                                <svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M12 16V5M8 9l4-4 4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            </div>
                            <div class="avatar-drop__text">Перетащите картинку или нажмите</div>
                            <div class="avatar-drop__hint">JPG, PNG или WebP · до 2 МБ · от 64×64 px</div>
                        </div>
                        <input type="file" id="avatar-input" accept="image/jpeg,image/png,image/webp" class="visually-hidden">
                        <div class="avatar-progress" id="avatar-progress"><div class="avatar-progress__bar" id="avatar-bar"></div></div>
                        <div class="avatar-actions">
                            <button class="btn btn--primary btn--sm" type="button" id="avatar-pick">Выбрать файл</button>
                            <button class="btn btn--ghost btn--sm" type="button" id="avatar-remove"<?= $profile['avatar_path'] ? '' : ' disabled' ?>>Удалить аватар</button>
                        </div>
                        <span class="field__error" id="avatar-error" hidden></span>
                        <p class="avatar-hint">Имя файла генерируется случайно, старый аватар удаляется с сервера автоматически.</p>
                    </div>
                </div>
            </div>

            <div class="card card--static cab-block">
                <h3 class="cab-block__title">Личные данные</h3>
                <form id="profile-form" novalidate>
                    <div class="field">
                        <label class="field__label">Логин для входа</label>
                        <div class="readonly-chip">
                            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            <b>@<?= e($profile['login']) ?></b>
                            <span class="small">— используется вместо email при входе</span>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label class="field__label" for="full_name">Имя и фамилия</label>
                            <input class="input" id="full_name" name="full_name" type="text" autocomplete="name" maxlength="150"
                                   value="<?= e($profile['full_name']) ?>" required>
                            <span class="field__error" data-error-for="full_name" hidden></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="email">Email</label>
                            <input class="input" id="email" name="email" type="email" autocomplete="email" maxlength="150"
                                   value="<?= e($profile['email']) ?>" required>
                            <span class="field__error" data-error-for="email" hidden></span>
                        </div>
                    </div>

                    <div class="field">
                        <label class="field__label" for="phone">Телефон</label>
                        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="30"
                               placeholder="+7 (___) ___-__-__" value="<?= e((string)$profile['phone']) ?>">
                        <span class="field__hint">Необязательно, но курьеру без него никак</span>
                        <span class="field__error" data-error-for="phone" hidden></span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="address">Адрес доставки</label>
                        <textarea class="textarea" id="address" name="address" rows="2" maxlength="255"
                                  placeholder="Город, улица, дом, квартира" style="min-height: 82px;"><?= e((string)$profile['address']) ?></textarea>
                        <span class="field__error" data-error-for="address" hidden></span>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn--primary" type="submit" id="profile-save">Сохранить профиль</button>
                        <button class="btn btn--ghost" type="button" id="profile-reset">Отменить изменения</button>
                    </div>
                </form>
            </div>

            <div class="card card--static cab-block">
                <h3 class="cab-block__title">Аккаунт</h3>
                <div class="cab-meta">
                    <div class="cab-meta__row"><span>Роль</span><i></i><b><?= $profile['role'] === 'admin' ? 'Администратор' : 'Покупатель' ?></b></div>
                    <div class="cab-meta__row"><span>Регистрация</span><i></i><b><?= e(date('d.m.Y H:i', strtotime((string)$profile['created_at']))) ?></b></div>
                    <div class="cab-meta__row"><span>Заказов оформлено</span><i></i><b id="meta-orders"><?= $ordersCount ?></b></div>
                    <div class="cab-meta__row"><span>Сумма заказов</span><i></i><b><?= e(format_price($spent)) ?></b></div>
                </div>
                <?php if ($profile['role'] === 'admin'): ?>
                    <div class="form-actions" style="margin-top: 16px;">
                        <a class="btn btn--dark btn--sm" href="admin.php">Перейти в админку</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ================= ЗАКАЗЫ ================= -->
        <section class="cab-panel" id="panel-orders" role="tabpanel" aria-labelledby="tab-orders"<?= $tab === 'orders' ? '' : ' hidden' ?>>
            <div class="cab-head">
                <div>
                    <h2 class="cab-head__title">Мои заказы</h2>
                    <p class="cab-head__hint">Раскройте состав, отмените новый заказ или соберите такой же снова.</p>
                </div>
                <button class="btn btn--ghost btn--sm" type="button" id="orders-refresh">Обновить</button>
            </div>

            <div class="orders-toolbar">
                <div class="status-chips" id="status-chips"></div>
                <div class="orders-search">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <label class="visually-hidden" for="orders-q">Поиск по номеру заказа</label>
                    <input class="input" id="orders-q" type="search" placeholder="Номер заказа: JN-2026…" maxlength="30" autocomplete="off">
                </div>
            </div>

            <div class="olist" id="orders-list" aria-live="polite"></div>
            <div class="cab-pagination" id="orders-pagination"></div>
        </section>

        <!-- ================= ИЗБРАННОЕ ================= -->
        <section class="cab-panel" id="panel-favorites" role="tabpanel" aria-labelledby="tab-favorites"<?= $tab === 'favorites' ? '' : ' hidden' ?>>
            <div class="cab-head">
                <div>
                    <h2 class="cab-head__title">Избранное</h2>
                    <p class="cab-head__hint">Эти машинки идут первыми в каталоге при любой сортировке.</p>
                </div>
                <button class="btn btn--primary btn--sm" type="button" id="fav-all-cart">Всё в корзину</button>
            </div>

            <div class="fav-toolbar">
                <div class="orders-search">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <label class="visually-hidden" for="fav-q">Поиск в избранном</label>
                    <input class="input" id="fav-q" type="search" placeholder="Название или модель" maxlength="100" autocomplete="off">
                </div>
                <label class="visually-hidden" for="fav-sort">Сортировка избранного</label>
                <select class="select" id="fav-sort">
                    <option value="new">Сначала добавленные</option>
                    <option value="price_asc">Цена: по возрастанию</option>
                    <option value="price_desc">Цена: по убыванию</option>
                    <option value="name">По названию</option>
                </select>
            </div>

            <div class="fgrid" id="fav-grid" aria-live="polite"></div>
            <div class="cab-pagination" id="fav-pagination"></div>
        </section>

        <!-- ================= БЕЗОПАСНОСТЬ ================= -->
        <section class="cab-panel" id="panel-security" role="tabpanel" aria-labelledby="tab-security"<?= $tab === 'security' ? '' : ' hidden' ?>>
            <div class="cab-head">
                <div>
                    <h2 class="cab-head__title">Безопасность</h2>
                    <p class="cab-head__hint">Смена пароля требует текущий пароль — без него никак.</p>
                </div>
            </div>

            <div class="sec-grid">
                <div class="card card--static cab-block">
                    <h3 class="cab-block__title">Смена пароля</h3>
                    <form id="password-form" novalidate>
                        <div class="field pass-field">
                            <label class="field__label" for="old_password">Текущий пароль</label>
                            <input class="input" id="old_password" name="old_password" type="password" autocomplete="current-password" maxlength="72" required>
                            <button class="pass-toggle" type="button" data-toggle="old_password" aria-label="Показать пароль">
                                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                            </button>
                            <span class="field__error" data-error-for="old_password" hidden></span>
                        </div>

                        <div class="field pass-field">
                            <label class="field__label" for="new_password">Новый пароль</label>
                            <input class="input" id="new_password" name="new_password" type="password" autocomplete="new-password" maxlength="72" required>
                            <button class="pass-toggle" type="button" data-toggle="new_password" aria-label="Показать пароль">
                                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                            </button>
                            <span class="field__error" data-error-for="new_password" hidden></span>
                        </div>

                        <div class="strength">
                            <div class="strength__track"><div class="strength__bar" id="strength-bar"></div></div>
                            <div class="strength__label"><span>Стойкость</span><span id="strength-text">—</span></div>
                        </div>

                        <ul class="req-list" id="req-list">
                            <li class="req" data-req="length"><span class="req__dot"><svg viewBox="0 0 24 24" width="11" height="11"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>Минимум 6 символов</li>
                            <li class="req" data-req="letter"><span class="req__dot"><svg viewBox="0 0 24 24" width="11" height="11"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>Есть буква</li>
                            <li class="req" data-req="digit"><span class="req__dot"><svg viewBox="0 0 24 24" width="11" height="11"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>Есть цифра</li>
                            <li class="req" data-req="bytes"><span class="req__dot"><svg viewBox="0 0 24 24" width="11" height="11"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>Не длиннее 72 байт (лимит bcrypt)</li>
                        </ul>

                        <div class="field pass-field">
                            <label class="field__label" for="repeat_password">Повтор нового пароля</label>
                            <input class="input" id="repeat_password" name="repeat_password" type="password" autocomplete="new-password" maxlength="72" required>
                            <button class="pass-toggle" type="button" data-toggle="repeat_password" aria-label="Показать пароль">
                                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                            </button>
                            <span class="field__error" data-error-for="repeat_password" hidden></span>
                        </div>

                        <div class="form-actions">
                            <button class="btn btn--primary" type="submit" id="password-save">Обновить пароль</button>
                            <button class="btn btn--ghost" type="button" id="password-clear">Очистить</button>
                        </div>
                    </form>
                </div>

                <div class="card card--static cab-block">
                    <h3 class="cab-block__title">Как защищены данные</h3>
                    <div class="sec-facts">
                        <div class="sec-fact">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            <span><b>Только bcrypt-хэш.</b> Пароль нигде не хранится в открытом виде и не приходит обратно в браузер.</span>
                        </div>
                        <div class="sec-fact">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span><b>Проверка старого пароля.</b> Пять неверных попыток за 15 минут — и смена временно блокируется.</span>
                        </div>
                        <div class="sec-fact">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 12a8 8 0 1 1 2.4 5.7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M4 18v-5h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span><b>Сессия обновляется.</b> После смены пароля идентификатор сессии меняется, вход сохраняется.</span>
                        </div>
                        <div class="sec-fact">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                            <span><b>Роль проверяет сервер.</b> Скрытые кнопки ничего не решают: каждый админский запрос проверяется отдельно.</span>
                        </div>
                    </div>

                    <form class="cab-logout" method="post" action="login.php">
                        <input type="hidden" name="logout" value="1">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--block" type="submit">Выйти из аккаунта</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Подтверждение отмены заказа -->
<div class="cab-confirm" id="cancel-confirm" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
    <div class="card card--static cab-confirm__box">
        <h2 class="cab-confirm__title" id="cancel-title">Отменить заказ?</h2>
        <p class="cab-confirm__text" id="cancel-text">Заказ перейдёт в статус «отменён», товары вернутся на склад. Собрать его снова можно кнопкой «Повторить заказ».</p>
        <div class="cab-confirm__actions">
            <button class="btn btn--ghost" id="cancel-no" type="button">Оставить заказ</button>
            <button class="btn btn--primary" id="cancel-yes" type="button">Отменить заказ</button>
        </div>
    </div>
</div>

<script id="cabinet-config" type="application/json"><?= json_encode($cabinetConfig, $jsonFlags) ?></script>

<script>
// Кабинет: вкладки, профиль, аватар с прогрессом, заказы, избранное, пароль
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('cabinet-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var esc = J.escapeHtml;
    var money = J.formatPrice;
    var encoder = new TextEncoder();

    var STATUS = { new: 'Новый', processing: 'В обработке', shipped: 'Отправлен', done: 'Выполнен', cancelled: 'Отменён' };
    var STATUS_BADGE = { new: 'badge--info', processing: 'badge--warning', shipped: 'badge--info', done: 'badge--success', cancelled: 'badge--danger' };
    var DELIVERY = { courier: 'Курьер', pickup: 'Самовывоз' };
    var PAYMENT = { on_delivery: 'При получении', card: 'Картой (демо)' };
    var HEART = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    var FALLBACK = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/></svg>');

    function fmtDate(value) {
        if (!value) { return '—'; }
        var parts = String(value).replace('T', ' ').split(' ');
        var d = (parts[0] || '').split('-');
        var t = (parts[1] || '').slice(0, 5);
        if (d.length !== 3) { return String(value); }
        return d[2] + '.' + d[1] + '.' + d[0] + (t ? ' ' + t : '');
    }

    /* ---------- Тост с действием ---------- */
    function actionToast(message, type, actionText, onAction, duration) {
        var stack = document.getElementById('toast-stack');
        if (!stack) { J.toast(message, type); return; }
        duration = duration || 7000;

        var el = document.createElement('div');
        el.className = 'toast toast--' + type;
        el.setAttribute('role', 'status');
        el.style.setProperty('--toast-dur', duration + 'ms');

        var icon = document.createElement('span');
        icon.className = 'toast__icon';
        icon.innerHTML = '<svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.4" r="1" fill="currentColor"/></svg>';

        var text = document.createElement('div');
        text.className = 'toast__text';
        text.textContent = message;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'toast__action';
        btn.textContent = actionText;
        text.appendChild(document.createElement('br'));
        text.appendChild(btn);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('aria-label', 'Закрыть уведомление');
        close.textContent = '\u00D7';

        var bar = document.createElement('span');
        bar.className = 'toast__bar';

        el.append(icon, text, close, bar);
        stack.appendChild(el);
        while (stack.children.length > 4) { stack.firstElementChild.remove(); }

        var gone = false;
        var timer = setTimeout(dismiss, duration);
        function dismiss() {
            if (gone) { return; }
            gone = true;
            clearTimeout(timer);
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 320);
        }
        close.addEventListener('click', dismiss);
        btn.addEventListener('click', function () { dismiss(); onAction(); });
        el.addEventListener('mouseenter', function () { clearTimeout(timer); el.classList.add('is-paused'); });
        el.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 3200); el.classList.remove('is-paused'); });
    }

    /* ---------- Счётчики в шапке, hero и сайдбаре ---------- */
    function setCounter(kind, value) {
        var n = parseInt(value, 10) || 0;
        ['pill-' + kind, 'stat-' + kind, 'tabcount-' + kind].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.textContent = n; }
        });
        if (kind === 'orders') {
            var meta = document.getElementById('meta-orders');
            if (meta) { meta.textContent = n; }
        }
        if (kind === 'cart') { J.setCounts({ cart: n }); }
        if (kind === 'favorites') { J.setCounts({ favorites: n }); }
    }

    /* ============================================================
       Вкладки
       ============================================================ */
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.cab-tab'));
    var loaded = { orders: false, favorites: false };

    function activateTab(name, push) {
        tabs.forEach(function (tab) {
            var active = tab.dataset.tab === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            var panel = document.getElementById('panel-' + tab.dataset.tab);
            if (panel) { panel.hidden = !active; }
        });
        cfg.tab = name;
        if (push !== false) {
            history.replaceState(null, '', window.location.pathname + '?tab=' + encodeURIComponent(name));
        }
        if (name === 'orders' && !loaded.orders) { loaded.orders = true; loadOrders(); }
        if (name === 'favorites' && !loaded.favorites) { loaded.favorites = true; loadFavorites(); }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { activateTab(tab.dataset.tab); });
        tab.addEventListener('keydown', function (e) {
            var dir = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 0);
            if (!dir) { return; }
            e.preventDefault();
            var next = tabs[(index + dir + tabs.length) % tabs.length];
            next.focus();
            activateTab(next.dataset.tab);
        });
    });

    /* ============================================================
       Профиль
       ============================================================ */
    var profileForm = document.getElementById('profile-form');
    var profileSave = document.getElementById('profile-save');
    var initialProfile = Object.assign({}, cfg.user);

    function setFieldError(form, name, message) {
        var slot = form.querySelector('[data-error-for="' + name + '"]');
        var input = form.elements[name];
        if (input && input.classList) { input.classList.toggle('is-invalid', Boolean(message)); }
        if (slot) { slot.textContent = message || ''; slot.hidden = !message; }
    }

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

    var phoneInput = profileForm.elements.phone;
    phoneInput.addEventListener('input', function () {
        phoneInput.value = maskPhone(phoneInput.value);
        setFieldError(profileForm, 'phone', '');
    });
    if (phoneInput.value !== '') { phoneInput.value = maskPhone(phoneInput.value); }

    ['full_name', 'email', 'address'].forEach(function (name) {
        profileForm.elements[name].addEventListener('input', function () { setFieldError(profileForm, name, ''); });
    });

    function validateProfile() {
        var errors = {};
        var name = profileForm.elements.full_name.value.trim();
        var email = profileForm.elements.email.value.trim();
        var phoneDigits = phoneInput.value.replace(/\D+/g, '');
        var address = profileForm.elements.address.value.trim();

        if (!/^[\p{L}\s'.-]{2,150}$/u.test(name)) { errors.full_name = 'Имя: 2–150 символов, буквы, пробелы, дефис'; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 150) { errors.email = 'Введите корректный email'; }
        if (phoneInput.value !== '' && phoneDigits.length !== 11) { errors.phone = 'Телефон: 11 цифр или оставьте поле пустым'; }
        if (address.length > 255) { errors.address = 'Адрес: до 255 символов'; }

        ['full_name', 'email', 'phone', 'address'].forEach(function (k) { setFieldError(profileForm, k, errors[k] || ''); });
        return errors;
    }

    function paintName(fullName, initials) {
        var side = document.getElementById('side-name');
        if (side) { side.textContent = fullName; }
        var hero = document.getElementById('hero-name');
        if (hero) { hero.textContent = String(fullName).trim().split(/\s+/)[0] || fullName; }
        var chip = document.querySelector('.user-chip__name');
        if (chip) { chip.textContent = fullName; }
        ['cab-avatar-initials', 'preview-initials'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.textContent = initials; }
        });
        var headerInitials = document.querySelector('.user-chip .avatar--initials');
        if (headerInitials) { headerInitials.textContent = initials; }
    }

    profileForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var errors = validateProfile();
        if (Object.keys(errors).length) {
            J.toast('Проверьте выделенные поля', 'warning');
            profileForm.elements[Object.keys(errors)[0]].focus();
            return;
        }

        var original = profileSave.innerHTML;
        profileSave.disabled = true;
        profileSave.innerHTML = 'Сохраняем…';

        J.api('../api/update_profile.php', {
            method: 'POST',
            body: {
                full_name: profileForm.elements.full_name.value.trim(),
                email: profileForm.elements.email.value.trim(),
                phone: phoneInput.value.trim(),
                address: profileForm.elements.address.value.trim()
            }
        })
            .then(function (data) {
                profileSave.disabled = false;
                profileSave.innerHTML = original;
                cfg.user = Object.assign({}, cfg.user, data.user);
                initialProfile = Object.assign({}, cfg.user);
                phoneInput.value = data.user.phone ? maskPhone(data.user.phone) : '';
                paintName(data.user.full_name, data.initials);
                J.toast(data.message, 'success');
            })
            .catch(function (err) {
                profileSave.disabled = false;
                profileSave.innerHTML = original;
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                var fieldErrors = (err.data && err.data.errors) || {};
                Object.keys(fieldErrors).forEach(function (k) { setFieldError(profileForm, k, fieldErrors[k]); });
                J.toast(err.message, 'error');
            });
    });

    document.getElementById('profile-reset').addEventListener('click', function () {
        profileForm.elements.full_name.value = initialProfile.full_name || '';
        profileForm.elements.email.value = initialProfile.email || '';
        phoneInput.value = initialProfile.phone ? maskPhone(initialProfile.phone) : '';
        profileForm.elements.address.value = initialProfile.address || '';
        ['full_name', 'email', 'phone', 'address'].forEach(function (k) { setFieldError(profileForm, k, ''); });
        J.toast('Изменения отменены', 'info', 2200);
    });

    /* ============================================================
       Аватар
       ============================================================ */
    var avatarInput = document.getElementById('avatar-input');
    var avatarDrop = document.getElementById('avatar-drop');
    var avatarPick = document.getElementById('avatar-pick');
    var avatarRemove = document.getElementById('avatar-remove');
    var avatarProgress = document.getElementById('avatar-progress');
    var avatarBar = document.getElementById('avatar-bar');
    var avatarError = document.getElementById('avatar-error');
    var previewImg = document.getElementById('preview-img');
    var previewInitials = document.getElementById('preview-initials');
    var previewAvatar = document.getElementById('preview-avatar');
    var cabAvatar = document.getElementById('cab-avatar');
    var cabAvatarImg = document.getElementById('cab-avatar-img');
    var cabInitials = document.getElementById('cab-avatar-initials');
    var objectUrl = null;
    var uploading = false;

    function showAvatarError(message) {
        avatarError.textContent = message || '';
        avatarError.hidden = !message;
    }

    function paintAvatar(url, initials) {
        [previewImg, cabAvatarImg].forEach(function (img) {
            if (!img) { return; }
            if (url) { img.src = url; img.hidden = false; }
            else { img.removeAttribute('src'); img.hidden = true; }
        });
        [previewInitials, cabInitials].forEach(function (el) {
            if (!el) { return; }
            el.textContent = initials || cfg.user.initials;
            el.hidden = Boolean(url);
        });
        [previewAvatar, cabAvatar].forEach(function (box) {
            if (box) { box.classList.toggle('avatar--initials', !url); }
        });

        // Аватар в шапке
        var headerAvatar = document.querySelector('.user-chip .avatar');
        if (headerAvatar) {
            headerAvatar.innerHTML = '';
            if (url) {
                var img = document.createElement('img');
                img.src = url;
                img.alt = 'Аватар профиля';
                headerAvatar.appendChild(img);
                headerAvatar.classList.remove('avatar--initials');
            } else {
                headerAvatar.textContent = initials || cfg.user.initials;
                headerAvatar.classList.add('avatar--initials');
            }
        }

        avatarRemove.disabled = !url;
        if (!reduced) {
            [cabAvatar, previewAvatar].forEach(function (box) {
                if (!box) { return; }
                box.classList.remove('is-updated');
                void box.offsetWidth;
                box.classList.add('is-updated');
                setTimeout(function () { box.classList.remove('is-updated'); }, 900);
            });
        }
    }

    function validateAvatarFile(file) {
        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (allowed.indexOf(file.type) === -1) { return 'Только JPG, PNG или WebP'; }
        if (file.size > cfg.maxAvatarBytes) { return 'Файл больше 2 МБ'; }
        if (file.size <= 0) { return 'Пустой файл'; }
        return '';
    }

    function uploadAvatar(file) {
        if (uploading) { return; }
        var problem = validateAvatarFile(file);
        if (problem) { showAvatarError(problem); J.toast(problem, 'error'); return; }
        showAvatarError('');

        // Мгновенное превью, пока файл летит на сервер
        if (objectUrl) { URL.revokeObjectURL(objectUrl); }
        objectUrl = URL.createObjectURL(file);
        previewImg.src = objectUrl;
        previewImg.hidden = false;
        previewInitials.hidden = true;
        previewAvatar.classList.remove('avatar--initials');

        uploading = true;
        avatarPick.disabled = true;
        avatarProgress.classList.add('is-visible');
        avatarBar.style.width = '0%';

        var form = new FormData();
        form.append('avatar', file);
        form.append('csrf', cfg.csrf);

        // XHR нужен ради реального прогресса загрузки
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '../api/upload_avatar.php', true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', cfg.csrf);
        xhr.withCredentials = true;

        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) { avatarBar.style.width = Math.round(e.loaded / e.total * 100) + '%'; }
        });

        xhr.addEventListener('load', function () {
            uploading = false;
            avatarPick.disabled = false;
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (err) { /* пустой ответ */ }

            if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
                avatarBar.style.width = '100%';
                setTimeout(function () { avatarProgress.classList.remove('is-visible'); avatarBar.style.width = '0%'; }, 500);
                if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
                cfg.user.avatar_path = data.avatar_path;
                cfg.user.avatar_url = data.url;
                paintAvatar(data.url, cfg.user.initials);
                J.toast(data.message, 'success');
            } else {
                avatarProgress.classList.remove('is-visible');
                avatarBar.style.width = '0%';
                if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
                paintAvatar(cfg.user.avatar_url, cfg.user.initials);
                if (xhr.status === 401) { window.location.href = J.config.loginUrl; return; }
                var message = data.error || 'Не удалось загрузить аватар';
                showAvatarError(message);
                J.toast(message, 'error');
            }
        });

        xhr.addEventListener('error', function () {
            uploading = false;
            avatarPick.disabled = false;
            avatarProgress.classList.remove('is-visible');
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            paintAvatar(cfg.user.avatar_url, cfg.user.initials);
            J.toast('Сеть не ответила, повторите загрузку', 'error');
        });

        xhr.send(form);
    }

    avatarPick.addEventListener('click', function () { avatarInput.click(); });
    avatarDrop.addEventListener('click', function () { avatarInput.click(); });
    avatarDrop.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); avatarInput.click(); }
    });
    avatarInput.addEventListener('change', function () {
        if (avatarInput.files && avatarInput.files[0]) { uploadAvatar(avatarInput.files[0]); }
        avatarInput.value = '';
    });
    ['dragenter', 'dragover'].forEach(function (type) {
        avatarDrop.addEventListener(type, function (e) { e.preventDefault(); avatarDrop.classList.add('is-drag'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        avatarDrop.addEventListener(type, function (e) { e.preventDefault(); avatarDrop.classList.remove('is-drag'); });
    });
    avatarDrop.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files[0]) { uploadAvatar(files[0]); }
    });

    avatarRemove.addEventListener('click', function () {
        if (uploading || avatarRemove.disabled) { return; }
        avatarRemove.disabled = true;
        J.api('../api/upload_avatar.php', { method: 'POST', body: { action: 'remove' } })
            .then(function (data) {
                cfg.user.avatar_path = null;
                cfg.user.avatar_url = null;
                cfg.user.initials = data.initials || cfg.user.initials;
                paintAvatar(null, cfg.user.initials);
                showAvatarError('');
                J.toast(data.message, 'info');
            })
            .catch(function (err) {
                avatarRemove.disabled = false;
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                J.toast(err.message, 'error');
            });
    });

    /* ============================================================
       Заказы
       ============================================================ */
    var ordersList = document.getElementById('orders-list');
    var ordersPagination = document.getElementById('orders-pagination');
    var statusChips = document.getElementById('status-chips');
    var ordersQ = document.getElementById('orders-q');
    var ordersState = { status: 'all', q: '', page: 1 };
    var ordersTimer = null;
    var cancelTarget = null;

    function orderSkeletons(n) {
        var html = '';
        for (var i = 0; i < n; i++) {
            html += '<div class="card card--static osk" aria-hidden="true">' +
                '<div class="sk sk--line sk--short"></div><div class="sk sk--line"></div><div class="sk sk--tall"></div></div>';
        }
        return html;
    }

    function renderStatusChips(counts, allCount) {
        var items = [{ key: 'all', label: 'Все', count: allCount }];
        Object.keys(STATUS).forEach(function (key) {
            items.push({ key: key, label: STATUS[key], count: counts[key] || 0 });
        });
        statusChips.innerHTML = items.map(function (item) {
            return '<button class="status-chip' + (ordersState.status === item.key ? ' is-active' : '') + '" type="button" data-status="' + item.key + '">' +
                esc(item.label) + '<span class="status-chip__count">' + item.count + '</span></button>';
        }).join('');
    }

    function paginationHTML(page, pages, attr) {
        if (pages <= 1) { return ''; }
        var html = '<button class="page-link' + (page <= 1 ? ' is-disabled' : '') + '" type="button" ' + attr + '="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + ' aria-label="Предыдущая страница">‹</button>';
        var slots = [];
        for (var i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - page) <= 1) { slots.push(i); }
        }
        var prev = 0;
        slots.forEach(function (n) {
            if (prev && n - prev > 1) { html += '<span class="page-ellipsis">…</span>'; }
            html += '<button class="page-link' + (n === page ? ' is-active' : '') + '" type="button" ' + attr + '="' + n + '"' + (n === page ? ' aria-current="page"' : '') + '>' + n + '</button>';
            prev = n;
        });
        html += '<button class="page-link' + (page >= pages ? ' is-disabled' : '') + '" type="button" ' + attr + '="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + ' aria-label="Следующая страница">›</button>';
        return html;
    }

    function orderHTML(order, index) {
        var items = order.items || [];
        var rows = items.map(function (it) {
            var name = it.url
                ? '<a href="' + esc(it.url) + '">' + esc(it.name) + '</a>'
                : esc(it.name) + ' <span class="badge badge--danger">снят</span>';
            return '<div class="oitem">' +
                '<span class="oitem__media"><img src="../' + esc(it.image_path) + '" alt="' + esc(it.name) + '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + FALLBACK + '\'"></span>' +
                '<span><span class="oitem__name">' + name + '</span>' +
                    '<span class="oitem__meta">' + it.quantity + ' × ' + esc(money(it.price)) + ' · ' + esc(it.model) + '</span></span>' +
                '<span class="oitem__sum">' + esc(money(it.line_total)) + '</span>' +
            '</div>';
        }).join('');

        var actions = '';
        if (order.can_cancel) {
            actions += '<button class="btn btn--ghost btn--sm" type="button" data-cancel="' + order.id + '" data-number="' + esc(order.order_number) + '">Отменить заказ</button>';
        }
        actions += '<button class="btn btn--primary btn--sm" type="button" data-repeat="' + order.id + '">Повторить заказ</button>';

        return '<article class="card ocard' + (order.status === 'cancelled' ? ' is-cancelled' : '') + '" data-order="' + order.id + '" style="--i:' + index + '">' +
            '<div class="ocard__head">' +
                '<span class="ocard__id"><span class="ocard__num">№ ' + esc(order.order_number) + '</span>' +
                    '<span class="ocard__date">' + esc(fmtDate(order.created_at)) + '</span></span>' +
                '<span class="badge ' + (STATUS_BADGE[order.status] || 'badge--info') + '" data-badge>' + esc(STATUS[order.status] || order.status) + '</span>' +
                '<span class="ocard__total">' + esc(money(order.total)) + '</span>' +
                '<button class="ocard__toggle" type="button" data-toggle-order="' + order.id + '" aria-expanded="false">' +
                    'Состав (' + items.length + ')' +
                    '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                '</button>' +
            '</div>' +
            '<div class="ocard__meta">' +
                '<span class="ocard__tag">' + esc(DELIVERY[order.delivery] || order.delivery) + '</span>' +
                '<span class="ocard__tag">' + esc(PAYMENT[order.payment] || order.payment) + '</span>' +
                '<span class="ocard__tag">' + esc(order.phone) + '</span>' +
            '</div>' +
            '<div class="ocard__body" data-body>' +
                '<div class="ocard__inner">' +
                    '<div class="ocard__items">' + rows + '</div>' +
                    (order.address ? '<div class="ocard__address"><b>Адрес:</b> ' + esc(order.address) + '</div>' : '') +
                    (order.comment ? '<div class="ocard__comment"><b>Комментарий:</b> ' + esc(order.comment) + '</div>' : '') +
                    '<div class="ocard__actions">' + actions + '</div>' +
                '</div>' +
            '</div>' +
        '</article>';
    }

    function loadOrders(silent) {
        if (!silent) { ordersList.innerHTML = orderSkeletons(3); ordersPagination.innerHTML = ''; }
        var params = new URLSearchParams();
        params.set('status', ordersState.status);
        if (ordersState.q) { params.set('q', ordersState.q); }
        params.set('page', ordersState.page);

        J.api('../api/orders_list.php?' + params.toString())
            .then(function (data) {
                ordersState.page = data.page;
                renderStatusChips(data.status_counts, data.all_count);
                setCounter('orders', data.all_count);
                ordersList.innerHTML = data.orders.length
                    ? data.orders.map(orderHTML).join('')
                    : '<div class="card card--static cab-empty">' +
                        '<svg class="cab-empty__art cab-empty__needle" viewBox="0 0 24 24" width="54" height="54" aria-hidden="true"><path d="M12 3v13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 16v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><ellipse cx="12" cy="6.5" rx="2" ry="3.4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>' +
                        '<h3 class="empty-state__title">Заказов пока нет</h3>' +
                        '<p class="empty-state__text">Соберите корзину и оформите первый заказ — он появится здесь вместе с составом и статусом.</p>' +
                        '<div class="cab-empty__actions"><a class="btn btn--primary" href="' + esc(cfg.urls.catalog) + '">Открыть каталог</a>' +
                        '<a class="btn btn--ghost" href="' + esc(cfg.urls.cart) + '">В корзину</a></div></div>';
                ordersPagination.innerHTML = paginationHTML(data.page, data.pages, 'data-orders-page');
            })
            .catch(function (err) {
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                ordersList.innerHTML = '<div class="card card--static cab-empty"><h3 class="empty-state__title">Заказы не загрузились</h3>' +
                    '<p class="empty-state__text">' + esc(err.message) + '</p>' +
                    '<button class="btn btn--primary" type="button" data-orders-retry>Повторить</button></div>';
            });
    }

    statusChips.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-status]');
        if (!chip) { return; }
        ordersState.status = chip.dataset.status;
        ordersState.page = 1;
        loadOrders();
    });

    ordersQ.addEventListener('input', function () {
        clearTimeout(ordersTimer);
        ordersTimer = setTimeout(function () {
            ordersState.q = ordersQ.value.trim();
            ordersState.page = 1;
            loadOrders();
        }, 350);
    });

    document.getElementById('orders-refresh').addEventListener('click', function () { loadOrders(); });

    ordersPagination.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-orders-page]');
        if (!btn || btn.disabled) { return; }
        ordersState.page = parseInt(btn.dataset.ordersPage, 10);
        loadOrders();
    });

    ordersList.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-toggle-order]');
        if (toggle) {
            var card = toggle.closest('.ocard');
            var body = card.querySelector('[data-body]');
            var open = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
            body.style.maxHeight = open ? '0px' : body.scrollHeight + 'px';
            return;
        }
        var cancel = e.target.closest('[data-cancel]');
        if (cancel) { openCancel(parseInt(cancel.dataset.cancel, 10), cancel.dataset.number); return; }

        var repeat = e.target.closest('[data-repeat]');
        if (repeat) { repeatOrder(parseInt(repeat.dataset.repeat, 10), repeat); return; }

        if (e.target.closest('[data-orders-retry]')) { loadOrders(); }
    });

    /* ---------- Отмена заказа ---------- */
    var confirmBox = document.getElementById('cancel-confirm');
    var cancelYes = document.getElementById('cancel-yes');
    var lastFocus = null;

    function openCancel(id, number) {
        cancelTarget = id;
        lastFocus = document.activeElement;
        document.getElementById('cancel-text').textContent = 'Заказ №' + number + ' перейдёт в статус «отменён», товары вернутся на склад. Собрать его снова можно кнопкой «Повторить заказ».';
        confirmBox.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        document.getElementById('cancel-no').focus();
    }
    function closeCancel() {
        confirmBox.classList.remove('is-open');
        document.body.style.overflow = '';
        cancelTarget = null;
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }
    document.getElementById('cancel-no').addEventListener('click', closeCancel);
    confirmBox.addEventListener('click', function (e) { if (e.target === confirmBox) { closeCancel(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && confirmBox.classList.contains('is-open')) { closeCancel(); }
    });

    cancelYes.addEventListener('click', function () {
        if (!cancelTarget) { closeCancel(); return; }
        var id = cancelTarget;
        cancelYes.disabled = true;
        cancelYes.textContent = 'Отменяем…';

        J.api('../api/cancel_order.php', { method: 'POST', body: { order_id: id } })
            .then(function (data) {
                cancelYes.disabled = false;
                cancelYes.textContent = 'Отменить заказ';
                closeCancel();
                setCounter('cart', data.cart_count);
                J.toast('Заказ отменён, товары вернулись на склад', 'info');
                loadOrders(true);
            })
            .catch(function (err) {
                cancelYes.disabled = false;
                cancelYes.textContent = 'Отменить заказ';
                closeCancel();
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                J.toast(err.message, 'error');
                loadOrders(true);
            });
    });

    /* ---------- Повтор заказа ---------- */
    function repeatOrder(orderId, btn) {
        var card = ordersList.querySelector('[data-order="' + orderId + '"]');
        if (!card) { return; }
        var rows = Array.prototype.slice.call(card.querySelectorAll('.oitem'));
        if (!rows.length) { return; }

        // Состав берём из уже отрендеренных данных заказа
        var order = null;
        J.api('../api/orders_list.php?status=all&page=1&per_page=1&q=' + encodeURIComponent(card.querySelector('.ocard__num').textContent.replace('№ ', '').trim()))
            .then(function (data) {
                order = (data.orders || [])[0];
                if (!order || !order.items.length) { throw new Error('Состав заказа не найден'); }

                var original = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = 'Собираем…';

                var chain = Promise.resolve();
                var ok = 0, skipped = 0;
                order.items.forEach(function (item) {
                    chain = chain.then(function () {
                        if (!item.is_active) { skipped++; return; }
                        return J.api('../api/cart_add.php', {
                            method: 'POST',
                            body: { product_id: item.product_id, quantity: item.quantity }
                        }).then(function (res) {
                            ok++;
                            if (res.capped) { skipped++; }
                            setCounter('cart', res.cart_count);
                        }).catch(function () { skipped++; });
                    });
                });

                return chain.then(function () {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    if (ok) {
                        actionToast(
                            skipped ? 'Собрали не всё: часть остатков изменилась' : 'Позиции заказа снова в корзине',
                            skipped ? 'warning' : 'success',
                            'Открыть корзину',
                            function () { window.location.href = cfg.urls.cart; }
                        );
                    } else {
                        J.toast('Ни одну позицию добавить не удалось', 'error');
                    }
                });
            })
            .catch(function (err) {
                btn.disabled = false;
                J.toast(err.message || 'Не удалось повторить заказ', 'error');
            });
    }

    /* ============================================================
       Избранное
       ============================================================ */
    var favGrid = document.getElementById('fav-grid');
    var favPagination = document.getElementById('fav-pagination');
    var favQ = document.getElementById('fav-q');
    var favSort = document.getElementById('fav-sort');
    var favAllBtn = document.getElementById('fav-all-cart');
    var favState = { q: '', sort: 'new', page: 1, items: [] };
    var favTimer = null;

    function favSkeletons(n) {
        var html = '';
        for (var i = 0; i < n; i++) {
            html += '<div class="card card--static fsk" aria-hidden="true"><div class="sk sk--media"></div>' +
                '<div class="sk sk--line" style="margin-bottom:8px"></div><div class="sk sk--line sk--short" style="margin-bottom:12px"></div><div class="sk sk--btn"></div></div>';
        }
        return html;
    }

    function favCardHTML(item, index) {
        var name = item.url
            ? '<a href="' + esc(item.url) + '">' + esc(item.name) + '</a>'
            : esc(item.name);
        var buy = item.can_buy
            ? '<button class="btn btn--primary btn--sm btn--block fcard__add" type="button" data-fav-add="' + item.id + '">В корзину</button>'
            : '<button class="btn btn--primary btn--sm btn--block" type="button" disabled>Нет в наличии</button>';

        return '<article class="card fcard" data-fav-card="' + item.id + '" style="--i:' + index + '">' +
            '<div class="fcard__media">' +
                (item.url
                    ? '<a href="' + esc(item.url) + '" tabindex="-1" aria-hidden="true"><img src="../' + esc(item.image_path) + '" alt="' + esc(item.name) + '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + FALLBACK + '\'"></a>'
                    : '<img src="../' + esc(item.image_path) + '" alt="' + esc(item.name) + '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + FALLBACK + '\'">') +
                '<span class="fcard__brand">' + esc(item.brand_name) + '</span>' +
                '<span class="fcard__type">' + esc(item.type_label) + '</span>' +
                (item.discount > 0 ? '<span class="fcard__sale">−' + item.discount + '%</span>' : '') +
                '<button class="fcard__fav" type="button" data-fav-remove="' + item.id + '" aria-label="Убрать из избранного" title="Убрать из избранного">' + HEART + '</button>' +
            '</div>' +
            '<h3 class="fcard__name">' + name + '</h3>' +
            '<span class="fcard__model">' + esc(item.model) + '</span>' +
            '<div class="fcard__price"><b>' + esc(money(item.price)) + '</b>' +
                (item.old_price && item.old_price > item.price ? '<s>' + esc(money(item.old_price)) + '</s>' : '') + '</div>' +
            '<div class="fcard__foot">' +
                '<span class="stock stock--' + esc(item.stock_status) + '">' + esc(item.stock_text) + '</span>' +
                (item.in_cart > 0 ? '<span class="fcard__incart">в корзине: ' + item.in_cart + ' шт.</span>' : '') +
                buy +
            '</div>' +
        '</article>';
    }

    function loadFavorites(silent) {
        if (!silent) { favGrid.innerHTML = favSkeletons(3); favPagination.innerHTML = ''; }
        var params = new URLSearchParams();
        if (favState.q) { params.set('q', favState.q); }
        params.set('sort', favState.sort);
        params.set('page', favState.page);

        J.api('../api/favorites_list.php?' + params.toString())
            .then(function (data) {
                favState.page = data.page;
                favState.items = data.items;
                setCounter('favorites', data.favorites_count);
                setCounter('cart', data.cart_count);
                favAllBtn.disabled = !data.items.some(function (it) { return it.can_buy; });

                favGrid.innerHTML = data.items.length
                    ? data.items.map(favCardHTML).join('')
                    : '<div class="card card--static cab-empty" style="grid-column:1/-1">' +
                        '<svg class="cab-empty__art" viewBox="0 0 24 24" width="54" height="54" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>' +
                        '<h3 class="empty-state__title">Избранное пустует</h3>' +
                        '<p class="empty-state__text">Отмечайте машинки сердечком в каталоге — они соберутся здесь и будут подниматься наверх списка.</p>' +
                        '<div class="cab-empty__actions"><a class="btn btn--primary" href="' + esc(cfg.urls.catalog) + '">В каталог</a></div></div>';
                favPagination.innerHTML = paginationHTML(data.page, data.pages, 'data-fav-page');
            })
            .catch(function (err) {
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                favGrid.innerHTML = '<div class="card card--static cab-empty" style="grid-column:1/-1"><h3 class="empty-state__title">Избранное не загрузилось</h3>' +
                    '<p class="empty-state__text">' + esc(err.message) + '</p><button class="btn btn--primary" type="button" data-fav-retry>Повторить</button></div>';
            });
    }

    favQ.addEventListener('input', function () {
        clearTimeout(favTimer);
        favTimer = setTimeout(function () {
            favState.q = favQ.value.trim();
            favState.page = 1;
            loadFavorites();
        }, 350);
    });
    favSort.addEventListener('change', function () {
        favState.sort = favSort.value;
        favState.page = 1;
        loadFavorites();
    });
    favPagination.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-fav-page]');
        if (!btn || btn.disabled) { return; }
        favState.page = parseInt(btn.dataset.favPage, 10);
        loadFavorites();
    });

    favGrid.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-fav-remove]');
        if (remove) { removeFavorite(parseInt(remove.dataset.favRemove, 10), remove); return; }

        var add = e.target.closest('[data-fav-add]');
        if (add) { addToCart(parseInt(add.dataset.favAdd, 10), 1, add); return; }

        if (e.target.closest('[data-fav-retry]')) { loadFavorites(); }
    });

    function removeFavorite(id, btn) {
        if (btn.classList.contains('is-busy')) { return; }
        btn.classList.add('is-busy');
        var card = favGrid.querySelector('[data-fav-card="' + id + '"]');
        var snapshot = favState.items.filter(function (it) { return it.id === id; })[0];

        J.api('../api/favorite_toggle.php', { method: 'POST', body: { product_id: id } })
            .then(function (data) {
                setCounter('favorites', data.favorites_count);
                if (card && !reduced) {
                    card.classList.add('is-leaving');
                    setTimeout(function () { loadFavorites(true); }, 300);
                } else {
                    loadFavorites(true);
                }
                actionToast('Убрали из избранного', 'info', 'Вернуть', function () {
                    J.api('../api/favorite_toggle.php', { method: 'POST', body: { product_id: id } })
                        .then(function (back) {
                            setCounter('favorites', back.favorites_count);
                            loadFavorites(true);
                            J.toast('Вернули в избранное', 'success', 2400);
                        })
                        .catch(function (err) { J.toast(err.message, 'error'); });
                });
            })
            .catch(function (err) {
                btn.classList.remove('is-busy');
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                J.toast(err.message, 'error');
            });
    }

    function addToCart(id, quantity, btn) {
        if (btn.dataset.busy === '1' || btn.disabled) { return Promise.resolve(); }
        var original = btn.innerHTML;
        btn.dataset.busy = '1';
        btn.disabled = true;
        btn.innerHTML = 'Добавляем…';

        return J.api('../api/cart_add.php', { method: 'POST', body: { product_id: id, quantity: quantity } })
            .then(function (data) {
                setCounter('cart', data.cart_count);
                btn.innerHTML = '✓ В корзине';
                btn.classList.add('is-added');
                setTimeout(function () {
                    btn.innerHTML = original;
                    btn.classList.remove('is-added');
                    btn.disabled = false;
                    btn.dataset.busy = '';
                }, 1600);
                J.toast(data.capped ? 'Добавили не всё — остаток ' + data.item_quantity + ' шт.' : 'Товар в корзине', data.capped ? 'warning' : 'success', 2600);
                return data;
            })
            .catch(function (err) {
                btn.innerHTML = original;
                btn.disabled = false;
                btn.dataset.busy = '';
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                J.toast(err.message, 'error');
            });
    }

    favAllBtn.addEventListener('click', function () {
        var buyable = favState.items.filter(function (it) { return it.can_buy; });
        if (!buyable.length) { J.toast('Нет доступных позиций', 'warning'); return; }

        favAllBtn.disabled = true;
        var original = favAllBtn.innerHTML;
        favAllBtn.innerHTML = 'Собираем…';

        var chain = Promise.resolve();
        var ok = 0, skipped = 0;
        buyable.forEach(function (item) {
            chain = chain.then(function () {
                return J.api('../api/cart_add.php', { method: 'POST', body: { product_id: item.id, quantity: 1 } })
                    .then(function (data) { ok++; if (data.capped) { skipped++; } setCounter('cart', data.cart_count); })
                    .catch(function () { skipped++; });
            });
        });
        chain.then(function () {
            favAllBtn.disabled = false;
            favAllBtn.innerHTML = original;
            loadFavorites(true);
            if (ok) {
                actionToast(
                    skipped ? 'Добавили ' + ok + ' поз., часть остатков изменилась' : 'Избранное в корзине: ' + ok + ' поз.',
                    skipped ? 'warning' : 'success',
                    'Открыть корзину',
                    function () { window.location.href = cfg.urls.cart; }
                );
            } else {
                J.toast('Добавить не удалось', 'error');
            }
        });
    });

    /* ============================================================
       Безопасность
       ============================================================ */
    var passForm = document.getElementById('password-form');
    var passSave = document.getElementById('password-save');
    var newPass = passForm.elements.new_password;
    var strengthBar = document.getElementById('strength-bar');
    var strengthText = document.getElementById('strength-text');

    document.querySelectorAll('.pass-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = passForm.elements[btn.dataset.toggle];
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
            btn.style.color = show ? 'var(--wine-700)' : '';
            input.focus();
        });
    });

    function scorePassword(value) {
        var bytes = encoder.encode(value).length;
        var checks = {
            length: value.length >= 6,
            letter: /\p{L}/u.test(value),
            digit: /\d/.test(value),
            bytes: bytes > 0 && bytes <= 72
        };
        var score = 0;
        if (checks.length) { score++; }
        if (checks.letter && checks.digit) { score++; }
        if (value.length >= 10) { score++; }
        if (/[^{}\p{L}\d]/u.test(value) || (/\p{Lu}/u.test(value) && /\p{Ll}/u.test(value))) { score++; }
        if (!checks.bytes) { score = Math.min(score, 1); }
        return { checks: checks, score: value === '' ? 0 : Math.max(1, score) };
    }

    function paintStrength() {
        var result = scorePassword(newPass.value);
        Object.keys(result.checks).forEach(function (key) {
            var row = document.querySelector('[data-req="' + key + '"]');
            if (row) { row.classList.toggle('is-ok', result.checks[key]); }
        });
        var levels = [
            { width: 0, color: 'var(--line)', text: '—' },
            { width: 25, color: 'var(--danger)', text: 'Слабый' },
            { width: 50, color: 'var(--warning)', text: 'Средний' },
            { width: 75, color: 'var(--info)', text: 'Хороший' },
            { width: 100, color: 'var(--success)', text: 'Отличный' }
        ];
        var level = levels[result.score];
        strengthBar.style.width = level.width + '%';
        strengthBar.style.background = level.color;
        strengthText.textContent = level.text;
    }

    ['old_password', 'new_password', 'repeat_password'].forEach(function (name) {
        passForm.elements[name].addEventListener('input', function () {
            setFieldError(passForm, name, '');
            if (name === 'new_password') { paintStrength(); }
        });
    });
    paintStrength();

    passForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var old = passForm.elements.old_password.value;
        var fresh = newPass.value;
        var repeat = passForm.elements.repeat_password.value;
        var result = scorePassword(fresh);
        var errors = {};

        if (old === '') { errors.old_password = 'Введите текущий пароль'; }
        if (!result.checks.length) { errors.new_password = 'Минимум 6 символов'; }
        else if (!result.checks.bytes) { errors.new_password = 'Слишком длинный (до 72 байт)'; }
        if (repeat !== fresh || repeat === '') { errors.repeat_password = 'Пароли не совпадают'; }

        ['old_password', 'new_password', 'repeat_password'].forEach(function (k) { setFieldError(passForm, k, errors[k] || ''); });
        if (Object.keys(errors).length) {
            J.toast('Проверьте выделенные поля', 'warning');
            passForm.elements[Object.keys(errors)[0]].focus();
            return;
        }

        var original = passSave.innerHTML;
        passSave.disabled = true;
        passSave.innerHTML = 'Обновляем…';

        J.api('../api/change_password.php', {
            method: 'POST',
            body: { old_password: old, new_password: fresh, repeat_password: repeat }
        })
            .then(function (data) {
                passSave.disabled = false;
                passSave.innerHTML = original;
                passForm.reset();
                paintStrength();
                document.querySelectorAll('.pass-toggle').forEach(function (btn) {
                    var input = passForm.elements[btn.dataset.toggle];
                    if (input) { input.type = 'password'; }
                    btn.style.color = '';
                });
                J.toast(data.message, 'success');
            })
            .catch(function (err) {
                passSave.disabled = false;
                passSave.innerHTML = original;
                if (err.status === 401) { window.location.href = J.config.loginUrl; return; }
                var fieldErrors = (err.data && err.data.errors) || {};
                Object.keys(fieldErrors).forEach(function (k) { setFieldError(passForm, k, fieldErrors[k]); });
                J.toast(err.message, 'error');
            });
    });

    document.getElementById('password-clear').addEventListener('click', function () {
        passForm.reset();
        ['old_password', 'new_password', 'repeat_password'].forEach(function (k) { setFieldError(passForm, k, ''); });
        paintStrength();
    });

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

    /* ---------- Старт ---------- */
    activateTab(cfg.tab, false);
});
</script>

<?php render_footer(); ?>
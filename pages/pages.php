<?php
/**
 * pages/admin.php — служебная панель «Стежок».
 * Вкладки: дашборд, товары, категории, бренды, заказы, пользователи,
 * обращения, просмотр базы. Все действия через fetch к api/admin/*.php,
 * роль проверяется на сервере в каждом эндпоинте.
 */

require_once __DIR__ . '/../config/helpers.php';

// Гард страницы: гость → вход, не админ → главная с тостом
require_admin();

$user = current_user();

$stmt = $pdo->prepare('SELECT id, name FROM brands ORDER BY sort_order ASC, name ASC');
$stmt->execute();
$brands = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, name FROM categories ORDER BY sort_order ASC, name ASC');
$stmt->execute();
$categories = $stmt->fetchAll();

$adminConfig = [
    'selfId' => (int)$user['id'],
    'csrf' => csrf_token(),
    'brands' => array_map(static fn(array $b): array => ['id' => (int)$b['id'], 'name' => $b['name']], $brands),
    'categories' => array_map(static fn(array $c): array => ['id' => (int)$c['id'], 'name' => $c['name']], $categories),
    'statusLabels' => [
        'new' => 'Новый', 'processing' => 'В обработке', 'shipped' => 'Отправлен',
        'done' => 'Выполнен', 'cancelled' => 'Отменён',
    ],
    'deliveryLabels' => ['courier' => 'Курьер', 'pickup' => 'Самовывоз'],
    'paymentLabels' => ['on_delivery' => 'При получении', 'card' => 'Картой'],
    'maxPhotoBytes' => 2 * 1024 * 1024,
    'placeholder' => 'assets/img/products/placeholder.svg',
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Админка', [
    'description' => 'Служебная панель магазина Janome.',
    'body_class' => 'page-admin',
]);
?>

<style>
/* ============================================================
   Админка «Стежок»: тёмный пульт вкладок, рабочие таблицы,
   редактор-ящик, просмотр базы только на чтение.
   ============================================================ */

.thread-progress { position: fixed; top: 0; left: 0; z-index: 60; height: 3px; width: 100%; transform: scaleX(0); transform-origin: left; background: linear-gradient(90deg, var(--wine-900), var(--wine-500) 45%, var(--wine-300)); }

/* ---------- Шапка режима ---------- */
.ad-hero { padding: 28px 0 22px; background-color: var(--wine-900); background-image: var(--weave-light); color: var(--white); }
.ad-hero__row { display: flex; align-items: center; justify-content: space-between; gap: 22px; flex-wrap: wrap; }
.ad-hero__title { color: var(--white); font-size: clamp(22px, 2.6vw, 30px); margin: 6px 0 6px; }
.ad-hero__lead { color: rgba(255, 255, 255, .68); font-size: 13.5px; margin: 0; max-width: 62ch; }
.ad-hero .label { color: var(--wine-300); }
.ad-hero__who { display: flex; align-items: center; gap: 12px; padding: 11px 15px; background: rgba(255, 255, 255, .09); border: 1px dashed rgba(255, 255, 255, .28); border-radius: var(--radius); }
.ad-hero__who b { display: block; font: 700 14px/1.3 var(--font-sans); color: var(--white); }
.ad-hero__who span { display: block; font-size: 11.5px; color: rgba(255, 255, 255, .6); }

/* ---------- Раскладка ---------- */
.ad-layout { display: grid; grid-template-columns: 250px 1fr; gap: 24px; align-items: start; padding: 24px 0 60px; }
.ad-rail { position: sticky; top: calc(var(--header-h) + 20px); display: grid; gap: 14px; }
.ad-tabs { padding: 8px; display: grid; gap: 3px; background-color: var(--wine-900); background-image: var(--weave-light); border-color: var(--wine-900); }
.ad-tabs::before { border-color: rgba(255, 255, 255, .18); }
.ad-tab {
    position: relative; display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 11px 12px; background: transparent; border: 1px solid transparent; border-radius: var(--radius);
    cursor: pointer; text-align: left; font: 600 13.5px/1.2 var(--font-sans); color: rgba(255, 255, 255, .78);
    transition: background-color .2s, color .2s, transform .16s;
}
.ad-tab:hover { background: rgba(255, 255, 255, .09); color: var(--white); transform: translateX(2px); }
.ad-tab.is-active { background: var(--wine-500); color: var(--white); box-shadow: var(--shadow-btn); }
.ad-tab__icon { flex: none; display: grid; place-items: center; }
.ad-tab__label { flex: 1; }
.ad-tab__count { font: 700 10.5px/1 var(--font-sans); padding: 4px 7px; border-radius: 999px; background: rgba(255, 255, 255, .16); color: var(--white); }
.ad-tab__count.is-alert { background: var(--wine-300); }
.ad-rail__note { padding: 14px 15px; font-size: 11.5px; color: var(--muted); }
.ad-rail__note b { display: block; color: var(--ink); font-size: 12.5px; margin-bottom: 5px; }

.ad-main { min-width: 0; display: grid; gap: 18px; }
.ad-panel { display: grid; gap: 16px; animation: ad-in .35s cubic-bezier(.22,.61,.36,1) both; }
.ad-panel[hidden] { display: none; }
@keyframes ad-in { from { opacity: 0; transform: translateY(12px); } }
.ad-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.ad-head__title { font: 700 clamp(20px, 2.2vw, 25px)/1.2 var(--font-serif); color: var(--wine-900); margin: 0 0 3px; }
.ad-head__hint { font-size: 12.5px; color: var(--muted); margin: 0; }
.ad-block { padding: 20px 18px; }
.ad-block__title { font: 700 15.5px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 13px; }

/* ---------- Инструменты ---------- */
.ad-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ad-search { position: relative; flex: 1 1 240px; min-width: 180px; }
.ad-search .input { padding-left: 38px; }
.ad-search svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
.ad-toolbar .select { min-width: 160px; }
.ad-chips { display: flex; gap: 7px; flex-wrap: wrap; }
.ad-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; background: var(--white); border: 1px dashed var(--line); border-radius: 999px; font: 600 12px/1 var(--font-sans); color: var(--ink); cursor: pointer; transition: background-color .2s, color .2s, border-color .2s, transform .16s; }
.ad-chip:hover { border-color: var(--wine-300); transform: translateY(-2px); }
.ad-chip.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); }
.ad-chip__n { font: 700 10px/1 var(--font-sans); padding: 3px 6px; border-radius: 999px; background: var(--linen); color: var(--muted); }
.ad-chip.is-active .ad-chip__n { background: rgba(255, 255, 255, .22); color: var(--white); }

/* ---------- Таблицы ---------- */
.ad-table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius); background: var(--white); scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen); }
.ad-table-wrap::-webkit-scrollbar { height: 10px; }
.ad-table-wrap::-webkit-scrollbar-track { background: var(--linen); border-radius: 5px; }
.ad-table-wrap::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 5px; border: 2px solid var(--linen); }
.ad-table-wrap::-webkit-scrollbar-thumb:hover { background: var(--wine-300); }
.ad-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ad-table th { text-align: left; padding: 11px 13px; background: var(--linen); font: 700 10.5px/1.3 var(--font-sans); letter-spacing: .09em; text-transform: uppercase; color: var(--muted); border-bottom: 2px dashed var(--line); white-space: nowrap; }
.ad-table td { padding: 11px 13px; border-bottom: 1px solid var(--line); vertical-align: middle; }
.ad-table tbody tr:last-child td { border-bottom: 0; }
.ad-table tbody tr:hover td { background: var(--linen); }
.ad-table .num { text-align: right; white-space: nowrap; }
.ad-cell-prod { display: flex; align-items: center; gap: 11px; min-width: 230px; }
.ad-thumb { width: 46px; height: 36px; flex: none; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); }
.ad-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ad-cell-prod b { display: block; font: 600 13px/1.35 var(--font-sans); color: var(--ink); }
.ad-cell-prod span { display: block; font-size: 11px; color: var(--muted); margin-top: 2px; }
.ad-row-actions { display: flex; gap: 6px; justify-content: flex-end; }
.ad-icon-btn { width: 32px; height: 32px; border-radius: var(--radius-sm); background: var(--white); border: 1px solid var(--line); color: var(--wine-700); display: grid; place-items: center; cursor: pointer; transition: border-color .2s, background-color .2s, color .2s, transform .14s; }
.ad-icon-btn:hover { border-color: var(--wine-300); background: var(--linen); }
.ad-icon-btn:active { transform: scale(.9); }
.ad-icon-btn--danger:hover { border-color: var(--danger); color: var(--danger); background: var(--danger-bg); }
.ad-icon-btn.is-on { background: var(--success-bg); border-color: var(--success); color: var(--success); }
.ad-mini-select { padding: 7px 10px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--white); font: 600 12px/1.2 var(--font-sans); color: var(--ink); cursor: pointer; }
.ad-mini-select:focus { outline: none; border-color: var(--wine-500); box-shadow: 0 0 0 3px rgba(176, 27, 46, .12); }
.ad-mini-select:disabled { opacity: .55; cursor: not-allowed; }
.ad-muted { color: var(--muted); font-size: 12px; }
.ad-mono { font-family: ui-monospace, 'Consolas', monospace; font-size: 12px; color: var(--wine-700); word-break: break-all; }

/* ---------- Дашборд ---------- */
.ad-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.ad-kpi { padding: 17px 17px; position: relative; }
.ad-kpi__label { display: block; font: 600 11px/1.3 var(--font-sans); letter-spacing: .07em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.ad-kpi__value { display: block; font: 700 26px/1.1 var(--font-serif); color: var(--wine-700); }
.ad-kpi__sub { display: block; font-size: 11.5px; color: var(--muted); margin-top: 5px; }
.ad-kpi__icon { position: absolute; top: 15px; right: 15px; color: var(--line); }
.ad-chart { display: flex; align-items: flex-end; gap: 10px; height: 150px; padding-top: 10px; }
.ad-bar { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; }
.ad-bar__fill { width: 100%; min-height: 3px; border-radius: var(--radius-sm) var(--radius-sm) 0 0; background: linear-gradient(180deg, var(--wine-300), var(--wine-700)); height: 0; transition: height .7s cubic-bezier(.22,.61,.36,1); position: relative; }
.ad-bar__value { font: 700 10.5px/1 var(--font-sans); color: var(--wine-700); white-space: nowrap; }
.ad-bar__label { font: 500 10.5px/1 var(--font-sans); color: var(--muted); }
.ad-two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

/* ---------- Обращения ---------- */
.ad-fb { display: grid; gap: 12px; }
.ad-fb-card { padding: 16px 17px; display: grid; gap: 10px; }
.ad-fb-card.is-unread { border-left: 4px solid var(--wine-500); }
.ad-fb__head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.ad-fb__who { flex: 1; min-width: 180px; }
.ad-fb__name { display: block; font: 700 14.5px/1.3 var(--font-serif); color: var(--wine-900); }
.ad-fb__meta { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.ad-fb__msg { font-size: 13.5px; color: var(--ink); white-space: pre-wrap; margin: 0; padding: 12px 14px; background: var(--linen); border-radius: var(--radius-sm); border: 1px dashed var(--line); }
.ad-fb__actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ---------- Просмотр базы ---------- */
.ad-db { display: grid; grid-template-columns: 230px 1fr; gap: 18px; align-items: start; }
.ad-db-tables { padding: 8px; display: grid; gap: 3px; }
.ad-db-table { display: flex; align-items: center; gap: 9px; width: 100%; padding: 9px 11px; background: transparent; border: 1px solid transparent; border-radius: var(--radius-sm); cursor: pointer; text-align: left; font: 600 12.5px/1.2 var(--font-sans); color: var(--ink); transition: background-color .2s, color .2s, border-color .2s; }
.ad-db-table:hover { background: var(--linen); border-color: var(--line); }
.ad-db-table.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); }
.ad-db-table__n { margin-left: auto; font: 700 10.5px/1 var(--font-sans); padding: 3px 7px; border-radius: 999px; background: var(--linen); color: var(--muted); }
.ad-db-table.is-active .ad-db-table__n { background: rgba(255, 255, 255, .22); color: var(--white); }
.ad-readonly { display: inline-flex; align-items: center; gap: 8px; padding: 8px 13px; background: var(--info-bg); border: 1px dashed rgba(58, 110, 165, .45); border-radius: var(--radius); font: 600 12px/1.3 var(--font-sans); color: #2A5580; }

/* ---------- Ящик-редактор ---------- */
.ad-drawer-scrim { position: fixed; inset: 0; z-index: 90; background: rgba(42, 16, 19, .5); opacity: 0; visibility: hidden; transition: opacity .3s, visibility .3s; }
.ad-drawer-scrim.is-open { opacity: 1; visibility: visible; }
.ad-drawer {
    position: fixed; top: 0; right: 0; bottom: 0; z-index: 100; width: min(560px, 94vw);
    background: var(--white); box-shadow: -14px 0 40px rgba(78, 11, 21, .22);
    transform: translateX(103%); transition: transform .34s cubic-bezier(.22,.61,.36,1);
    display: flex; flex-direction: column;
}
.ad-drawer.is-open { transform: none; }
.ad-drawer__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 20px; border-bottom: 2px dashed var(--line); }
.ad-drawer__title { font: 700 18px/1.3 var(--font-serif); color: var(--wine-900); margin: 0; }
.ad-drawer__body { flex: 1; overflow-y: auto; padding: 20px; scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen); }
.ad-drawer__body::-webkit-scrollbar { width: 9px; }
.ad-drawer__body::-webkit-scrollbar-track { background: var(--linen); }
.ad-drawer__body::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 5px; border: 2px solid var(--linen); }
.ad-drawer__foot { padding: 16px 20px; border-top: 2px dashed var(--line); display: flex; gap: 10px; flex-wrap: wrap; }
.ad-drawer__foot .btn--primary { flex: 1; }

.ad-photo { display: grid; grid-template-columns: 130px 1fr; gap: 14px; align-items: start; }
.ad-photo__preview { aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); border: 1px solid var(--line); }
.ad-photo__preview img { width: 100%; height: 100%; object-fit: cover; }
.ad-photo__drop { padding: 14px; border: 2px dashed var(--line); border-radius: var(--radius); background: var(--linen); text-align: center; cursor: pointer; font: 600 12.5px/1.4 var(--font-sans); color: var(--ink); transition: border-color .2s, background-color .2s; }
.ad-photo__drop:hover, .ad-photo__drop.is-drag { border-color: var(--wine-500); background: var(--wine-050); }
.ad-photo__hint { display: block; font-size: 11px; color: var(--muted); margin-top: 4px; font-weight: 500; }
.ad-photo__actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.ad-progress { height: 6px; border-radius: 3px; background: var(--line); overflow: hidden; margin-top: 10px; display: none; }
.ad-progress.is-visible { display: block; }
.ad-progress__bar { height: 100%; width: 0; background: linear-gradient(90deg, var(--wine-500), var(--wine-300)); transition: width .2s; }
.ad-specs-tools { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
.ad-specs-area { font-family: ui-monospace, 'Consolas', monospace; font-size: 12.5px; min-height: 150px; }

/* ---------- Модальные окна ---------- */
.ad-modal { position: fixed; inset: 0; z-index: 210; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(42, 16, 19, .5); }
.ad-modal.is-open { display: flex; animation: ad-fade .22s ease; }
@keyframes ad-fade { from { opacity: 0; } }
.ad-modal__box { width: min(460px, 100%); padding: 24px 22px; animation: ad-pop .3s cubic-bezier(.22,.61,.36,1); }
@keyframes ad-pop { from { transform: translateY(14px) scale(.97); opacity: 0; } }
.ad-modal__title { font: 700 20px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 10px; }
.ad-modal__text { color: var(--muted); font-size: 13.5px; margin: 0 0 18px; }
.ad-modal__actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; margin-top: 18px; }

/* ---------- Скелетоны и пусто ---------- */
.sk { background: linear-gradient(90deg, var(--linen) 25%, #F4E9EB 37%, var(--linen) 63%); background-size: 400% 100%; animation: sk-sweep 1.3s ease infinite; border-radius: var(--radius-sm); }
@keyframes sk-sweep { from { background-position: 100% 0; } to { background-position: 0 0; } }
.ad-sk { padding: 14px; display: grid; gap: 9px; }
.sk--line { height: 13px; }
.sk--short { width: 40%; }
.ad-empty { text-align: center; padding: 44px 22px; }
.ad-empty__title { font: 700 18px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 8px; }
.ad-empty__text { color: var(--muted); font-size: 13.5px; margin: 0 auto 16px; max-width: 46ch; }
.ad-pager { display: flex; gap: 7px; flex-wrap: wrap; justify-content: center; }
.page-ellipsis { display: grid; place-items: center; min-width: 22px; color: var(--muted); }

/* ---------- Заказы ---------- */
.ad-order-extra td { background: var(--linen); padding: 0; }
.ad-order-extra[hidden] { display: none; }
.ad-order-body { padding: 14px 16px; display: grid; gap: 11px; }
.ad-order-items { display: grid; gap: 8px; }
.ad-oi { display: grid; grid-template-columns: 40px 1fr auto; gap: 10px; align-items: center; font-size: 12.5px; }
.ad-oi__media { aspect-ratio: 1; border-radius: var(--radius-sm); overflow: hidden; background: var(--white); }
.ad-oi__media img { width: 100%; height: 100%; object-fit: cover; }
.ad-oi b { display: block; font-weight: 600; }
.ad-oi span { display: block; color: var(--muted); font-size: 11px; }
.ad-order-meta { display: flex; gap: 8px; flex-wrap: wrap; }
.ad-tag { font: 500 11.5px/1.3 var(--font-sans); color: var(--muted); padding: 4px 9px; background: var(--white); border: 1px solid var(--line); border-radius: var(--radius-sm); }
.ad-order-comment { padding: 10px 13px; background: var(--white); border-left: 3px solid var(--wine-500); border-radius: var(--radius-sm); font-size: 12.5px; color: var(--muted); }
.ad-toggle-row { display: inline-flex; align-items: center; gap: 7px; background: none; border: 0; cursor: pointer; font: 600 12px/1 var(--font-sans); color: var(--wine-700); padding: 0; }
.ad-toggle-row svg { transition: transform .3s; }
.ad-toggle-row[aria-expanded="true"] svg { transform: rotate(180deg); }

/* ---------- Адаптив ---------- */
@media (max-width: 1180px) {
    .ad-kpis { grid-template-columns: repeat(2, 1fr); }
    .ad-two, .ad-db { grid-template-columns: 1fr; }
}
@media (max-width: 1080px) {
    .ad-layout { grid-template-columns: 1fr; }
    .ad-rail { position: static; }
    .ad-tabs { display: flex; gap: 5px; overflow-x: auto; position: sticky; top: var(--header-h); z-index: 20; scrollbar-width: thin; scrollbar-color: var(--wine-300) rgba(255,255,255,.15); }
    .ad-tabs::-webkit-scrollbar { height: 6px; }
    .ad-tabs::-webkit-scrollbar-track { background: rgba(255, 255, 255, .12); border-radius: 3px; }
    .ad-tabs::-webkit-scrollbar-thumb { background: var(--wine-300); border-radius: 3px; }
    .ad-tab { flex: none; }
    .ad-tab:hover { transform: none; }
    .ad-rail__note { display: none; }
    .ad-db-tables { display: flex; gap: 5px; overflow-x: auto; }
    .ad-db-table { flex: none; }
}
@media (max-width: 620px) {
    .ad-kpis { grid-template-columns: 1fr; }
    .ad-photo { grid-template-columns: 1fr; }
    .ad-toolbar .select { flex: 1 1 100%; min-width: 0; }
    .ad-block { padding: 16px 14px; }
    .ad-drawer__foot .btn { flex: 1 1 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .ad-panel, .ad-modal__box { animation: none; }
    .sk { animation: none; }
    .ad-bar__fill, .ad-drawer, .ad-tab { transition: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<section class="ad-hero">
    <div class="container ad-hero__row">
        <div>
            <span class="label">Служебный режим</span>
            <h1 class="ad-hero__title">Панель управления Janome</h1>
            <p class="ad-hero__lead">Каждый запрос проверяет роль на сервере. Просмотр базы — только чтение, произвольный SQL не выполняется.</p>
        </div>
        <div class="ad-hero__who">
            <?= user_avatar($user) ?>
            <span>
                <b><?= e($user['full_name']) ?></b>
                <span>@<?= e($user['login']) ?> · администратор</span>
            </span>
        </div>
    </div>
</section>

<div class="container ad-layout">
    <!-- Пульт вкладок -->
    <aside class="ad-rail">
        <nav class="card card--static ad-tabs" role="tablist" aria-label="Разделы админки">
            <?php
            $tabs = [
                ['key' => 'dashboard', 'label' => 'Дашборд', 'icon' => '<path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-4H4zM14 8h6V4h-6z"/>'],
                ['key' => 'products', 'label' => 'Товары', 'icon' => '<path d="M4 8l8-4 8 4-8 4z"/><path d="M4 12l8 4 8-4M4 16l8 4 8-4"/>'],
                ['key' => 'categories', 'label' => 'Категории', 'icon' => '<path d="M4 6h7l2 2h7v11H4z"/>'],
                ['key' => 'brands', 'label' => 'Бренды', 'icon' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/>'],
                ['key' => 'orders', 'label' => 'Заказы', 'icon' => '<path d="M5 4h14v16H5z"/><path d="M8.5 9h7M8.5 13h5"/>'],
                ['key' => 'users', 'label' => 'Пользователи', 'icon' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c1.2-3.6 4-5.5 7-5.5s5.8 1.9 7 5.5"/>'],
                ['key' => 'feedback', 'label' => 'Обращения', 'icon' => '<path d="M4 5h16v11H8l-4 4z"/>'],
                ['key' => 'db', 'label' => 'База данных', 'icon' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>'],
            ];
            foreach ($tabs as $i => $tab): ?>
                <button class="ad-tab<?= $i === 0 ? ' is-active' : '' ?>" type="button" role="tab"
                        id="adtab-<?= e($tab['key']) ?>" data-tab="<?= e($tab['key']) ?>"
                        aria-controls="adpanel-<?= e($tab['key']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                    <span class="ad-tab__icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $tab['icon'] ?></svg></span>
                    <span class="ad-tab__label"><?= e($tab['label']) ?></span>
                    <span class="ad-tab__count" id="adcount-<?= e($tab['key']) ?>" hidden>0</span>
                </button>
            <?php endforeach; ?>
        </nav>
        <div class="card card--static ad-rail__note">
            <b>Осторожно с удалениями</b>
            Товар в заказах удалить нельзя — снимайте с продажи. Категория и бренд с товарами тоже защищены внешними ключами.
        </div>
    </aside>

    <!-- Панели -->
    <div class="ad-main">
        <noscript><div class="notice notice--warning">Админке нужен JavaScript: все действия идут через fetch.</div></noscript>

        <!-- ДАШБОРД -->
        <section class="ad-panel" id="adpanel-dashboard" role="tabpanel" aria-labelledby="adtab-dashboard">
            <div class="ad-head">
                <div><h2 class="ad-head__title">Дашборд</h2><p class="ad-head__hint">Сводка по магазину в реальном времени</p></div>
                <button class="btn btn--ghost btn--sm" type="button" id="dash-refresh">Обновить</button>
            </div>
            <div class="ad-kpis" id="dash-kpis"></div>
            <div class="card card--static ad-block">
                <h3 class="ad-block__title">Выручка за 7 дней</h3>
                <div class="ad-chart" id="dash-chart"></div>
            </div>
            <div class="ad-two">
                <div class="card card--static ad-block">
                    <h3 class="ad-block__title">Товары на исходе</h3>
                    <div id="dash-low"></div>
                </div>
                <div class="card card--static ad-block">
                    <h3 class="ad-block__title">Последние заказы</h3>
                    <div id="dash-recent"></div>
                </div>
            </div>
            <div class="ad-two">
                <div class="card card--static ad-block">
                    <h3 class="ad-block__title">Ходовые товары</h3>
                    <div id="dash-top"></div>
                </div>
                <div class="card card--static ad-block">
                    <h3 class="ad-block__title">Непрочитанные обращения</h3>
                    <div id="dash-unread"></div>
                </div>
            </div>
        </section>

        <!-- ТОВАРЫ -->
        <section class="ad-panel" id="adpanel-products" role="tabpanel" aria-labelledby="adtab-products" hidden>
            <div class="ad-head">
                <div><h2 class="ad-head__title">Товары</h2><p class="ad-head__hint">Создание, редактирование, фото и снятие с продажи</p></div>
                <button class="btn btn--primary btn--sm" type="button" id="prod-new">Добавить товар</button>
            </div>
            <div class="ad-toolbar">
                <div class="ad-search">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <label class="visually-hidden" for="prod-q">Поиск товаров</label>
                    <input class="input" id="prod-q" type="search" placeholder="Название, модель, slug" maxlength="100" autocomplete="off">
                </div>
                <label class="visually-hidden" for="prod-brand">Бренд</label>
                <select class="select" id="prod-brand"><option value="0">Все бренды</option><?php foreach ($brands as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
                <label class="visually-hidden" for="prod-category">Категория</label>
                <select class="select" id="prod-category"><option value="0">Все категории</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
                <label class="visually-hidden" for="prod-status">Состояние</label>
                <select class="select" id="prod-status">
                    <option value="all">Все</option><option value="active">В каталоге</option>
                    <option value="hidden">Скрытые</option><option value="low">Заканчиваются</option><option value="out">Нет в наличии</option>
                </select>
            </div>
            <div id="prod-list"></div>
            <div class="ad-pager" id="prod-pager"></div>
        </section>

        <!-- КАТЕГОРИИ -->
        <section class="ad-panel" id="adpanel-categories" role="tabpanel" aria-labelledby="adtab-categories" hidden>
            <div class="ad-head">
                <div><h2 class="ad-head__title">Категории</h2><p class="ad-head__hint">Порядок вывода и slug для фильтров каталога</p></div>
                <button class="btn btn--primary btn--sm" type="button" data-dict-new="categories">Добавить категорию</button>
            </div>
            <div id="cat-list"></div>
        </section>

        <!-- БРЕНДЫ -->
        <section class="ad-panel" id="adpanel-brands" role="tabpanel" aria-labelledby="adtab-brands" hidden>
            <div class="ad-head">
                <div><h2 class="ad-head__title">Бренды</h2><p class="ad-head__hint">Janome — флагман, порядок влияет на плитки на главной</p></div>
                <button class="btn btn--primary btn--sm" type="button" data-dict-new="brands">Добавить бренд</button>
            </div>
            <div id="brand-list"></div>
        </section>

        <!-- ЗАКАЗЫ -->
        <section class="ad-panel" id="adpanel-orders" role="tabpanel" aria-labelledby="adtab-orders" hidden>
            <div class="ad-head">
                <div><h2 class="ad-head__title">Заказы</h2><p class="ad-head__hint">Отмена возвращает остатки на склад автоматически</p></div>
                <button class="btn btn--ghost btn--sm" type="button" id="ord-refresh">Обновить</button>
            </div>
            <div class="ad-toolbar">
                <div class="ad-chips" id="ord-chips"></div>
                <div class="ad-search">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <label class="visually-hidden" for="ord-q">Поиск заказов</label>
                    <input class="input" id="ord-q" type="search" placeholder="Номер, логин, имя, телефон" maxlength="60" autocomplete="off">
                </div>
            </div>
            <div id="ord-list"></div>
            <div class="ad-pager" id="ord-pager"></div>
        </section>

        <!-- ПОЛЬЗОВАТЕЛИ -->
        <section class="ad-panel" id="adpanel-users" role="tabpanel" aria-labelledby="adtab-users" hidden>
            <div class="ad-head">
                <div><h2 class="ad-head__title">Пользователи</h2><p class="ad-head__hint">Свою роль менять нельзя, последнего админа разжаловать нельзя</p></div>
                <button class="btn btn--ghost btn--sm" type="button" id="usr-refresh">Обновить</button>
            </div>
            <div class="ad-toolbar">
                <div class="ad-chips" id="usr-chips"></div>
                <div class="ad-search">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <label class="visually-hidden" for="usr-q">Поиск пользователей</label>
                    <input class="input" id="usr-q" type="search" placeholder="Логин, имя, email" maxlength="100" autocomplete="off">
                </div>
            </div>
            <div id="usr-list"></div>
            <div class="ad-pager" id="usr-pager"></div>
        </section>

        <!-- ОБРАЩЕНИЯ -->
        <section class="ad-panel" id="adpanel-feedback" role="tabpanel" aria-labelledby="adtab-feedback" hidden>
            <div class="ad-head">
                <div><h2 class="ad-head__title">Обращения</h2><p class="ad-head__hint">Пишут и гости, и покупатели — ответ по телефону или почте из формы</p></div>
                <button class="btn btn--ghost btn--sm" type="button" id="fb-read-all">Прочитать все</button>
            </div>
            <div class="ad-toolbar">
                <div class="ad-chips" id="fb-chips"></div>
                <div class="ad-search">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <label class="visually-hidden" for="fb-q">Поиск обращений</label>
                    <input class="input" id="fb-q" type="search" placeholder="Имя, почта, текст" maxlength="100" autocomplete="off">
                </div>
            </div>
            <div class="ad-fb" id="fb-list"></div>
            <div class="ad-pager" id="fb-pager"></div>
        </section>

        <!-- БАЗА -->
        <section class="ad-panel" id="adpanel-db" role="tabpanel" aria-labelledby="adtab-db" hidden>
            <div class="ad-head">
                <div><h2 class="ad-head__title">Просмотр базы</h2><p class="ad-head__hint">Только чтение: список таблиц и постраничный вывод строк</p></div>
                <span class="ad-readonly">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    READ ONLY · произвольный SQL не выполняется
                </span>
            </div>
            <div class="ad-db">
                <div class="card card--static ad-db-tables" id="db-tables"></div>
                <div style="display:grid; gap:14px; min-width:0;">
                    <div class="ad-toolbar">
                        <div class="ad-search">
                            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            <label class="visually-hidden" for="db-q">Поиск по таблице</label>
                            <input class="input" id="db-q" type="search" placeholder="Поиск по текстовым колонкам" maxlength="100" autocomplete="off">
                        </div>
                        <button class="btn btn--ghost btn--sm" type="button" id="db-refresh">Обновить</button>
                    </div>
                    <div id="db-note"></div>
                    <div id="db-rows"></div>
                    <div class="ad-pager" id="db-pager"></div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Ящик редактирования товара -->
<div class="ad-drawer-scrim" id="prod-scrim" hidden></div>
<aside class="ad-drawer" id="prod-drawer" role="dialog" aria-modal="true" aria-labelledby="prod-drawer-title">
    <div class="ad-drawer__head">
        <h2 class="ad-drawer__title" id="prod-drawer-title">Новый товар</h2>
        <button class="ad-icon-btn" type="button" id="prod-close" aria-label="Закрыть редактор">
            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
    </div>
    <div class="ad-drawer__body">
        <form id="prod-form" novalidate>
            <input type="hidden" id="p-id" value="0">
            <input type="hidden" id="p-remove-photo" value="0">

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="p-name">Название</label>
                    <input class="input" id="p-name" type="text" maxlength="200" required>
                    <span class="field__error" data-perr="name" hidden></span>
                </div>
                <div class="field">
                    <label class="field__label" for="p-model">Модель</label>
                    <input class="input" id="p-model" type="text" maxlength="100" required>
                    <span class="field__error" data-perr="model" hidden></span>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="p-slug">Slug</label>
                <input class="input" id="p-slug" type="text" maxlength="150" placeholder="пусто → соберём из модели">
                <span class="field__hint">Латиница, цифры и дефис. Используется в адресе товара.</span>
                <span class="field__error" data-perr="slug" hidden></span>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="p-brand">Бренд</label>
                    <select class="select" id="p-brand"><?php foreach ($brands as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
                    <span class="field__error" data-perr="brand_id" hidden></span>
                </div>
                <div class="field">
                    <label class="field__label" for="p-category">Категория</label>
                    <select class="select" id="p-category"><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
                    <span class="field__error" data-perr="category_id" hidden></span>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="p-price">Цена, ₽</label>
                    <input class="input" id="p-price" type="number" min="1" step="0.01" required>
                    <span class="field__error" data-perr="price" hidden></span>
                </div>
                <div class="field">
                    <label class="field__label" for="p-old">Старая цена, ₽</label>
                    <input class="input" id="p-old" type="number" min="0" step="0.01" placeholder="пусто — без скидки">
                    <span class="field__error" data-perr="old_price" hidden></span>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="p-stock">Остаток, шт.</label>
                    <input class="input" id="p-stock" type="number" min="0" step="1" value="0">
                    <span class="field__error" data-perr="stock" hidden></span>
                </div>
                <div class="field">
                    <label class="field__label">Показ в каталоге</label>
                    <label class="checkbox" style="padding-top: 10px;"><input type="checkbox" id="p-active" checked><span>Товар активен</span></label>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="p-desc">Описание</label>
                <textarea class="textarea" id="p-desc" rows="4" maxlength="5000"></textarea>
                <span class="field__error" data-perr="description" hidden></span>
            </div>

            <div class="field">
                <label class="field__label" for="p-specs">Характеристики (JSON)</label>
                <div class="ad-specs-tools">
                    <button class="btn btn--ghost btn--sm" type="button" id="p-specs-check">Проверить JSON</button>
                    <button class="btn btn--ghost btn--sm" type="button" id="p-specs-format">Отформатировать</button>
                </div>
                <textarea class="textarea ad-specs-area" id="p-specs" rows="8" placeholder='{"Количество операций": 60}'></textarea>
                <span class="field__hint">Ключи на русском — они попадают в техпаспорт товара.</span>
                <span class="field__error" data-perr="specs" hidden></span>
            </div>

            <div class="field">
                <label class="field__label">Фото товара</label>
                <div class="ad-photo">
                    <div class="ad-photo__preview"><img id="p-photo-preview" alt="Превью фото товара"></div>
                    <div>
                        <div class="ad-photo__drop" id="p-drop" role="button" tabindex="0">
                            Перетащите фото или нажмите
                            <span class="ad-photo__hint">JPG, PNG, WebP · до 2 МБ · пропорции 4:3</span>
                        </div>
                        <input type="file" id="p-photo" accept="image/jpeg,image/png,image/webp" class="visually-hidden">
                        <div class="ad-progress" id="p-progress"><div class="ad-progress__bar" id="p-progress-bar"></div></div>
                        <div class="ad-photo__actions">
                            <button class="btn btn--ghost btn--sm" type="button" id="p-photo-pick">Выбрать файл</button>
                            <button class="btn btn--ghost btn--sm" type="button" id="p-photo-reset">Вернуть плейсхолдер</button>
                        </div>
                        <span class="field__error" id="p-photo-error" hidden></span>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="ad-drawer__foot">
        <button class="btn btn--primary" type="button" id="prod-save">Сохранить товар</button>
        <button class="btn btn--ghost" type="button" id="prod-cancel">Отмена</button>
    </div>
</aside>

<!-- Модальное окно справочника -->
<div class="ad-modal" id="dict-modal" role="dialog" aria-modal="true" aria-labelledby="dict-title">
    <div class="card card--static ad-modal__box">
        <h2 class="ad-modal__title" id="dict-title">Категория</h2>
        <form id="dict-form" novalidate>
            <input type="hidden" id="d-id" value="0">
            <input type="hidden" id="d-kind" value="categories">
            <div class="field">
                <label class="field__label" for="d-name">Название</label>
                <input class="input" id="d-name" type="text" maxlength="100" required>
                <span class="field__error" data-derr="name" hidden></span>
            </div>
            <div class="field">
                <label class="field__label" for="d-slug">Slug</label>
                <input class="input" id="d-slug" type="text" maxlength="100" placeholder="пусто → из названия">
                <span class="field__error" data-derr="slug" hidden></span>
            </div>
            <div class="field">
                <label class="field__label" for="d-sort">Порядок сортировки</label>
                <input class="input" id="d-sort" type="number" min="0" max="9999" step="1" value="0">
                <span class="field__error" data-derr="sort_order" hidden></span>
            </div>
            <div class="ad-modal__actions">
                <button class="btn btn--ghost" type="button" id="dict-cancel">Отмена</button>
                <button class="btn btn--primary" type="submit" id="dict-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Подтверждение -->
<div class="ad-modal" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
    <div class="card card--static ad-modal__box">
        <h2 class="ad-modal__title" id="confirm-title">Подтвердите действие</h2>
        <p class="ad-modal__text" id="confirm-text"></p>
        <div class="ad-modal__actions">
            <button class="btn btn--ghost" type="button" id="confirm-no">Отмена</button>
            <button class="btn btn--primary" type="button" id="confirm-yes">Подтвердить</button>
        </div>
    </div>
</div>

<script id="admin-config" type="application/json"><?= json_encode($adminConfig, $jsonFlags) ?></script>

<script>
// Админка: вкладки, дашборд, CRUD, статусы, роли, обращения, просмотр базы
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('admin-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var esc = J.escapeHtml;
    var money = J.formatPrice;
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var API = '../api/admin/';
    var FALLBACK = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/></svg>');

    function fmtDate(v) {
        if (!v) { return '—'; }
        var parts = String(v).replace('T', ' ').split(' ');
        var d = (parts[0] || '').split('-');
        var t = (parts[1] || '').slice(0, 5);
        return d.length === 3 ? d[2] + '.' + d[1] + '.' + d[0] + (t ? ' ' + t : '') : String(v);
    }
    function debounce(fn, ms) {
        var timer = null;
        return function () { clearTimeout(timer); timer = setTimeout(fn, ms); };
    }
    function img(path) { return '../' + (path || cfg.placeholder); }
    function sk(rows) {
        var html = '';
        for (var i = 0; i < rows; i++) {
            html += '<div class="card card--static ad-sk" aria-hidden="true"><div class="sk sk--line sk--short"></div><div class="sk sk--line"></div><div class="sk sk--line"></div></div>';
        }
        return html;
    }
    function empty(title, text, btn) {
        return '<div class="card card--static ad-empty"><h3 class="ad-empty__title">' + esc(title) + '</h3><p class="ad-empty__text">' + esc(text) + '</p>' + (btn || '') + '</div>';
    }
    function fail(host, message, retryAttr) {
        host.innerHTML = empty('Не загрузилось', message, '<button class="btn btn--primary btn--sm" type="button" ' + retryAttr + '>Повторить</button>');
    }
    function statusBadge(status) {
        var map = { new: 'badge--info', processing: 'badge--warning', shipped: 'badge--info', done: 'badge--success', cancelled: 'badge--danger' };
        return '<span class="badge ' + (map[status] || 'badge--info') + '">' + esc(cfg.statusLabels[status] || status) + '</span>';
    }

    /* ---------- Пагинация ---------- */
    function pager(host, page, pages, attr) {
        if (!host) { return; }
        if (pages <= 1) { host.innerHTML = ''; return; }
        var html = '<button class="page-link' + (page <= 1 ? ' is-disabled' : '') + '" type="button" ' + attr + '="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>‹</button>';
        var slots = [], prev = 0;
        for (var i = 1; i <= pages; i++) { if (i === 1 || i === pages || Math.abs(i - page) <= 1) { slots.push(i); } }
        slots.forEach(function (n) {
            if (prev && n - prev > 1) { html += '<span class="page-ellipsis">…</span>'; }
            html += '<button class="page-link' + (n === page ? ' is-active' : '') + '" type="button" ' + attr + '="' + n + '">' + n + '</button>';
            prev = n;
        });
        html += '<button class="page-link' + (page >= pages ? ' is-disabled' : '') + '" type="button" ' + attr + '="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>›</button>';
        host.innerHTML = html;
    }
    function bindPager(host, attr, cb) {
        if (!host) { return; }
        host.addEventListener('click', function (e) {
            var btn = e.target.closest('[' + attr + ']');
            if (!btn || btn.disabled) { return; }
            cb(parseInt(btn.getAttribute(attr), 10));
        });
    }

    /* ---------- Подтверждение ---------- */
    var confirmModal = document.getElementById('confirm-modal');
    var confirmYes = document.getElementById('confirm-yes');
    var confirmNo = document.getElementById('confirm-no');
    var confirmCb = null, confirmFocus = null;

    function askConfirm(title, text, okLabel, cb) {
        document.getElementById('confirm-title').textContent = title;
        document.getElementById('confirm-text').textContent = text;
        confirmYes.textContent = okLabel || 'Подтвердить';
        confirmCb = cb;
        confirmFocus = document.activeElement;
        confirmModal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        confirmNo.focus();
    }
    function closeConfirm() {
        confirmModal.classList.remove('is-open');
        if (!drawer.classList.contains('is-open') && !dictModal.classList.contains('is-open')) { document.body.style.overflow = ''; }
        confirmCb = null;
        if (confirmFocus && confirmFocus.focus) { confirmFocus.focus(); }
    }
    confirmNo.addEventListener('click', closeConfirm);
    confirmModal.addEventListener('click', function (e) { if (e.target === confirmModal) { closeConfirm(); } });
    confirmYes.addEventListener('click', function () {
        var cb = confirmCb;
        closeConfirm();
        if (cb) { cb(); }
    });

    /* ---------- Счётчики на вкладках ---------- */
    function tabCount(key, value, alert) {
        var el = document.getElementById('adcount-' + key);
        if (!el) { return; }
        if (value === null) { el.hidden = true; return; }
        el.hidden = false;
        el.textContent = value;
        el.classList.toggle('is-alert', !!alert);
    }

    /* ---------- Вкладки ---------- */
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.ad-tab'));
    var loaded = {};
    var loaders = {};

    function activate(key) {
        tabs.forEach(function (tab) {
            var on = tab.dataset.tab === key;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            var panel = document.getElementById('adpanel-' + tab.dataset.tab);
            if (panel) { panel.hidden = !on; }
        });
        history.replaceState(null, '', window.location.pathname + '?tab=' + key);
        if (!loaded[key] && loaders[key]) { loaded[key] = true; loaders[key](); }
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { activate(tab.dataset.tab); });
        tab.addEventListener('keydown', function (e) {
            var dir = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 0);
            if (!dir) { return; }
            e.preventDefault();
            var next = tabs[(index + dir + tabs.length) % tabs.length];
            next.focus(); activate(next.dataset.tab);
        });
    });

    /* ============================================================
       ДАШБОРД
       ============================================================ */
    var dashKpis = document.getElementById('dash-kpis');
    var dashChart = document.getElementById('dash-chart');

    function kpi(label, value, sub, icon) {
        return '<div class="card ad-kpi"><span class="ad-kpi__icon"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + icon + '</svg></span>' +
            '<span class="ad-kpi__label">' + esc(label) + '</span>' +
            '<span class="ad-kpi__value">' + esc(value) + '</span>' +
            '<span class="ad-kpi__sub">' + esc(sub) + '</span></div>';
    }

    function loadDashboard() {
        dashKpis.innerHTML = sk(4);
        J.api(API + 'stats.php').then(function (data) {
            var c = data.counters;
            dashKpis.innerHTML =
                kpi('Выручка', money(c.revenue), 'сегодня ' + money(c.revenue_today) + ' · 7 дней ' + money(c.revenue_7d), '<path d="M4 17l5-6 4 3 7-8"/><path d="M15 6h5v5"/>') +
                kpi('Заказы', String(c.orders_total), 'новых ' + c.orders_new + ' · в обработке ' + c.orders_processing, '<path d="M5 4h14v16H5z"/><path d="M8.5 9h7M8.5 13h5"/>') +
                kpi('Товары', String(c.products_active) + ' / ' + c.products_total, 'на исходе ' + c.products_low + ' · нет ' + c.products_out, '<path d="M4 8l8-4 8 4-8 4z"/><path d="M4 12l8 4 8-4"/>') +
                kpi('Обращения', String(c.feedback_unread), 'всего ' + c.feedback_total + ' · покупателей ' + c.users_total, '<path d="M4 5h16v11H8l-4 4z"/>');

            tabCount('orders', c.orders_new, c.orders_new > 0);
            tabCount('feedback', c.feedback_unread, c.feedback_unread > 0);
            tabCount('products', c.products_total, false);
            tabCount('users', c.users_total, false);

            // График выручки
            var max = Math.max.apply(null, data.chart.map(function (d) { return d.revenue; }).concat([1]));
            dashChart.innerHTML = data.chart.map(function (d) {
                var h = Math.round(d.revenue / max * 100);
                return '<div class="ad-bar" title="' + esc(d.label + ': ' + money(d.revenue) + ' · заказов ' + d.orders) + '">' +
                    '<span class="ad-bar__value">' + (d.revenue > 0 ? esc(money(d.revenue)) : '—') + '</span>' +
                    '<span class="ad-bar__fill" data-h="' + h + '"></span>' +
                    '<span class="ad-bar__label">' + esc(d.label) + '</span></div>';
            }).join('');
            requestAnimationFrame(function () {
                dashChart.querySelectorAll('.ad-bar__fill').forEach(function (bar) { bar.style.height = Math.max(3, parseInt(bar.dataset.h, 10)) + '%'; });
            });

            // Товары на исходе
            document.getElementById('dash-low').innerHTML = data.low_stock.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><tbody>' + data.low_stock.map(function (p) {
                    return '<tr><td><div class="ad-cell-prod"><span class="ad-thumb"><img src="' + esc(img(p.image_path)) + '" alt="" onerror="this.onerror=null;this.src=\'' + FALLBACK + '\'"></span><span><b>' + esc(p.name) + '</b><span>' + esc(p.brand_name) + '</span></span></div></td>' +
                        '<td class="num"><span class="badge ' + (p.stock === 0 ? 'badge--danger' : 'badge--warning') + '">' + p.stock + ' шт.</span></td>' +
                        '<td class="num"><button class="ad-icon-btn" type="button" data-goto-product="' + p.id + '" title="Открыть в редакторе"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16z"/></svg></button></td></tr>';
                }).join('') + '</tbody></table></div>'
                : empty('Склад в порядке', 'Товаров с остатком 3 и меньше нет.');

            // Последние заказы
            document.getElementById('dash-recent').innerHTML = data.recent_orders.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><tbody>' + data.recent_orders.map(function (o) {
                    return '<tr><td><b class="ad-mono">' + esc(o.order_number) + '</b><div class="ad-muted">@' + esc(o.login) + ' · ' + esc(fmtDate(o.created_at)) + '</div></td>' +
                        '<td>' + statusBadge(o.status) + '</td><td class="num"><b>' + esc(money(o.total)) + '</b></td></tr>';
                }).join('') + '</tbody></table></div>'
                : empty('Заказов пока нет', 'Как только оформят первый — появится здесь.');

            // Ходовые товары
            document.getElementById('dash-top').innerHTML = data.top_products.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><tbody>' + data.top_products.map(function (p) {
                    return '<tr><td><b>' + esc(p.name) + '</b><div class="ad-muted">' + esc(p.model) + '</div></td>' +
                        '<td class="num">' + p.sold + ' шт.</td><td class="num"><b>' + esc(money(p.earned)) + '</b></td></tr>';
                }).join('') + '</tbody></table></div>'
                : empty('Продаж ещё нет', 'Статистика появится после первых заказов.');

            // Непрочитанные
            document.getElementById('dash-unread').innerHTML = data.unread_feedback.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><tbody>' + data.unread_feedback.map(function (f) {
                    return '<tr><td><b>' + esc(f.name) + '</b><div class="ad-muted">' + esc(f.email) + ' · ' + esc(fmtDate(f.created_at)) + '</div><div class="ad-muted" style="margin-top:5px">' + esc(f.message) + '</div></td></tr>';
                }).join('') + '</tbody></table></div>'
                : empty('Всё прочитано', 'Новых обращений нет.');
        }).catch(function (err) {
            dashKpis.innerHTML = empty('Дашборд не загрузился', err.message, '<button class="btn btn--primary btn--sm" type="button" id="dash-retry">Повторить</button>');
        });
    }
    loaders.dashboard = loadDashboard;
    document.getElementById('dash-refresh').addEventListener('click', loadDashboard);
    document.getElementById('dash-kpis').addEventListener('click', function (e) {
        if (e.target.closest('#dash-retry')) { loadDashboard(); }
    });
    document.getElementById('dash-low').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-goto-product]');
        if (!btn) { return; }
        var id = parseInt(btn.dataset.gotoProduct, 10);
        activate('products');
        setTimeout(function () { openProductById(id); }, 200);
    });

    /* ============================================================
       ТОВАРЫ
       ============================================================ */
    var prodList = document.getElementById('prod-list');
    var prodPager = document.getElementById('prod-pager');
    var prodState = { q: '', brand: 0, category: 0, status: 'all', page: 1 };
    var prodCache = {};

    function loadProducts() {
        prodList.innerHTML = sk(4);
        var params = new URLSearchParams();
        if (prodState.q) { params.set('q', prodState.q); }
        if (prodState.brand) { params.set('brand', prodState.brand); }
        if (prodState.category) { params.set('category', prodState.category); }
        params.set('status', prodState.status);
        params.set('page', prodState.page);

        J.api(API + 'products.php?' + params.toString()).then(function (data) {
            prodState.page = data.page;
            tabCount('products', data.total, false);
            prodCache = {};
            data.items.forEach(function (p) { prodCache[p.id] = p; });

            prodList.innerHTML = data.items.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>' +
                    '<th>Товар</th><th>Бренд / категория</th><th class="num">Цена</th><th class="num">Остаток</th><th>Каталог</th><th></th>' +
                    '</tr></thead><tbody>' + data.items.map(function (p) {
                        var stockBadge = p.stock === 0 ? 'badge--danger' : (p.stock <= 3 ? 'badge--warning' : 'badge--success');
                        return '<tr data-prow="' + p.id + '">' +
                            '<td><div class="ad-cell-prod"><span class="ad-thumb"><img src="' + esc(img(p.image_path)) + '" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'' + FALLBACK + '\'"></span>' +
                                '<span><b>' + esc(p.name) + '</b><span>' + esc(p.model) + ' · ' + esc(p.slug) + '</span></span></div></td>' +
                            '<td>' + esc(p.brand_name) + '<div class="ad-muted">' + esc(p.category_name) + '</div></td>' +
                            '<td class="num"><b>' + esc(money(p.price)) + '</b>' + (p.old_price ? '<div class="ad-muted"><s>' + esc(money(p.old_price)) + '</s></div>' : '') + '</td>' +
                            '<td class="num"><span class="badge ' + stockBadge + '">' + p.stock + '</span></td>' +
                            '<td><button class="ad-icon-btn' + (p.is_active ? ' is-on' : '') + '" type="button" data-ptoggle="' + p.id + '" title="' + (p.is_active ? 'Скрыть из каталога' : 'Показать в каталоге') + '">' +
                                '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
                                (p.is_active ? '<path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.6"/>' : '<path d="M4 4l16 16"/><path d="M9.5 9.6A2.6 2.6 0 0 0 12 14.6"/><path d="M6.3 6.4C3.9 8 2.5 12 2.5 12s3.5 5.5 9.5 5.5c1.6 0 3-.4 4.2-1"/><path d="M18.6 15.4C20.6 13.8 21.5 12 21.5 12S18 6.5 12 6.5c-.9 0-1.7.1-2.5.3"/>') +
                                '</svg></button></td>' +
                            '<td><div class="ad-row-actions">' +
                                '<button class="ad-icon-btn" type="button" data-pedit="' + p.id + '" title="Редактировать"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16z"/><path d="M14 6l4 4"/></svg></button>' +
                                '<button class="ad-icon-btn ad-icon-btn--danger" type="button" data-pdel="' + p.id + '" title="Удалить"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13"/></svg></button>' +
                            '</div></td></tr>';
                    }).join('') + '</tbody></table></div>'
                : empty('Товары не найдены', 'Измените фильтры или добавьте новую модель.', '<button class="btn btn--primary btn--sm" type="button" id="prod-empty-new">Добавить товар</button>');

            pager(prodPager, data.page, data.pages, 'data-ppage');
        }).catch(function (err) { fail(prodList, err.message, 'data-pretry'); });
    }
    loaders.products = loadProducts;

    document.getElementById('prod-q').addEventListener('input', debounce(function (e) {
        prodState.q = e.target.value.trim(); prodState.page = 1; loadProducts();
    }, 350));
    ['brand', 'category', 'status'].forEach(function (key) {
        document.getElementById('prod-' + key).addEventListener('change', function (e) {
            prodState[key] = key === 'status' ? e.target.value : parseInt(e.target.value, 10);
            prodState.page = 1; loadProducts();
        });
    });
    bindPager(prodPager, 'data-ppage', function (p) { prodState.page = p; loadProducts(); });

    prodList.addEventListener('click', function (e) {
        if (e.target.closest('[data-pretry]')) { loadProducts(); return; }
        if (e.target.closest('#prod-empty-new')) { openProduct(null); return; }

        var edit = e.target.closest('[data-pedit]');
        if (edit) { openProduct(prodCache[parseInt(edit.dataset.pedit, 10)]); return; }

        var toggle = e.target.closest('[data-ptoggle]');
        if (toggle) {
            var tid = parseInt(toggle.dataset.ptoggle, 10);
            toggle.disabled = true;
            J.api(API + 'products.php', { method: 'POST', body: { action: 'toggle_active', id: tid } })
                .then(function (data) { toggle.disabled = false; J.toast(data.message, 'success', 2200); loadProducts(); })
                .catch(function (err) { toggle.disabled = false; J.toast(err.message, 'error'); });
            return;
        }

        var del = e.target.closest('[data-pdel]');
        if (del) {
            var did = parseInt(del.dataset.pdel, 10);
            var product = prodCache[did];
            askConfirm('Удалить товар?', '«' + (product ? product.name : 'Товар') + '» будет удалён вместе с фото. Если он есть в заказах, сервер откажет — тогда снимите его с продажи.', 'Удалить', function () {
                J.api(API + 'products.php', { method: 'POST', body: { action: 'delete', id: did } })
                    .then(function (data) { J.toast(data.message, 'success'); loadProducts(); })
                    .catch(function (err) { J.toast(err.message, 'error'); });
            });
        }
    });

    function openProductById(id) {
        J.api(API + 'products.php?q=&page=1').then(function () {
            if (prodCache[id]) { openProduct(prodCache[id]); return; }
            J.toast('Откройте товар через список — он на другой странице', 'info');
        }).catch(function () { /* список уже загружен */ });
    }

    /* ---------- Ящик товара ---------- */
    var drawer = document.getElementById('prod-drawer');
    var scrim = document.getElementById('prod-scrim');
    var prodForm = document.getElementById('prod-form');
    var photoInput = document.getElementById('p-photo');
    var photoPreview = document.getElementById('p-photo-preview');
    var photoDrop = document.getElementById('p-drop');
    var photoProgress = document.getElementById('p-progress');
    var photoBar = document.getElementById('p-progress-bar');
    var photoError = document.getElementById('p-photo-error');
    var pickedFile = null, objectUrl = null, drawerFocus = null, saving = false;

    function perr(name, message) {
        var slot = prodForm.querySelector('[data-perr="' + name + '"]');
        if (slot) { slot.textContent = message || ''; slot.hidden = !message; }
    }
    function clearPerr() {
        prodForm.querySelectorAll('[data-perr]').forEach(function (s) { s.textContent = ''; s.hidden = true; });
        photoError.hidden = true; photoError.textContent = '';
    }

    function openProduct(product) {
        drawerFocus = document.activeElement;
        clearPerr();
        pickedFile = null;
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        photoInput.value = '';
        photoProgress.classList.remove('is-visible');
        photoBar.style.width = '0%';
        document.getElementById('p-remove-photo').value = '0';

        document.getElementById('prod-drawer-title').textContent = product ? 'Товар: ' + product.model : 'Новый товар';
        document.getElementById('p-id').value = product ? product.id : 0;
        document.getElementById('p-name').value = product ? product.name : '';
        document.getElementById('p-model').value = product ? product.model : '';
        document.getElementById('p-slug').value = product ? product.slug : '';
        document.getElementById('p-brand').value = product ? product.brand_id : (cfg.brands[0] ? cfg.brands[0].id : 0);
        document.getElementById('p-category').value = product ? product.category_id : (cfg.categories[0] ? cfg.categories[0].id : 0);
        document.getElementById('p-price').value = product ? product.price : '';
        document.getElementById('p-old').value = product && product.old_price ? product.old_price : '';
        document.getElementById('p-stock').value = product ? product.stock : 0;
        document.getElementById('p-active').checked = product ? product.is_active : true;
        document.getElementById('p-desc').value = product ? (product.description || '') : '';

        var specsText = '{}';
        if (product && product.specs) {
            try { specsText = JSON.stringify(JSON.parse(product.specs), null, 2); }
            catch (err) { specsText = product.specs; }
        }
        document.getElementById('p-specs').value = specsText;
        photoPreview.src = product ? img(product.image_path) : img(cfg.placeholder);

        scrim.hidden = false;
        requestAnimationFrame(function () { scrim.classList.add('is-open'); });
        drawer.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        document.getElementById('p-name').focus();
    }

    function closeProduct() {
        drawer.classList.remove('is-open');
        scrim.classList.remove('is-open');
        setTimeout(function () { if (!scrim.classList.contains('is-open')) { scrim.hidden = true; } }, 300);
        document.body.style.overflow = '';
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        pickedFile = null;
        if (drawerFocus && drawerFocus.focus) { drawerFocus.focus(); }
    }

    document.getElementById('prod-new').addEventListener('click', function () { openProduct(null); });
    document.getElementById('prod-close').addEventListener('click', closeProduct);
    document.getElementById('prod-cancel').addEventListener('click', closeProduct);
    scrim.addEventListener('click', closeProduct);

    document.getElementById('p-specs-check').addEventListener('click', function () {
        var raw = document.getElementById('p-specs').value.trim();
        if (raw === '') { perr('specs', ''); J.toast('Пустой specs — это {}', 'info', 2000); return; }
        try { JSON.parse(raw); perr('specs', ''); J.toast('JSON корректный', 'success', 2000); }
        catch (err) { perr('specs', 'Ошибка JSON: ' + err.message); J.toast('JSON битый', 'error'); }
    });
    document.getElementById('p-specs-format').addEventListener('click', function () {
        var raw = document.getElementById('p-specs').value.trim();
        if (raw === '') { document.getElementById('p-specs').value = '{}'; return; }
        try {
            document.getElementById('p-specs').value = JSON.stringify(JSON.parse(raw), null, 2);
            perr('specs', ''); J.toast('Отформатировано', 'success', 1800);
        } catch (err) { perr('specs', 'Ошибка JSON: ' + err.message); }
    });

    function pickPhoto(file) {
        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (allowed.indexOf(file.type) === -1) { photoError.textContent = 'Только JPG, PNG или WebP'; photoError.hidden = false; return; }
        if (file.size > cfg.maxPhotoBytes) { photoError.textContent = 'Фото больше 2 МБ'; photoError.hidden = false; return; }
        photoError.hidden = true;
        pickedFile = file;
        document.getElementById('p-remove-photo').value = '0';
        if (objectUrl) { URL.revokeObjectURL(objectUrl); }
        objectUrl = URL.createObjectURL(file);
        photoPreview.src = objectUrl;
    }
    document.getElementById('p-photo-pick').addEventListener('click', function () { photoInput.click(); });
    photoDrop.addEventListener('click', function () { photoInput.click(); });
    photoDrop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); photoInput.click(); } });
    photoInput.addEventListener('change', function () { if (photoInput.files[0]) { pickPhoto(photoInput.files[0]); } });
    ['dragenter', 'dragover'].forEach(function (t) { photoDrop.addEventListener(t, function (e) { e.preventDefault(); photoDrop.classList.add('is-drag'); }); });
    ['dragleave', 'drop'].forEach(function (t) { photoDrop.addEventListener(t, function (e) { e.preventDefault(); photoDrop.classList.remove('is-drag'); }); });
    photoDrop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) { pickPhoto(e.dataTransfer.files[0]); } });
    document.getElementById('p-photo-reset').addEventListener('click', function () {
        pickedFile = null; photoInput.value = '';
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        document.getElementById('p-remove-photo').value = '1';
        photoPreview.src = img(cfg.placeholder);
        photoError.hidden = true;
        J.toast('Вернём плейсхолдер после сохранения', 'info', 2200);
    });

    document.getElementById('prod-save').addEventListener('click', function () {
        if (saving) { return; }
        clearPerr();

        var id = parseInt(document.getElementById('p-id').value, 10);
        var name = document.getElementById('p-name').value.trim();
        var model = document.getElementById('p-model').value.trim();
        var price = parseFloat(document.getElementById('p-price').value);
        var stock = parseInt(document.getElementById('p-stock').value, 10);
        var specsRaw = document.getElementById('p-specs').value.trim();
        var errors = {};

        if (name.length < 2) { errors.name = 'Название: минимум 2 символа'; }
        if (!model) { errors.model = 'Укажите модель'; }
        if (!(price > 0)) { errors.price = 'Цена должна быть больше нуля'; }
        if (isNaN(stock) || stock < 0) { errors.stock = 'Остаток: 0 или больше'; }
        if (specsRaw !== '') {
            try { JSON.parse(specsRaw); } catch (err) { errors.specs = 'Ошибка JSON: ' + err.message; }
        }
        Object.keys(errors).forEach(function (k) { perr(k, errors[k]); });
        if (Object.keys(errors).length) { J.toast('Проверьте поля формы', 'warning'); return; }

        var form = new FormData();
        form.append('action', id > 0 ? 'update' : 'create');
        form.append('id', id);
        form.append('csrf', cfg.csrf);
        form.append('name', name);
        form.append('model', model);
        form.append('slug', document.getElementById('p-slug').value.trim());
        form.append('brand_id', document.getElementById('p-brand').value);
        form.append('category_id', document.getElementById('p-category').value);
        form.append('price', price);
        form.append('old_price', document.getElementById('p-old').value.trim());
        form.append('stock', stock);
        form.append('is_active', document.getElementById('p-active').checked ? '1' : '0');
        form.append('description', document.getElementById('p-desc').value);
        form.append('specs', specsRaw);
        form.append('remove_photo', document.getElementById('p-remove-photo').value);
        if (pickedFile) { form.append('photo', pickedFile); }

        saving = true;
        var saveBtn = document.getElementById('prod-save');
        var original = saveBtn.textContent;
        saveBtn.disabled = true; saveBtn.textContent = 'Сохраняем…';
        photoProgress.classList.add('is-visible');
        photoBar.style.width = '0%';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API + 'products.php', true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', cfg.csrf);
        xhr.withCredentials = true;
        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) { photoBar.style.width = Math.round(e.loaded / e.total * 100) + '%'; }
        });
        xhr.addEventListener('load', function () {
            saving = false;
            saveBtn.disabled = false; saveBtn.textContent = original;
            photoProgress.classList.remove('is-visible');
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (err) { /* пусто */ }
            if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
                J.toast(data.message, 'success');
                closeProduct();
                loadProducts();
                if (loaded.dashboard) { loadDashboard(); }
            } else {
                var fieldErrors = data.errors || {};
                Object.keys(fieldErrors).forEach(function (k) { perr(k, fieldErrors[k]); });
                J.toast(data.error || 'Не удалось сохранить', 'error');
            }
        });
        xhr.addEventListener('error', function () {
            saving = false;
            saveBtn.disabled = false; saveBtn.textContent = original;
            photoProgress.classList.remove('is-visible');
            J.toast('Сеть не ответила, повторите', 'error');
        });
        xhr.send(form);
    });

    /* ============================================================
       СПРАВОЧНИКИ: категории и бренды
       ============================================================ */
    var dictModal = document.getElementById('dict-modal');
    var dictForm = document.getElementById('dict-form');
    var dictCache = { categories: {}, brands: {} };
    var dictFocus = null;

    function dictHost(kind) { return document.getElementById(kind === 'categories' ? 'cat-list' : 'brand-list'); }

    function loadDict(kind) {
        var host = dictHost(kind);
        host.innerHTML = sk(3);
        J.api(API + kind + '.php').then(function (data) {
            dictCache[kind] = {};
            data.items.forEach(function (item) { dictCache[kind][item.id] = item; });
            tabCount(kind, data.total, false);

            host.innerHTML = data.items.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Название</th><th>Slug</th><th class="num">Порядок</th><th class="num">Товаров</th><th></th></tr></thead><tbody>' +
                    data.items.map(function (item) {
                        return '<tr><td><b>' + esc(item.name) + '</b></td><td class="ad-mono">' + esc(item.slug) + '</td>' +
                            '<td class="num">' + item.sort_order + '</td>' +
                            '<td class="num"><span class="badge ' + (item.products_count > 0 ? 'badge--info' : 'badge--user') + '">' + item.products_count + '</span></td>' +
                            '<td><div class="ad-row-actions">' +
                                '<button class="ad-icon-btn" type="button" data-dedit="' + kind + ':' + item.id + '" title="Редактировать"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16z"/><path d="M14 6l4 4"/></svg></button>' +
                                '<button class="ad-icon-btn ad-icon-btn--danger" type="button" data-ddel="' + kind + ':' + item.id + '" title="Удалить"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13"/></svg></button>' +
                            '</div></td></tr>';
                    }).join('') + '</tbody></table></div>'
                : empty('Пусто', 'Добавьте первую запись.', '');
        }).catch(function (err) { fail(host, err.message, 'data-dretry="' + kind + '"'); });
    }
    loaders.categories = function () { loadDict('categories'); };
    loaders.brands = function () { loadDict('brands'); };

    ['categories', 'brands'].forEach(function (kind) {
        dictHost(kind).addEventListener('click', function (e) {
            var retry = e.target.closest('[data-dretry]');
            if (retry) { loadDict(retry.dataset.dretry); return; }

            var edit = e.target.closest('[data-dedit]');
            if (edit) {
                var parts = edit.dataset.dedit.split(':');
                openDict(parts[0], dictCache[parts[0]][parseInt(parts[1], 10)]);
                return;
            }
            var del = e.target.closest('[data-ddel]');
            if (del) {
                var dp = del.dataset.ddel.split(':');
                var item = dictCache[dp[0]][parseInt(dp[1], 10)];
                askConfirm('Удалить запись?', '«' + (item ? item.name : '') + '» будет удалена. Если к ней привязаны товары, сервер откажет.', 'Удалить', function () {
                    J.api(API + dp[0] + '.php', { method: 'POST', body: { action: 'delete', id: parseInt(dp[1], 10) } })
                        .then(function (data) { J.toast(data.message, 'success'); loadDict(dp[0]); })
                        .catch(function (err) { J.toast(err.message, 'error'); });
                });
            }
        });
    });

    function derr(name, message) {
        var slot = dictForm.querySelector('[data-derr="' + name + '"]');
        if (slot) { slot.textContent = message || ''; slot.hidden = !message; }
    }
    function openDict(kind, item) {
        dictFocus = document.activeElement;
        ['name', 'slug', 'sort_order'].forEach(function (k) { derr(k, ''); });
        document.getElementById('d-kind').value = kind;
        document.getElementById('d-id').value = item ? item.id : 0;
        document.getElementById('d-name').value = item ? item.name : '';
        document.getElementById('d-slug').value = item ? item.slug : '';
        document.getElementById('d-sort').value = item ? item.sort_order : 0;
        document.getElementById('dict-title').textContent = (kind === 'categories' ? 'Категория' : 'Бренд') + (item ? ': ' + item.name : ' — новая запись');
        dictModal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        document.getElementById('d-name').focus();
    }
    function closeDict() {
        dictModal.classList.remove('is-open');
        document.body.style.overflow = '';
        if (dictFocus && dictFocus.focus) { dictFocus.focus(); }
    }
    document.querySelectorAll('[data-dict-new]').forEach(function (btn) {
        btn.addEventListener('click', function () { openDict(btn.dataset.dictNew, null); });
    });
    document.getElementById('dict-cancel').addEventListener('click', closeDict);
    dictModal.addEventListener('click', function (e) { if (e.target === dictModal) { closeDict(); } });

    dictForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var kind = document.getElementById('d-kind').value;
        var id = parseInt(document.getElementById('d-id').value, 10);
        var name = document.getElementById('d-name').value.trim();
        ['name', 'slug', 'sort_order'].forEach(function (k) { derr(k, ''); });
        if (name.length < 2) { derr('name', 'Название: минимум 2 символа'); J.toast('Проверьте название', 'warning'); return; }

        var btn = document.getElementById('dict-save');
        btn.disabled = true;
        J.api(API + kind + '.php', {
            method: 'POST',
            body: {
                action: id > 0 ? 'update' : 'create', id: id, name: name,
                slug: document.getElementById('d-slug').value.trim(),
                sort_order: parseInt(document.getElementById('d-sort').value, 10) || 0
            }
        }).then(function (data) {
            btn.disabled = false;
            J.toast(data.message, 'success');
            closeDict();
            loadDict(kind);
        }).catch(function (err) {
            btn.disabled = false;
            var fieldErrors = (err.data && err.data.errors) || {};
            Object.keys(fieldErrors).forEach(function (k) { derr(k, fieldErrors[k]); });
            J.toast(err.message, 'error');
        });
    });

    /* ============================================================
       ЗАКАЗЫ
       ============================================================ */
    var ordList = document.getElementById('ord-list');
    var ordPager = document.getElementById('ord-pager');
    var ordChips = document.getElementById('ord-chips');
    var ordState = { status: 'all', q: '', page: 1 };

    function loadOrders() {
        ordList.innerHTML = sk(3);
        var params = new URLSearchParams();
        params.set('status', ordState.status);
        if (ordState.q) { params.set('q', ordState.q); }
        params.set('page', ordState.page);

        J.api(API + 'orders.php?' + params.toString()).then(function (data) {
            ordState.page = data.page;
            tabCount('orders', data.status_counts.new, data.status_counts.new > 0);

            var chips = [{ key: 'all', label: 'Все', n: data.all_count }];
            Object.keys(cfg.statusLabels).forEach(function (key) { chips.push({ key: key, label: cfg.statusLabels[key], n: data.status_counts[key] || 0 }); });
            ordChips.innerHTML = chips.map(function (chip) {
                return '<button class="ad-chip' + (ordState.status === chip.key ? ' is-active' : '') + '" type="button" data-ostatus="' + chip.key + '">' + esc(chip.label) + '<span class="ad-chip__n">' + chip.n + '</span></button>';
            }).join('');

            ordList.innerHTML = data.orders.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Заказ</th><th>Покупатель</th><th class="num">Сумма</th><th>Статус</th><th>Сменить</th><th></th></tr></thead><tbody>' +
                    data.orders.map(function (o) {
                        var options = '<option value="">—</option>' + o.next_statuses.map(function (s) {
                            return '<option value="' + s + '">' + esc(cfg.statusLabels[s]) + '</option>';
                        }).join('');
                        var main = '<tr><td><b class="ad-mono">' + esc(o.order_number) + '</b><div class="ad-muted">' + esc(fmtDate(o.created_at)) + '</div></td>' +
                            '<td>' + esc(o.full_name) + '<div class="ad-muted">@' + esc(o.login) + ' · ' + esc(o.phone) + '</div></td>' +
                            '<td class="num"><b>' + esc(money(o.total)) + '</b></td>' +
                            '<td>' + statusBadge(o.status) + '</td>' +
                            '<td>' + (o.next_statuses.length
                                ? '<select class="ad-mini-select" data-ostatus-set="' + o.id + '" aria-label="Новый статус">' + options + '</select>'
                                : '<span class="ad-muted">финальный</span>') + '</td>' +
                            '<td><button class="ad-toggle-row" type="button" data-oexpand="' + o.id + '" aria-expanded="false">Состав (' + o.items.length + ')' +
                                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button></td></tr>';

                        var extra = '<tr class="ad-order-extra" data-oextra="' + o.id + '" hidden><td colspan="6"><div class="ad-order-body">' +
                            '<div class="ad-order-meta">' +
                                '<span class="ad-tag">' + esc(cfg.deliveryLabels[o.delivery] || o.delivery) + '</span>' +
                                '<span class="ad-tag">' + esc(cfg.paymentLabels[o.payment] || o.payment) + '</span>' +
                                (o.address ? '<span class="ad-tag">' + esc(o.address) + '</span>' : '') +
                                '<span class="ad-tag">' + esc(o.email) + '</span>' +
                            '</div>' +
                            '<div class="ad-order-items">' + o.items.map(function (it) {
                                return '<div class="ad-oi"><span class="ad-oi__media"><img src="' + esc(img(it.image_path)) + '" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'' + FALLBACK + '\'"></span>' +
                                    '<span><b>' + esc(it.name) + '</b><span>' + it.quantity + ' × ' + esc(money(it.price)) + '</span></span>' +
                                    '<b>' + esc(money(it.line_total)) + '</b></div>';
                            }).join('') + '</div>' +
                            (o.comment ? '<div class="ad-order-comment"><b>Комментарий:</b> ' + esc(o.comment) + '</div>' : '') +
                            '</div></td></tr>';
                        return main + extra;
                    }).join('') + '</tbody></table></div>'
                : empty('Заказов нет', 'По этому фильтру ничего не нашлось.');

            pager(ordPager, data.page, data.pages, 'data-opage');
        }).catch(function (err) { fail(ordList, err.message, 'data-oretry'); });
    }
    loaders.orders = loadOrders;
    document.getElementById('ord-refresh').addEventListener('click', loadOrders);
    document.getElementById('ord-q').addEventListener('input', debounce(function (e) {
        ordState.q = e.target.value.trim(); ordState.page = 1; loadOrders();
    }, 350));
    ordChips.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-ostatus]');
        if (!chip) { return; }
        ordState.status = chip.dataset.ostatus; ordState.page = 1; loadOrders();
    });
    bindPager(ordPager, 'data-opage', function (p) { ordState.page = p; loadOrders(); });

    ordList.addEventListener('click', function (e) {
        if (e.target.closest('[data-oretry]')) { loadOrders(); return; }
        var exp = e.target.closest('[data-oexpand]');
        if (exp) {
            var row = ordList.querySelector('[data-oextra="' + exp.dataset.oexpand + '"]');
            var open = exp.getAttribute('aria-expanded') === 'true';
            exp.setAttribute('aria-expanded', open ? 'false' : 'true');
            if (row) { row.hidden = open; }
        }
    });
    ordList.addEventListener('change', function (e) {
        var select = e.target.closest('[data-ostatus-set]');
        if (!select || !select.value) { return; }
        var id = parseInt(select.dataset.ostatusSet, 10);
        var status = select.value;
        var apply = function () {
            select.disabled = true;
            J.api(API + 'orders.php', { method: 'POST', body: { action: 'set_status', id: id, status: status } })
                .then(function (data) { J.toast(data.message, 'success'); loadOrders(); if (loaded.dashboard) { loadDashboard(); } })
                .catch(function (err) { select.disabled = false; select.value = ''; J.toast(err.message, 'error'); });
        };
        if (status === 'cancelled') {
            askConfirm('Отменить заказ?', 'Остатки по всем позициям вернутся на склад. Действие необратимо.', 'Отменить заказ', apply);
            select.value = '';
        } else { apply(); }
    });

    /* ============================================================
       ПОЛЬЗОВАТЕЛИ
       ============================================================ */
    var usrList = document.getElementById('usr-list');
    var usrPager = document.getElementById('usr-pager');
    var usrChips = document.getElementById('usr-chips');
    var usrState = { role: 'all', q: '', page: 1 };

    function loadUsers() {
        usrList.innerHTML = sk(3);
        var params = new URLSearchParams();
        params.set('role', usrState.role);
        if (usrState.q) { params.set('q', usrState.q); }
        params.set('page', usrState.page);

        J.api(API + 'users.php?' + params.toString()).then(function (data) {
            usrState.page = data.page;
            tabCount('users', data.total, false);
            var chips = [
                { key: 'all', label: 'Все', n: data.role_counts.user + data.role_counts.admin },
                { key: 'admin', label: 'Админы', n: data.role_counts.admin },
                { key: 'user', label: 'Покупатели', n: data.role_counts.user }
            ];
            usrChips.innerHTML = chips.map(function (chip) {
                return '<button class="ad-chip' + (usrState.role === chip.key ? ' is-active' : '') + '" type="button" data-urole="' + chip.key + '">' + esc(chip.label) + '<span class="ad-chip__n">' + chip.n + '</span></button>';
            }).join('');

            usrList.innerHTML = data.items.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Пользователь</th><th>Контакты</th><th class="num">Заказов</th><th class="num">Сумма</th><th>Роль</th></tr></thead><tbody>' +
                    data.items.map(function (u) {
                        var avatar = u.avatar_path
                            ? '<span class="avatar" style="width:34px;height:34px;"><img src="' + esc(img(u.avatar_path)) + '" alt=""></span>'
                            : '<span class="avatar avatar--initials" style="width:34px;height:34px;font-size:12px;">' + esc((u.full_name || u.login).trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('')) + '</span>';
                        return '<tr><td><div class="ad-cell-prod">' + avatar + '<span><b>' + esc(u.full_name) + '</b><span>@' + esc(u.login) + ' · с ' + esc(fmtDate(u.created_at).slice(0, 10)) + '</span></span></div></td>' +
                            '<td>' + esc(u.email) + '<div class="ad-muted">' + esc(u.phone || 'телефон не указан') + '</div></td>' +
                            '<td class="num">' + u.orders_count + '</td>' +
                            '<td class="num"><b>' + esc(money(u.spent)) + '</b></td>' +
                            '<td>' + (u.is_self
                                ? '<span class="badge badge--admin">админ · это вы</span>'
                                : '<select class="ad-mini-select" data-usetrole="' + u.id + '" aria-label="Роль пользователя">' +
                                    '<option value="user"' + (u.role === 'user' ? ' selected' : '') + '>Покупатель</option>' +
                                    '<option value="admin"' + (u.role === 'admin' ? ' selected' : '') + '>Администратор</option></select>') +
                            '</td></tr>';
                    }).join('') + '</tbody></table></div>'
                : empty('Никого не нашли', 'Измените поиск или фильтр роли.');

            pager(usrPager, data.page, data.pages, 'data-upage');
        }).catch(function (err) { fail(usrList, err.message, 'data-uretry'); });
    }
    loaders.users = loadUsers;
    document.getElementById('usr-refresh').addEventListener('click', loadUsers);
    document.getElementById('usr-q').addEventListener('input', debounce(function (e) {
        usrState.q = e.target.value.trim(); usrState.page = 1; loadUsers();
    }, 350));
    usrChips.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-urole]');
        if (!chip) { return; }
        usrState.role = chip.dataset.urole; usrState.page = 1; loadUsers();
    });
    bindPager(usrPager, 'data-upage', function (p) { usrState.page = p; loadUsers(); });
    usrList.addEventListener('click', function (e) { if (e.target.closest('[data-uretry]')) { loadUsers(); } });
    usrList.addEventListener('change', function (e) {
        var select = e.target.closest('[data-usetrole]');
        if (!select) { return; }
        var id = parseInt(select.dataset.usetrole, 10);
        var role = select.value;
        select.disabled = true;
        askConfirm('Сменить роль?', role === 'admin' ? 'Пользователь получит доступ ко всей админке.' : 'Пользователь потеряет доступ к админке.', 'Сменить', function () {
            J.api(API + 'users.php', { method: 'POST', body: { action: 'set_role', id: id, role: role } })
                .then(function (data) { J.toast(data.message, 'success'); loadUsers(); })
                .catch(function (err) { select.disabled = false; J.toast(err.message, 'error'); loadUsers(); });
        });
        setTimeout(function () { if (!confirmModal.classList.contains('is-open')) { select.disabled = false; } }, 100);
    });

    /* ============================================================
       ОБРАЩЕНИЯ
       ============================================================ */
    var fbList = document.getElementById('fb-list');
    var fbPager = document.getElementById('fb-pager');
    var fbChips = document.getElementById('fb-chips');
    var fbState = { filter: 'all', q: '', page: 1 };

    function loadFeedback() {
        fbList.innerHTML = sk(3);
        var params = new URLSearchParams();
        params.set('filter', fbState.filter);
        if (fbState.q) { params.set('q', fbState.q); }
        params.set('page', fbState.page);

        J.api(API + 'feedback.php?' + params.toString()).then(function (data) {
            fbState.page = data.page;
            tabCount('feedback', data.unread_count, data.unread_count > 0);
            var chips = [
                { key: 'all', label: 'Все', n: data.all_count },
                { key: 'unread', label: 'Непрочитанные', n: data.unread_count },
                { key: 'read', label: 'Прочитанные', n: data.all_count - data.unread_count }
            ];
            fbChips.innerHTML = chips.map(function (chip) {
                return '<button class="ad-chip' + (fbState.filter === chip.key ? ' is-active' : '') + '" type="button" data-fbfilter="' + chip.key + '">' + esc(chip.label) + '<span class="ad-chip__n">' + chip.n + '</span></button>';
            }).join('');

            fbList.innerHTML = data.items.length
                ? data.items.map(function (f) {
                    return '<article class="card ad-fb-card' + (f.is_read ? '' : ' is-unread') + '">' +
                        '<div class="ad-fb__head">' +
                            '<span class="ad-fb__who"><span class="ad-fb__name">' + esc(f.name) + (f.is_read ? '' : ' <span class="badge badge--warning">новое</span>') + '</span>' +
                            '<span class="ad-fb__meta">' + esc(f.email) + ' · ' + esc(fmtDate(f.created_at)) + (f.login ? ' · @' + esc(f.login) : ' · гость') + ' · №' + f.id + '</span></span>' +
                        '</div>' +
                        '<p class="ad-fb__msg">' + esc(f.message) + '</p>' +
                        '<div class="ad-fb__actions">' +
                            '<button class="btn btn--ghost btn--sm" type="button" data-fbread="' + f.id + '" data-read="' + (f.is_read ? '0' : '1') + '">' + (f.is_read ? 'Вернуть в непрочитанные' : 'Отметить прочитанным') + '</button>' +
                            '<button class="btn btn--ghost btn--sm" type="button" data-fbdel="' + f.id + '">Удалить</button>' +
                        '</div></article>';
                }).join('')
                : empty('Обращений нет', 'По этому фильтру пусто.');

            pager(fbPager, data.page, data.pages, 'data-fbpage');
        }).catch(function (err) { fail(fbList, err.message, 'data-fbretry'); });
    }
    loaders.feedback = loadFeedback;
    document.getElementById('fb-q').addEventListener('input', debounce(function (e) {
        fbState.q = e.target.value.trim(); fbState.page = 1; loadFeedback();
    }, 350));
    fbChips.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-fbfilter]');
        if (!chip) { return; }
        fbState.filter = chip.dataset.fbfilter; fbState.page = 1; loadFeedback();
    });
    bindPager(fbPager, 'data-fbpage', function (p) { fbState.page = p; loadFeedback(); });
    document.getElementById('fb-read-all').addEventListener('click', function () {
        J.api(API + 'feedback.php', { method: 'POST', body: { action: 'mark_all_read' } })
            .then(function (data) { J.toast(data.message, 'success'); loadFeedback(); if (loaded.dashboard) { loadDashboard(); } })
            .catch(function (err) { J.toast(err.message, 'error'); });
    });
    fbList.addEventListener('click', function (e) {
        if (e.target.closest('[data-fbretry]')) { loadFeedback(); return; }
        var read = e.target.closest('[data-fbread]');
        if (read) {
            read.disabled = true;
            J.api(API + 'feedback.php', { method: 'POST', body: { action: 'mark_read', id: parseInt(read.dataset.fbread, 10), is_read: read.dataset.read === '1' } })
                .then(function (data) { J.toast(data.message, 'success', 2000); loadFeedback(); })
                .catch(function (err) { read.disabled = false; J.toast(err.message, 'error'); });
            return;
        }
        var del = e.target.closest('[data-fbdel]');
        if (del) {
            var id = parseInt(del.dataset.fbdel, 10);
            askConfirm('Удалить обращение?', 'Запись №' + id + ' будет удалена из базы безвозвратно.', 'Удалить', function () {
                J.api(API + 'feedback.php', { method: 'POST', body: { action: 'delete', id: id } })
                    .then(function (data) { J.toast(data.message, 'success'); loadFeedback(); })
                    .catch(function (err) { J.toast(err.message, 'error'); });
            });
        }
    });

    /* ============================================================
       ПРОСМОТР БАЗЫ (только чтение)
       ============================================================ */
    var dbTables = document.getElementById('db-tables');
    var dbRows = document.getElementById('db-rows');
    var dbPager = document.getElementById('db-pager');
    var dbNote = document.getElementById('db-note');
    var dbState = { table: 'products', q: '', page: 1 };

    function loadDb() {
        dbRows.innerHTML = sk(3);
        var params = new URLSearchParams();
        params.set('table', dbState.table);
        if (dbState.q) { params.set('q', dbState.q); }
        params.set('page', dbState.page);

        J.api(API + 'db_view.php?' + params.toString()).then(function (data) {
            dbState.page = data.page;
            dbTables.innerHTML = data.tables.map(function (t) {
                return '<button class="ad-db-table' + (t.name === data.table ? ' is-active' : '') + '" type="button" data-dbtable="' + esc(t.name) + '">' +
                    esc(t.label) + '<span class="ad-db-table__n">' + t.rows + '</span></button>';
            }).join('');

            dbNote.innerHTML = '<div class="notice notice--info" style="margin:0;">Таблица <b>' + esc(data.table) + '</b> · строк: <b>' + data.total + '</b> · только чтение.' +
                (data.note ? ' ' + esc(data.note) : '') + '</div>';

            dbRows.innerHTML = data.rows.length
                ? '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>' +
                    data.columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') +
                    '</tr></thead><tbody>' + data.rows.map(function (row) {
                        return '<tr>' + data.columns.map(function (c) {
                            var value = row[c];
                            if (value === null || value === undefined) { return '<td class="ad-muted">NULL</td>'; }
                            return '<td>' + esc(String(value)) + '</td>';
                        }).join('') + '</tr>';
                    }).join('') + '</tbody></table></div>'
                : empty('Строк нет', 'Таблица пуста или поиск ничего не нашёл.');

            pager(dbPager, data.page, data.pages, 'data-dbpage');
        }).catch(function (err) { fail(dbRows, err.message, 'data-dbretry'); });
    }
    loaders.db = loadDb;
    document.getElementById('db-refresh').addEventListener('click', loadDb);
    document.getElementById('db-q').addEventListener('input', debounce(function (e) {
        dbState.q = e.target.value.trim(); dbState.page = 1; loadDb();
    }, 350));
    dbTables.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-dbtable]');
        if (!btn) { return; }
        dbState.table = btn.dataset.dbtable; dbState.page = 1; dbState.q = '';
        document.getElementById('db-q').value = '';
        loadDb();
    });
    bindPager(dbPager, 'data-dbpage', function (p) { dbState.page = p; loadDb(); });
    dbRows.addEventListener('click', function (e) { if (e.target.closest('[data-dbretry]')) { loadDb(); } });

    /* ---------- Escape закрывает верхний слой ---------- */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (confirmModal.classList.contains('is-open')) { closeConfirm(); return; }
        if (dictModal.classList.contains('is-open')) { closeDict(); return; }
        if (drawer.classList.contains('is-open')) { closeProduct(); }
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
    var initial = new URLSearchParams(window.location.search).get('tab');
    var valid = tabs.some(function (t) { return t.dataset.tab === initial; });
    activate(valid ? initial : 'dashboard');
});
</script>

<?php render_footer(); ?>
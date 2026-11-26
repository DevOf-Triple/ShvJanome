<?php
/**
 * pages/cart.php — корзина «Стежок».
 * Гостю — приглашение войти (корзины гостя не существует ни в сессии, ни в БД).
 * Авторизованному — позиции из cart_items, изменение количества, удаление,
 * пересчёт суммы через fetch, проверка остатка, отмена удаления.
 */

require_once __DIR__ . '/../config/helpers.php';

$user = current_user();
$loginUrl = login_url();
$registerUrl = 'register.php?return=' . urlencode(current_url());

// Серверная сводка для пилюль в hero (дальше её обновляет JS)
$summary = ['positions' => 0, 'units' => 0, 'subtotal' => 0.0];
if ($user) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS positions,
                COALESCE(SUM(ci.quantity), 0) AS units,
                COALESCE(SUM(ci.quantity * p.price), 0) AS subtotal
         FROM cart_items ci JOIN products p ON p.id = ci.product_id
         WHERE ci.user_id = ?'
    );
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $summary = [
            'positions' => (int)$row['positions'],
            'units' => (int)$row['units'],
            'subtotal' => (float)$row['subtotal'],
        ];
    }
}

$cartConfig = [
    'isAuth' => (bool)$user,
    'loginUrl' => $loginUrl,
    'checkoutUrl' => 'checkout.php',
    'catalogUrl' => 'catalog.php',
    'favoritesUrl' => 'cabinet.php?tab=favorites',
    'maxPerItem' => 99,
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Корзина', [
    'description' => 'Корзина интернет-магазина Janome: изменение количества, удаление позиций и расчёт суммы заказа.',
    'body_class' => 'page-cart',
]);
?>

<style>
/* ============================================================
   Корзина «Стежок»: лоскуты-позиции, смета заказа,
   стежковые чекбоксы, отмена удаления, липкая панель.
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
.cart-hero { padding: 34px 0 26px; position: relative; overflow: hidden;
    background: linear-gradient(118deg, rgba(176, 27, 46, .06) 0%, rgba(212, 42, 63, .03) 40%, rgba(255, 255, 255, 0) 72%); }
.cart-hero__row { display: flex; align-items: flex-end; justify-content: space-between; gap: 26px; flex-wrap: wrap; }
.cart-hero__lead { color: var(--muted); max-width: 56ch; margin-bottom: 0; }
.cart-hero__pills { display: flex; gap: 10px; flex-wrap: wrap; }
.cart-pill { padding: 9px 14px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 600 12px/1.25 var(--font-sans); color: var(--muted); }
.cart-pill strong { display: block; font-family: var(--font-serif); font-size: 19px; color: var(--wine-700); }
.cart-hero .tape { margin-top: 24px; }

/* ---------- Раскладка ---------- */
.cart-layout { display: grid; grid-template-columns: 1fr 352px; gap: 28px; align-items: start; padding: 26px 0 60px; }

/* ---------- Заголовок списка и массовые действия ---------- */
.list-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
.list-head__title { font: 700 19px/1.2 var(--font-serif); color: var(--wine-900); margin: 0; }
.list-head__tools { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

/* Стежковый чекбокс */
.cselect { display: inline-flex; align-items: center; gap: 9px; cursor: pointer; font: 500 13px/1 var(--font-sans); color: var(--muted); user-select: none; }
.cselect input { position: absolute; opacity: 0; width: 0; height: 0; }
.cselect__box {
    width: 22px; height: 22px; flex: none; border-radius: var(--radius-sm);
    background: var(--white); border: 1px solid var(--line); position: relative;
    display: grid; place-items: center; color: transparent;
    transition: background-color .2s, border-color .2s, color .2s, transform .15s;
}
.cselect__box::before { content: ""; position: absolute; inset: 3px; border: 1px dashed rgba(176, 27, 46, .28); border-radius: 3px; }
.cselect:hover .cselect__box { border-color: var(--wine-300); }
.cselect input:checked + .cselect__box { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); transform: scale(1.05); }
.cselect input:checked + .cselect__box::before { border-color: rgba(255, 255, 255, .5); }
.cselect input:focus-visible + .cselect__box { outline: 2px solid var(--wine-300); outline-offset: 2px; }

.bulkbar {
    display: none; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    padding: 11px 15px; margin-bottom: 14px;
    background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .35); border-radius: var(--radius);
    animation: bulk-in .28s cubic-bezier(.22,.61,.36,1) both;
}
.bulkbar.is-visible { display: flex; }
@keyframes bulk-in { from { opacity: 0; transform: translateY(-8px); } }
.bulkbar__text { font: 600 13px/1.3 var(--font-sans); color: var(--wine-700); }
.bulkbar__actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ---------- Позиция корзины ---------- */
.cart-list { display: grid; gap: 16px; }
.crow {
    display: grid; grid-template-columns: 26px 128px 1fr 208px; gap: 16px;
    padding: 14px; align-items: start;
    animation: crow-in .45s cubic-bezier(.22,.61,.36,1) both;
    animation-delay: calc(var(--i, 0) * 60ms);
}
@keyframes crow-in { from { opacity: 0; transform: translateY(18px); } }
.crow.is-leaving { animation: crow-out .3s ease forwards; pointer-events: none; }
@keyframes crow-out { to { opacity: 0; transform: translateX(28px) scale(.97); } }
.crow.is-busy { opacity: .62; }
.crow.is-problem { border-color: rgba(184, 134, 11, .55); }
.crow__check { padding-top: 4px; }
.crow__media { position: relative; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); }
.crow__media img { width: 100%; height: 100%; object-fit: cover; transition: transform .45s ease; }
.crow:hover .crow__media img { transform: scale(1.06); }
.crow__media::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 6px; background: repeating-linear-gradient(45deg, rgba(176, 27, 46, .5) 0 4px, transparent 4px 8px); }
.crow__num {
    position: absolute; top: 7px; left: 7px; padding: 3px 7px;
    background: var(--wine-900); color: var(--white); border-radius: var(--radius-sm);
    font: 700 9.5px/1.4 var(--font-sans); letter-spacing: .1em;
}
.crow__brand { font: 600 10px/1 var(--font-sans); letter-spacing: .13em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.crow__name { font: 700 16.5px/1.35 var(--font-serif); margin: 0 0 7px; }
.crow__name a { color: var(--wine-900); text-decoration: none; transition: color .2s; }
.crow__name a:hover { color: var(--wine-700); }
.crow__model { display: inline-block; margin-bottom: 9px; padding: 3px 9px; background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm); font: 600 10px/1.5 var(--font-sans); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
.crow__specs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 9px; }
.crow__spec { font: 500 11px/1.3 var(--font-sans); color: var(--muted); padding: 3px 8px; background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm); }
.crow__warn { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin-top: 9px; padding: 8px 11px; background: var(--warning-bg); border-left: 3px solid var(--warning); border-radius: var(--radius-sm); font: 600 12px/1.4 var(--font-sans); color: #7A5A08; }
.crow__fix { padding: 5px 10px; border: 1px solid rgba(184, 134, 11, .5); background: var(--white); color: #7A5A08; border-radius: var(--radius-sm); font: 700 11px/1 var(--font-sans); cursor: pointer; transition: background-color .2s, transform .15s; }
.crow__fix:hover { background: var(--warning); color: var(--white); transform: translateY(-1px); }

.crow__side { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
.crow__unit { font: 600 12.5px/1.2 var(--font-sans); color: var(--muted); text-align: right; }
.crow__unit s { color: var(--line); margin-left: 6px; }
.crow__total { font: 700 22px/1.1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.crow__actions { display: flex; gap: 8px; }
.crow__icon {
    width: 36px; height: 36px; border-radius: var(--radius); background: var(--white);
    border: 1px solid var(--line); color: var(--wine-700); display: grid; place-items: center;
    cursor: pointer; transition: border-color .2s, color .2s, background-color .2s, transform .15s;
}
.crow__icon:hover { border-color: var(--wine-300); background: var(--linen); }
.crow__icon:active { transform: scale(.92); }
.crow__icon.is-active { color: var(--wine-500); border-color: var(--wine-500); background: var(--wine-050); }
.crow__icon.is-active svg path { fill: currentColor; }
.crow__icon.is-burst::after { content: ""; position: absolute; inset: -4px; border-radius: var(--radius); border: 2px solid var(--wine-300); animation: crow-burst .55s ease-out forwards; pointer-events: none; }
.crow__icon { position: relative; }
@keyframes crow-burst { to { transform: scale(1.5); opacity: 0; } }
.crow__icon--danger:hover { border-color: var(--danger); color: var(--danger); background: var(--danger-bg); }

/* Степпер количества */
.cqty { display: flex; align-items: stretch; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; background: var(--white); }
.cqty__btn { width: 36px; border: 0; background: var(--linen); color: var(--wine-700); font: 400 19px/1 var(--font-serif); cursor: pointer; transition: background-color .2s, color .2s; }
.cqty__btn:hover:not(:disabled) { background: var(--wine-500); color: var(--white); }
.cqty__btn:active:not(:disabled) { transform: scale(.93); }
.cqty__btn:disabled { opacity: .38; cursor: not-allowed; }
.cqty__input { width: 54px; border: 0; border-left: 1px solid var(--line); border-right: 1px solid var(--line); text-align: center; font: 700 15px/1 var(--font-serif); color: var(--wine-900); padding: 9px 2px; /*-moz-appearance: textfield;*/ }
.cqty__input::-webkit-outer-spin-button, .cqty__input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.cqty__input:focus { outline: none; background: var(--wine-050); }
.cqty-tape { width: 126px; }

/* ---------- Смета ---------- */
.summary { position: sticky; top: calc(var(--header-h) + 20px); }
.summary__inner { padding: 22px 20px; }
.summary__title { font: 700 19px/1.2 var(--font-serif); color: var(--wine-900); margin: 0 0 16px; }
.summary__row { display: flex; align-items: baseline; gap: 8px; margin-bottom: 10px; font-size: 13.5px; }
.summary__row span { color: var(--muted); flex: none; }
.summary__row i { flex: 1; border-bottom: 1px dotted var(--line); transform: translateY(-3px); }
.summary__row b { font-weight: 600; color: var(--ink); flex: none; }
.summary__row--save b { color: var(--success); }
.summary__total { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 16px 0 6px; }
.summary__total span { font: 600 13px/1 var(--font-sans); color: var(--muted); }
.summary__total strong { font: 700 31px/1.1 var(--font-serif); color: var(--wine-700); }
.summary__note { font-size: 11.5px; color: var(--muted); margin: 0 0 16px; }
.summary__actions { display: grid; gap: 10px; }
.summary__tape { margin-top: 16px; }
.summary__problems { display: none; margin-bottom: 14px; }
.summary__problems.is-visible { display: block; }
.summary__trust { margin-top: 16px; padding-top: 14px; border-top: 2px dashed var(--line); display: grid; gap: 9px; }
.summary__trust div { display: flex; align-items: flex-start; gap: 9px; font-size: 12px; color: var(--muted); }
.summary__trust svg { color: var(--wine-500); flex: none; margin-top: 1px; }

/* ---------- Скелетоны и пусто ---------- */
.csk { display: grid; grid-template-columns: 26px 128px 1fr 208px; gap: 16px; padding: 14px; }
.sk { background: linear-gradient(90deg, var(--linen) 25%, #F4E9EB 37%, var(--linen) 63%); background-size: 400% 100%; animation: sk-sweep 1.3s ease infinite; border-radius: var(--radius-sm); }
@keyframes sk-sweep { from { background-position: 100% 0; } to { background-position: 0 0; } }
.sk--media { aspect-ratio: 4 / 3; }
.sk--line { height: 13px; margin-bottom: 8px; }
.sk--short { width: 52%; }
.sk--tall { height: 74px; }

.cart-empty { text-align: center; padding: 54px 24px; }
.cart-empty__art { color: var(--line); margin-bottom: 14px; }
.cart-empty__needle { animation: needle-sway 3.4s ease-in-out infinite; transform-origin: 50% 18%; }
@keyframes needle-sway { 0%, 100% { transform: rotate(-8deg); } 50% { transform: rotate(8deg); } }
.cart-empty__actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

/* ---------- Приглашение гостю ---------- */
.invite { display: grid; grid-template-columns: 1.05fr .95fr; gap: 40px; align-items: center; padding: 34px 30px; }
.invite__title { font-size: clamp(24px, 3vw, 33px); margin-bottom: 12px; }
.invite__text { color: var(--muted); font-size: 15.5px; margin-bottom: 20px; }
.invite__list { list-style: none; margin: 0 0 24px; padding: 0; display: grid; gap: 12px; }
.invite__list li { display: flex; align-items: flex-start; gap: 12px; font-size: 14px; color: var(--ink); }
.invite__list svg { color: var(--wine-500); flex: none; margin-top: 2px; }
.invite__actions { display: flex; gap: 12px; flex-wrap: wrap; }
.invite__art { position: relative; display: grid; place-items: center; min-height: 260px; }
.invite__svg { width: min(340px, 100%); }
.invite__thread { fill: none; stroke: url(#invite-grad); stroke-width: 2.6; stroke-linecap: round; stroke-dasharray: 620; stroke-dashoffset: 620; animation: invite-draw 2.8s ease .3s forwards; }
@keyframes invite-draw { to { stroke-dashoffset: 0; } }
.invite__stitches { fill: none; stroke: var(--wine-300); stroke-width: 2; stroke-dasharray: 6 6; opacity: .5; }
.invite__spool { animation: spool-spin 26s linear infinite; transform-origin: 150px 150px; }
@keyframes spool-spin { to { transform: rotate(360deg); } }
.invite__note { margin-top: 18px; font-size: 12.5px; color: var(--muted); }

/* ---------- Липкая мобильная панель ---------- */
.mcart {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 70; display: none;
    align-items: center; gap: 12px; padding: 11px 16px;
    background: rgba(255, 255, 255, .98); border-top: 2px dashed var(--line);
    box-shadow: 0 -8px 24px rgba(78, 11, 21, .12);
    transform: translateY(110%); transition: transform .3s cubic-bezier(.22,.61,.36,1);
}
.mcart.is-visible { transform: none; }
.mcart__sum { display: flex; flex-direction: column; line-height: 1.15; }
.mcart__sum small { font-size: 10.5px; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; }
.mcart__sum strong { font: 700 20px/1.1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.mcart__btn { flex: 1; }

/* ---------- Подтверждение ---------- */
.confirm { position: fixed; inset: 0; z-index: 210; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(42, 16, 19, .5); }
.confirm.is-open { display: flex; animation: confirm-in .22s ease; }
@keyframes confirm-in { from { opacity: 0; } }
.confirm__box { width: min(430px, 100%); padding: 26px 24px; text-align: center; animation: confirm-pop .3s cubic-bezier(.22,.61,.36,1); }
@keyframes confirm-pop { from { transform: translateY(14px) scale(.97); opacity: 0; } }
.confirm__title { font: 700 21px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 10px; }
.confirm__text { color: var(--muted); font-size: 14px; margin-bottom: 20px; }
.confirm__actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

/* ---------- Тост с действием ---------- */
.toast__action {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 8px;
    padding: 6px 12px; background: var(--wine-500); color: var(--white); border: 0;
    border-radius: var(--radius-sm); cursor: pointer; text-decoration: none;
    font: 600 12px/1 var(--font-sans); transition: background-color .2s, transform .15s;
}
.toast__action:hover { background: var(--wine-300); transform: translateX(2px); }

/* ---------- Адаптив ---------- */
@media (max-width: 1080px) {
    .cart-layout { grid-template-columns: 1fr; }
    .summary { position: static; }
    .invite { grid-template-columns: 1fr; gap: 26px; }
    .invite__art { order: -1; min-height: 200px; }
}
@media (max-width: 860px) {
    .mcart { display: flex; }
    .page-cart .site-footer { padding-bottom: 74px; }
    .crow, .csk { grid-template-columns: 24px 96px 1fr; }
    .crow__side { grid-column: 2 / -1; flex-direction: row; align-items: center; justify-content: space-between; flex-wrap: wrap; }
    .crow__total { order: 3; }
    .cqty-tape { display: none; }
}
@media (max-width: 560px) {
    .crow, .csk { grid-template-columns: 22px 80px 1fr; gap: 12px; }
    .crow__specs { display: none; }
    .cart-hero__pills { width: 100%; }
    .invite { padding: 24px 18px; }
    .invite__actions .btn { width: 100%; }
    .summary__total strong { font-size: 26px; }
}
@media (prefers-reduced-motion: reduce) {
    .crow, .bulkbar, .confirm__box { animation: none; }
    .sk { animation: none; }
    .cart-empty__needle, .invite__spool { animation: none; }
    .invite__thread { stroke-dashoffset: 0; animation: none; }
    .crow__media img { transition: none; }
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
            <li class="crumbs__current" aria-current="page">Корзина</li>
        </ol>
    </div>
</nav>

<?php if (!$user): ?>
    <!-- ================= ГОСТЬ: ПРИГЛАШЕНИЕ ВОЙТИ ================= -->
    <section class="cart-hero">
        <div class="container">
            <span class="label reveal">Корзина</span>
            <h1 class="reveal">Корзина живёт в вашем аккаунте</h1>
            <p class="cart-hero__lead reveal">У гостя корзины нет — ни в сессии, ни в базе. Так задумано: машинка не должна теряться при закрытии вкладки.</p>
            <div class="tape reveal" aria-hidden="true"></div>
        </div>
    </section>

    <section class="section" style="padding-top: 22px;">
        <div class="container">
            <div class="card card--static invite reveal">
                <div>
                    <h2 class="invite__title">Войдите — и подбор продолжится с того же стежка</h2>
                    <p class="invite__text">После входа мы вернём вас прямо в корзину. Всё, что вы отметите, сохранится в аккаунте и будет доступно с любого устройства.</p>
                    <ul class="invite__list">
                        <li>
                            <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M6.2 8h11.6l-1 12H7.2l-1-12z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 8V6a3 3 0 0 1 6 0v2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <span><b>Корзина не теряется.</b> Позиции и количество хранятся в базе, а не во вкладке браузера.</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            <span><b>Избранное поднимается наверх.</b> Отмеченные машинки идут первыми в каталоге при любой сортировке.</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9h7M8.5 13h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <span><b>Заказы и история в кабинете.</b> Оформление с резервом остатка, отмена заказа и смена пароля.</span>
                        </li>
                    </ul>
                    <div class="invite__actions">
                        <a class="btn btn--primary btn--lg" href="<?= e($loginUrl) ?>">Войти в аккаунт</a>
                        <a class="btn btn--ghost btn--lg" href="<?= e($registerUrl) ?>">Создать аккаунт</a>
                    </div>
                    <p class="invite__note">Пока не готовы войти — <a class="link" href="catalog.php">посмотрите каталог</a>: там все модели и фильтры.</p>
                </div>

                <div class="invite__art" aria-hidden="true">
                    <svg class="invite__svg" viewBox="0 0 300 300" focusable="false">
                        <defs>
                            <linearGradient id="invite-grad" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0" stop-color="#B01B2E"/>
                                <stop offset=".55" stop-color="#D42A3F"/>
                                <stop offset="1" stop-color="#7A1220"/>
                            </linearGradient>
                        </defs>
                        <g class="invite__spool">
                            <circle cx="150" cy="150" r="112" fill="none" stroke="#E8D5D8" stroke-width="2" stroke-dasharray="8 8"/>
                            <circle cx="150" cy="150" r="86" fill="none" stroke="#E8D5D8" stroke-width="2"/>
                        </g>
                        <circle cx="150" cy="150" r="64" fill="#FFFFFF" stroke="#7A1220" stroke-width="3"/>
                        <circle cx="150" cy="150" r="27" fill="none" stroke="#B01B2E" stroke-width="3"/>
                        <circle cx="150" cy="150" r="10" fill="#4E0B15"/>
                        <path class="invite__stitches" d="M20 232C80 190 130 268 190 222S268 176 284 196"/>
                        <path class="invite__thread" d="M20 232C80 190 130 268 190 222S268 176 284 196"/>
                        <g transform="translate(228 92) rotate(22)">
                            <line x1="0" y1="0" x2="0" y2="76" stroke="#4E0B15" stroke-width="5" stroke-linecap="round"/>
                            <line x1="0" y1="76" x2="0" y2="92" stroke="#7A1220" stroke-width="3" stroke-linecap="round"/>
                            <ellipse cx="0" cy="14" rx="4.5" ry="10" fill="none" stroke="#7A1220" stroke-width="2.4"/>
                        </g>
                        <path d="M177 128C205 104 214 96 226 100" fill="none" stroke="url(#invite-grad)" stroke-width="2.6" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>

<?php else: ?>
    <!-- ================= АВТОРИЗОВАННЫЙ: КОРЗИНА ================= -->
    <section class="cart-hero">
        <div class="container">
            <div class="cart-hero__row">
                <div class="reveal">
                    <span class="label">Корзина · <?= e($user['full_name']) ?></span>
                    <h1>Ваш заказ на пошив</h1>
                    <p class="cart-hero__lead">Меняйте количество и убирайте лишнее — сумма пересчитается сразу. Остаток проверяется на сервере при каждом изменении.</p>
                </div>
                <div class="cart-hero__pills reveal">
                    <span class="cart-pill"><strong id="pill-positions"><?= $summary['positions'] ?></strong> позиций</span>
                    <span class="cart-pill"><strong id="pill-units"><?= $summary['units'] ?></strong> штук</span>
                    <span class="cart-pill"><strong id="pill-subtotal"><?= e(format_price($summary['subtotal'])) ?></strong> сумма</span>
                </div>
            </div>
            <div class="tape reveal" aria-hidden="true"></div>
        </div>
    </section>

    <div class="container">
        <div class="cart-layout">
            <!-- Список позиций -->
            <section aria-label="Позиции корзины">
                <div class="list-head">
                    <h2 class="list-head__title">Позиции в корзине</h2>
                    <div class="list-head__tools">
                        <label class="cselect" id="select-all-label">
                            <input type="checkbox" id="select-all">
                            <span class="cselect__box" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span>Выделить все</span>
                        </label>
                        <button class="btn btn--ghost btn--sm" id="clear-cart" type="button">Очистить корзину</button>
                    </div>
                </div>

                <div class="bulkbar" id="bulkbar">
                    <span class="bulkbar__text" id="bulk-text">Выбрано: 0</span>
                    <div class="bulkbar__actions">
                        <button class="btn btn--ghost btn--sm" id="bulk-cancel" type="button">Снять выделение</button>
                        <button class="btn btn--primary btn--sm" id="bulk-remove" type="button">Удалить выбранные</button>
                    </div>
                </div>

                <div class="cart-list" id="cart-list" aria-live="polite"></div>
            </section>

            <!-- Смета -->
            <aside class="summary" id="summary" aria-label="Смета заказа">
                <div class="card card--static summary__inner">
                    <h2 class="summary__title">Смета заказа</h2>

                    <div class="notice notice--warning summary__problems" id="summary-problems">
                        Часть позиций требует внимания: остаток изменился или модель снята с продажи. Оформить заказ можно после исправления.
                    </div>

                    <div class="summary__row"><span>Позиций</span><i></i><b id="sum-positions">0</b></div>
                    <div class="summary__row"><span>Товаров</span><i></i><b id="sum-units">0 шт.</b></div>
                    <div class="summary__row"><span>Сумма</span><i></i><b id="sum-subtotal">0 ₽</b></div>
                    <div class="summary__row summary__row--save" id="save-row" hidden><span>Выгода</span><i></i><b id="sum-savings">0 ₽</b></div>

                    <div class="summary__total">
                        <span>Итого</span>
                        <strong id="sum-total" aria-live="polite">0 ₽</strong>
                    </div>
                    <p class="summary__note">Доставка и способ оплаты выбираются на следующем шаге. Остаток резервируется при оформлении заказа.</p>

                    <div class="summary__actions">
                        <a class="btn btn--primary btn--lg btn--block is-disabled" id="checkout-btn" href="checkout.php" aria-disabled="true">Оформить заказ</a>
                        <a class="btn btn--ghost btn--block" href="catalog.php">Продолжить подбор</a>
                    </div>

                    <div class="tape summary__tape" aria-hidden="true"></div>

                    <div class="summary__trust">
                        <div>
                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 11.8l2.2 2.2 4-4.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span>Остаток проверяется при каждом изменении количества.</span>
                        </div>
                        <div>
                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 12a8 8 0 1 1 2.4 5.7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M4 18v-5h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span>Удаление можно отменить — вернём позицию с прежним количеством.</span>
                        </div>
                        <div>
                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.7" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="18" r="1.7" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                            <span>Курьер или самовывоз, оплата картой или при получении.</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <!-- Липкая панель на мобильных -->
    <div class="mcart" id="mcart">
        <span class="mcart__sum"><small>Итого</small><strong id="mcart-total">0 ₽</strong></span>
        <a class="btn btn--primary mcart__btn is-disabled" id="mcart-checkout" href="checkout.php" aria-disabled="true">Оформить</a>
    </div>

    <!-- Подтверждение очистки -->
    <div class="confirm" id="confirm" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <div class="card card--static confirm__box">
            <h2 class="confirm__title" id="confirm-title">Очистить корзину?</h2>
            <p class="confirm__text">Уберём все позиции. Сразу после удаления появится кнопка «Вернуть» — можно отменить.</p>
            <div class="confirm__actions">
                <button class="btn btn--ghost" id="confirm-cancel" type="button">Отмена</button>
                <button class="btn btn--primary" id="confirm-ok" type="button">Очистить</button>
            </div>
        </div>
    </div>

    <script id="cart-config" type="application/json"><?= json_encode($cartConfig, $jsonFlags) ?></script>

    <script>
    // Корзина: загрузка, количество, удаление с отменой, массовые действия, смета
    document.addEventListener('DOMContentLoaded', function () {
        'use strict';

        var cfgEl = document.getElementById('cart-config');
        var J = window.Janome;
        if (!cfgEl || !J) { return; }

        var cfg;
        try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }
        if (!cfg.isAuth) { return; }

        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var esc = J.escapeHtml;
        var money = J.formatPrice;

        var list = document.getElementById('cart-list');
        var bulkbar = document.getElementById('bulkbar');
        var bulkText = document.getElementById('bulk-text');
        var selectAll = document.getElementById('select-all');
        var clearBtn = document.getElementById('clear-cart');
        var checkoutBtn = document.getElementById('checkout-btn');
        var mcart = document.getElementById('mcart');
        var mcartTotal = document.getElementById('mcart-total');
        var mcartCheckout = document.getElementById('mcart-checkout');
        var problemsNote = document.getElementById('summary-problems');
        var saveRow = document.getElementById('save-row');
        var confirmBox = document.getElementById('confirm');

        var state = { items: [], totals: { positions: 0, units: 0, subtotal: 0, savings: 0, problems: 0 }, selected: {} };
        var shownTotal = 0;

        var HEART = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
        var TRASH = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        var FALLBACK = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/></svg>');

        /* ---------- Тост с кнопкой действия (отмена удаления) ---------- */
        function actionToast(message, type, actionText, onAction, duration) {
            var stack = document.getElementById('toast-stack');
            if (!stack) { J.toast(message, type); return; }
            duration = duration || 8000;

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
            el.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 3500); el.classList.remove('is-paused'); });
        }

        /* ---------- Характеристики-чипы ---------- */
        var SPEC_KEYS = [
            ['Количество операций', 'Операций', ''],
            ['Швейных операций', 'Операций', ''],
            ['Нитей', 'Нитей', ''],
            ['Скорость шитья (ст/мин)', 'Скорость', ' ст/мин'],
            ['Скорость (ст/мин)', 'Скорость', ' ст/мин'],
            ['Макс. поле вышивки (мм)', 'Поле', ' мм'],
            ['Встроенных дизайнов', 'Дизайнов', ''],
            ['Челнок', 'Челнок', '']
        ];
        function specChips(specs) {
            var out = [];
            SPEC_KEYS.forEach(function (rule) {
                if (out.length >= 2 || !(rule[0] in specs)) { return; }
                var v = specs[rule[0]];
                if (typeof v === 'boolean') { v = v ? 'да' : 'нет'; }
                if (rule[0] === 'Челнок') { v = String(v).replace(' ротационный', ''); }
                if (typeof v === 'number') { v = v.toLocaleString('ru-RU'); }
                out.push(rule[1] + ': ' + v + rule[2]);
            });
            return out;
        }

        /* ---------- Разметка ---------- */
        function rowHTML(item, index) {
            var num = String(index + 1).padStart(2, '0');
            var problem = item.over_stock || item.unavailable;
            var max = item.max_quantity;
            var chips = specChips(item.specs || {});

            var warn = '';
            if (item.unavailable) {
                warn = '<div class="crow__warn">Позиция недоступна для заказа — уберите её из корзины.</div>';
            } else if (item.over_stock) {
                warn = '<div class="crow__warn">Остаток изменился: доступно ' + item.stock + ' шт.' +
                    '<button class="crow__fix" type="button" data-fix="' + item.product_id + '">Исправить</button></div>';
            }

            return '' +
            '<article class="card crow' + (problem ? ' is-problem' : '') + '" data-row="' + item.product_id + '" style="--i:' + index + '">' +
                '<div class="crow__check">' +
                    '<label class="cselect">' +
                        '<input type="checkbox" data-select="' + item.product_id + '"' + (state.selected[item.product_id] ? ' checked' : '') + ' aria-label="Выбрать ' + esc(item.name) + '">' +
                        '<span class="cselect__box" aria-hidden="true"><svg viewBox="0 0 24 24" width="13" height="13"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
                    '</label>' +
                '</div>' +
                '<a class="crow__media" href="' + esc(item.url) + '" tabindex="-1" aria-hidden="true">' +
                    '<img src="../' + esc(item.image_path) + '" alt="' + esc(item.name) + '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + FALLBACK + '\'">' +
                    '<span class="crow__num">' + num + '</span>' +
                '</a>' +
                '<div class="crow__info">' +
                    '<div class="crow__brand">' + esc(item.brand_name) + ' · ' + esc(item.category_name) + '</div>' +
                    '<h3 class="crow__name"><a href="' + esc(item.url) + '">' + esc(item.name) + '</a></h3>' +
                    '<span class="crow__model">' + esc(item.model) + '</span>' +
                    (chips.length ? '<div class="crow__specs">' + chips.map(function (c) { return '<span class="crow__spec">' + esc(c) + '</span>'; }).join('') + '</div>' : '') +
                    '<span class="stock stock--' + esc(item.stock_status) + '">' + esc(item.stock_text) + '</span>' +
                    warn +
                '</div>' +
                '<div class="crow__side">' +
                    '<div class="crow__unit">' + esc(money(item.price)) + ' за шт.' +
                        (item.old_price && item.old_price > item.price ? '<s>' + esc(money(item.old_price)) + '</s>' : '') +
                    '</div>' +
                    '<div class="cqty">' +
                        '<button class="cqty__btn" type="button" data-qty="minus" data-id="' + item.product_id + '" aria-label="Уменьшить количество"' + (item.quantity <= 1 ? ' disabled' : '') + '>−</button>' +
                        '<input class="cqty__input" type="number" value="' + item.quantity + '" min="1" max="' + max + '" step="1" inputmode="numeric" data-qty-input="' + item.product_id + '" aria-label="Количество ' + esc(item.name) + '"' + (item.unavailable ? ' disabled' : '') + '>' +
                        '<button class="cqty__btn" type="button" data-qty="plus" data-id="' + item.product_id + '" aria-label="Увеличить количество"' + (item.quantity >= max || item.unavailable ? ' disabled' : '') + '>+</button>' +
                    '</div>' +
                    '<div class="crow__total" data-line="' + item.product_id + '">' + esc(money(item.line_total)) + '</div>' +
                    '<div class="crow__actions">' +
                        '<button class="crow__icon' + (item.is_favorite ? ' is-active' : '') + '" type="button" data-fav="' + item.product_id + '" aria-pressed="' + (item.is_favorite ? 'true' : 'false') + '" aria-label="' + (item.is_favorite ? 'Убрать из избранного' : 'В избранное') + '" title="' + (item.is_favorite ? 'Убрать из избранного' : 'В избранное') + '">' + HEART + '</button>' +
                        '<button class="crow__icon crow__icon--danger" type="button" data-remove="' + item.product_id + '" aria-label="Удалить ' + esc(item.name) + '" title="Удалить">' + TRASH + '</button>' +
                    '</div>' +
                '</div>' +
            '</article>';
        }

        function skeletons(n) {
            var html = '';
            for (var i = 0; i < n; i++) {
                html += '<div class="card card--static csk" aria-hidden="true">' +
                    '<div class="sk" style="height:22px"></div>' +
                    '<div class="sk sk--media"></div>' +
                    '<div><div class="sk sk--line"></div><div class="sk sk--line sk--short"></div><div class="sk sk--line sk--short"></div></div>' +
                    '<div class="sk sk--tall"></div>' +
                '</div>';
            }
            return html;
        }

        function emptyHTML() {
            return '<div class="card card--static cart-empty">' +
                '<svg class="cart-empty__art cart-empty__needle" viewBox="0 0 24 24" width="56" height="56" aria-hidden="true">' +
                    '<path d="M12 3v13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
                    '<path d="M12 16v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
                    '<ellipse cx="12" cy="6.5" rx="2" ry="3.4" fill="none" stroke="currentColor" stroke-width="1.6"/>' +
                '</svg>' +
                '<h3 class="empty-state__title">Корзина пока пуста</h3>' +
                '<p class="empty-state__text">Самое время выбрать машинку: фильтры по бренду, типу и цене помогут сузить подбор.</p>' +
                '<div class="cart-empty__actions">' +
                    '<a class="btn btn--primary" href="' + esc(cfg.catalogUrl) + '">Открыть каталог</a>' +
                    '<a class="btn btn--ghost" href="' + esc(cfg.favoritesUrl) + '">Моё избранное</a>' +
                '</div>' +
            '</div>';
        }

        /* ---------- Смета и шапка ---------- */
        function animateNumber(el, target, formatter) {
            if (!el) { return; }
            if (reduced) { el.textContent = formatter(target); return; }
            var from = parseFloat(el.dataset.value || '0');
            var start = null;
            function step(ts) {
                if (start === null) { start = ts; }
                var p = Math.min((ts - start) / 420, 1);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = formatter(from + (target - from) * eased);
                if (p < 1) { requestAnimationFrame(step); } else { el.dataset.value = String(target); }
            }
            requestAnimationFrame(step);
        }

        function paintTotals(totals, checkoutReady) {
            state.totals = totals;
            document.getElementById('sum-positions').textContent = totals.positions;
            document.getElementById('sum-units').textContent = totals.units + ' шт.';
            document.getElementById('sum-subtotal').textContent = money(totals.subtotal);
            document.getElementById('pill-positions').textContent = totals.positions;
            document.getElementById('pill-units').textContent = totals.units;
            document.getElementById('pill-subtotal').textContent = money(totals.subtotal);

            saveRow.hidden = !(totals.savings > 0);
            document.getElementById('sum-savings').textContent = '−' + money(totals.savings);

            var totalEl = document.getElementById('sum-total');
            animateNumber(totalEl, totals.subtotal, money);
            shownTotal = totals.subtotal;
            mcartTotal.textContent = money(totals.subtotal);

            problemsNote.classList.toggle('is-visible', totals.problems > 0);

            var ready = !!checkoutReady && totals.positions > 0 && totals.problems === 0;
            [checkoutBtn, mcartCheckout].forEach(function (btn) {
                btn.classList.toggle('is-disabled', !ready);
                btn.setAttribute('aria-disabled', ready ? 'false' : 'true');
            });

            clearBtn.disabled = totals.positions === 0;
            document.getElementById('select-all-label').style.display = totals.positions ? '' : 'none';
            J.setCounts({ cart: totals.units });
            updateMobileBar();
        }

        function localTotals() {
            var positions = state.items.length;
            var units = 0, subtotal = 0, savings = 0, problems = 0;
            state.items.forEach(function (it) {
                units += it.quantity;
                subtotal += it.price * it.quantity;
                if (it.old_price && it.old_price > it.price) { savings += (it.old_price - it.price) * it.quantity; }
                if (it.unavailable || it.over_stock) { problems++; }
            });
            return { positions: positions, units: units, subtotal: subtotal, savings: savings, problems: problems };
        }

        /* ---------- Выделение ---------- */
        function selectedIds() {
            return Object.keys(state.selected).filter(function (id) { return state.selected[id]; }).map(Number);
        }
        function syncSelection() {
            var ids = selectedIds();
            bulkbar.classList.toggle('is-visible', ids.length > 0);
            bulkText.textContent = 'Выбрано: ' + ids.length;
            var all = state.items.length > 0 && ids.length === state.items.length;
            selectAll.checked = all;
            selectAll.indeterminate = ids.length > 0 && !all;
        }

        /* ---------- Загрузка ---------- */
        function load(silent) {
            if (!silent) { list.innerHTML = skeletons(3); }
            J.api('../api/cart_get.php')
                .then(function (data) {
                    state.items = data.items;
                    // Чистим выделение от исчезнувших позиций
                    Object.keys(state.selected).forEach(function (id) {
                        if (!state.items.some(function (it) { return it.product_id === Number(id); })) { delete state.selected[id]; }
                    });
                    list.innerHTML = state.items.length ? state.items.map(rowHTML).join('') : emptyHTML();
                    paintTotals(data.totals, data.checkout_ready);
                    if (data.favorites_count !== undefined) { J.setCounts({ favorites: data.favorites_count }); }
                    syncSelection();
                })
                .catch(function (err) {
                    if (err.status === 401) { window.location.href = cfg.loginUrl; return; }
                    list.innerHTML = '<div class="card card--static cart-empty">' +
                        '<h3 class="empty-state__title">Корзина не отвечает</h3>' +
                        '<p class="empty-state__text">' + esc(err.message) + '</p>' +
                        '<button class="btn btn--primary" type="button" data-retry>Повторить</button></div>';
                });
        }

        /* ---------- Количество ---------- */
        function findItem(id) {
            return state.items.filter(function (it) { return it.product_id === id; })[0];
        }

        function patchRow(item) {
            var row = list.querySelector('[data-row="' + item.product_id + '"]');
            if (!row) { return; }
            var input = row.querySelector('[data-qty-input]');
            var minus = row.querySelector('[data-qty="minus"]');
            var plus = row.querySelector('[data-qty="plus"]');
            var line = row.querySelector('[data-line]');
            if (input) { input.value = item.quantity; }
            if (minus) { minus.disabled = item.quantity <= 1; }
            if (plus) { plus.disabled = item.quantity >= item.max_quantity || item.unavailable; }
            if (line) { line.textContent = money(item.price * item.quantity); }
        }

        function updateQuantity(id, next) {
            var item = findItem(id);
            if (!item || item.unavailable) { return; }
            next = Math.max(1, Math.min(item.max_quantity, next));
            if (next === item.quantity) { patchRow(item); return; }

            var prev = item.quantity;
            var row = list.querySelector('[data-row="' + id + '"]');

            // Оптимистично обновляем строку и смету
            item.quantity = next;
            item.line_total = item.price * next;
            patchRow(item);
            paintTotals(localTotals(), false);
            if (row) { row.classList.add('is-busy'); }

            J.api('../api/cart_update.php', { method: 'POST', body: { product_id: id, quantity: next } })
                .then(function (data) {
                    if (row) { row.classList.remove('is-busy'); }
                    item.quantity = data.item_quantity;
                    item.line_total = data.line_total;
                    item.stock = data.stock;
                    item.max_quantity = Math.max(1, Math.min(cfg.maxPerItem, data.stock));
                    item.over_stock = item.quantity > data.stock;
                    patchRow(item);
                    paintTotals(data.totals, data.checkout_ready);
                    if (data.capped) {
                        J.toast('Остаток всего ' + data.stock + ' шт. — поставили максимум', 'warning');
                        load(true);
                    }
                })
                .catch(function (err) {
                    if (row) { row.classList.remove('is-busy'); }
                    item.quantity = prev;
                    item.line_total = item.price * prev;
                    patchRow(item);
                    paintTotals(localTotals(), false);
                    if (err.status === 401) { window.location.href = cfg.loginUrl; return; }
                    J.toast(err.message, 'error');
                    load(true);
                });
        }

        /* ---------- Удаление с отменой ---------- */
        function removeItems(ids) {
            if (!ids.length) { return; }
            var snapshot = state.items.filter(function (it) { return ids.indexOf(it.product_id) !== -1; })
                .map(function (it) { return { product_id: it.product_id, name: it.name, quantity: it.quantity }; });
            if (!snapshot.length) { return; }

            // Анимация ухода строк
            ids.forEach(function (id) {
                var row = list.querySelector('[data-row="' + id + '"]');
                if (row) { row.classList.add('is-leaving'); }
                delete state.selected[id];
            });

            var finalize = function () {
                state.items = state.items.filter(function (it) { return ids.indexOf(it.product_id) === -1; });
                list.innerHTML = state.items.length ? state.items.map(rowHTML).join('') : emptyHTML();
                paintTotals(localTotals(), false);
                syncSelection();
            };
            if (reduced) { finalize(); } else { setTimeout(finalize, 300); }

            J.api('../api/cart_remove.php', { method: 'POST', body: { product_ids: ids } })
                .then(function (data) {
                    paintTotals(data.totals, data.checkout_ready);
                    var removed = data.removed || snapshot;
                    var message = removed.length === 1
                        ? 'Убрали «' + removed[0].name + '»'
                        : 'Убрали позиций: ' + removed.length;

                    actionToast(message, 'info', 'Вернуть', function () {
                        undoRemove(removed);
                    });
                })
                .catch(function (err) {
                    if (err.status === 401) { window.location.href = cfg.loginUrl; return; }
                    J.toast(err.message, 'error');
                    load(true);
                });
        }

        function undoRemove(removed) {
            var chain = Promise.resolve();
            var restored = 0;
            var failed = 0;
            removed.forEach(function (item) {
                chain = chain.then(function () {
                    return J.api('../api/cart_add.php', {
                        method: 'POST',
                        body: { product_id: item.product_id, quantity: item.quantity }
                    }).then(function (data) {
                        restored++;
                        if (data.capped) { failed++; }
                    }).catch(function () { failed++; });
                });
            });
            chain.then(function () {
                load(true);
                if (restored && !failed) { J.toast('Вернули в корзину', 'success'); }
                else if (restored) { J.toast('Вернули не всё: часть остатков изменилась', 'warning'); }
                else { J.toast('Вернуть не удалось — остатки изменились', 'error'); }
            });
        }

        /* ---------- Избранное ---------- */
        function toggleFavorite(btn) {
            var id = parseInt(btn.dataset.fav, 10);
            if (btn.classList.contains('is-busy')) { return; }
            btn.classList.add('is-busy');

            J.api('../api/favorite_toggle.php', { method: 'POST', body: { product_id: id } })
                .then(function (data) {
                    btn.classList.remove('is-busy');
                    btn.classList.toggle('is-active', data.favorite);
                    btn.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
                    var label = data.favorite ? 'Убрать из избранного' : 'В избранное';
                    btn.setAttribute('aria-label', label);
                    btn.title = label;
                    var item = findItem(id);
                    if (item) { item.is_favorite = data.favorite; }
                    if (data.favorite && !reduced) {
                        btn.classList.remove('is-burst');
                        void btn.offsetWidth;
                        btn.classList.add('is-burst');
                        setTimeout(function () { btn.classList.remove('is-burst'); }, 600);
                    }
                    J.setCounts({ favorites: data.favorites_count });
                    J.toast(data.favorite ? 'Добавлено в избранное' : 'Убрано из избранного', data.favorite ? 'success' : 'info', 2400);
                })
                .catch(function (err) {
                    btn.classList.remove('is-busy');
                    if (err.status === 401) { window.location.href = cfg.loginUrl; return; }
                    J.toast(err.message, 'error');
                });
        }

        /* ---------- События списка ---------- */
        list.addEventListener('click', function (e) {
            var qtyBtn = e.target.closest('[data-qty]');
            if (qtyBtn) {
                var id = parseInt(qtyBtn.dataset.id, 10);
                var item = findItem(id);
                if (item) { updateQuantity(id, item.quantity + (qtyBtn.dataset.qty === 'plus' ? 1 : -1)); }
                return;
            }
            var removeBtn = e.target.closest('[data-remove]');
            if (removeBtn) { removeItems([parseInt(removeBtn.dataset.remove, 10)]); return; }

            var favBtn = e.target.closest('[data-fav]');
            if (favBtn) { toggleFavorite(favBtn); return; }

            var fixBtn = e.target.closest('[data-fix]');
            if (fixBtn) {
                var fixId = parseInt(fixBtn.dataset.fix, 10);
                var fixItem = findItem(fixId);
                if (fixItem) { updateQuantity(fixId, fixItem.stock); }
                return;
            }
            if (e.target.closest('[data-retry]')) { load(); }
        });

        list.addEventListener('change', function (e) {
            var input = e.target.closest('[data-qty-input]');
            if (input) {
                var id = parseInt(input.dataset.qtyInput, 10);
                var n = parseInt(input.value, 10);
                if (isNaN(n)) { n = 1; }
                updateQuantity(id, n);
                return;
            }
            var check = e.target.closest('[data-select]');
            if (check) {
                var pid = parseInt(check.dataset.select, 10);
                if (check.checked) { state.selected[pid] = true; } else { delete state.selected[pid]; }
                syncSelection();
            }
        });

        list.addEventListener('keydown', function (e) {
            var input = e.target.closest('[data-qty-input]');
            if (input && e.key === 'Enter') { e.preventDefault(); input.blur(); }
        });

        /* ---------- Массовые действия ---------- */
        selectAll.addEventListener('change', function () {
            if (selectAll.checked) {
                state.items.forEach(function (it) { state.selected[it.product_id] = true; });
            } else {
                state.selected = {};
            }
            list.querySelectorAll('[data-select]').forEach(function (box) {
                box.checked = !!state.selected[parseInt(box.dataset.select, 10)];
            });
            syncSelection();
        });

        document.getElementById('bulk-cancel').addEventListener('click', function () {
            state.selected = {};
            list.querySelectorAll('[data-select]').forEach(function (box) { box.checked = false; });
            syncSelection();
        });

        document.getElementById('bulk-remove').addEventListener('click', function () {
            removeItems(selectedIds());
        });

        /* ---------- Очистка с подтверждением ---------- */
        var lastFocus = null;
        function openConfirm() {
            if (!state.items.length) { return; }
            lastFocus = document.activeElement;
            confirmBox.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            document.getElementById('confirm-cancel').focus();
        }
        function closeConfirm() {
            confirmBox.classList.remove('is-open');
            document.body.style.overflow = '';
            if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        }
        clearBtn.addEventListener('click', openConfirm);
        document.getElementById('confirm-cancel').addEventListener('click', closeConfirm);
        document.getElementById('confirm-ok').addEventListener('click', function () {
            closeConfirm();
            removeItems(state.items.map(function (it) { return it.product_id; }));
        });
        confirmBox.addEventListener('click', function (e) { if (e.target === confirmBox) { closeConfirm(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && confirmBox.classList.contains('is-open')) { closeConfirm(); }
        });

        /* ---------- Липкая панель и прогресс-нить ---------- */
        var summary = document.getElementById('summary');
        function updateMobileBar() {
            if (!mcart) { return; }
            var shouldShow = state.items.length > 0 && window.innerWidth <= 860 && !summaryVisible;
            mcart.classList.toggle('is-visible', shouldShow);
        }
        var summaryVisible = true;
        if (summary && 'IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    summaryVisible = entry.isIntersecting;
                    updateMobileBar();
                });
            }, { threshold: 0, rootMargin: '-80px 0px 0px 0px' });
            io.observe(summary);
        }
        window.addEventListener('resize', updateMobileBar);

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

        /* ---------- Оформление ---------- */
        [checkoutBtn, mcartCheckout].forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                if (btn.classList.contains('is-disabled')) {
                    e.preventDefault();
                    J.toast(state.totals.problems > 0 ? 'Сначала исправьте проблемные позиции' : 'Корзина пуста', 'warning');
                }
            });
        });

        /* ---------- Старт ---------- */
        load();
    });
    </script>
<?php endif; ?>

<?php render_footer(); ?>
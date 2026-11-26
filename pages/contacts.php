<?php
/**
 * pages/contacts.php — контакты «Стежок».
 * Телефоны, почта, адрес, часы, реквизиты и рисованная схема-плейсхолдер карты.
 * Никаких mailto: почта копируется в буфер, письмо пишется через форму в БД.
 */

require_once __DIR__ . '/../config/helpers.php';

$shopAddress = 'Москва, ул. Швейная, 12';
$legalAddress = '105005, Москва, ул. Швейная, 12, офис 4';
$metro = 'Бауманская';

$phones = [
    ['label' => 'Отдел продаж', 'value' => '+7 (495) 120-34-56', 'tel' => '+74951203456', 'note' => 'Подбор и заказы'],
    ['label' => 'Сервис и гарантия', 'value' => '+7 (495) 120-34-78', 'tel' => '+74951203478', 'note' => 'Ремонт и обслуживание'],
    ['label' => 'Бесплатно по России', 'value' => '8 (800) 555-01-23', 'tel' => '+78005550123', 'note' => 'Звонок бесплатный'],
];

$emails = [
    ['label' => 'Общие вопросы', 'value' => 'hello@janome.example'],
    ['label' => 'Сервисный центр', 'value' => 'service@janome.example'],
    ['label' => 'Опт и сотрудничество', 'value' => 'opt@janome.example'],
];

// Индекс = номер дня недели: 0 — воскресенье
$schedule = [
    0 => null,
    1 => ['10:00', '20:00'],
    2 => ['10:00', '20:00'],
    3 => ['10:00', '20:00'],
    4 => ['10:00', '20:00'],
    5 => ['10:00', '20:00'],
    6 => ['10:00', '20:00'],
];
$dayNames = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 0 => 'Воскресенье'];
$dayShort = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 0 => 'вс'];

$requisites = [
    'Наименование' => 'ООО «Жаноме Рус»',
    'Юридический адрес' => $legalAddress,
    'ИНН' => '7701234567',
    'КПП' => '770101001',
    'ОГРН' => '1157746123456',
    'ОКПО' => '12345678',
    'Расчётный счёт' => '40702810100000012345',
    'Банк' => 'АО «Банк «Стежок»», г. Москва',
    'БИК' => '044525999',
    'Корр. счёт' => '30101810400000000999',
    'Генеральный директор' => 'Иванова Мария Сергеевна',
];

$contactsConfig = [
    'address' => $shopAddress,
    'legalAddress' => $legalAddress,
    'metro' => $metro,
    'schedule' => $schedule,
    'dayShort' => $dayShort,
    'requisites' => $requisites,
    'feedbackUrl' => 'feedback.php',
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Контакты', [
    'description' => 'Контакты магазина Janome: телефоны, почта, адрес шоурума на Швейной, часы работы, реквизиты и схема проезда.',
    'body_class' => 'page-contacts',
]);
?>

<style>
/* ============================================================
   Контакты «Стежок»: карточки-лоскуты, живой статус работы,
   рисованная схема квартала, реквизиты с копированием.
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
.ct-hero { padding: 38px 0 28px; position: relative; overflow: hidden;
    background: linear-gradient(118deg, rgba(176, 27, 46, .065) 0%, rgba(212, 42, 63, .03) 42%, rgba(255, 255, 255, 0) 74%); }
.ct-hero__row { display: flex; align-items: flex-end; justify-content: space-between; gap: 26px; flex-wrap: wrap; }
.ct-hero__lead { color: var(--muted); max-width: 58ch; margin-bottom: 0; }
.ct-pills { display: flex; gap: 10px; flex-wrap: wrap; }
.ct-pill { display: block; padding: 9px 14px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 600 12px/1.25 var(--font-sans); color: var(--muted); }
.ct-pill strong { display: block; font-family: var(--font-serif); font-size: 17px; color: var(--wine-700); }
.ct-hero .tape { margin-top: 24px; }

/* Живой статус */
.open-badge { display: inline-flex; align-items: center; gap: 9px; padding: 9px 15px; border-radius: 999px; font: 700 12.5px/1 var(--font-sans); border: 1px solid transparent; }
.open-badge__dot { display: block; width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
.open-badge.is-open { background: var(--success-bg); color: var(--success); border-color: rgba(46, 125, 79, .3); }
.open-badge.is-open .open-badge__dot { animation: open-pulse 2s ease-in-out infinite; }
@keyframes open-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(46, 125, 79, .45); } 50% { box-shadow: 0 0 0 6px rgba(46, 125, 79, 0); } }
.open-badge.is-closed { background: var(--danger-bg); color: var(--danger); border-color: rgba(192, 57, 43, .28); }

/* ---------- Карточки контактов ---------- */
.ct-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
.ct-card { display: flex; flex-direction: column; padding: 20px 18px; }
.ct-card__icon { display: grid; place-items: center; width: 46px; height: 46px; border-radius: 50%; background: var(--wine-050); border: 1px solid var(--line); color: var(--wine-500); margin-bottom: 14px; position: relative; transition: transform .3s; }
.ct-card__icon::before { content: ""; position: absolute; inset: 4px; border: 1px dashed rgba(176, 27, 46, .32); border-radius: 50%; }
.ct-card:hover .ct-card__icon { transform: rotate(-8deg) scale(1.06); }
.ct-card__title { font: 700 16.5px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 12px; }
.ct-rows { display: grid; gap: 12px; }
.ct-row { display: block; }
.ct-row__label { display: block; font: 700 10px/1.4 var(--font-sans); letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 3px; }
.ct-row__line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ct-row__value { font: 600 14.5px/1.35 var(--font-sans); color: var(--ink); word-break: break-word; }
.ct-row__value--link { color: var(--wine-700); text-decoration: none; }
.ct-row__value--link:hover { color: var(--wine-500); }
.ct-row__note { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; }

.copy-btn {
    display: inline-grid; place-items: center; width: 28px; height: 28px; flex: none;
    background: var(--white); border: 1px solid var(--line); border-radius: var(--radius-sm);
    color: var(--muted); cursor: pointer; transition: color .2s, border-color .2s, background-color .2s, transform .15s;
}
.copy-btn:hover { color: var(--wine-700); border-color: var(--wine-300); background: var(--wine-050); }
.copy-btn:active { transform: scale(.9); }
.copy-btn.is-done { color: var(--success); border-color: var(--success); background: var(--success-bg); }

/* Часы */
.hours-list { display: grid; gap: 6px; }
.hours-row { display: flex; align-items: baseline; gap: 8px; padding: 5px 8px; border-radius: var(--radius-sm); font-size: 13px; transition: background-color .25s; }
.hours-row span { color: var(--muted); flex: none; }
.hours-row i { flex: 1; border-bottom: 1px dotted var(--line); transform: translateY(-3px); }
.hours-row b { font-weight: 600; color: var(--ink); flex: none; }
.hours-row.is-today { background: var(--wine-050); }
.hours-row.is-today span, .hours-row.is-today b { color: var(--wine-700); }
.hours-row.is-off b { color: var(--danger); }

/* ---------- Схема проезда ---------- */
.map-block { padding: 22px 20px; }
.map-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.map-title { font: 700 19px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 4px; }
.map-sub { font-size: 13px; color: var(--muted); margin: 0; }
.map-controls { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.map-stage {
    position: relative; border: 1px solid var(--line); border-radius: var(--radius);
    overflow: hidden; background: var(--linen); cursor: grab; touch-action: none;
}
.map-stage.is-drag { cursor: grabbing; }
.map-svg { display: block; width: 100%; height: auto; }
.map-block-rect { fill: var(--wine-050); stroke: var(--line); stroke-width: 1.5; }
.map-park { fill: var(--success-bg); stroke: rgba(46, 125, 79, .45); stroke-width: 1.5; stroke-dasharray: 6 5; }
.map-street { fill: var(--white); stroke: var(--line); stroke-width: 1.2; }
.map-street-line { stroke: var(--line); stroke-width: 2; stroke-dasharray: 10 10; }
.map-label { font: 600 12px var(--font-sans); fill: var(--muted); letter-spacing: .04em; }
.map-label--strong { font: 700 13px var(--font-serif); fill: var(--wine-900); }
.map-shop { fill: var(--white); stroke: var(--wine-500); stroke-width: 2.5; }
.map-route { fill: none; stroke: var(--wine-500); stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 9 8; }
.map-route-group { opacity: 0; transition: opacity .3s; }
.map-route-group.is-on { opacity: 1; }
.map-route-group.is-on .map-route { animation: route-run 1.6s linear infinite; }
@keyframes route-run { to { stroke-dashoffset: -34; } }
.map-metro { fill: var(--wine-700); }
.map-metro-text { font: 700 15px var(--font-serif); fill: var(--white); }
.map-pin-pulse { fill: rgba(176, 27, 46, .22); animation: pin-pulse 2.4s ease-out infinite; transform-origin: 475px 120px; }
.map-pin-pulse--2 { animation-delay: .8s; }
@keyframes pin-pulse { 0% { transform: scale(.5); opacity: .8; } 100% { transform: scale(1.7); opacity: 0; } }
.map-pin-body { fill: var(--wine-500); stroke: var(--white); stroke-width: 2; }
.map-pin-dot { fill: var(--white); }
.map-tape-tick { stroke: var(--wine-500); stroke-width: 2; opacity: .7; }
.map-legend { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 14px; font-size: 12px; color: var(--muted); }
.map-legend__item { display: inline-flex; align-items: center; gap: 7px; }
.map-legend__swatch { display: block; width: 14px; height: 14px; border-radius: 3px; border: 1px solid var(--line); }
.map-note { margin: 14px 0 0; font-size: 11.5px; color: var(--muted); }

/* ---------- Реквизиты ---------- */
.req-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.req-table td:first-child { width: 40%; color: var(--muted); }
.req-table td:last-child { font-weight: 600; font-variant-numeric: tabular-nums; }
.req-note { margin: 14px 0 0; font-size: 12px; color: var(--muted); }

/* ---------- Как добраться ---------- */
.route-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; }

/* ---------- Призыв ---------- */
.ct-cta { display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; padding: 28px 26px; background: var(--wine-900); background-image: var(--weave-light); border-radius: var(--radius); color: rgba(255, 255, 255, .82); }
.ct-cta__title { font: 700 clamp(20px, 2.4vw, 27px)/1.25 var(--font-serif); color: var(--white); margin: 0 0 8px; }
.ct-cta__text { margin: 0; font-size: 14px; max-width: 52ch; }
.ct-cta__actions { display: flex; gap: 12px; flex-wrap: wrap; }
.ct-cta .btn--ghost { background: transparent; color: var(--white); border-color: rgba(255, 255, 255, .35); --stitch-c: rgba(255, 255, 255, .45); }
.ct-cta .btn--ghost:hover { background: rgba(255, 255, 255, .1); border-color: var(--wine-300); }

/* ---------- Адаптив ---------- */
@media (max-width: 1180px) { .ct-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 900px) { .route-grid { grid-template-columns: 1fr; } }
@media (max-width: 620px) {
    .ct-grid { grid-template-columns: 1fr; }
    .ct-pills { width: 100%; }
    .map-block, .ct-card { padding: 18px 16px; }
    .ct-cta { padding: 22px 18px; }
    .ct-cta__actions .btn { width: 100%; }
    .map-controls .btn { flex: 1; }
}
@media (prefers-reduced-motion: reduce) {
    .map-pin-pulse, .map-route-group.is-on .map-route, .open-badge.is-open .open-badge__dot { animation: none; }
    .ct-card__icon { transition: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<nav class="crumbs" aria-label="Навигация по разделам">
    <div class="container">
        <ol class="crumbs__list">
            <li><a href="index.php">Главная</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li class="crumbs__current" aria-current="page">Контакты</li>
        </ol>
    </div>
</nav>

<section class="ct-hero">
    <div class="container">
        <div class="ct-hero__row">
            <div class="reveal">
                <span class="label">Контакты</span>
                <h1>Ателье на Швейной, 12</h1>
                <p class="ct-hero__lead">Шоурум, пункт выдачи и сервисный центр под одной крышей. Семь минут пешком от метро — можно приехать, потрогать машинки и прошить пробный образец.</p>
                <div style="margin-top: 16px;">
                    <span class="open-badge is-closed" id="open-badge">
                        <span class="open-badge__dot" aria-hidden="true"></span>
                        <span id="open-text">Проверяем расписание…</span>
                    </span>
                </div>
            </div>
            <div class="ct-pills reveal">
                <span class="ct-pill"><strong><?= e($metro) ?></strong> 7 минут пешком</span>
                <span class="ct-pill"><strong>Пн–Сб</strong> 10:00–20:00</span>
                <span class="ct-pill"><strong>3</strong> телефона</span>
            </div>
        </div>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<!-- ================= КАРТОЧКИ ================= -->
<section class="section" style="padding-top: 26px;">
    <div class="container">
        <div class="ct-grid" data-stagger>
            <!-- Телефоны -->
            <article class="card ct-card reveal">
                <span class="ct-card__icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 3h3l2 5-2.5 1.5a12 12 0 0 0 6 6L16 13l5 2v3a2 2 0 0 1-2.2 2A17 17 0 0 1 4 5.2 2 2 0 0 1 6 3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                </span>
                <h2 class="ct-card__title">Телефоны</h2>
                <div class="ct-rows">
                    <?php foreach ($phones as $phone): ?>
                        <div class="ct-row">
                            <span class="ct-row__label"><?= e($phone['label']) ?></span>
                            <span class="ct-row__line">
                                <a class="ct-row__value ct-row__value--link" href="tel:<?= e($phone['tel']) ?>"><?= e($phone['value']) ?></a>
                                <button class="copy-btn" type="button" data-copy="<?= e($phone['value']) ?>" aria-label="Скопировать номер <?= e($phone['value']) ?>" title="Скопировать">
                                    <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 6H6a2 2 0 0 0-2 2v9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                            </span>
                            <span class="ct-row__note"><?= e($phone['note']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <!-- Почта -->
            <article class="card ct-card reveal">
                <span class="ct-card__icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect x="3" y="5.5" width="18" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3.6 7l8.4 6 8.4-6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <h2 class="ct-card__title">Почта</h2>
                <div class="ct-rows">
                    <?php foreach ($emails as $mail): ?>
                        <div class="ct-row">
                            <span class="ct-row__label"><?= e($mail['label']) ?></span>
                            <span class="ct-row__line">
                                <span class="ct-row__value"><?= e($mail['value']) ?></span>
                                <button class="copy-btn" type="button" data-copy="<?= e($mail['value']) ?>" aria-label="Скопировать адрес <?= e($mail['value']) ?>" title="Скопировать">
                                    <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 6H6a2 2 0 0 0-2 2v9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="ct-row__note" style="margin-top: 14px;">Письмо лучше отправить <a class="link" href="feedback.php">через форму</a> — оно сразу попадёт в очередь и не потеряется.</p>
            </article>

            <!-- Адрес -->
            <article class="card ct-card reveal">
                <span class="ct-card__icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="10" r="2.6" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                </span>
                <h2 class="ct-card__title">Адрес</h2>
                <div class="ct-rows">
                    <div class="ct-row">
                        <span class="ct-row__label">Шоурум и выдача</span>
                        <span class="ct-row__line">
                            <span class="ct-row__value"><?= e($shopAddress) ?></span>
                            <button class="copy-btn" type="button" data-copy="<?= e($shopAddress) ?>" aria-label="Скопировать адрес" title="Скопировать">
                                <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 6H6a2 2 0 0 0-2 2v9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            </button>
                        </span>
                        <span class="ct-row__note">Вход со стороны улицы, вывеска «Janome»</span>
                    </div>
                    <div class="ct-row">
                        <span class="ct-row__label">Метро</span>
                        <span class="ct-row__value"><?= e($metro) ?></span>
                        <span class="ct-row__note">7 минут пешком, маршрут на схеме ниже</span>
                    </div>
                    <div class="ct-row">
                        <span class="ct-row__label">Юридический адрес</span>
                        <span class="ct-row__value"><?= e($legalAddress) ?></span>
                    </div>
                </div>
            </article>

            <!-- Часы -->
            <article class="card ct-card reveal">
                <span class="ct-card__icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <h2 class="ct-card__title">Часы работы</h2>
                <div class="hours-list" id="hours-list">
                    <?php foreach ($dayNames as $index => $dayName): ?>
                        <div class="hours-row<?= $schedule[$index] === null ? ' is-off' : '' ?>" data-day="<?= $index ?>">
                            <span><?= e($dayName) ?></span><i></i>
                            <b><?= $schedule[$index] === null ? 'выходной' : e($schedule[$index][0] . '–' . $schedule[$index][1]) ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="ct-row__note" style="margin-top: 12px;">Сервисный центр принимает технику до 19:00.</p>
            </article>
        </div>
    </div>
</section>

<!-- ================= СХЕМА ================= -->
<section class="section section--linen">
    <div class="container">
        <div class="card card--static map-block reveal">
            <div class="map-head">
                <div>
                    <h2 class="map-title">Схема квартала</h2>
                    <p class="map-sub">Плейсхолдер карты: квартал нарисован вручную в стиле «Стежок». Тяните схему мышью, масштаб — кнопками.</p>
                </div>
                <div class="map-controls">
                    <button class="btn btn--ghost btn--sm" type="button" id="map-route">Маршрут от метро</button>
                    <button class="icon-btn" type="button" id="map-out" aria-label="Уменьшить масштаб">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                    <button class="icon-btn" type="button" id="map-reset" aria-label="Сбросить масштаб">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 9V4h5M20 15v5h-5M15 4h5v5M9 20H4v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button class="icon-btn" type="button" id="map-in" aria-label="Увеличить масштаб">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                </div>
            </div>

            <div class="map-stage" id="map-stage">
                <svg class="map-svg" id="map-svg" viewBox="0 0 800 480" role="img" aria-label="Схема квартала: магазин Janome на улице Швейной, 12, рядом с метро Бауманская">
                    <g id="map-layer">
                        <rect x="0" y="0" width="800" height="480" fill="#FBF6F6"/>

                        <!-- Кварталы -->
                        <rect class="map-block-rect" x="40" y="120" width="230" height="80" rx="4"/>
                        <rect class="map-block-rect" x="600" y="120" width="160" height="80" rx="4"/>
                        <rect class="map-block-rect" x="40" y="300" width="230" height="140" rx="4"/>
                        <rect class="map-block-rect" x="400" y="300" width="150" height="140" rx="4"/>
                        <rect class="map-park" x="600" y="300" width="160" height="140" rx="4"/>
                        <text class="map-label" x="680" y="376" text-anchor="middle">Сквер</text>

                        <!-- Улицы -->
                        <rect class="map-street" x="0" y="210" width="800" height="60"/>
                        <line class="map-street-line" x1="0" y1="240" x2="800" y2="240"/>
                        <rect class="map-street" x="300" y="0" width="55" height="480"/>
                        <line class="map-street-line" x1="327" y1="0" x2="327" y2="480"/>
                        <rect class="map-street" x="0" y="55" width="800" height="42"/>
                        <line class="map-street-line" x1="0" y1="76" x2="800" y2="76"/>

                        <text class="map-label" x="640" y="234">ул. Швейная</text>
                        <text class="map-label" x="640" y="70">пр. Челночный</text>
                        <text class="map-label" x="322" y="452" transform="rotate(-90 322 452)">ул. Игловая</text>

                        <!-- Магазин -->
                        <rect class="map-shop" x="400" y="120" width="150" height="80" rx="4"/>
                        <text class="map-label--strong" x="475" y="166" text-anchor="middle">Janome</text>
                        <text class="map-label" x="475" y="184" text-anchor="middle">шоурум · выдача</text>

                        <!-- Маршрут -->
                        <g class="map-route-group" id="map-route-group">
                            <path class="map-route" d="M150 388 L150 240 L475 240 L475 124"/>
                        </g>

                        <!-- Метро -->
                        <circle class="map-metro" cx="150" cy="388" r="19"/>
                        <text class="map-metro-text" x="150" y="394" text-anchor="middle">М</text>
                        <text class="map-label--strong" x="150" y="428" text-anchor="middle">Бауманская</text>
                        <text class="map-label" x="150" y="446" text-anchor="middle">7 минут пешком</text>

                        <!-- Булавка магазина -->
                        <circle class="map-pin-pulse" cx="475" cy="120" r="18"/>
                        <circle class="map-pin-pulse map-pin-pulse--2" cx="475" cy="120" r="18"/>
                        <path class="map-pin-body" d="M475 120l-9-14a11 11 0 1 1 18 0z"/>
                        <circle class="map-pin-body" cx="475" cy="99" r="12"/>
                        <circle class="map-pin-dot" cx="475" cy="99" r="4.5"/>
                        <text class="map-label--strong" x="475" y="72" text-anchor="middle">Швейная, 12</text>

                        <!-- Масштабная лента -->
                        <g transform="translate(40 452)">
                            <line x1="0" y1="0" x2="160" y2="0" stroke="#E8D5D8" stroke-width="2"/>
                            <path class="map-tape-tick" d="M0 0v-12M40 0v-7M80 0v-7M120 0v-7M160 0v-12"/>
                            <text class="map-label" x="0" y="16">0</text>
                            <text class="map-label" x="160" y="16" text-anchor="end">200 м</text>
                        </g>

                        <!-- Компас-шпуля -->
                        <g transform="translate(742 48)">
                            <circle cx="0" cy="0" r="24" fill="#FFFFFF" stroke="#E8D5D8" stroke-width="1.5" stroke-dasharray="5 5"/>
                            <circle cx="0" cy="0" r="14" fill="none" stroke="#7A1220" stroke-width="2"/>
                            <path d="M0 -13l4 13-4 13-4-13z" fill="#B01B2E"/>
                            <text class="map-label" x="0" y="-28" text-anchor="middle">С</text>
                        </g>
                    </g>
                </svg>
            </div>

            <div class="map-legend">
                <span class="map-legend__item"><span class="map-legend__swatch" style="background: var(--white); border-color: var(--wine-500);"></span>Магазин Janome</span>
                <span class="map-legend__item"><span class="map-legend__swatch" style="background: var(--wine-700); border-color: var(--wine-700);"></span>Метро</span>
                <span class="map-legend__item"><span class="map-legend__swatch" style="background: var(--success-bg); border-color: rgba(46,125,79,.45);"></span>Сквер</span>
                <span class="map-legend__item"><span class="map-legend__swatch" style="background: var(--wine-050);"></span>Кварталы</span>
            </div>

            <p class="map-note">Схема — рисованный плейсхолдер: внешние картографические сервисы в учебном проекте не подключаются. Адрес можно скопировать кнопкой в карточке выше.</p>
        </div>
    </div>
</section>

<!-- ================= КАК ДОБРАТЬСЯ + РЕКВИЗИТЫ ================= -->
<section class="section">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Как добраться</h2>
            <span class="muted small">Пешком, на машине и где вход</span>
        </div>

        <div class="route-grid">
            <div class="reveal">
                <details class="card card--static accordion" open>
                    <summary>Пешком от метро <?= e($metro) ?></summary>
                    <div class="accordion__body">
                        <p>Выход №2 из метро, направо по улице Игловой до пересечения с Швейной. На перекрёстке поверните направо и идите 200 метров — магазин в первом этаже углового дома.</p>
                        <p>Весь путь около 7 минут, дорога освещена и без ступеней.</p>
                    </div>
                </details>
                <details class="card card--static accordion">
                    <summary>На машине и парковка</summary>
                    <div class="accordion__body">
                        <p>Гостевая парковка на четыре места со стороны улицы Игловой, въезд под шлагбаум — назовите охране «Janome, 12».</p>
                        <p>Крупногабаритную технику удобно забирать с торца здания: там площадка для погрузки и тележки.</p>
                    </div>
                </details>
                <details class="card card--static accordion">
                    <summary>Вход и примерочная строчка</summary>
                    <div class="accordion__body">
                        <p>Вход со стороны улицы Швейной, стеклянная дверь с багровой вывеской. Внутри — стойка демонстрации: приносите свою ткань, прошьём пробный образец на выбранной машине.</p>
                        <p>Пункт выдачи заказов работает в том же зале, отдельная стойка справа.</p>
                    </div>
                </details>
            </div>

            <div class="card card--static map-block reveal">
                <div class="req-head">
                    <div>
                        <h3 class="map-title">Реквизиты</h3>
                        <p class="map-sub">Для договоров и бухгалтерии</p>
                    </div>
                    <button class="btn btn--ghost btn--sm" type="button" id="copy-req">Скопировать всё</button>
                </div>
                <div class="table-wrap">
                    <table class="table req-table">
                        <caption class="visually-hidden">Реквизиты организации</caption>
                        <tbody>
                        <?php foreach ($requisites as $label => $value): ?>
                            <tr>
                                <td><?= e($label) ?></td>
                                <td><?= e($value) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="req-note">Реквизиты демонстрационные: проект учебный, реальные платежи и договоры не проводятся.</p>
            </div>
        </div>
    </div>
</section>

<!-- ================= ПРИЗЫВ ================= -->
<section class="section" style="padding-top: 0;">
    <div class="container">
        <div class="ct-cta reveal">
            <div>
                <h2 class="ct-cta__title">Не нашли ответ — напишите нам</h2>
                <p class="ct-cta__text">Форма кладёт обращение прямо в базу и очередь админки. Ответ придёт на почту в течение рабочего дня, ничего не потеряется в спаме.</p>
            </div>
            <div class="ct-cta__actions">
                <a class="btn btn--primary btn--lg" href="feedback.php">Написать письмо</a>
                <a class="btn btn--ghost btn--lg" href="catalog.php">Смотреть каталог</a>
            </div>
        </div>
    </div>
</section>

<script id="contacts-config" type="application/json"><?= json_encode($contactsConfig, $jsonFlags) ?></script>

<script>
// Контакты: копирование, живой статус работы, схема с масштабом и маршрутом
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('contacts-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Копирование ---------- */
    function copyText(text, okMessage) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text)
                .then(function () { J.toast(okMessage, 'success', 2200); })
                .catch(function () { fallbackCopy(text, okMessage); });
        } else {
            fallbackCopy(text, okMessage);
        }
    }
    function fallbackCopy(text, okMessage) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); J.toast(okMessage, 'success', 2200); }
        catch (err) { J.toast('Не удалось скопировать', 'error'); }
        area.remove();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-copy]');
        if (!btn) { return; }
        copyText(btn.dataset.copy, 'Скопировано: ' + btn.dataset.copy);
        btn.classList.add('is-done');
        setTimeout(function () { btn.classList.remove('is-done'); }, 1200);
    });

    var copyReq = document.getElementById('copy-req');
    if (copyReq) {
        copyReq.addEventListener('click', function () {
            var lines = Object.keys(cfg.requisites).map(function (key) {
                return key + ': ' + cfg.requisites[key];
            });
            copyText(lines.join('\n'), 'Реквизиты скопированы');
        });
    }

    /* ---------- Открыто сейчас ---------- */
    var badge = document.getElementById('open-badge');
    var openText = document.getElementById('open-text');
    var dayOrder = [1, 2, 3, 4, 5, 6, 0];

    function toMinutes(hhmm) {
        var parts = String(hhmm).split(':');
        return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
    }

    function paintStatus() {
        var now = new Date();
        var day = now.getDay();
        var minutes = now.getHours() * 60 + now.getMinutes();
        var today = cfg.schedule[day];

        document.querySelectorAll('.hours-row').forEach(function (row) {
            row.classList.toggle('is-today', parseInt(row.dataset.day, 10) === day);
        });

        var isOpen = false;
        var text = '';

        if (today && minutes >= toMinutes(today[0]) && minutes < toMinutes(today[1])) {
            isOpen = true;
            text = 'Открыто сейчас · до ' + today[1];
        } else if (today && minutes < toMinutes(today[0])) {
            text = 'Закрыто · откроется сегодня в ' + today[0];
        } else {
            var nextText = '';
            for (var i = 1; i <= 7; i++) {
                var nextDay = (day + i) % 7;
                var slot = cfg.schedule[nextDay];
                if (slot) {
                    nextText = (i === 1 ? 'завтра' : 'в ' + cfg.dayShort[nextDay]) + ' в ' + slot[0];
                    break;
                }
            }
            text = 'Закрыто · откроется ' + nextText;
        }

        badge.classList.toggle('is-open', isOpen);
        badge.classList.toggle('is-closed', !isOpen);
        openText.textContent = text;
    }
    paintStatus();
    setInterval(paintStatus, 60000);

    /* ---------- Схема: масштаб, перетаскивание, маршрут ---------- */
    var stage = document.getElementById('map-stage');
    var svg = document.getElementById('map-svg');
    var layer = document.getElementById('map-layer');
    var routeGroup = document.getElementById('map-route-group');
    var routeBtn = document.getElementById('map-route');
    var view = { k: 1, x: 0, y: 0 };
    var dragging = false, startX = 0, startY = 0, originX = 0, originY = 0;

    function clampPan() {
        var maxX = 0, minX = -(view.k - 1) * 800;
        var maxY = 0, minY = -(view.k - 1) * 480;
        view.x = Math.max(minX, Math.min(maxX, view.x));
        view.y = Math.max(minY, Math.min(maxY, view.y));
    }
    function applyView() {
        clampPan();
        layer.setAttribute('transform', 'translate(' + view.x.toFixed(2) + ',' + view.y.toFixed(2) + ') scale(' + view.k.toFixed(3) + ')');
    }
    function zoomTo(next) {
        var k = Math.max(1, Math.min(2.6, next));
        var ratio = k / view.k;
        view.x = 400 - (400 - view.x) * ratio;
        view.y = 240 - (240 - view.y) * ratio;
        view.k = k;
        applyView();
    }

    document.getElementById('map-in').addEventListener('click', function () { zoomTo(view.k + 0.35); });
    document.getElementById('map-out').addEventListener('click', function () { zoomTo(view.k - 0.35); });
    document.getElementById('map-reset').addEventListener('click', function () {
        view = { k: 1, x: 0, y: 0 };
        applyView();
        J.toast('Масштаб сброшен', 'info', 1800);
    });

    routeBtn.addEventListener('click', function () {
        var on = routeGroup.classList.toggle('is-on');
        routeBtn.classList.toggle('btn--primary', on);
        routeBtn.classList.toggle('btn--ghost', !on);
        routeBtn.textContent = on ? 'Скрыть маршрут' : 'Маршрут от метро';
        if (on) { J.toast('Показан пеший маршрут от метро ' + cfg.metro, 'info', 2600); }
    });

    stage.addEventListener('pointerdown', function (e) {
        if (view.k <= 1) { return; }
        dragging = true;
        stage.classList.add('is-drag');
        startX = e.clientX; startY = e.clientY;
        originX = view.x; originY = view.y;
        stage.setPointerCapture(e.pointerId);
    });
    stage.addEventListener('pointermove', function (e) {
        if (!dragging) { return; }
        var rect = svg.getBoundingClientRect();
        var scaleX = rect.width > 0 ? 800 / rect.width : 1;
        var scaleY = rect.height > 0 ? 480 / rect.height : 1;
        view.x = originX + (e.clientX - startX) * scaleX;
        view.y = originY + (e.clientY - startY) * scaleY;
        applyView();
    });
    function stopDrag() { dragging = false; stage.classList.remove('is-drag'); }
    stage.addEventListener('pointerup', stopDrag);
    stage.addEventListener('pointercancel', stopDrag);
    stage.addEventListener('pointerleave', stopDrag);

    applyView();

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
});
</script>

<?php render_footer(); ?>
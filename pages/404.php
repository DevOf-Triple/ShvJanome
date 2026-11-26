<?php
/**
 * pages/404.php — «Потерянный стежок»: фирменная страница ошибки.
 * Поиск уходит в каталог через GET, популярные товары берутся из БД,
 * есть ссылки на главную и каталог, возврат назад и копирование адреса.
 */

require_once __DIR__ . '/../config/helpers.php';

http_response_code(404);

// Что показать вместо тупика: три ходовые модели из наличия
$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.model, p.slug, p.price, p.image_path, b.name AS brand_name,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id) AS sold
     FROM products p
     JOIN brands b ON b.id = p.brand_id
     WHERE p.is_active = 1 AND p.stock > 0
     ORDER BY sold DESC, p.stock DESC, p.id ASC
     LIMIT 3'
);
$stmt->execute();
$popular = $stmt->fetchAll();

// Категории для быстрых переходов
$stmt = $pdo->prepare('SELECT name, slug FROM categories ORDER BY sort_order ASC, name ASC');
$stmt->execute();
$categories = $stmt->fetchAll();

$requestedPath = (string)($_SERVER['REQUEST_URI'] ?? '');
$shortPath = mb_strimwidth($requestedPath, 0, 64, '…', 'UTF-8');

$fallbackImg = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/></svg>'
);

render_header('Страница не найдена', [
    'description' => 'Страница не найдена в магазине Janome. Вернитесь на главную или откройте каталог швейных машин.',
    'body_class' => 'page-404',
]);
?>

<style>
/* ============================================================
   404 «Потерянный стежок»: оборванная нить, поиск в каталог,
   ходовые модели и быстрые переходы. Без тупиков.
   ============================================================ */

.nf-hero { position: relative; overflow: hidden; padding: 56px 0 40px;
    background: linear-gradient(124deg, rgba(78, 11, 21, .1) 0%, rgba(176, 27, 46, .05) 36%, rgba(251, 246, 246, 0) 74%); }
.nf-hero::before { content: ""; position: absolute; inset: 0; pointer-events: none;
    background-image: repeating-linear-gradient(90deg, rgba(122, 18, 32, .05) 0 2px, transparent 2px 116px);
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent);
    mask-image: linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent); }
.nf-grid { position: relative; z-index: 2; display: grid; grid-template-columns: 1fr .9fr; gap: 46px; align-items: center; }

.nf-code { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.nf-digit {
    font: 700 clamp(72px, 12vw, 132px)/.9 var(--font-serif); color: var(--wine-700);
    letter-spacing: -.02em; transition: transform .35s cubic-bezier(.22,.61,.36,1), color .35s;
}
.nf-digit:hover { transform: translateY(-6px) rotate(-3deg); color: var(--wine-500); }
.nf-spool { width: clamp(60px, 9vw, 104px); height: clamp(60px, 9vw, 104px); flex: none; }
.nf-spool__ring { fill: none; stroke: var(--line); stroke-width: 3; stroke-dasharray: 7 7; transform-origin: 50px 50px; animation: nf-spin 26s linear infinite; }
@keyframes nf-spin { to { transform: rotate(360deg); } }
.nf-title { font-size: clamp(24px, 3vw, 34px); margin-bottom: 12px; max-width: 20ch; }
.nf-lead { color: var(--muted); font-size: 16px; max-width: 52ch; margin-bottom: 20px; }
.nf-path { display: inline-flex; align-items: center; gap: 9px; max-width: 100%; padding: 9px 13px; margin-bottom: 22px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 500 12.5px/1.4 var(--font-sans); color: var(--muted); }
.nf-path code { font-family: ui-monospace, 'Consolas', monospace; color: var(--wine-700); word-break: break-all; }
.nf-path button { flex: none; width: 28px; height: 28px; border: 1px solid var(--line); background: var(--white); border-radius: var(--radius-sm); color: var(--wine-700); display: grid; place-items: center; cursor: pointer; transition: background-color .2s, border-color .2s, transform .15s; }
.nf-path button:hover { background: var(--linen); border-color: var(--wine-300); }
.nf-path button:active { transform: scale(.9); }

.nf-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
.nf-search { display: flex; gap: 10px; flex-wrap: wrap; }
.nf-search__field { position: relative; flex: 1 1 260px; min-width: 200px; }
.nf-search__field .input { padding-left: 40px; }
.nf-search__icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }

/* Оборванная нить */
.nf-art { position: relative; }
.nf-art svg { display: block; width: 100%; height: auto; }
.nf-thread-whole { fill: none; stroke: url(#nf-grad); stroke-width: 3; stroke-linecap: round; stroke-dasharray: 420; stroke-dashoffset: 420; animation: nf-draw 1.6s ease .2s forwards; }
@keyframes nf-draw { to { stroke-dashoffset: 0; } }
.nf-thread-loose { fill: none; stroke: var(--wine-300); stroke-width: 2.6; stroke-linecap: round; stroke-dasharray: 5 7; opacity: .75; animation: nf-sway 5.5s ease-in-out infinite; transform-origin: 250px 150px; }
@keyframes nf-sway { 0%, 100% { transform: rotate(-1.5deg) translateY(0); } 50% { transform: rotate(1.5deg) translateY(4px); } }
.nf-needle { animation: nf-needle 4.5s ease-in-out infinite; transform-origin: 250px 120px; }
@keyframes nf-needle { 0%, 100% { transform: rotate(-6deg); } 50% { transform: rotate(6deg); } }
.nf-gap-label { font: 700 12px var(--font-sans); fill: var(--wine-500); letter-spacing: .1em; }

/* Быстрые переходы и модели */
.nf-section { padding: 34px 0; }
.nf-chips { display: flex; gap: 10px; flex-wrap: wrap; }
.nf-chip { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: var(--white); border: 1px dashed var(--line); border-radius: 999px; text-decoration: none; color: var(--ink); font: 600 13px/1 var(--font-sans); transition: background-color .22s, color .22s, border-color .22s, transform .2s; }
.nf-chip:hover { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); transform: translateY(-2px); }
.nf-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.nf-card { display: flex; flex-direction: column; padding: 13px; text-decoration: none; }
.nf-card__media { position: relative; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); margin-bottom: 12px; }
.nf-card__media img { width: 100%; height: 100%; object-fit: cover; transition: transform .45s ease; }
.nf-card:hover .nf-card__media img { transform: scale(1.06); }
.nf-card__media::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 6px; background: repeating-linear-gradient(45deg, rgba(176, 27, 46, .5) 0 4px, transparent 4px 8px); }
.nf-card__brand { display: block; font: 600 10px/1 var(--font-sans); letter-spacing: .13em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.nf-card__name { display: block; font: 700 15.5px/1.35 var(--font-serif); color: var(--wine-900); margin-bottom: 6px; }
.nf-card__model { display: block; font-size: 11.5px; color: var(--muted); margin-bottom: 10px; }
.nf-card__price { display: block; margin-top: auto; font: 700 19px/1 var(--font-serif); color: var(--wine-700); }
.nf-help { display: grid; grid-template-columns: 1.2fr .8fr; gap: 30px; align-items: center; padding: 22px 20px; }
.nf-help__title { font: 700 19px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 8px; }
.nf-help__text { color: var(--muted); font-size: 14px; margin: 0; }
.nf-help__actions { display: flex; gap: 10px; flex-wrap: wrap; }

@media (max-width: 1080px) {
    .nf-grid { grid-template-columns: 1fr; gap: 30px; }
    .nf-art { order: -1; max-width: 460px; }
    .nf-cards { grid-template-columns: repeat(2, 1fr); }
    .nf-help { grid-template-columns: 1fr; gap: 18px; }
}
@media (max-width: 620px) {
    .nf-hero { padding: 34px 0 26px; }
    .nf-cards { grid-template-columns: 1fr; }
    .nf-actions .btn, .nf-search .btn { width: 100%; }
    .nf-help__actions .btn { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .nf-spool__ring, .nf-thread-loose, .nf-needle { animation: none; }
    .nf-thread-whole { stroke-dashoffset: 0; animation: none; }
    .nf-digit, .nf-card__media img, .nf-chip { transition: none; }
}
</style>

<!-- ================= HERO ================= -->
<section class="nf-hero">
    <div class="container">
        <div class="nf-grid" data-stagger>
            <div>
                <span class="label reveal">Ошибка 404</span>
                <div class="nf-code reveal" aria-hidden="true">
                    <span class="nf-digit">4</span>
                    <svg class="nf-spool" viewBox="0 0 100 100" focusable="false">
                        <circle class="nf-spool__ring" cx="50" cy="50" r="46"/>
                        <circle cx="50" cy="50" r="34" fill="#FFFFFF" stroke="#7A1220" stroke-width="3.5"/>
                        <circle cx="50" cy="50" r="14" fill="none" stroke="#B01B2E" stroke-width="3.5"/>
                        <circle cx="50" cy="50" r="5" fill="#4E0B15"/>
                    </svg>
                    <span class="nf-digit">4</span>
                </div>
                <h1 class="nf-title reveal">Стежок оборвался: такой страницы нет</h1>
                <p class="nf-lead reveal">Адрес устарел, товар снят с продажи или в ссылке опечатка. Ничего страшного — вернитесь на главную, откройте каталог или найдите нужную модель поиском.</p>

                <?php if ($shortPath !== ''): ?>
                    <div class="nf-path reveal">
                        <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <code><?= e($shortPath) ?></code>
                        <button type="button" id="copy-path" aria-label="Скопировать адрес страницы" title="Скопировать адрес">
                            <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 15V6a2 2 0 0 1 2-2h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="nf-actions reveal">
                    <a class="btn btn--primary btn--lg" href="catalog.php">В каталог</a>
                    <a class="btn btn--ghost btn--lg" href="index.php">На главную</a>
                    <button class="btn btn--ghost btn--lg" type="button" id="go-back">Вернуться назад</button>
                </div>

                <form class="nf-search reveal" action="catalog.php" method="get" role="search">
                    <div class="nf-search__field">
                        <svg class="nf-search__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        <label class="visually-hidden" for="nf-q">Поиск по каталогу</label>
                        <input class="input" id="nf-q" name="q" type="search" maxlength="100" autocomplete="off"
                               placeholder="Найти модель: DC 603, MyLock, Super Jeans…">
                    </div>
                    <button class="btn btn--primary" type="submit">Искать в каталоге</button>
                </form>
            </div>

            <!-- Оборванная нить -->
            <div class="nf-art reveal" aria-hidden="true">
                <svg viewBox="0 0 420 300" focusable="false">
                    <defs>
                        <linearGradient id="nf-grad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" stop-color="#B01B2E"/>
                            <stop offset="1" stop-color="#7A1220"/>
                        </linearGradient>
                    </defs>
                    <!-- Полотно -->
                    <rect x="24" y="196" width="372" height="76" rx="8" fill="#FFFFFF" stroke="#E8D5D8" stroke-width="2"/>
                    <rect x="34" y="206" width="352" height="56" rx="6" fill="none" stroke="#E8D5D8" stroke-width="2" stroke-dasharray="6 6"/>
                    <!-- Прошитая часть -->
                    <path class="nf-thread-whole" d="M40 234H196"/>
                    <!-- Разрыв -->
                    <path class="nf-thread-loose" d="M228 234C252 226 262 246 286 238S322 222 344 240"/>
                    <text class="nf-gap-label" x="204" y="222" text-anchor="middle">РАЗРЫВ</text>
                    <!-- Игла ушла вверх -->
                    <g class="nf-needle">
                        <line x1="250" y1="70" x2="250" y2="150" stroke="#4E0B15" stroke-width="6" stroke-linecap="round"/>
                        <line x1="250" y1="150" x2="250" y2="170" stroke="#7A1220" stroke-width="4" stroke-linecap="round"/>
                        <ellipse cx="250" cy="86" rx="5" ry="12" fill="none" stroke="#7A1220" stroke-width="2.6"/>
                    </g>
                    <path d="M250 96C214 118 206 176 226 232" fill="none" stroke="url(#nf-grad)" stroke-width="2.6" stroke-linecap="round" opacity=".8"/>
                    <!-- Лента -->
                    <g stroke="#D42A3F" stroke-width="2" opacity=".6">
                        <path d="M40 288H380"/>
                        <path d="M40 288v-12M120 288v-12M200 288v-12M280 288v-12M360 288v-12"/>
                        <path d="M80 288v-6M160 288v-6M240 288v-6M320 288v-6"/>
                    </g>
                </svg>
            </div>
        </div>
    </div>
</section>

<!-- ================= БЫСТРЫЕ ПЕРЕХОДЫ ================= -->
<section class="nf-section section--linen">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Куда пойти дальше</h2>
            <span class="muted small">Все разделы на месте</span>
        </div>
        <div class="nf-chips reveal" data-stagger>
            <?php foreach ($categories as $category): ?>
                <a class="nf-chip reveal" href="catalog.php?category=<?= e($category['slug']) ?>">
                    <?= e($category['name']) ?>
                    <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            <?php endforeach; ?>
            <a class="nf-chip reveal" href="cart.php">Корзина</a>
            <a class="nf-chip reveal" href="cabinet.php">Личный кабинет</a>
            <a class="nf-chip reveal" href="about.php">О нас</a>
            <a class="nf-chip reveal" href="contacts.php">Контакты</a>
            <a class="nf-chip reveal" href="feedback.php">Обратная связь</a>
        </div>
    </div>
</section>

<!-- ================= ХОДОВЫЕ МОДЕЛИ ================= -->
<?php if ($popular): ?>
<section class="nf-section">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Может, искали это</h2>
            <a class="link" href="catalog.php">Весь каталог</a>
        </div>
        <div class="nf-cards" data-stagger>
            <?php foreach ($popular as $product): ?>
                <a class="card nf-card reveal" href="product.php?slug=<?= e($product['slug']) ?>">
                    <span class="nf-card__media">
                        <img src="../<?= e($product['image_path']) ?>" alt="<?= e($product['name']) ?>" loading="lazy" decoding="async"
                             onerror="this.onerror=null;this.src='<?= e($fallbackImg) ?>';">
                    </span>
                    <span class="nf-card__brand"><?= e($product['brand_name']) ?></span>
                    <span class="nf-card__name"><?= e($product['name']) ?></span>
                    <span class="nf-card__model"><?= e($product['model']) ?></span>
                    <span class="nf-card__price"><?= e(format_price($product['price'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ================= ПОМОЩЬ ================= -->
<section class="nf-section" style="padding-bottom: 56px;">
    <div class="container">
        <div class="card card--static nf-help reveal">
            <div>
                <h2 class="nf-help__title">Ссылка была рабочей и вдруг сломалась?</h2>
                <p class="nf-help__text">Скопируйте адрес кнопкой выше и пришлите его в форму обращений — разберёмся и починим переход. Ответим в рабочий день.</p>
            </div>
            <div class="nf-help__actions">
                <a class="btn btn--primary" href="feedback.php">Сообщить о битой ссылке</a>
                <a class="btn btn--ghost" href="contacts.php">Позвонить в магазин</a>
            </div>
        </div>
    </div>
</section>

<script>
// 404: возврат назад, копирование адреса, фокус на поиск
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var J = window.Janome;

    var backBtn = document.getElementById('go-back');
    if (backBtn) {
        backBtn.addEventListener('click', function () {
            // Если истории нет — уходим на главную, чтобы не оставлять тупик
            if (window.history.length > 1 && document.referrer !== '') {
                window.history.back();
            } else {
                window.location.href = 'index.php';
            }
        });
    }

    var copyBtn = document.getElementById('copy-path');
    if (copyBtn && J) {
        copyBtn.addEventListener('click', function () {
            var text = window.location.href;
            function done() { J.toast('Адрес страницы скопирован', 'success', 2400); }
            function fallback() {
                var area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); done(); }
                catch (err) { J.toast('Не удалось скопировать', 'error'); }
                area.remove();
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(fallback);
            } else {
                fallback();
            }
        });
    }

    // Клавиша «/» ставит курсор в поиск — быстрый выход из тупика
    var search = document.getElementById('nf-q');
    if (search) {
        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && document.activeElement !== search && !e.ctrlKey && !e.metaKey) {
                var tag = (document.activeElement && document.activeElement.tagName) || '';
                if (tag === 'INPUT' || tag === 'TEXTAREA') { return; }
                e.preventDefault();
                search.focus();
            }
        });
    }
});
</script>

<?php render_footer(); ?>
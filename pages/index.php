<?php
/**
 * pages/index.php — главная магазина Janome.
 * Все данные тянутся из БД: бренды, категории, хиты продаж, статистика, specs.
 * Дизайн-концепция «Стежок»: полотно, строчка, лента, лоскуты.
 */

require_once __DIR__ . '/../config/helpers.php';

/* ---------- Данные из БД (все запросы подготовленные) ---------- */

// Бренды с количеством активных товаров
$stmt = $pdo->prepare(
    'SELECT b.id, b.name, b.slug, b.sort_order,
            (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.is_active = 1) AS product_count
     FROM brands b
     ORDER BY b.sort_order ASC, b.name ASC'
);
$stmt->execute();
$brands = $stmt->fetchAll();

// Категории с количеством активных товаров
$stmt = $pdo->prepare(
    'SELECT c.id, c.name, c.slug,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
     FROM categories c
     ORDER BY c.sort_order ASC, c.name ASC'
);
$stmt->execute();
$categories = $stmt->fetchAll();

// Хиты продаж: сначала реально заказанные, затем со скидкой, затем в наличии
$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.model, p.slug, p.description, p.specs, p.price, p.old_price,
            p.stock, p.image_path, b.name AS brand_name, b.slug AS brand_slug,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id) AS sold
     FROM products p
     JOIN brands b ON b.id = p.brand_id
     WHERE p.is_active = 1
     ORDER BY sold DESC, (p.old_price IS NOT NULL) DESC, p.stock DESC, p.id ASC
     LIMIT 4'
);
$stmt->execute();
$hits = $stmt->fetchAll();

// Общая статистика каталога
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS products_count,
            COALESCE(MIN(price), 0) AS min_price,
            COALESCE(MAX(CASE WHEN old_price IS NOT NULL THEN old_price - price END), 0) AS max_discount
     FROM products WHERE is_active = 1'
);
$stmt->execute();
$stats = $stmt->fetch() ?: ['products_count' => 0, 'min_price' => 0, 'max_discount' => 0];

// Максимальная скорость и максимальное поле вышивки — из specs JSON
$stmt = $pdo->prepare('SELECT specs FROM products WHERE is_active = 1');
$stmt->execute();
$maxSpeed = 0;
$maxField = '';
$maxFieldArea = 0;
foreach ($stmt->fetchAll() as $row) {
    $specs = json_decode((string)$row['specs'], true) ?: [];
    foreach ($specs as $key => $value) {
        if (is_numeric($value) && mb_stripos((string)$key, 'скорость') !== false) {
            $maxSpeed = max($maxSpeed, (int)$value);
        }
        if (is_string($value) && mb_stripos((string)$key, 'поле вышивки') !== false
            && preg_match('/(\d+)\s*[×x]\s*(\d+)/u', $value, $m)) {
            $area = (int)$m[1] * (int)$m[2];
            if ($area > $maxFieldArea) {
                $maxFieldArea = $area;
                $maxField = $m[1] . '×' . $m[2];
            }
        }
    }
}

// Сколько покупателей уже в магазине
$stmt = $pdo->prepare('SELECT COUNT(*) FROM users');
$stmt->execute();
$usersCount = (int)$stmt->fetchColumn();

$productsCount = (int)$stats['products_count'];
$brandCount = count($brands);
$categoryCount = count($categories);
$minPrice = (float)$stats['min_price'];
$maxDiscount = (float)$stats['max_discount'];
$counts = user_counts();
$user = current_user();

// Подписи брендов — редакционный контент, привязан к slug из БД
$brandTaglines = [
    'janome' => 'Флагман каталога: бытовая, вышивальная и швейно-вышивальная техника',
    'juki' => 'Промышленная скорость и легендарные оверлоки',
    'brother' => 'Серия Innov-is и вышивка с Disney-дизайнами',
    'toyota' => 'Джинса в двенадцать слоёв и тяжёлые ткани',
    'baby-lock' => 'Премиум-оверлоки с воздушной заправкой петлителей',
];

// Наличие: класс и текст
$stockLabel = static function (int $stock): array {
    if ($stock <= 0) { return ['out', 'Нет в наличии']; }
    if ($stock <= 3) { return ['low', 'Осталось ' . $stock . ' шт.']; }
    return ['in', 'В наличии'];
};

// Запасная картинка, если файл товара вдруг пропал
$fallbackImg = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/><text x="400" y="420" text-anchor="middle" font-family="Georgia,serif" font-size="30" fill="#6B5558">Фото готовится</text></svg>'
);

render_header('Главная', [
    'description' => 'Интернет-магазин японских швейных машин Janome: ' . $brandCount . ' брендов, '
        . $productsCount . ' моделей, хиты продаж и подбор под ткань.',
    'body_class' => 'page-home',
]);
?>

<style>
/* ============================================================
   Главная «Стежок»: hero, преимущества, бренды, хиты,
   «Почему Япония», призыв к регистрации. Палитра из base.css.
   ============================================================ */

/* Нить-прогресс скролла поверх шапки */
.thread-progress {
    position: fixed; top: 0; left: 0; z-index: 60; height: 3px; width: 100%;
    transform: scaleX(0); transform-origin: left;
    background: linear-gradient(90deg, var(--wine-900), var(--wine-500) 45%, var(--wine-300));
    box-shadow: 0 1px 6px rgba(176, 27, 46, .35);
}

/* ---------- Hero ---------- */
.home-hero {
    position: relative; overflow: hidden; padding: 76px 0 54px;
    background:
        linear-gradient(180deg, rgba(251, 246, 246, .92) 0%, rgba(255, 255, 255, 0) 58%),
        linear-gradient(112deg, rgba(176, 27, 46, .07) 0%, rgba(212, 42, 63, .035) 36%, rgba(255, 255, 255, 0) 68%);
}
/* Кромка полотна: редкие вертикальные нити, без зерна и blur */
.home-hero::before {
    content: ""; position: absolute; inset: 0; pointer-events: none;
    background-image: repeating-linear-gradient(90deg, rgba(122, 18, 32, .05) 0 2px, transparent 2px 120px);
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent);
    mask-image: linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent);
}
.home-hero__grid {
    position: relative; z-index: 2;
    display: grid; grid-template-columns: 1.08fr .92fr; gap: 48px; align-items: center;
}
.home-hero__title { max-width: 15ch; margin-bottom: 18px; }
.home-hero__title em { font-style: italic; color: var(--wine-500); }
.home-hero__lead { font-size: 17px; color: var(--muted); max-width: 54ch; margin-bottom: 26px; }
.home-hero__cta { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 34px; }

.hero-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.hero-stat {
    padding: 14px 16px; background: var(--white);
    border: 1px solid var(--line); border-radius: var(--radius); position: relative;
}
.hero-stat::before { content: ""; position: absolute; inset: 5px; border: 1px dashed var(--line); border-radius: var(--radius-sm); pointer-events: none; }
.hero-stat__num { font: 700 26px/1.1 var(--font-serif); color: var(--wine-700); display: block; }
.hero-stat__label { font: 500 11.5px/1.35 var(--font-sans); color: var(--muted); letter-spacing: .04em; }

/* Декоративная нить с иглой, бегущей по строчке */
.home-hero__decor {
    position: absolute; right: -40px; bottom: 6px; z-index: 1;
    width: min(660px, 62vw); opacity: .55; pointer-events: none;
    transform: translateY(var(--parallax, 0px));
}
.hero-thread-path { fill: none; stroke: url(#hero-thread-grad); stroke-width: 2.5; stroke-linecap: round; }
.hero-thread-stitches { fill: none; stroke: var(--wine-300); stroke-width: 2; stroke-dasharray: 6 6; opacity: .55; }
.hero-thread-draw { stroke-dasharray: 900; stroke-dashoffset: 900; animation: hero-draw 2.6s ease .4s forwards; }
@keyframes hero-draw { to { stroke-dashoffset: 0; } }

/* Витрина-лоскут в hero */
.hero-showcase { position: relative; justify-self: end; width: min(400px, 100%); }
.hero-showcase__bobbin {
    position: absolute; top: -46px; right: -34px; width: 150px; height: 150px; z-index: 0; opacity: .5;
    animation: bobbin-spin 34s linear infinite; transform-origin: 50% 50%;
}
@keyframes bobbin-spin { to { transform: rotate(360deg); } }
.hero-showcase__card {
    position: relative; z-index: 1; padding: 16px;
    transform: rotate(-1.6deg) perspective(900px) rotateX(var(--tilt-x, 0deg)) rotateY(var(--tilt-y, 0deg));
    transition: transform .35s ease, box-shadow .35s ease;
}
.hero-showcase__card:hover { transform: rotate(0deg) perspective(900px) rotateX(var(--tilt-x, 0deg)) rotateY(var(--tilt-y, 0deg)); box-shadow: var(--shadow); }
.hero-showcase__badge {
    position: absolute; top: -12px; left: 18px; z-index: 2;
    padding: 5px 12px; background: var(--wine-900); color: var(--white);
    font: 700 10.5px/1.4 var(--font-sans); letter-spacing: .12em; text-transform: uppercase;
    border-radius: var(--radius-sm); box-shadow: var(--shadow-sm);
}
.hero-showcase__media { aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); margin-bottom: 14px; }
.hero-showcase__media img { width: 100%; height: 100%; object-fit: cover; }
.hero-showcase__brand { font: 600 11px/1 var(--font-sans); letter-spacing: .14em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.hero-showcase__name { font: 700 19px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 10px; }
.hero-showcase__foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.hero-showcase__link { font: 600 13px/1 var(--font-sans); color: var(--wine-700); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.hero-showcase__link svg { transition: transform .25s; }
.hero-showcase__link:hover svg { transform: translateX(4px); }

.home-hero__tape { position: relative; z-index: 2; margin-top: 44px; }

/* ---------- Преимущества ---------- */
.benefit-card { padding: 22px 20px; height: 100%; }
.benefit-card__icon {
    width: 52px; height: 52px; border-radius: 50%; margin-bottom: 16px;
    display: grid; place-items: center; color: var(--wine-500);
    background: var(--wine-050); border: 1px solid var(--line); position: relative;
    transition: transform .3s ease, color .3s ease;
}
.benefit-card__icon::before { content: ""; position: absolute; inset: 4px; border: 1px dashed rgba(176, 27, 46, .35); border-radius: 50%; }
.benefit-card:hover .benefit-card__icon { transform: rotate(-8deg) scale(1.06); color: var(--wine-700); }
.benefit-card__title { font: 700 17px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 8px; }
.benefit-card__text { font-size: 14px; color: var(--muted); margin: 0; }
.benefit-card__num { font: 700 12px/1 var(--font-sans); color: var(--wine-500); letter-spacing: .1em; display: block; margin-bottom: 10px; }

/* ---------- Бренды ---------- */
.brand-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.brand-tile {
    position: relative; display: flex; flex-direction: column; gap: 10px;
    padding: 24px 22px; text-decoration: none; overflow: hidden; min-height: 168px;
}
.brand-tile__name { font: 700 27px/1.1 var(--font-serif); color: var(--wine-900); letter-spacing: .01em; }
.brand-tile__tag { font-size: 13.5px; color: var(--muted); max-width: 34ch; }
.brand-tile__foot { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.brand-tile__count { font: 600 12.5px/1 var(--font-sans); color: var(--wine-700); padding: 6px 10px; background: var(--wine-050); border-radius: var(--radius-sm); }
.brand-tile__arrow { color: var(--wine-500); transition: transform .3s ease; }
.brand-tile:hover .brand-tile__arrow { transform: translateX(6px); }
.brand-tile--flagship { grid-column: span 2; background: var(--wine-900); border-color: var(--wine-900); }
.brand-tile--flagship::before { border-color: rgba(255, 255, 255, .22); }
.brand-tile--flagship .brand-tile__name { color: var(--white); font-size: 34px; }
.brand-tile--flagship .brand-tile__tag { color: rgba(255, 255, 255, .68); }
.brand-tile--flagship .brand-tile__count { background: rgba(255, 255, 255, .12); color: var(--white); }
.brand-tile--flagship .brand-tile__arrow { color: var(--wine-300); }
.brand-tile__flag {
    position: absolute; top: 16px; right: 16px; padding: 4px 10px;
    background: var(--wine-500); color: var(--white); border-radius: var(--radius-sm);
    font: 700 10px/1.4 var(--font-sans); letter-spacing: .12em; text-transform: uppercase;
}
.brand-tile__watermark { position: absolute; right: -18px; bottom: -30px; font: 700 110px/1 var(--font-serif); color: rgba(122, 18, 32, .05); pointer-events: none; user-select: none; }
.brand-tile--flagship .brand-tile__watermark { color: rgba(255, 255, 255, .06); }

/* Категории-чипы */
.category-chips { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 26px; }
.category-chip {
    display: inline-flex; align-items: center; gap: 8px; padding: 9px 15px;
    background: var(--white); border: 1px dashed var(--line); border-radius: 999px;
    text-decoration: none; color: var(--ink); font: 500 13.5px/1 var(--font-sans);
    transition: background-color .25s, color .25s, border-color .25s, transform .2s;
}
.category-chip__count { font: 700 11px/1 var(--font-sans); color: var(--wine-500); padding: 3px 7px; background: var(--wine-050); border-radius: 999px; transition: background-color .25s, color .25s; }
.category-chip:hover { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); transform: translateY(-2px); }
.category-chip:hover .category-chip__count { background: rgba(255, 255, 255, .2); color: var(--white); }

/* ---------- Хиты ---------- */
.hits-note { font-size: 13px; color: var(--muted); margin: -12px 0 24px; }
.hit-card { display: flex; flex-direction: column; padding: 14px; height: 100%; }
.hit-card__badges { position: absolute; top: 12px; left: 12px; z-index: 2; display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.hit-badge { padding: 4px 9px; border-radius: var(--radius-sm); font: 700 10px/1.4 var(--font-sans); letter-spacing: .1em; text-transform: uppercase; }
.hit-badge--sale { background: var(--wine-500); color: var(--white); }
.hit-badge--top { background: var(--wine-900); color: var(--white); }
.hit-card__media { position: relative; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); margin-bottom: 13px; }
.hit-card__media img { width: 100%; height: 100%; object-fit: cover; transition: transform .45s ease; }
.hit-card:hover .hit-card__media img { transform: scale(1.05); }
.hit-card__brand { font: 600 11px/1 var(--font-sans); letter-spacing: .14em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.hit-card__name { font: 700 16.5px/1.35 var(--font-serif); margin: 0 0 4px; }
.hit-card__name a { color: var(--wine-900); text-decoration: none; }
.hit-card__name a:hover { color: var(--wine-700); }
.hit-card__model { font-size: 12.5px; color: var(--muted); margin-bottom: 10px; }
.spec-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 13px; }
.spec-chip {
    font: 500 11px/1.3 var(--font-sans); color: var(--muted);
    padding: 4px 8px; background: var(--linen); border: 1px solid var(--line);
    border-radius: var(--radius-sm); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.hit-card__bottom { margin-top: auto; display: flex; flex-direction: column; gap: 11px; }
.hit-card__meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.hit-card__sold { font: 600 11.5px/1 var(--font-sans); color: var(--success); }

/* ---------- Почему японские машинки ---------- */
.why-layout { display: grid; grid-template-columns: 380px 1fr; gap: 52px; align-items: start; }
.why-side { position: sticky; top: calc(var(--header-h) + 28px); }
.why-side__lead { color: var(--muted); font-size: 15.5px; margin-bottom: 24px; }
.why-stats { display: grid; gap: 12px; }
.why-stat { padding: 14px 16px; background: var(--white); border: 1px solid var(--line); border-left: 4px solid var(--wine-500); border-radius: var(--radius); }
.why-stat__num { font: 700 23px/1.15 var(--font-serif); color: var(--wine-700); display: block; }
.why-stat__label { font-size: 12.5px; color: var(--muted); }

.why-list { position: relative; padding-left: 4px; }
.why-list::before { content: ""; position: absolute; left: 21px; top: 20px; bottom: 20px; border-left: 2px dashed var(--line); }
.why-item { position: relative; padding-left: 60px; margin-bottom: 14px; }
.why-item__num {
    position: absolute; left: 0; top: 12px; width: 44px; height: 44px; z-index: 1;
    display: grid; place-items: center; border-radius: 50%;
    background: var(--white); border: 2px solid var(--line);
    font: 700 16px/1 var(--font-serif); color: var(--wine-700);
    transition: border-color .3s, background-color .3s, color .3s, transform .3s;
}
.why-item[open] .why-item__num, .why-item:hover .why-item__num { border-color: var(--wine-500); background: var(--wine-500); color: var(--white); transform: scale(1.06); }
.why-item summary {
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
    padding: 16px 18px; cursor: pointer; list-style: none;
    background: var(--white); border: 1px solid var(--line); border-radius: var(--radius);
    font: 700 16.5px/1.4 var(--font-serif); color: var(--wine-900);
    transition: border-color .25s, box-shadow .25s;
}
.why-item summary::-webkit-details-marker { display: none; }
.why-item summary:hover { border-color: var(--wine-300); box-shadow: var(--shadow-sm); }
.why-item summary::after { content: "+"; font: 400 22px/1 var(--font-serif); color: var(--wine-500); transition: transform .3s; flex: none; }
.why-item[open] summary::after { transform: rotate(45deg); }
.why-item[open] summary { border-color: var(--wine-300); border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.why-item__body {
    padding: 16px 18px 18px; background: var(--linen);
    border: 1px solid var(--line); border-top: 0;
    border-radius: 0 0 var(--radius) var(--radius);
    color: var(--muted); font-size: 14.5px;
}
.why-item__body p { margin: 0 0 10px; }
.why-item__body p:last-child { margin-bottom: 0; }
.why-item__fact { display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; padding: 7px 12px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius-sm); font: 600 12.5px/1.3 var(--font-sans); color: var(--wine-700); }

/* ---------- Призыв ---------- */
.cta-band { position: relative; overflow: hidden; background-color: var(--wine-900); background-image: var(--weave-light); color: var(--white); padding: 0 0 56px; }
.cta-band__inner { display: grid; grid-template-columns: 1.2fr .8fr; gap: 40px; align-items: center; padding-top: 48px; }
.cta-band__title { color: var(--white); font-size: clamp(26px, 3.2vw, 36px); margin-bottom: 14px; max-width: 18ch; }
.cta-band__text { color: rgba(255, 255, 255, .72); font-size: 15.5px; max-width: 52ch; margin-bottom: 0; }
.cta-band__actions { display: flex; flex-direction: column; gap: 12px; align-items: stretch; }
.cta-band__note { font-size: 12.5px; color: rgba(255, 255, 255, .55); text-align: center; }
.cta-band .btn--ghost { background: transparent; color: var(--white); border-color: rgba(255, 255, 255, .35); --stitch-c: rgba(255, 255, 255, .45); }
.cta-band .btn--ghost:hover { background: rgba(255, 255, 255, .1); border-color: var(--wine-300); }
.cta-personal { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
.cta-personal__item { padding: 10px 16px; background: rgba(255, 255, 255, .1); border: 1px dashed rgba(255, 255, 255, .28); border-radius: var(--radius); font: 600 13px/1.3 var(--font-sans); }
.cta-personal__item strong { font-family: var(--font-serif); font-size: 18px; color: var(--wine-300); }

/* Кнопка «наверх» */
.to-top {
    position: fixed; right: 20px; bottom: 20px; z-index: 90;
    opacity: 0; visibility: hidden; transform: translateY(10px);
    transition: opacity .3s, transform .3s, visibility .3s;
    box-shadow: var(--shadow);
}
.to-top.is-visible { opacity: 1; visibility: visible; transform: none; }

/* ---------- Адаптив главной ---------- */
@media (max-width: 1080px) {
    .home-hero__grid { grid-template-columns: 1fr; gap: 36px; }
    .hero-showcase { justify-self: start; width: min(420px, 100%); }
    .home-hero__decor { width: min(520px, 80vw); opacity: .35; }
    .brand-grid { grid-template-columns: repeat(2, 1fr); }
    .brand-tile--flagship { grid-column: span 2; }
    .why-layout { grid-template-columns: 1fr; gap: 32px; }
    .why-side { position: static; }
    .why-stats { grid-template-columns: repeat(3, 1fr); }
    .cta-band__inner { grid-template-columns: 1fr; gap: 28px; }
    .cta-band__actions { flex-direction: row; flex-wrap: wrap; }
    .cta-band__actions .btn { flex: 1 1 200px; }
    .cta-band__note { flex-basis: 100%; }
}
@media (max-width: 768px) {
    .home-hero { padding: 48px 0 36px; }
    .hero-stats { grid-template-columns: repeat(2, 1fr); }
    .why-stats { grid-template-columns: 1fr; }
    .home-hero__decor { display: none; }
    .hero-showcase__bobbin { display: none; }
}
@media (max-width: 560px) {
    .brand-grid { grid-template-columns: 1fr; }
    .brand-tile--flagship { grid-column: span 1; }
    .brand-tile--flagship .brand-tile__name { font-size: 28px; }
    .home-hero__cta .btn { width: 100%; }
    .to-top { right: 16px; bottom: 16px; }
}
@media (prefers-reduced-motion: reduce) {
    .hero-thread-draw { stroke-dashoffset: 0; animation: none; }
    .hero-showcase__bobbin { animation: none; }
    .hero-showcase__card { transform: none; }
    .home-hero__decor { transform: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<!-- ================= HERO ================= -->
<section class="home-hero">
    <!-- Декоративная нить: игла бежит по строчке -->
    <svg class="home-hero__decor" id="hero-decor" viewBox="0 0 600 220" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="hero-thread-grad" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#B01B2E"/>
                <stop offset=".55" stop-color="#D42A3F"/>
                <stop offset="1" stop-color="#7A1220"/>
            </linearGradient>
        </defs>
        <path class="hero-thread-stitches" d="M10 150C150 40 300 200 450 90S560 40 590 70"/>
        <path class="hero-thread-path hero-thread-draw" d="M10 150C150 40 300 200 450 90S560 40 590 70"/>
        <g class="hero-needle-runner">
            <line x1="0" y1="-14" x2="0" y2="10" stroke="#4E0B15" stroke-width="3.5" stroke-linecap="round"/>
            <line x1="0" y1="10" x2="0" y2="16" stroke="#7A1220" stroke-width="2.5" stroke-linecap="round"/>
            <ellipse cx="0" cy="-8" rx="2.4" ry="4.6" fill="none" stroke="#7A1220" stroke-width="1.6"/>
            <animateMotion dur="11s" repeatCount="indefinite" rotate="auto"
                           path="M10 150C150 40 300 200 450 90S560 40 590 70"/>
        </g>
    </svg>

    <div class="container">
        <div class="home-hero__grid" data-stagger>
            <div>
                <span class="label reveal">Janome · магазин японских швейных машин</span>
                <h1 class="home-hero__title reveal">Техника, которая <em>прошивает</em> десятилетия</h1>
                <p class="home-hero__lead reveal">
                    <?php if ($maxSpeed > 0): ?>
                        От первой строчки на джинсе до <?= number_format($maxSpeed, 0, ',', ' ') ?> стежков в минуту.
                    <?php else: ?>
                        От первой строчки на джинсе до промышленной скорости.
                    <?php endif; ?>
                    Подберём машину под вашу ткань, а не наоборот: <?= $brandCount ?> японских брендов,
                    <?= $productsCount ?> моделей с честными характеристиками.
                </p>
                <div class="home-hero__cta reveal">
                    <a class="btn btn--primary btn--lg" href="catalog.php">Открыть каталог</a>
                    <?php if ($user): ?>
                        <a class="btn btn--ghost btn--lg" href="cabinet.php">Личный кабинет</a>
                    <?php else: ?>
                        <a class="btn btn--ghost btn--lg" href="register.php">Создать аккаунт</a>
                    <?php endif; ?>
                </div>
                <div class="hero-stats reveal">
                    <div class="hero-stat">
                        <span class="hero-stat__num" data-count-to="<?= $productsCount ?>">0</span>
                        <span class="hero-stat__label">моделей в каталоге</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat__num" data-count-to="<?= $brandCount ?>">0</span>
                        <span class="hero-stat__label">японских брендов</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat__num" data-count-to="<?= $categoryCount ?>">0</span>
                        <span class="hero-stat__label">категорий техники</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat__num" data-count-to="<?= (int)$minPrice ?>" data-suffix=" ₽">0</span>
                        <span class="hero-stat__label">цены от</span>
                    </div>
                </div>
            </div>

            <!-- Витрина: первый хит из БД -->
            <?php if (!empty($hits)): $top = $hits[0]; [$stockClass, $stockText] = $stockLabel((int)$top['stock']); ?>
                <div class="hero-showcase reveal">
                    <svg class="hero-showcase__bobbin" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                        <circle cx="50" cy="50" r="46" fill="none" stroke="#E8D5D8" stroke-width="2" stroke-dasharray="6 6"/>
                        <circle cx="50" cy="50" r="34" fill="none" stroke="#D42A3F" stroke-width="2" opacity=".5"/>
                        <circle cx="50" cy="50" r="12" fill="none" stroke="#7A1220" stroke-width="3"/>
                    </svg>
                    <article class="card hero-showcase__card" id="hero-showcase-card">
                        <span class="hero-showcase__badge">Хит недели</span>
                        <a class="hero-showcase__media" href="product.php?slug=<?= e($top['slug']) ?>" tabindex="-1" aria-hidden="true">
                            <img src="../<?= e($top['image_path']) ?>" alt="<?= e($top['name']) ?>"
                                 loading="eager" decoding="async"
                                 onerror="this.onerror=null;this.src='<?= e($fallbackImg) ?>';">
                        </a>
                        <div class="hero-showcase__brand"><?= e($top['brand_name']) ?></div>
                        <h2 class="hero-showcase__name"><?= e($top['name']) ?></h2>
                        <div class="hero-showcase__foot">
                            <div class="price-row">
                                <span class="price"><?= e(format_price($top['price'])) ?></span>
                                <?php if ($top['old_price'] !== null): ?>
                                    <span class="price--old"><?= e(format_price($top['old_price'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <a class="hero-showcase__link" href="product.php?slug=<?= e($top['slug']) ?>">
                                Смотреть
                                <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                        </div>
                    </article>
                </div>
            <?php endif; ?>
        </div>

        <div class="tape home-hero__tape reveal" aria-hidden="true"></div>
    </div>
</section>

<!-- ================= ПРЕИМУЩЕСТВА ================= -->
<section class="section section--linen">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Что вы получаете</h2>
            <span class="muted small">Четыре причины шить спокойно</span>
        </div>
        <div class="grid grid--4" data-stagger>
            <article class="card benefit-card reveal">
                <span class="benefit-card__num">01</span>
                <div class="benefit-card__icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12 3v13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 16v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><ellipse cx="12" cy="6.5" rx="2" ry="3.4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M14 10c4-1 6 1 7 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </div>
                <h3 class="benefit-card__title">Подбор под ткань</h3>
                <p class="benefit-card__text">Джинса в двенадцать слоёв, тонкий шифон или трикотаж — смотрим на подачу, прокол и лапки, а не на количество строчек в рекламе.</p>
            </article>
            <article class="card benefit-card reveal">
                <span class="benefit-card__num">02</span>
                <div class="benefit-card__icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.5 9h7M8.5 12.5h7M8.5 16h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <h3 class="benefit-card__title">Честные характеристики</h3>
                <p class="benefit-card__text"><?= $productsCount ?> моделей с полными specs: скорость, челнок, поле вышивки и подача. Без маркетингового тумана.</p>
            </article>
            <article class="card benefit-card reveal">
                <span class="benefit-card__num">03</span>
                <div class="benefit-card__icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 11.8l2.2 2.2 4-4.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <h3 class="benefit-card__title">Заказ без сюрпризов</h3>
                <p class="benefit-card__text">Остатки списываются в транзакции: если машина оформлена в заказе — она зарезервирована за вами, а не «ушла другому».</p>
            </article>
            <article class="card benefit-card reveal">
                <span class="benefit-card__num">04</span>
                <div class="benefit-card__icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </div>
                <h3 class="benefit-card__title">Кабинет, который помнит</h3>
                <p class="benefit-card__text">Избранное всегда первым в каталоге, история заказов и смена пароля — в одном месте. Корзина живёт в аккаунте, а не в сессии.</p>
            </article>
        </div>
    </div>
</section>

<!-- ================= БРЕНДЫ + КАТЕГОРИИ ================= -->
<section class="section">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Пять японских школ шитья</h2>
            <a class="link" href="catalog.php">Все бренды в каталоге</a>
        </div>
        <div class="brand-grid" data-stagger>
            <?php foreach ($brands as $brand):
                $isFlagship = $brand['slug'] === 'janome';
                $tagline = $brandTaglines[$brand['slug']] ?? 'Японская техника для шитья и вышивки';
                $count = (int)$brand['product_count'];
            ?>
                <a class="card brand-tile reveal<?= $isFlagship ? ' brand-tile--flagship' : '' ?>"
                   href="catalog.php?brand=<?= e($brand['slug']) ?>">
                    <?php if ($isFlagship): ?><span class="brand-tile__flag">флагман</span><?php endif; ?>
                    <span class="brand-tile__watermark" aria-hidden="true"><?= e(mb_substr($brand['name'], 0, 1)) ?></span>
                    <span class="brand-tile__name"><?= e($brand['name']) ?></span>
                    <span class="brand-tile__tag"><?= e($tagline) ?></span>
                    <span class="brand-tile__foot">
                        <span class="brand-tile__count"><?= $count ?> <?= $count === 1 ? 'модель' : ($count >= 2 && $count <= 4 ? 'модели' : 'моделей') ?></span>
                        <svg class="brand-tile__arrow" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="category-chips reveal" data-stagger>
            <?php foreach ($categories as $category): ?>
                <a class="category-chip reveal" href="catalog.php?category=<?= e($category['slug']) ?>">
                    <?= e($category['name']) ?>
                    <span class="category-chip__count"><?= (int)$category['product_count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ================= ХИТЫ ПРОДАЖ ================= -->
<section class="section section--linen">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Хиты продаж</h2>
            <a class="link" href="catalog.php">Весь каталог</a>
        </div>
        <p class="hits-note reveal">Сначала то, что чаще заказывают; при равенстве — со скидкой и в наличии.</p>

        <?php if (empty($hits)): ?>
            <div class="card card--static empty-state reveal">
                <h3 class="empty-state__title">Каталог наполняется</h3>
                <p class="empty-state__text">Активных товаров пока нет. Загляните в админку или вернитесь позже.</p>
                <a class="btn btn--primary" href="catalog.php">Открыть каталог</a>
            </div>
        <?php else: ?>
            <div class="grid grid--products" data-stagger>
                <?php foreach ($hits as $index => $product):
                    [$stockClass, $stockText] = $stockLabel((int)$product['stock']);
                    $specs = json_decode((string)$product['specs'], true) ?: [];
                    $chips = [];
                    foreach ($specs as $key => $value) {
                        if (count($chips) >= 2) { break; }
                        if (is_bool($value)) { $value = $value ? 'да' : 'нет'; }
                        if (is_scalar($value)) { $chips[] = $key . ': ' . $value; }
                    }
                    $discount = 0;
                    if ($product['old_price'] !== null && (float)$product['old_price'] > 0) {
                        $discount = (int)round(((float)$product['old_price'] - (float)$product['price']) / (float)$product['old_price'] * 100);
                    }
                    $sold = (int)$product['sold'];
                ?>
                    <article class="card hit-card reveal">
                        <div class="hit-card__badges">
                            <?php if ($index === 0): ?><span class="hit-badge hit-badge--top">хит №1</span><?php endif; ?>
                            <?php if ($discount > 0): ?><span class="hit-badge hit-badge--sale">−<?= $discount ?>%</span><?php endif; ?>
                        </div>
                        <a class="hit-card__media" href="product.php?slug=<?= e($product['slug']) ?>" tabindex="-1" aria-hidden="true">
                            <img src="../<?= e($product['image_path']) ?>" alt="<?= e($product['name']) ?>"
                                 loading="lazy" decoding="async"
                                 onerror="this.onerror=null;this.src='<?= e($fallbackImg) ?>';">
                        </a>
                        <div class="hit-card__brand"><?= e($product['brand_name']) ?></div>
                        <h3 class="hit-card__name">
                            <a href="product.php?slug=<?= e($product['slug']) ?>"><?= e($product['name']) ?></a>
                        </h3>
                        <div class="hit-card__model"><?= e($product['model']) ?></div>
                        <?php if ($chips): ?>
                            <div class="spec-chips">
                                <?php foreach ($chips as $chip): ?>
                                    <span class="spec-chip" title="<?= e($chip) ?>"><?= e($chip) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="hit-card__bottom">
                            <div class="price-row">
                                <span class="price"><?= e(format_price($product['price'])) ?></span>
                                <?php if ($product['old_price'] !== null): ?>
                                    <span class="price--old"><?= e(format_price($product['old_price'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="hit-card__meta">
                                <span class="stock stock--<?= e($stockClass) ?>"><?= e($stockText) ?></span>
                                <?php if ($sold > 0): ?><span class="hit-card__sold">заказано <?= $sold ?> шт.</span><?php endif; ?>
                            </div>
                            <a class="btn btn--primary btn--sm btn--block" href="product.php?slug=<?= e($product['slug']) ?>">Подробнее</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ================= ПОЧЕМУ ЯПОНСКИЕ МАШИНКИ ================= -->
<section class="section" id="why-japan">
    <div class="container">
        <div class="why-layout">
            <div class="why-side reveal">
                <span class="label">Инженерия, а не маркетинг</span>
                <h2 class="section-title">Почему японские машинки</h2>
                <p class="why-side__lead">
                    Японская школа шитья выросла из промышленного оборудования: точность подачи, ресурс механики
                    и электроника, которая помогает, а не отвлекает. Вот что это даёт на практике.
                </p>
                <div class="why-stats">
                    <?php if ($maxSpeed > 0): ?>
                        <div class="why-stat">
                            <span class="why-stat__num" data-count-to="<?= $maxSpeed ?>" data-suffix=" ст/мин">0</span>
                            <span class="why-stat__label">максимальная скорость в каталоге</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($maxField !== ''): ?>
                        <div class="why-stat">
                            <span class="why-stat__num"><?= e($maxField) ?> мм</span>
                            <span class="why-stat__label">самое большое поле вышивки</span>
                        </div>
                    <?php endif; ?>
                    <div class="why-stat">
                        <span class="why-stat__num" data-count-to="<?= $brandCount ?>">0</span>
                        <span class="why-stat__label">брендов с сервисной поддержкой</span>
                    </div>
                </div>
            </div>

            <div class="why-list reveal" id="why-list">
                <details class="why-item" open>
                    <span class="why-item__num" aria-hidden="true">1</span>
                    <summary>Механика, рассчитанная на ресурс</summary>
                    <div class="why-item__body">
                        <p>Металлическая станина, горизонтальный ротационный челнок и узлы, доступные для обслуживания. Машину не выбрасывают через три года — ей меняют иглы, чистят челнок и шьют дальше.</p>
                        <p>Промышленные модели вроде Juki DDL идут с полусухой головкой и прямым сервоприводом: меньше масла на ткани, меньше шума, стабильный стежок на скорости.</p>
                        <span class="why-item__fact">В каталоге <?= $productsCount ?> моделей с описанными узлами</span>
                    </div>
                </details>
                <details class="why-item">
                    <span class="why-item__num" aria-hidden="true">2</span>
                    <summary>Подача, которая не врёт</summary>
                    <div class="why-item__body">
                        <p>Дифференциальная подача на оверлоках не даёт трикотажу растягиваться, а двойной транспортёр AcuFeed Flex ведёт верхний и нижний слой синхронно — без посадки и перекоса на скользких тканях.</p>
                        <p>Для тяжёлых материалов важнее усиленный прокол и специальные лапки: Toyota Super Jeans спокойно проходит двенадцать слоёв джинсы.</p>
                    </div>
                </details>
                <details class="why-item">
                    <span class="why-item__num" aria-hidden="true">3</span>
                    <summary>Электроника помогает, а не мешает</summary>
                    <div class="why-item__body">
                        <p>Нитевдеватель, кнопка старт/стоп, коленоподъёмник, фиксация строчки — это не «функции ради функций», а руки, которые остаются свободными для ткани.</p>
                        <p>В вышивальных машинах сенсорный экран и Wi-Fi позволяют передать узор с компьютера и сразу запустить пяльцы, без танцев с картами памяти.</p>
                        <?php if ($maxField !== ''): ?>
                            <span class="why-item__fact">Максимальное поле вышивки: <?= e($maxField) ?> мм</span>
                        <?php endif; ?>
                    </div>
                </details>
                <details class="why-item">
                    <span class="why-item__num" aria-hidden="true">4</span>
                    <summary>Экосистема лапок и пялец</summary>
                    <div class="why-item__body">
                        <p>Стандартные крепления лапок, распространённые пяльцы и форматы вышивки (.jef, .jef+) означают, что аксессуар найдётся через год и через пять лет.</p>
                        <p>Японские бренды держат линейки десятилетиями: купленная сегодня машина остаётся совместимой с экосистемой, а не превращается в изолированную железку без аксессуаров.</p>
                    </div>
                </details>
            </div>
        </div>
    </div>
</section>

<!-- ================= ПРИЗЫВ ================= -->
<section class="cta-band">
    <div class="tape tape--light" aria-hidden="true"></div>
    <div class="container">
        <div class="cta-band__inner">
            <div class="reveal">
                <?php if ($user): ?>
                    <span class="label" style="color: var(--wine-300);">С возвращением</span>
                    <h2 class="cta-band__title"><?= e($user['full_name']) ?>, ваша корзина на месте</h2>
                    <p class="cta-band__text">Избранное уже поднимается наверх каталога, а история заказов ждёт в кабинете. Продолжайте шить.</p>
                    <div class="cta-personal">
                        <span class="cta-personal__item">В корзине: <strong><?= (int)$counts['cart'] ?></strong></span>
                        <span class="cta-personal__item">В избранном: <strong><?= (int)$counts['favorites'] ?></strong></span>
                        <?php if ($maxDiscount > 0): ?>
                            <span class="cta-personal__item">Выгода до: <strong><?= e(format_price($maxDiscount)) ?></strong></span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="label" style="color: var(--wine-300);">Аккаунт за минуту</span>
                    <h2 class="cta-band__title">Заведите аккаунт — и корзина запомнит вас</h2>
                    <p class="cta-band__text">Гостю корзина и избранное недоступны: зарегистрируйтесь, чтобы складывать машины, сравнивать их и оформлять заказы с доставкой или самовывозом.</p>
                <?php endif; ?>
            </div>
            <div class="cta-band__actions reveal">
                <?php if ($user): ?>
                    <a class="btn btn--primary btn--lg" href="cabinet.php">Перейти в кабинет</a>
                    <a class="btn btn--ghost btn--lg" href="catalog.php">Продолжить подбор</a>
                    <span class="cta-band__note">Роль: <?= $user['role'] === 'admin' ? 'администратор' : 'покупатель' ?></span>
                <?php else: ?>
                    <a class="btn btn--primary btn--lg" href="register.php">Создать аккаунт</a>
                    <a class="btn btn--ghost btn--lg" href="<?= e(login_url()) ?>">Уже есть аккаунт — войти</a>
                    <span class="cta-band__note">К магазину уже присоединились <?= $usersCount ?> <?= $usersCount === 1 ? 'покупатель' : ($usersCount >= 2 && $usersCount <= 4 ? 'покупателя' : 'покупателей') ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<button class="icon-btn to-top" id="to-top" type="button" aria-label="Наверх" title="Наверх">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 19V6M6 11l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
</button>

<script>
// Анимации главной: прогресс-нить, параллакс, наклон витрины, счётчики, аккордеон
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Нить-прогресс скролла и кнопка «наверх» ---------- */
    var progress = document.getElementById('thread-progress');
    var toTop = document.getElementById('to-top');
    var heroDecor = document.getElementById('hero-decor');
    var ticking = false;

    function onScroll() {
        var doc = document.documentElement;
        var max = doc.scrollHeight - window.innerHeight;
        var ratio = max > 0 ? window.scrollY / max : 0;
        if (progress) { progress.style.transform = 'scaleX(' + ratio + ')'; }
        if (toTop) { toTop.classList.toggle('is-visible', window.scrollY > 600); }
        // Лёгкий параллакс декоративной нити
        if (heroDecor && !reduced && window.innerWidth > 768) {
            heroDecor.style.setProperty('--parallax', (window.scrollY * 0.12) + 'px');
        }
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { window.requestAnimationFrame(onScroll); ticking = true; }
    }, { passive: true });
    onScroll();

    if (toTop) {
        toTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
        });
    }

    /* ---------- Наклон витрины за курсором ---------- */
    var showcase = document.getElementById('hero-showcase-card');
    if (showcase && !reduced && window.matchMedia('(pointer: fine)').matches) {
        showcase.addEventListener('pointermove', function (e) {
            var rect = showcase.getBoundingClientRect();
            var px = (e.clientX - rect.left) / rect.width - 0.5;
            var py = (e.clientY - rect.top) / rect.height - 0.5;
            showcase.style.setProperty('--tilt-y', (px * 6) + 'deg');
            showcase.style.setProperty('--tilt-x', (-py * 6) + 'deg');
        });
        showcase.addEventListener('pointerleave', function () {
            showcase.style.setProperty('--tilt-x', '0deg');
            showcase.style.setProperty('--tilt-y', '0deg');
        });
    }

    /* ---------- Счётчики из БД: плавный набор числа при появлении ---------- */
    var counters = document.querySelectorAll('[data-count-to]');
    function animateCount(el) {
        var target = parseInt(el.dataset.countTo, 10) || 0;
        var suffix = el.dataset.suffix || '';
        if (reduced || target === 0) {
            el.textContent = target.toLocaleString('ru-RU') + suffix;
            return;
        }
        var duration = 950;
        var start = null;
        function step(ts) {
            if (start === null) { start = ts; }
            var p = Math.min((ts - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased).toLocaleString('ru-RU') + suffix;
            if (p < 1) { window.requestAnimationFrame(step); }
        }
        window.requestAnimationFrame(step);
    }
    if ('IntersectionObserver' in window) {
        var countObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    animateCount(entry.target);
                    countObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.4 });
        counters.forEach(function (el) { countObserver.observe(el); });
    } else {
        counters.forEach(animateCount);
    }

    /* ---------- Аккордеон «Почему Япония»: открыт один пункт ---------- */
    var whyList = document.getElementById('why-list');
    if (whyList) {
        var items = whyList.querySelectorAll('details.why-item');
        items.forEach(function (item) {
            item.addEventListener('toggle', function () {
                if (item.open) {
                    items.forEach(function (other) {
                        if (other !== item) { other.open = false; }
                    });
                }
            });
        });
    }

    /* ---------- SMIL-игла: убираем движение при reduced motion ---------- */
    if (reduced) {
        document.querySelectorAll('animateMotion').forEach(function (node) { node.remove(); });
    }
});
</script>

<?php render_footer(); ?>
<?php
/**
 * pages/catalog.php — каталог с фильтрами, поиском, сортировкой и пагинацией.
 * Первичная разметка и фасеты рендерятся на сервере, товары подгружает
 * assets/js/catalog.js через api/filter_products.php без перезагрузки.
 */

require_once __DIR__ . '/../config/helpers.php';

// Тот же вывод типа машинки, что и в api/filter_products.php
$typeExpr = <<<'SQL'
CASE
    WHEN JSON_UNQUOTE(JSON_EXTRACT(p.specs, '$."Тип управления"')) = 'компьютеризированная' THEN 'computer'
    WHEN JSON_UNQUOTE(JSON_EXTRACT(p.specs, '$."Тип управления"')) = 'электромеханическая' THEN 'mechanical'
    WHEN c.slug = 'overloki' THEN 'overlock'
    WHEN c.slug = 'vyshivalnye-mashiny' THEN 'embroidery'
    WHEN c.slug = 'shveyno-vyshivalnye-mashiny' THEN 'combo'
    WHEN c.slug = 'promyshlennye-mashiny' THEN 'industrial'
    ELSE 'other'
END
SQL;

$typeLabels = [
    'computer' => 'Компьютеризированная',
    'mechanical' => 'Электромеханическая',
    'overlock' => 'Оверлок',
    'embroidery' => 'Вышивальная',
    'combo' => 'Швейно-вышивальная',
    'industrial' => 'Промышленная',
];
$typeOrder = ['computer', 'mechanical', 'overlock', 'embroidery', 'combo', 'industrial'];
$allowedSorts = ['new', 'price_asc', 'price_desc', 'popular'];

/* ---------- Фасеты из БД ---------- */
$stmt = $pdo->prepare(
    'SELECT b.name, b.slug,
            (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.is_active = 1) AS cnt
     FROM brands b ORDER BY b.sort_order ASC, b.name ASC'
);
$stmt->execute();
$brands = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT c.name, c.slug,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS cnt
     FROM categories c ORDER BY c.sort_order ASC, c.name ASC'
);
$stmt->execute();
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT (' . $typeExpr . ') AS machine_type, COUNT(*) AS cnt
     FROM products p JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1 GROUP BY machine_type'
);
$stmt->execute();
$typeCounts = [];
foreach ($stmt->fetchAll() as $row) {
    $typeCounts[$row['machine_type']] = (int)$row['cnt'];
}

$stmt = $pdo->prepare(
    'SELECT COALESCE(FLOOR(MIN(price)), 0) AS min_price, COALESCE(CEIL(MAX(price)), 0) AS max_price,
            COUNT(*) AS total,
            SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) AS in_stock,
            SUM(CASE WHEN old_price IS NOT NULL AND old_price > price THEN 1 ELSE 0 END) AS on_sale
     FROM products WHERE is_active = 1'
);
$stmt->execute();
$agg = $stmt->fetch() ?: ['min_price' => 0, 'max_price' => 0, 'total' => 0, 'in_stock' => 0, 'on_sale' => 0];

$boundMin = (int)$agg['min_price'];
$boundMax = (int)$agg['max_price'];
$catalogTotal = (int)$agg['total'];

/* ---------- Начальное состояние из GET (ссылки с главной работают) ---------- */
$initBrand = trim((string)($_GET['brand'] ?? ''));
$initCategory = trim((string)($_GET['category'] ?? ''));
$initType = (string)($_GET['type'] ?? '');
$initType = in_array($initType, $typeOrder, true) ? $initType : '';
$initQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$initSort = (string)($_GET['sort'] ?? 'new');
$initSort = in_array($initSort, $allowedSorts, true) ? $initSort : 'new';
$initMin = isset($_GET['min']) && is_numeric($_GET['min']) ? max($boundMin, min((int)$_GET['min'], $boundMax)) : $boundMin;
$initMax = isset($_GET['max']) && is_numeric($_GET['max']) ? max($boundMin, min((int)$_GET['max'], $boundMax)) : $boundMax;
if ($initMin > $initMax) { [$initMin, $initMax] = [$initMax, $initMin]; }
$initPage = max(1, (int)($_GET['page'] ?? 1));
$initInStock = !empty($_GET['in_stock']);
$initOnSale = !empty($_GET['on_sale']);

$priceStep = 100;

$catalogConfig = [
    'isAuth' => is_logged_in(),
    'loginUrl' => login_url(),
    'bounds' => ['min' => $boundMin, 'max' => $boundMax],
    'step' => $priceStep,
    'perPage' => 9,
    'typeLabels' => $typeLabels,
    'brandNames' => array_column($brands, 'name', 'slug'),
    'categoryNames' => array_column($categories, 'name', 'slug'),
    'initial' => [
        'brand' => $initBrand,
        'category' => $initCategory,
        'type' => $initType,
        'q' => $initQ,
        'min' => $initMin,
        'max' => $initMax,
        'sort' => $initSort,
        'page' => $initPage,
        'in_stock' => $initInStock,
        'on_sale' => $initOnSale,
    ],
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Каталог', [
    'description' => 'Каталог японских швейных машин: фильтры по бренду, категории, типу и цене, поиск и сортировка.',
    'body_class' => 'page-catalog',
]);
?>

<style>
/* ============================================================
   Каталог «Стежок»: липкая панель, фильтры-лоскуты,
   ползунок-лента, уникальные карточки товаров, скелетоны.
   ============================================================ */

/* Нить-прогресс скролла */
.thread-progress {
    position: fixed; top: 0; left: 0; z-index: 60; height: 3px; width: 100%;
    transform: scaleX(0); transform-origin: left;
    background: linear-gradient(90deg, var(--wine-900), var(--wine-500) 45%, var(--wine-300));
}

/* ---------- Hero каталога ---------- */
.catalog-hero { padding: 52px 0 30px; position: relative; overflow: hidden;
    background: linear-gradient(115deg, rgba(176, 27, 46, .06) 0%, rgba(212, 42, 63, .03) 40%, rgba(255, 255, 255, 0) 72%); }
.catalog-hero__row { display: flex; align-items: flex-end; justify-content: space-between; gap: 28px; flex-wrap: wrap; }
.catalog-hero__title { margin-bottom: 10px; }
.catalog-hero__lead { color: var(--muted); max-width: 60ch; margin-bottom: 0; }
.catalog-hero__meta { display: flex; gap: 10px; flex-wrap: wrap; }
.hero-pill { padding: 9px 14px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 600 12.5px/1.2 var(--font-sans); color: var(--muted); }
.hero-pill strong { font-family: var(--font-serif); font-size: 17px; color: var(--wine-700); }
.catalog-hero .tape { margin-top: 26px; }

/* ---------- Липкая панель управления ---------- */
.catalog-toolbar {
    position: sticky; top: var(--header-h); z-index: 30;
    background: rgba(255, 255, 255, .97); border-bottom: 2px dashed var(--line);
    padding: 12px 0; transition: box-shadow .3s;
}
.catalog-toolbar.is-stuck { box-shadow: 0 8px 22px rgba(78, 11, 21, .08); }
.toolbar__inner { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.toolbar__search { position: relative; flex: 1 1 280px; min-width: 200px; }
.toolbar__search .input { padding-left: 40px; padding-right: 38px; }
.toolbar__search-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
.toolbar__clear {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    width: 26px; height: 26px; border: 0; background: var(--linen); border-radius: 50%;
    color: var(--muted); cursor: pointer; font-size: 15px; line-height: 1; display: none;
    transition: background-color .2s, color .2s;
}
.toolbar__clear.is-visible { display: block; }
.toolbar__clear:hover { background: var(--wine-050); color: var(--wine-700); }
.toolbar__sort { display: flex; align-items: center; gap: 8px; flex: none; }
.toolbar__sort label { font: 600 12.5px/1 var(--font-sans); color: var(--muted); white-space: nowrap; }
.toolbar__sort .select { min-width: 210px; padding-block: 9px; }
.toolbar__count { font: 600 13px/1.3 var(--font-sans); color: var(--muted); white-space: nowrap; }
.toolbar__count strong { font-family: var(--font-serif); font-size: 18px; color: var(--wine-700); }
.toolbar__filters-btn { display: none; }

/* ---------- Раскладка ---------- */
.catalog-layout { display: grid; grid-template-columns: 286px 1fr; gap: 30px; align-items: start; padding: 26px 0 60px; }

/* ---------- Фильтры ---------- */
.filters { position: sticky; top: calc(var(--header-h) + 74px); }
.filters__inner { padding: 20px 18px; }
.filters__head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 16px; }
.filters__title { font: 700 18px/1.2 var(--font-serif); color: var(--wine-900); margin: 0; }
.filters__close { display: none; }
.filters__group { padding: 16px 0; border-top: 2px dashed var(--line); }
.filters__group:first-of-type { border-top: 0; padding-top: 0; }
.filters__label { display: flex; align-items: center; gap: 8px; font: 700 11.5px/1 var(--font-sans); letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 11px; }
.filters__label::before { content: ""; width: 14px; height: 2px; background: var(--wine-500); }
.filters__options { display: flex; flex-direction: column; gap: 4px; }
.filter-opt {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    width: 100%; padding: 8px 11px; background: transparent; border: 1px solid transparent;
    border-radius: var(--radius-sm); cursor: pointer; text-align: left;
    font: 500 13.5px/1.3 var(--font-sans); color: var(--ink);
    transition: background-color .2s, color .2s, border-color .2s, transform .15s;
}
.filter-opt:hover { background: var(--linen); border-color: var(--line); transform: translateX(2px); }
.filter-opt__count { font: 700 11px/1 var(--font-sans); color: var(--muted); padding: 3px 7px; background: var(--linen); border-radius: 999px; transition: background-color .2s, color .2s; }
.filter-opt.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); font-weight: 600; box-shadow: var(--shadow-btn); }
.filter-opt.is-active .filter-opt__count { background: rgba(255, 255, 255, .22); color: var(--white); }
.filter-opt.is-zero { opacity: .45; }

/* Переключатели */
.filter-toggle { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 7px 0; cursor: pointer; font: 500 13.5px/1.3 var(--font-sans); }
.filter-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.filter-toggle__switch {
    width: 42px; height: 23px; flex: none; border-radius: 999px; position: relative;
    background: var(--linen); border: 1px solid var(--line); transition: background-color .25s, border-color .25s;
}
.filter-toggle__switch::after {
    content: ""; position: absolute; top: 2px; left: 2px; width: 17px; height: 17px; border-radius: 50%;
    background: var(--white); border: 1px solid var(--line); transition: transform .25s cubic-bezier(.22,.61,.36,1), border-color .25s;
}
.filter-toggle input:checked + .filter-toggle__switch { background: var(--wine-500); border-color: var(--wine-500); }
.filter-toggle input:checked + .filter-toggle__switch::after { transform: translateX(19px); border-color: var(--wine-500); }
.filter-toggle input:focus-visible + .filter-toggle__switch { outline: 2px solid var(--wine-300); outline-offset: 2px; }

/* ---------- Ползунок цены с лентой ---------- */
.price-values { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.price-values .input { padding: 8px 10px; font-size: 13px; text-align: center; }
.price-values span { color: var(--muted); font-size: 13px; }
.price-slider { position: relative; height: 26px; margin-bottom: 2px; }
.price-slider__track { position: absolute; top: 11px; left: 0; right: 0; height: 4px; border-radius: 2px; background: var(--line); }
.price-slider__fill { position: absolute; top: 0; bottom: 0; background: linear-gradient(90deg, var(--wine-500), var(--wine-300)); border-radius: 2px; transition: left .12s linear, width .12s linear; }
.price-slider input[type="range"] {
    position: absolute; top: 0; left: 0; width: 100%; height: 26px; margin: 0;
    appearance: none; background: transparent; pointer-events: none;
}
.price-slider input[type="range"]::-webkit-slider-thumb {
    appearance: none; pointer-events: auto; width: 20px; height: 20px; border-radius: 50%;
    background: var(--white); border: 3px solid var(--wine-500); box-shadow: var(--shadow-sm);
    cursor: grab; transition: transform .15s, border-color .2s;
}
.price-slider input[type="range"]::-webkit-slider-thumb:hover { transform: scale(1.15); border-color: var(--wine-300); }
.price-slider input[type="range"]::-webkit-slider-thumb:active { cursor: grabbing; transform: scale(1.05); }
.price-slider input[type="range"]::-moz-range-thumb {
    pointer-events: auto; width: 14px; height: 14px; border-radius: 50%;
    background: var(--white); border: 3px solid var(--wine-500); cursor: grab;
}
.price-slider input[type="range"]:focus-visible::-webkit-slider-thumb { outline: 2px solid var(--wine-300); outline-offset: 2px; }
.price-slider + .tape { margin-top: 6px; }
.price-hint { font-size: 11.5px; color: var(--muted); margin-top: 8px; }
.filters__reset { margin-top: 16px; }

/* ---------- Активные фильтры-чипы ---------- */
.active-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; min-height: 0; }
.active-chip {
    display: inline-flex; align-items: center; gap: 7px; padding: 7px 10px 7px 13px;
    background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .35); border-radius: 999px;
    font: 600 12.5px/1 var(--font-sans); color: var(--wine-700);
    animation: chip-in .3s cubic-bezier(.22,.61,.36,1) both;
}
.active-chip button {
    width: 19px; height: 19px; border: 0; border-radius: 50%; cursor: pointer;
    background: rgba(176, 27, 46, .14); color: var(--wine-700); font-size: 13px; line-height: 1;
    display: grid; place-items: center; transition: background-color .2s, transform .15s;
}
.active-chip button:hover { background: var(--wine-500); color: var(--white); transform: rotate(90deg); }
@keyframes chip-in { from { opacity: 0; transform: scale(.85); } }

/* ---------- Сетка товаров ---------- */
.catalog-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; transition: opacity .25s ease; }
.catalog-grid.is-updating { opacity: .45; pointer-events: none; }

/* ---------- Карточка товара «лоскут» ---------- */
.pcard {
    display: flex; flex-direction: column; padding: 13px; overflow: hidden;
    animation: pcard-in .5s cubic-bezier(.22, .61, .36, 1) both;
    animation-delay: calc(var(--i, 0) * 55ms);
}
@keyframes pcard-in { from { opacity: 0; transform: translateY(22px) scale(.98); } }
.pcard__media { position: relative; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); margin-bottom: 13px; }
.pcard__media img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s cubic-bezier(.22,.61,.36,1); }
.pcard:hover .pcard__media img { transform: scale(1.06); }
/* Прошитый край фото */
.pcard__media::before {
    content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 7px; z-index: 2;
    background: repeating-linear-gradient(45deg, rgba(176, 27, 46, .55) 0 4px, transparent 4px 8px);
}
/* Строчка по периметру фото на hover */
.pcard__media::after {
    content: ""; position: absolute; inset: 7px; z-index: 2; pointer-events: none;
    border: 2px dashed rgba(255, 255, 255, .8); border-radius: var(--radius-sm);
    opacity: 0; transition: opacity .3s ease;
}
.pcard:hover .pcard__media::after { opacity: 1; }
.pcard__brand {
    position: absolute; top: 9px; left: 9px; z-index: 3; padding: 4px 9px;
    background: var(--wine-900); color: var(--white); border-radius: var(--radius-sm);
    font: 700 9.5px/1.4 var(--font-sans); letter-spacing: .14em; text-transform: uppercase;
}
.pcard__type {
    position: absolute; bottom: 12px; left: 9px; z-index: 3; padding: 4px 9px;
    background: rgba(255, 255, 255, .94); color: var(--wine-700); border-radius: var(--radius-sm);
    font: 600 10px/1.4 var(--font-sans); letter-spacing: .04em;
}
.pcard__sale {
    position: absolute; bottom: 12px; right: 9px; z-index: 3; padding: 4px 9px;
    background: var(--wine-500); color: var(--white); border-radius: var(--radius-sm);
    font: 700 11px/1.4 var(--font-sans); transform: rotate(-3deg); box-shadow: var(--shadow-sm);
    transition: transform .3s;
}
.pcard:hover .pcard__sale { transform: rotate(0deg) scale(1.05); }
.pcard__fav {
    position: absolute; top: 8px; right: 8px; z-index: 4; width: 36px; height: 36px;
    border-radius: 50%; background: rgba(255, 255, 255, .95); border: 1px solid var(--line);
    color: var(--wine-700); display: grid; place-items: center; cursor: pointer;
    transition: transform .18s, border-color .2s, color .2s, background-color .2s;
}
.pcard__fav::before { content: ""; position: absolute; inset: 3px; border: 1px dashed rgba(176, 27, 46, .3); border-radius: 50%; }
.pcard__fav:hover { border-color: var(--wine-300); transform: scale(1.08); }
.pcard__fav:active { transform: scale(.9); }
.pcard__fav.is-active { color: var(--wine-500); background: var(--white); border-color: var(--wine-500); }
.pcard__fav.is-active svg path { fill: currentColor; }
.pcard__fav.is-burst::after {
    content: ""; position: absolute; inset: -4px; border-radius: 50%;
    border: 2px solid var(--wine-300); animation: fav-burst .55s ease-out forwards; pointer-events: none;
}
@keyframes fav-burst { to { transform: scale(1.7); opacity: 0; } }
.pcard__fav.is-busy { opacity: .6; pointer-events: none; }

.pcard__name { font: 700 16.5px/1.35 var(--font-serif); margin: 0 0 7px; }
.pcard__name a { color: var(--wine-900); text-decoration: none; transition: color .2s; }
.pcard__name a:hover { color: var(--wine-700); }
.pcard__model {
    display: inline-block; margin-bottom: 11px; padding: 3px 9px;
    background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm);
    font: 600 10.5px/1.5 var(--font-sans); letter-spacing: .1em; text-transform: uppercase; color: var(--muted);
}
/* Характеристики с точечными выносками, как в выкройке */
.pcard__specs { list-style: none; margin: 0 0 13px; padding: 0; display: flex; flex-direction: column; gap: 5px; }
.pcard__specs li { display: flex; align-items: baseline; gap: 6px; font-size: 12.5px; }
.pcard__specs span { color: var(--muted); flex: none; }
.pcard__specs i { flex: 1; border-bottom: 1px dotted var(--line); transform: translateY(-3px); }
.pcard__specs b { color: var(--ink); font-weight: 600; flex: none; text-align: right; }
/* Бирка с ценой */
.pcard__tag {
    position: relative; display: flex; align-items: baseline; gap: 9px; flex-wrap: wrap;
    padding: 10px 14px 10px 26px; margin-bottom: 11px;
    background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .3);
    border-radius: var(--radius-sm); transform: rotate(-.7deg); transition: transform .3s ease;
    clip-path: polygon(10px 0, 100% 0, 100% 100%, 10px 100%, 0 50%);
}
.pcard:hover .pcard__tag { transform: rotate(0deg); }
.pcard__tag::before { content: ""; position: absolute; left: 11px; top: 50%; width: 6px; height: 6px; margin-top: -3px; border-radius: 50%; background: var(--white); border: 1px solid rgba(176, 27, 46, .4); }
.pcard__price { font: 700 21px/1.1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.pcard__old { font: 400 13px/1 var(--font-sans); color: var(--muted); text-decoration: line-through; }
.pcard__meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.pcard__sold { font: 600 11px/1 var(--font-sans); color: var(--success); }
.pcard__favmark { font: 600 11px/1 var(--font-sans); color: var(--wine-500); display: inline-flex; align-items: center; gap: 5px; }
.pcard__actions { margin-top: auto; display: flex; gap: 8px; }
.pcard__cart { flex: 1; }
.pcard__more { flex: none; width: 42px; padding-inline: 0; }
.pcard__more svg { transition: transform .25s; }
.pcard__more:hover svg { transform: translateX(3px); }

/* ---------- Скелетоны ---------- */
.skeleton-card { padding: 13px; }
.sk { background: linear-gradient(90deg, var(--linen) 25%, #F4E9EB 37%, var(--linen) 63%); background-size: 400% 100%; animation: sk-sweep 1.3s ease infinite; border-radius: var(--radius-sm); }
@keyframes sk-sweep { from { background-position: 100% 0; } to { background-position: 0 0; } }
.sk--media { aspect-ratio: 4 / 3; margin-bottom: 13px; }
.sk--line { height: 13px; margin-bottom: 9px; }
.sk--line.sk--short { width: 55%; }
.sk--tag { height: 42px; margin-bottom: 11px; }
.sk--btn { height: 38px; }

/* ---------- Пагинация ---------- */
.catalog-pagination { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 34px; }
.catalog-pagination .page-link { min-width: 42px; height: 42px; }
.catalog-pagination .page-link:hover { transform: translateY(-2px); }
.page-ellipsis { display: grid; place-items: center; min-width: 26px; color: var(--muted); }

/* ---------- Пусто ---------- */
.catalog-empty { text-align: center; padding: 56px 24px; }
.catalog-empty svg { color: var(--line); margin-bottom: 16px; }
.catalog-empty__needle { animation: needle-sway 3.2s ease-in-out infinite; transform-origin: 50% 20%; }
@keyframes needle-sway { 0%, 100% { transform: rotate(-7deg); } 50% { transform: rotate(7deg); } }

/* ---------- Тост со ссылкой ---------- */
.toast__action {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 8px;
    padding: 6px 12px; background: var(--wine-500); color: var(--white);
    border-radius: var(--radius-sm); text-decoration: none;
    font: 600 12px/1 var(--font-sans); transition: background-color .2s, transform .15s;
}
.toast__action:hover { background: var(--wine-300); transform: translateX(2px); }

/* ---------- Затемнение и мобильный ящик фильтров ---------- */
.filters-scrim {
    position: fixed; inset: 0; z-index: 70; background: rgba(42, 16, 19, .45);
    opacity: 0; visibility: hidden; transition: opacity .3s, visibility .3s;
}
.filters-scrim.is-open { opacity: 1; visibility: visible; }

/* ---------- Адаптив ---------- */
@media (max-width: 1180px) {
    .catalog-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1080px) {
    .catalog-layout { grid-template-columns: 1fr; }
    .toolbar__filters-btn { display: inline-flex; }
    .filters {
        position: fixed; top: 0; right: 0; bottom: 0; z-index: 80; width: min(360px, 88vw);
        transform: translateX(102%); transition: transform .32s cubic-bezier(.22, .61, .36, 1);
        overflow-y: auto; background: var(--white); box-shadow: -12px 0 34px rgba(78, 11, 21, .18);
        scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen);
    }
    .filters.is-open { transform: none; }
    .filters > .card { border: 0; border-radius: 0; min-height: 100%; }
    .filters > .card::before { display: none; }
    .filters__close { display: inline-flex; }
    .filters__inner { padding: 18px 18px 40px; }
}
@media (max-width: 768px) {
    .catalog-hero { padding: 36px 0 22px; }
    .toolbar__inner { gap: 10px; }
    .toolbar__search { flex: 1 1 100%; order: 1; }
    .toolbar__sort { order: 2; flex: 1; }
    .toolbar__sort .select { min-width: 0; width: 100%; }
    .toolbar__count { order: 3; }
    .toolbar__filters-btn { order: 4; }
}
@media (max-width: 620px) {
    .catalog-grid { grid-template-columns: 1fr; gap: 18px; }
    .catalog-hero__meta { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .pcard, .active-chip { animation: none; }
    .sk { animation: none; }
    .catalog-empty__needle { animation: none; }
    .pcard__media img, .pcard__tag, .pcard__sale { transition: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<!-- ================= HERO ================= -->
<section class="catalog-hero">
    <div class="container">
        <div class="catalog-hero__row">
            <div class="reveal">
                <span class="label">Каталог · <?= $catalogTotal ?> моделей</span>
                <h1 class="catalog-hero__title">Подберите свою машинку</h1>
                <p class="catalog-hero__lead">Фильтруйте по бренду, категории и типу, тяните ленту цены, ищите по названию и модели. Избранное всегда поднимается наверх — при любой сортировке.</p>
            </div>
            <div class="catalog-hero__meta reveal">
                <span class="hero-pill"><strong><?= count($brands) ?></strong> брендов</span>
                <span class="hero-pill"><strong><?= (int)$agg['in_stock'] ?></strong> в наличии</span>
                <span class="hero-pill"><strong><?= (int)$agg['on_sale'] ?></strong> со скидкой</span>
            </div>
        </div>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<!-- ================= ПАНЕЛЬ ================= -->
<div class="catalog-toolbar" id="catalog-toolbar">
    <div class="container toolbar__inner">
        <div class="toolbar__search">
            <svg class="toolbar__search-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <label class="visually-hidden" for="catalog-search">Поиск по названию и модели</label>
            <input class="input" id="catalog-search" type="search" placeholder="Поиск: Janome DC 603, MyLock, Super Jeans…"
                   value="<?= e($initQ) ?>" autocomplete="off" maxlength="100">
            <button class="toolbar__clear<?= $initQ !== '' ? ' is-visible' : '' ?>" id="search-clear" type="button" aria-label="Очистить поиск">×</button>
        </div>

        <div class="toolbar__sort">
            <label for="catalog-sort">Сортировка</label>
            <select class="select" id="catalog-sort">
                <option value="new"<?= $initSort === 'new' ? ' selected' : '' ?>>Сначала новые</option>
                <option value="price_asc"<?= $initSort === 'price_asc' ? ' selected' : '' ?>>Цена: по возрастанию</option>
                <option value="price_desc"<?= $initSort === 'price_desc' ? ' selected' : '' ?>>Цена: по убыванию</option>
                <option value="popular"<?= $initSort === 'popular' ? ' selected' : '' ?>>Сначала популярные</option>
            </select>
        </div>

        <span class="toolbar__count" id="results-count" aria-live="polite">Загружаем…</span>

        <button class="btn btn--ghost btn--sm toolbar__filters-btn" id="filters-open" type="button" aria-controls="filters" aria-expanded="false">
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 7h16M7 12h10M10 17h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            Фильтры
        </button>
    </div>
</div>

<!-- ================= РАСКЛАДКА ================= -->
<div class="container">
    <div class="catalog-layout">

        <!-- Фильтры -->
        <aside class="filters" id="filters" aria-label="Фильтры каталога">
            <div class="card card--static">
                <div class="filters__inner">
                    <div class="filters__head">
                        <h2 class="filters__title">Фильтры</h2>
                        <button class="icon-btn filters__close" id="filters-close" type="button" aria-label="Закрыть фильтры">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </button>
                    </div>

                    <div class="filters__group">
                        <div class="filters__label">Бренд</div>
                        <div class="filters__options" data-facet="brand">
                            <button class="filter-opt<?= $initBrand === '' ? ' is-active' : '' ?>" type="button" data-value="">
                                <span>Все бренды</span><span class="filter-opt__count"><?= $catalogTotal ?></span>
                            </button>
                            <?php foreach ($brands as $brand): $cnt = (int)$brand['cnt']; ?>
                                <button class="filter-opt<?= $initBrand === $brand['slug'] ? ' is-active' : '' ?><?= $cnt === 0 ? ' is-zero' : '' ?>"
                                        type="button" data-value="<?= e($brand['slug']) ?>">
                                    <span><?= e($brand['name']) ?></span><span class="filter-opt__count"><?= $cnt ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="filters__group">
                        <div class="filters__label">Категория</div>
                        <div class="filters__options" data-facet="category">
                            <button class="filter-opt<?= $initCategory === '' ? ' is-active' : '' ?>" type="button" data-value="">
                                <span>Все категории</span><span class="filter-opt__count"><?= $catalogTotal ?></span>
                            </button>
                            <?php foreach ($categories as $category): $cnt = (int)$category['cnt']; ?>
                                <button class="filter-opt<?= $initCategory === $category['slug'] ? ' is-active' : '' ?><?= $cnt === 0 ? ' is-zero' : '' ?>"
                                        type="button" data-value="<?= e($category['slug']) ?>">
                                    <span><?= e($category['name']) ?></span><span class="filter-opt__count"><?= $cnt ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="filters__group">
                        <div class="filters__label">Тип машинки</div>
                        <div class="filters__options" data-facet="type">
                            <button class="filter-opt<?= $initType === '' ? ' is-active' : '' ?>" type="button" data-value="">
                                <span>Любой тип</span><span class="filter-opt__count"><?= $catalogTotal ?></span>
                            </button>
                            <?php foreach ($typeOrder as $typeKey): $cnt = $typeCounts[$typeKey] ?? 0; ?>
                                <button class="filter-opt<?= $initType === $typeKey ? ' is-active' : '' ?><?= $cnt === 0 ? ' is-zero' : '' ?>"
                                        type="button" data-value="<?= e($typeKey) ?>">
                                    <span><?= e($typeLabels[$typeKey]) ?></span><span class="filter-opt__count"><?= $cnt ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="filters__group">
                        <div class="filters__label">Цена, ₽</div>
                        <div class="price-values">
                            <label class="visually-hidden" for="price-min-input">Цена от</label>
                            <input class="input" id="price-min-input" type="number" min="<?= $boundMin ?>" max="<?= $boundMax ?>" step="<?= $priceStep ?>" value="<?= $initMin ?>">
                            <span>—</span>
                            <label class="visually-hidden" for="price-max-input">Цена до</label>
                            <input class="input" id="price-max-input" type="number" min="<?= $boundMin ?>" max="<?= $boundMax ?>" step="<?= $priceStep ?>" value="<?= $initMax ?>">
                        </div>
                        <div class="price-slider" id="price-slider">
                            <div class="price-slider__track"><div class="price-slider__fill" id="price-fill"></div></div>
                            <label class="visually-hidden" for="price-min">Минимальная цена</label>
                            <input id="price-min" type="range" min="<?= $boundMin ?>" max="<?= $boundMax ?>" step="<?= $priceStep ?>" value="<?= $initMin ?>">
                            <label class="visually-hidden" for="price-max">Максимальная цена</label>
                            <input id="price-max" type="range" min="<?= $boundMin ?>" max="<?= $boundMax ?>" step="<?= $priceStep ?>" value="<?= $initMax ?>">
                        </div>
                        <div class="tape" aria-hidden="true"></div>
                        <p class="price-hint">Лента цены: от <?= e(format_price($boundMin)) ?> до <?= e(format_price($boundMax)) ?></p>
                    </div>

                    <div class="filters__group">
                        <div class="filters__label">Дополнительно</div>
                        <label class="filter-toggle">
                            <span>Только в наличии</span>
                            <input type="checkbox" id="filter-in-stock"<?= $initInStock ? ' checked' : '' ?>>
                            <span class="filter-toggle__switch" aria-hidden="true"></span>
                        </label>
                        <label class="filter-toggle">
                            <span>Только со скидкой</span>
                            <input type="checkbox" id="filter-on-sale"<?= $initOnSale ? ' checked' : '' ?>>
                            <span class="filter-toggle__switch" aria-hidden="true"></span>
                        </label>
                    </div>

                    <button class="btn btn--ghost btn--block filters__reset" id="filters-reset" type="button">Сбросить всё</button>
                </div>
            </div>
        </aside>

        <!-- Результаты -->
        <section id="results" aria-label="Товары каталога">
            <div class="active-chips" id="active-chips"></div>
            <div class="catalog-grid" id="catalog-grid"></div>
            <div class="catalog-pagination" id="catalog-pagination"></div>
        </section>
    </div>
</div>

<div class="filters-scrim" id="filters-scrim" hidden></div>

<script id="catalog-config" type="application/json"><?= json_encode($catalogConfig, $jsonFlags) ?></script>
<script src="../assets/js/catalog.js" defer></script>

<?php render_footer(); ?>
<?php
/**
 * pages/investors.php — «Инвесторам»: KPI, рынок, модель развития,
 * финансовая таблица, юнит-экономика, раунд и IR-контакты.
 * Все цифры выдуманы для учебного проекта и помечены как выдуманные.
 * Интерактив: липкая навигация разделов, вкладки графиков с подсказками,
 * переключатель единиц таблицы, экспорт CSV, живая юнит-экономика.
 */

require_once __DIR__ . '/../config/helpers.php';

/* ---------- Немного реальной базы для контекста ---------- */
$stmt = $pdo->prepare(
    'SELECT (SELECT COUNT(*) FROM products WHERE is_active = 1) AS products_count,
            (SELECT COUNT(*) FROM brands) AS brands_count,
            (SELECT COUNT(*) FROM categories) AS categories_count'
);
$stmt->execute();
$ctx = $stmt->fetch() ?: [];
$productsCount = (int)($ctx['products_count'] ?? 0);
$brandsCount = (int)($ctx['brands_count'] ?? 0);
$categoriesCount = (int)($ctx['categories_count'] ?? 0);

/* ---------- Финансовая модель, ₽ млн ---------- */
$years = ['2023', '2024', '2025', '2026П'];
$finRows = [
    ['key' => 'revenue', 'label' => 'Выручка', 'values' => [96.4, 149.6, 184.6, 232.0], 'kind' => 'money', 'strong' => true],
    ['key' => 'cogs', 'label' => 'Себестоимость продаж', 'values' => [-62.7, -95.7, -114.1, -141.5], 'kind' => 'money', 'strong' => false],
    ['key' => 'gross', 'label' => 'Валовая прибыль', 'values' => [33.7, 53.9, 70.5, 90.5], 'kind' => 'money', 'strong' => true],
    ['key' => 'gm', 'label' => 'Валовая маржа', 'values' => [35.0, 36.0, 38.2, 39.0], 'kind' => 'percent', 'strong' => false],
    ['key' => 'opex', 'label' => 'Операционные расходы', 'values' => [-26.1, -38.2, -46.3, -57.1], 'kind' => 'money', 'strong' => false],
    ['key' => 'ebitda', 'label' => 'EBITDA', 'values' => [7.6, 15.7, 24.2, 33.4], 'kind' => 'money', 'strong' => true],
    ['key' => 'em', 'label' => 'Маржа EBITDA', 'values' => [7.9, 10.5, 13.1, 14.4], 'kind' => 'percent', 'strong' => false],
    ['key' => 'net', 'label' => 'Чистая прибыль', 'values' => [4.1, 9.8, 15.6, 22.1], 'kind' => 'money', 'strong' => true],
    ['key' => 'capex', 'label' => 'Инвестиции (CAPEX)', 'values' => [-3.2, -6.4, -8.1, -12.0], 'kind' => 'money', 'strong' => false],
    ['key' => 'fcf', 'label' => 'Свободный денежный поток', 'values' => [1.4, 5.2, 9.3, 14.6], 'kind' => 'money', 'strong' => true],
];
$revenueRow = $finRows[0]['values'];

// Проценты к выручке для режима «% к выручке»
$finTable = [];
foreach ($finRows as $row) {
    $cells = [];
    foreach ($row['values'] as $i => $value) {
        $share = $revenueRow[$i] > 0 ? $value / $revenueRow[$i] * 100 : 0;
        $cells[] = [
            'abs' => $row['kind'] === 'percent'
                ? number_format($value, 1, ',', ' ') . '%'
                : number_format($value, 1, ',', ' '),
            'pct' => number_format($row['kind'] === 'percent' ? $value : $share, 1, ',', ' ') . '%',
        ];
    }
    $finTable[] = [
        'key' => $row['key'],
        'label' => $row['label'],
        'strong' => $row['strong'],
        'kind' => $row['kind'],
        'cells' => $cells,
    ];
}

/* ---------- KPI со спарклайнами ---------- */
$kpis = [
    ['label' => 'Выручка 2025', 'value' => 184.6, 'suffix' => ' млн ₽', 'decimals' => 1, 'delta' => '+23,4% г/г', 'tone' => 'success', 'spark' => [72, 88, 96, 118, 132, 150, 168, 185]],
    ['label' => 'EBITDA 2025', 'value' => 24.2, 'suffix' => ' млн ₽', 'decimals' => 1, 'delta' => '+54,1% г/г', 'tone' => 'success', 'spark' => [3.1, 4.4, 7.6, 10.2, 13.1, 15.7, 20.4, 24.2]],
    ['label' => 'Валовая маржа', 'value' => 38.2, 'suffix' => '%', 'decimals' => 1, 'delta' => '+2,2 п.п.', 'tone' => 'success', 'spark' => [32.4, 33.1, 35.0, 35.4, 36.0, 36.8, 37.6, 38.2]],
    ['label' => 'Средний чек', 'value' => 62400, 'suffix' => ' ₽', 'decimals' => 0, 'delta' => '+8,7% г/г', 'tone' => 'success', 'spark' => [48200, 51400, 54100, 55800, 57400, 59100, 60800, 62400]],
    ['label' => 'Заказов за год', 'value' => 2958, 'suffix' => '', 'decimals' => 0, 'delta' => '+14,1% г/г', 'tone' => 'success', 'spark' => [1720, 1980, 2140, 2360, 2510, 2640, 2810, 2958]],
    ['label' => 'Повторные покупки', 'value' => 34.0, 'suffix' => '%', 'decimals' => 1, 'delta' => '+6,0 п.п.', 'tone' => 'success', 'spark' => [21, 24, 26, 28, 30, 31.5, 33, 34]],
    ['label' => 'NPS', 'value' => 71, 'suffix' => '', 'decimals' => 0, 'delta' => '+9 пунктов', 'tone' => 'success', 'spark' => [48, 52, 56, 59, 62, 66, 69, 71]],
    ['label' => 'Оборачиваемость склада', 'value' => 46, 'suffix' => ' дней', 'decimals' => 0, 'delta' => '−7 дней', 'tone' => 'success', 'spark' => [68, 64, 61, 58, 55, 52, 49, 46]],
];

/* ---------- Рынок ---------- */
$marketYears = ['2021', '2022', '2023', '2024', '2025', '2026П'];
$marketSize = [14.2, 13.6, 16.1, 18.9, 21.4, 24.3];
$marketGrowth = [null, -4.2, 18.4, 17.4, 13.2, 13.6];

// Шесть оттенков: различаются и тоном, и штриховкой, и прямой подписью
$brandShares = [
    ['name' => 'Janome', 'share' => 34, 'color' => '#7A1220', 'pattern' => 'inv-p-diag'],
    ['name' => 'Brother', 'share' => 24, 'color' => '#B01B2E', 'pattern' => 'inv-p-dots'],
    ['name' => 'Juki', 'share' => 18, 'color' => '#D42A3F', 'pattern' => 'inv-p-cross'],
    ['name' => 'Toyota', 'share' => 12, 'color' => '#3A6EA5', 'pattern' => 'inv-p-vert'],
    ['name' => 'Baby Lock', 'share' => 7, 'color' => '#B8860B', 'pattern' => 'inv-p-horiz'],
    ['name' => 'Прочие', 'share' => 5, 'color' => '#6B5558', 'pattern' => 'inv-p-grid'],
];

$segments = [
    ['name' => 'Бытовые швейные', 'share' => 41, 'color' => '#7A1220', 'pattern' => 'inv-p-diag'],
    ['name' => 'Оверлоки и распошивальные', 'share' => 22, 'color' => '#B01B2E', 'pattern' => 'inv-p-dots'],
    ['name' => 'Вышивальные', 'share' => 17, 'color' => '#D42A3F', 'pattern' => 'inv-p-cross'],
    ['name' => 'Промышленные', 'share' => 15, 'color' => '#3A6EA5', 'pattern' => 'inv-p-vert'],
    ['name' => 'Швейно-вышивальные', 'share' => 5, 'color' => '#2E7D4F', 'pattern' => 'inv-p-horiz'],
];

/* ---------- Модель развития и раунд ---------- */
$horizons = [
    ['tag' => 'H1 · 2026', 'title' => 'Ядро: каталог и сервис', 'text' => 'Оборачиваемость склада до 40 дней, своя сервисная сеть в 6 городах, рост повторных покупок до 40%.', 'metrics' => [['Выручка', '₽ 232 млн'], ['EBITDA', '14,4%'], ['Города', '6']]],
    ['tag' => 'H2 · 2027', 'title' => 'Регионы и B2B', 'text' => 'Три новых шоурума, контрактные поставки швейным производствам, подписка на расходники и ТО.', 'metrics' => [['Выручка', '₽ 320 млн'], ['B2B доля', '22%'], ['Шоурумы', '5']]],
    ['tag' => 'H3 · 2028', 'title' => 'Платформа подбора', 'text' => 'Подбор машины по ткани и проекту, маркетплейс сервиса, аренда техники для микробрендов.', 'metrics' => [['Выручка', '₽ 500 млн'], ['Подписка', '8% выручки'], ['Аренда', '120 машин']]],
];

$funds = [
    ['name' => 'Склад и логистика', 'share' => 35, 'color' => '#7A1220'],
    ['name' => 'Шоурумы в трёх городах', 'share' => 28, 'color' => '#B01B2E'],
    ['name' => 'IT и платформа подбора', 'share' => 20, 'color' => '#3A6EA5'],
    ['name' => 'Маркетинг и бренд', 'share' => 12, 'color' => '#B8860B'],
    ['name' => 'Команда и сервис', 'share' => 5, 'color' => '#2E7D4F'],
];
$roundAmount = 45.0;

$channels = [
    ['name' => 'Органика и контент', 'share' => 38, 'cac' => 2400],
    ['name' => 'Маркетплейсы', 'share' => 26, 'cac' => 7100],
    ['name' => 'Платный трафик', 'share' => 21, 'cac' => 11800],
    ['name' => 'Рекомендации', 'share' => 15, 'cac' => 900],
];

$investorConfig = [
    'financial' => ['years' => $years, 'rows' => $finTable],
    'unit' => [
        'check' => 62400, 'frequency' => 1.15, 'lifetime' => 2.4,
        'margin' => 38.2, 'service' => 4200, 'cac' => 6800,
    ],
    'presets' => [
        'base' => ['check' => 62400, 'frequency' => 1.15, 'lifetime' => 2.4, 'margin' => 38.2, 'service' => 4200, 'cac' => 6800],
        'conservative' => ['check' => 48000, 'frequency' => 1.0, 'lifetime' => 1.8, 'margin' => 33.0, 'service' => 2000, 'cac' => 9500],
        'aggressive' => ['check' => 78000, 'frequency' => 1.4, 'lifetime' => 3.2, 'margin' => 41.0, 'service' => 7000, 'cac' => 5200],
    ],
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Форматирование чисел для подписей
$fmt1 = static fn(float $v): string => number_format($v, 1, ',', ' ');

// Геометрия столбиков рынка
$chartW = 640; $chartH = 260; $padL = 46; $padB = 34; $padT = 18;
$plotW = $chartW - $padL - 16;
$plotH = $chartH - $padB - $padT;
$maxSize = max($marketSize) * 1.14;
$barSlot = $plotW / count($marketSize);
$barW = $barSlot * 0.52;

render_header('Инвесторам', [
    'description' => 'Инвесторам магазина Janome: ключевые показатели, рынок японской швейной техники, модель развития, финансовая таблица и контакты IR. Учебный проект.',
    'body_class' => 'page-investors',
]);
?>

<style>
/* ============================================================
   «Инвесторам»: липкая навигация, графики со штриховкой,
   финансовая таблица с переключателем единиц и экспортом,
   живая юнит-экономика на ползунках-лентах.
   ============================================================ */

.thread-progress { position: fixed; top: 0; left: 0; z-index: 60; height: 3px; width: 100%; transform: scaleX(0); transform-origin: left; background: linear-gradient(90deg, var(--wine-900), var(--wine-500) 45%, var(--wine-300)); }

.crumbs { padding: 18px 0 6px; }
.crumbs__list { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; list-style: none; margin: 0; padding: 0; font-size: 12.5px; }
.crumbs__list a { color: var(--muted); text-decoration: none; transition: color .2s; }
.crumbs__list a:hover { color: var(--wine-700); }
.crumbs__sep { color: var(--line); }
.crumbs__current { color: var(--wine-700); font-weight: 600; }

/* ---------- Hero ---------- */
.iv-hero { position: relative; overflow: hidden; padding: 42px 0 30px;
    background: linear-gradient(118deg, rgba(78, 11, 21, .09) 0%, rgba(176, 27, 46, .05) 34%, rgba(58, 110, 165, .04) 68%, rgba(255, 255, 255, 0) 92%); }
.iv-hero__row { display: flex; align-items: flex-end; justify-content: space-between; gap: 28px; flex-wrap: wrap; }
.iv-hero__lead { color: var(--muted); max-width: 60ch; margin-bottom: 14px; }
.iv-disclaimer { display: inline-flex; align-items: center; gap: 9px; padding: 9px 14px; background: var(--warning-bg); border: 1px dashed rgba(184, 134, 11, .5); border-radius: var(--radius); font: 600 12px/1.4 var(--font-sans); color: #7A5A08; }
.iv-disclaimer svg { flex: none; }
.iv-hero__pills { display: flex; gap: 10px; flex-wrap: wrap; }
.iv-pill { padding: 10px 15px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 600 12px/1.25 var(--font-sans); color: var(--muted); }
.iv-pill strong { display: block; font-family: var(--font-serif); font-size: 21px; color: var(--wine-700); }
.iv-hero .tape { margin-top: 24px; }

/* ---------- Липкая навигация разделов ---------- */
.iv-nav { position: sticky; top: var(--header-h); z-index: 30; background: rgba(255, 255, 255, .97); border-bottom: 2px dashed var(--line); padding: 10px 0; transition: box-shadow .3s; }
.iv-nav.is-stuck { box-shadow: 0 8px 22px rgba(78, 11, 21, .08); }
.iv-nav__inner { display: flex; gap: 8px; overflow-x: auto; scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen); }
.iv-nav__inner::-webkit-scrollbar { height: 6px; }
.iv-nav__inner::-webkit-scrollbar-track { background: var(--linen); border-radius: 3px; }
.iv-nav__inner::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 3px; }
.iv-nav__inner::-webkit-scrollbar-thumb:hover { background: var(--wine-300); }
.iv-nav__link { flex: none; padding: 8px 14px; border: 1px dashed var(--line); border-radius: 999px; background: var(--white); color: var(--ink); text-decoration: none; font: 600 12.5px/1 var(--font-sans); white-space: nowrap; transition: background-color .22s, color .22s, border-color .22s, transform .18s; }
.iv-nav__link:hover { border-color: var(--wine-300); transform: translateY(-2px); }
.iv-nav__link.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); box-shadow: var(--shadow-btn); }

.iv-section { padding: 46px 0; scroll-margin-top: calc(var(--header-h) + 62px); }

/* ---------- KPI ---------- */
.iv-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.iv-kpi { padding: 18px 18px 14px; display: flex; flex-direction: column; gap: 8px; }
.iv-kpi__label { display: block; font: 600 11.5px/1.35 var(--font-sans); letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
.iv-kpi__value { display: block; font: 700 27px/1.1 var(--font-serif); color: var(--wine-900); }
.iv-kpi__delta { display: inline-flex; align-items: center; gap: 5px; align-self: flex-start; padding: 4px 9px; border-radius: var(--radius-sm); font: 700 11px/1.3 var(--font-sans); background: var(--success-bg); color: var(--success); }
.iv-kpi__spark { display: block; width: 100%; height: 34px; margin-top: auto; }
.iv-spark-line { fill: none; stroke: var(--wine-500); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 300; stroke-dashoffset: 300; transition: stroke-dashoffset 1.2s ease .2s; }
.reveal.is-visible .iv-spark-line { stroke-dashoffset: 0; }
.iv-spark-area { fill: rgba(176, 27, 46, .1); }
.iv-spark-dot { fill: var(--wine-700); }

/* ---------- Графики ---------- */
.iv-chart-card { padding: 22px 20px; }
.iv-chart-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
.iv-chart-title { font: 700 17px/1.3 var(--font-serif); color: var(--wine-900); margin: 0; }
.iv-switch { display: flex; gap: 6px; flex-wrap: wrap; }
.iv-switch__btn { padding: 8px 13px; background: var(--white); border: 1px dashed var(--line); border-radius: 999px; font: 600 12px/1 var(--font-sans); color: var(--ink); cursor: pointer; transition: background-color .22s, color .22s, border-color .22s; }
.iv-switch__btn:hover { border-color: var(--wine-300); }
.iv-switch__btn.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); }
.iv-chart-panel[hidden] { display: none; }
.iv-chart { position: relative; }
.iv-chart svg { display: block; width: 100%; height: auto; overflow: visible; }
.iv-grid-line { stroke: var(--line); stroke-width: 1; stroke-dasharray: 5 5; }
.iv-axis-label { font: 500 11px var(--font-sans); fill: var(--muted); }
.iv-bar { transform-box: fill-box; transform-origin: bottom; transform: scaleY(0); transition: transform .8s cubic-bezier(.22,.61,.36,1); }
.reveal.is-visible .iv-bar { transform: scaleY(1); }
.iv-bar-value { font: 700 12px var(--font-serif); fill: var(--wine-900); }
.iv-bar-year { font: 600 11.5px var(--font-sans); fill: var(--muted); }
.iv-trend { fill: none; stroke: #3A6EA5; stroke-width: 2.4; stroke-linecap: round; stroke-dasharray: 700; stroke-dashoffset: 700; transition: stroke-dashoffset 1.4s ease .5s; }
.reveal.is-visible .iv-trend { stroke-dashoffset: 0; }
.iv-trend-dot { fill: #3A6EA5; }
.iv-hit { fill: transparent; cursor: pointer; }
.iv-tip {
    position: absolute; z-index: 5; padding: 8px 12px; pointer-events: none;
    background: var(--wine-900); color: var(--white); border-radius: var(--radius-sm);
    font: 600 12px/1.4 var(--font-sans); white-space: nowrap; box-shadow: var(--shadow);
    opacity: 0; transform: translate(-50%, -120%); transition: opacity .18s;
}
.iv-tip.is-on { opacity: 1; }
.iv-tip small { display: block; font-weight: 500; color: rgba(255, 255, 255, .65); }

.iv-share-bar { display: flex; width: 100%; height: 56px; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--line); }
.iv-share-seg { position: relative; display: grid; place-items: center; transform: scaleX(0); transform-origin: left; transition: transform .7s cubic-bezier(.22,.61,.36,1); }
.reveal.is-visible .iv-share-seg { transform: scaleX(1); }
.iv-share-seg span { position: relative; z-index: 2; font: 700 12px/1 var(--font-sans); color: var(--white); text-shadow: 0 1px 2px rgba(0, 0, 0, .35); }
.iv-legend { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 16px; }
.iv-legend__item { display: flex; align-items: center; gap: 9px; font: 500 12.5px/1.3 var(--font-sans); color: var(--ink); }
.iv-legend__swatch { width: 26px; height: 18px; flex: none; border-radius: 3px; border: 1px solid rgba(0, 0, 0, .12); }
.iv-legend__value { margin-left: auto; font-weight: 700; color: var(--wine-700); }

.iv-hbars { display: grid; gap: 13px; }
.iv-hbar__head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; font: 500 13px/1.3 var(--font-sans); margin-bottom: 6px; }
.iv-hbar__head b { font: 700 14px/1 var(--font-serif); color: var(--wine-700); }
.iv-hbar__track { height: 22px; border-radius: var(--radius-sm); background: var(--linen); border: 1px solid var(--line); overflow: hidden; }
.iv-hbar__fill { height: 100%; width: 0; transition: width .9s cubic-bezier(.22,.61,.36,1); }
.reveal.is-visible .iv-hbar__fill { width: var(--w, 0%); }

.iv-two { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.iv-list-card { padding: 20px 18px; }
.iv-list-title { display: flex; align-items: center; gap: 9px; font: 700 15.5px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 13px; }
.iv-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.iv-list li { display: flex; align-items: flex-start; gap: 10px; font-size: 13.5px; color: var(--muted); }
.iv-list li b { color: var(--ink); }
.iv-dot { width: 9px; height: 9px; margin-top: 6px; flex: none; border-radius: 50%; }

/* ---------- Горизонты ---------- */
.iv-horizons { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.iv-horizon { padding: 20px 18px; display: flex; flex-direction: column; gap: 11px; }
.iv-horizon__tag { display: inline-block; align-self: flex-start; padding: 5px 11px; background: var(--wine-900); color: var(--white); border-radius: var(--radius-sm); font: 700 10.5px/1.4 var(--font-sans); letter-spacing: .12em; text-transform: uppercase; }
.iv-horizon__title { font: 700 17px/1.3 var(--font-serif); color: var(--wine-900); margin: 0; }
.iv-horizon__text { font-size: 13.5px; color: var(--muted); margin: 0; }
.iv-horizon__metrics { display: grid; gap: 8px; margin-top: auto; padding-top: 13px; border-top: 2px dashed var(--line); }
.iv-metric { display: flex; align-items: baseline; gap: 8px; font-size: 12.5px; }
.iv-metric span { color: var(--muted); flex: none; }
.iv-metric i { flex: 1; border-bottom: 1px dotted var(--line); transform: translateY(-3px); }
.iv-metric b { font-weight: 700; color: var(--wine-700); flex: none; }

/* ---------- Юнит-экономика ---------- */
.iv-unit { display: grid; grid-template-columns: 1.05fr .95fr; gap: 22px; align-items: start; }
.iv-controls { padding: 22px 20px; }
.iv-controls__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.iv-presets { display: flex; gap: 6px; flex-wrap: wrap; }
.iv-preset { padding: 7px 12px; background: var(--white); border: 1px dashed var(--line); border-radius: 999px; font: 600 11.5px/1 var(--font-sans); color: var(--ink); cursor: pointer; transition: background-color .2s, color .2s, border-color .2s; }
.iv-preset:hover { border-color: var(--wine-300); }
.iv-preset.is-active { background: var(--wine-700); border-color: var(--wine-700); color: var(--white); }
.iv-slider { margin-bottom: 16px; }
.iv-slider__head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 7px; }
.iv-slider__label { font: 600 12.5px/1.3 var(--font-sans); color: var(--ink); }
.iv-slider__value { font: 700 14px/1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.iv-slider .tape { margin-top: 8px; height: 12px; }
.iv-unit-out { padding: 22px 20px; }
.iv-out-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
.iv-out { padding: 14px 15px; background: var(--linen); border: 1px dashed var(--line); border-radius: var(--radius); }
.iv-out__label { display: block; font: 700 10.5px/1.4 var(--font-sans); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 5px; }
.iv-out__value { display: block; font: 700 23px/1.15 var(--font-serif); color: var(--wine-700); }
.iv-out__hint { display: block; font-size: 11px; color: var(--muted); margin-top: 4px; }
.iv-compare { display: grid; gap: 10px; margin-bottom: 16px; }
.iv-compare__row { display: grid; grid-template-columns: 74px 1fr auto; gap: 10px; align-items: center; font: 600 12px/1 var(--font-sans); color: var(--muted); }
.iv-compare__track { height: 20px; background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm); overflow: hidden; }
.iv-compare__fill { height: 100%; width: 0; transition: width .45s cubic-bezier(.22,.61,.36,1); }
.iv-compare__value { font: 700 12.5px/1 var(--font-serif); color: var(--wine-900); white-space: nowrap; }
.iv-verdict { display: flex; align-items: flex-start; gap: 10px; padding: 13px 15px; border-radius: var(--radius); font-size: 13px; border-left: 4px solid var(--success); background: var(--success-bg); color: #1F5B39; }
.iv-verdict.is-watch { border-left-color: var(--warning); background: var(--warning-bg); color: #7A5A08; }
.iv-verdict.is-risk { border-left-color: var(--danger); background: var(--danger-bg); color: #8C2A20; }
.iv-verdict svg { flex: none; margin-top: 2px; }

/* ---------- Финансовая таблица ---------- */
.iv-fin-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 16px; }
.iv-fin-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.iv-fin-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius); background: var(--white); scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen); }
.iv-fin-wrap::-webkit-scrollbar { height: 10px; }
.iv-fin-wrap::-webkit-scrollbar-track { background: var(--linen); border-radius: 5px; }
.iv-fin-wrap::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 5px; border: 2px solid var(--linen); }
.iv-fin-wrap::-webkit-scrollbar-thumb:hover { background: var(--wine-300); }
.iv-fin { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 620px; }
.iv-fin th, .iv-fin td { padding: 12px 14px; border-bottom: 1px solid var(--line); text-align: right; white-space: nowrap; }
.iv-fin thead th { background: var(--linen); font: 700 11.5px/1.3 var(--font-sans); letter-spacing: .09em; text-transform: uppercase; color: var(--muted); border-bottom: 2px dashed var(--line); }
.iv-fin th:first-child, .iv-fin td:first-child { text-align: left; position: sticky; left: 0; background: var(--white); z-index: 2; box-shadow: 1px 0 0 var(--line); }
.iv-fin thead th:first-child { background: var(--linen); }
.iv-fin tbody tr:hover td { background: var(--linen); }
.iv-fin tbody tr:hover td:first-child { background: var(--linen); }
.iv-fin tr.is-strong td { font-weight: 700; color: var(--wine-900); }
.iv-fin tr.is-strong td:first-child { color: var(--wine-900); }
.iv-fin tr.is-ratio td { color: var(--muted); font-style: italic; }
.iv-fin tbody tr:last-child td { border-bottom: 0; }
.iv-fin__note { margin: 12px 0 0; font-size: 11.5px; color: var(--muted); }
.iv-unit-badge { display: inline-block; padding: 3px 9px; background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .3); border-radius: var(--radius-sm); font: 700 11px/1.4 var(--font-sans); color: var(--wine-700); }

/* ---------- Раунд ---------- */
.iv-round { display: grid; grid-template-columns: .85fr 1.15fr; gap: 22px; align-items: start; }
.iv-round-card { padding: 22px 20px; background-color: var(--wine-900); background-image: var(--weave-light); border-color: var(--wine-900); color: var(--white); }
.iv-round-card::before { border-color: rgba(255, 255, 255, .22); }
.iv-round-card .iv-round__label { color: rgba(255, 255, 255, .6); }
.iv-round__amount { display: block; font: 700 40px/1.1 var(--font-serif); color: var(--white); margin: 6px 0 16px; }
.iv-round__label { display: block; font: 700 10.5px/1.4 var(--font-sans); letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
.iv-round__rows { display: grid; gap: 10px; }
.iv-round__row { display: flex; align-items: baseline; gap: 8px; font-size: 13px; }
.iv-round__row span { color: rgba(255, 255, 255, .65); flex: none; }
.iv-round__row i { flex: 1; border-bottom: 1px dotted rgba(255, 255, 255, .3); transform: translateY(-3px); }
.iv-round__row b { color: var(--white); flex: none; }
.iv-funds { padding: 22px 20px; }
.iv-milestones { display: grid; gap: 12px; margin-top: 18px; padding-top: 16px; border-top: 2px dashed var(--line); }
.iv-ms { display: flex; align-items: flex-start; gap: 11px; font-size: 13px; color: var(--muted); }
.iv-ms__tag { flex: none; padding: 4px 9px; background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .3); border-radius: var(--radius-sm); font: 700 10.5px/1.4 var(--font-sans); color: var(--wine-700); }
.iv-ms b { color: var(--ink); }

/* ---------- IR-контакты ---------- */
.iv-ir { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.iv-ir-card { padding: 22px 20px; }
.iv-ir-person { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.iv-ir-avatar { position: relative; width: 60px; height: 60px; font-size: 21px; flex: none; }
.iv-ir-avatar::after { content: ""; position: absolute; inset: -5px; border-radius: 50%; border: 2px dashed var(--line); }
.iv-ir-name { display: block; font: 700 16px/1.3 var(--font-serif); color: var(--wine-900); }
.iv-ir-role { display: block; font-size: 12.5px; color: var(--muted); margin-top: 3px; }
.iv-ir-line { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px dashed var(--line); }
.iv-ir-line:last-of-type { border-bottom: 0; }
.iv-ir-line__value { font: 600 14px/1.4 var(--font-sans); color: var(--ink); word-break: break-word; }
.iv-ir-line__value a { color: var(--wine-700); text-decoration: none; }
.iv-ir-line__sub { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.iv-copy { flex: none; width: 32px; height: 32px; border-radius: var(--radius); background: var(--white); border: 1px solid var(--line); color: var(--wine-700); display: grid; place-items: center; cursor: pointer; transition: border-color .2s, background-color .2s, color .2s, transform .15s; }
.iv-copy:hover { border-color: var(--wine-300); background: var(--linen); }
.iv-copy:active { transform: scale(.9); }
.iv-copy.is-copied { background: var(--success); border-color: var(--success); color: var(--white); }
.iv-docs { display: grid; gap: 10px; }
.iv-doc { display: flex; align-items: center; gap: 12px; padding: 13px 15px; background: var(--linen); border: 1px dashed var(--line); border-radius: var(--radius); }
.iv-doc svg { color: var(--wine-500); flex: none; }
.iv-doc__name { display: block; font: 600 13.5px/1.3 var(--font-sans); color: var(--ink); }
.iv-doc__meta { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.iv-doc .btn { margin-left: auto; flex: none; }

.iv-final-note { margin-top: 26px; padding: 16px 18px; background: var(--warning-bg); border: 1px dashed rgba(184, 134, 11, .5); border-radius: var(--radius); font-size: 12.5px; color: #7A5A08; }

/* ---------- Адаптив ---------- */
@media (max-width: 1180px) {
    .iv-kpis { grid-template-columns: repeat(2, 1fr); }
    .iv-horizons { grid-template-columns: 1fr; }
}
@media (max-width: 1080px) {
    .iv-unit, .iv-round, .iv-ir, .iv-two { grid-template-columns: 1fr; }
    .iv-legend { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 620px) {
    .iv-kpis { grid-template-columns: 1fr; }
    .iv-legend, .iv-out-grid { grid-template-columns: 1fr; }
    .iv-hero__pills { width: 100%; }
    .iv-section { padding: 34px 0; }
    .iv-share-seg span { font-size: 10px; }
}
@media (prefers-reduced-motion: reduce) {
    .iv-bar, .iv-share-seg { transform: none; transition: none; }
    .iv-hbar__fill { transition: none; }
    .iv-spark-line, .iv-trend { stroke-dashoffset: 0; transition: none; }
    .iv-compare__fill { transition: none; }
}
</style>

<!-- Общие штриховки для графиков: цвет + паттерн + прямая подпись -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <pattern id="inv-p-diag" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
            <rect width="8" height="8" fill="transparent"/><line x1="0" y1="0" x2="0" y2="8" stroke="rgba(255,255,255,.34)" stroke-width="3"/>
        </pattern>
        <pattern id="inv-p-dots" width="7" height="7" patternUnits="userSpaceOnUse">
            <circle cx="3.5" cy="3.5" r="1.6" fill="rgba(255,255,255,.4)"/>
        </pattern>
        <pattern id="inv-p-cross" width="8" height="8" patternUnits="userSpaceOnUse">
            <path d="M0 0L8 8M8 0L0 8" stroke="rgba(255,255,255,.3)" stroke-width="1.4"/>
        </pattern>
        <pattern id="inv-p-vert" width="7" height="7" patternUnits="userSpaceOnUse">
            <line x1="3.5" y1="0" x2="3.5" y2="7" stroke="rgba(255,255,255,.36)" stroke-width="2.4"/>
        </pattern>
        <pattern id="inv-p-horiz" width="7" height="7" patternUnits="userSpaceOnUse">
            <line x1="0" y1="3.5" x2="7" y2="3.5" stroke="rgba(255,255,255,.4)" stroke-width="2.4"/>
        </pattern>
        <pattern id="inv-p-grid" width="7" height="7" patternUnits="userSpaceOnUse">
            <path d="M0 3.5H7M3.5 0V7" stroke="rgba(255,255,255,.32)" stroke-width="1.3"/>
        </pattern>
    </defs>
</svg>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<nav class="crumbs" aria-label="Навигация по разделам">
    <div class="container">
        <ol class="crumbs__list">
            <li><a href="index.php">Главная</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li class="crumbs__current" aria-current="page">Инвесторам</li>
        </ol>
    </div>
</nav>

<!-- ================= HERO ================= -->
<section class="iv-hero">
    <div class="container">
        <div class="iv-hero__row">
            <div class="reveal">
                <span class="label">Инвесторам · раунд A открыт</span>
                <h1>Растём на рынке, который чинят, а не выбрасывают</h1>
                <p class="iv-hero__lead">Магазин японской швейной техники с собственным сервисом: выручка 2025 — ₽ 184,6 млн, EBITDA ₽ 24,2 млн, повторные покупки 34%. Привлекаем ₽ 45 млн на регионы и платформу подбора.</p>
                <span class="iv-disclaimer">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 4 2.8 19.5h18.4L12 4z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="M12 10v4.2" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><circle cx="12" cy="16.8" r="1" fill="currentColor"/></svg>
                    Учебный курсовой проект: все цифры выдуманы, это не оферта и не инвестиционная рекомендация
                </span>
            </div>
            <div class="iv-hero__pills reveal">
                <span class="iv-pill"><strong>₽ 184,6 млн</strong> выручка 2025</span>
                <span class="iv-pill"><strong>11,4%</strong> CAGR рынка</span>
                <span class="iv-pill"><strong>₽ 45 млн</strong> раунд A</span>
            </div>
        </div>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<!-- ================= НАВИГАЦИЯ РАЗДЕЛОВ ================= -->
<div class="iv-nav" id="iv-nav">
    <div class="container iv-nav__inner">
        <a class="iv-nav__link is-active" href="#kpi" data-nav="kpi">Показатели</a>
        <a class="iv-nav__link" href="#market" data-nav="market">Рынок</a>
        <a class="iv-nav__link" href="#model" data-nav="model">Модель развития</a>
        <a class="iv-nav__link" href="#unit" data-nav="unit">Юнит-экономика</a>
        <a class="iv-nav__link" href="#financial" data-nav="financial">Финансы</a>
        <a class="iv-nav__link" href="#round" data-nav="round">Раунд</a>
        <a class="iv-nav__link" href="#ir" data-nav="ir">Контакты IR</a>
    </div>
</div>

<!-- ================= KPI ================= -->
<section class="iv-section" id="kpi">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Ключевые показатели</h2>
            <span class="muted small">2025 год · динамика за 8 кварталов</span>
        </div>
        <div class="iv-kpis" data-stagger>
            <?php foreach ($kpis as $kpi):
                $spark = $kpi['spark'];
                $sMin = min($spark); $sMax = max($spark);
                $sRange = max(0.0001, $sMax - $sMin);
                $sw = 200; $sh = 34;
                $points = [];
                foreach ($spark as $i => $value) {
                    $x = $i * ($sw / (count($spark) - 1));
                    $y = $sh - 4 - (($value - $sMin) / $sRange) * ($sh - 10);
                    $points[] = round($x, 1) . ',' . round($y, 1);
                }
                $linePath = 'M' . implode(' L', $points);
                $areaPath = $linePath . ' L' . $sw . ',' . $sh . ' L0,' . $sh . ' Z';
                $lastPoint = explode(',', $points[count($points) - 1]);
            ?>
                <div class="card iv-kpi reveal">
                    <span class="iv-kpi__label"><?= e($kpi['label']) ?></span>
                    <span class="iv-kpi__value"
                          data-count-to="<?= e((string)$kpi['value']) ?>"
                          data-decimals="<?= (int)$kpi['decimals'] ?>"
                          data-suffix="<?= e($kpi['suffix']) ?>">0</span>
                    <span class="iv-kpi__delta">
                        <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="M12 19V6M6 11l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?= e($kpi['delta']) ?>
                    </span>
                    <svg class="iv-kpi__spark" viewBox="0 0 <?= $sw ?> <?= $sh ?>" preserveAspectRatio="none" aria-hidden="true">
                        <path class="iv-spark-area" d="<?= e($areaPath) ?>"/>
                        <path class="iv-spark-line" d="<?= e($linePath) ?>"/>
                        <circle class="iv-spark-dot" cx="<?= e($lastPoint[0]) ?>" cy="<?= e($lastPoint[1]) ?>" r="2.6"/>
                    </svg>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted small reveal" style="margin-top: 14px;">Ассортиментная база считается из каталога магазина: <?= $productsCount ?> активных моделей, <?= $brandsCount ?> брендов, <?= $categoriesCount ?> категорий. Финансовые показатели — выдуманные для учебного проекта.</p>
    </div>
</section>

<!-- ================= РЫНОК ================= -->
<section class="iv-section section--linen" id="market">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Рынок японской швейной техники</h2>
            <span class="muted small">РФ · оценки учебного проекта</span>
        </div>

        <div class="card card--static iv-chart-card reveal">
            <div class="iv-chart-head">
                <h3 class="iv-chart-title" id="market-title">Объём рынка, ₽ млрд</h3>
                <div class="iv-switch" role="tablist" aria-label="Разрезы рынка">
                    <button class="iv-switch__btn is-active" type="button" role="tab" data-market="size" aria-selected="true">Объём</button>
                    <button class="iv-switch__btn" type="button" role="tab" data-market="brands" aria-selected="false">Доли брендов</button>
                    <button class="iv-switch__btn" type="button" role="tab" data-market="segments" aria-selected="false">Сегменты</button>
                </div>
            </div>

            <!-- Объём рынка -->
            <div class="iv-chart-panel iv-chart" id="market-size" data-tip-host>
                <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" role="img" aria-label="Объём рынка японской швейной техники с 2021 по 2026 год, ₽ млрд">
                    <?php for ($g = 0; $g <= 4; $g++):
                        $gy = $padT + $plotH - ($g / 4) * $plotH;
                        $gv = $maxSize * $g / 4;
                    ?>
                        <line class="iv-grid-line" x1="<?= $padL ?>" y1="<?= round($gy, 1) ?>" x2="<?= $chartW - 16 ?>" y2="<?= round($gy, 1) ?>"/>
                        <text class="iv-axis-label" x="<?= $padL - 8 ?>" y="<?= round($gy + 4, 1) ?>" text-anchor="end"><?= e(number_format($gv, 0, ',', ' ')) ?></text>
                    <?php endfor; ?>

                    <?php $trendPoints = []; foreach ($marketSize as $i => $value):
                        $x = $padL + $i * $barSlot + ($barSlot - $barW) / 2;
                        $h = ($value / $maxSize) * $plotH;
                        $y = $padT + $plotH - $h;
                        $cx = $x + $barW / 2;
                        $trendPoints[] = round($cx, 1) . ',' . round($y - 12, 1);
                        $growth = $marketGrowth[$i];
                        $tip = $marketYears[$i] . ': ₽ ' . $fmt1($value) . ' млрд' . ($growth !== null ? ' · ' . ($growth > 0 ? '+' : '') . $fmt1($growth) . '% г/г' : '');
                    ?>
                        <rect class="iv-bar" x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round($h, 1) ?>" rx="4"
                              fill="<?= $i === count($marketSize) - 1 ? '#B01B2E' : '#7A1220' ?>" style="transition-delay: <?= $i * 90 ?>ms"/>
                        <rect x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round($h, 1) ?>" rx="4" fill="url(#inv-p-diag)" opacity=".55" pointer-events="none"/>
                        <text class="iv-bar-value" x="<?= round($cx, 1) ?>" y="<?= round($y - 20, 1) ?>" text-anchor="middle"><?= e($fmt1($value)) ?></text>
                        <text class="iv-bar-year" x="<?= round($cx, 1) ?>" y="<?= $chartH - 12 ?>" text-anchor="middle"><?= e($marketYears[$i]) ?></text>
                        <rect class="iv-hit" x="<?= round($padL + $i * $barSlot, 1) ?>" y="<?= $padT ?>" width="<?= round($barSlot, 1) ?>" height="<?= $plotH ?>"
                              data-tip="<?= e($tip) ?>" data-tip-sub="<?= $growth !== null ? 'изменение к предыдущему году' : 'базовый год' ?>"/>
                    <?php endforeach; ?>

                    <path class="iv-trend" d="M<?= implode(' L', $trendPoints) ?>"/>
                    <?php foreach ($trendPoints as $point): $p = explode(',', $point); ?>
                        <circle class="iv-trend-dot" cx="<?= e($p[0]) ?>" cy="<?= e($p[1]) ?>" r="3"/>
                    <?php endforeach; ?>
                </svg>
                <div class="iv-tip" id="market-tip" role="status"></div>
                <p class="muted small" style="margin: 14px 0 0;">CAGR 2021–2026П — 11,4%. Провал 2022 года — логистика и уход части дистрибуции, восстановление с 2023-го на прямых контрактах и сервисных сетях.</p>
            </div>

            <!-- Доли брендов -->
            <div class="iv-chart-panel" id="market-brands" hidden>
                <div class="iv-share-bar reveal is-visible">
                    <?php foreach ($brandShares as $i => $brand): ?>
                        <div class="iv-share-seg" style="width: <?= $brand['share'] ?>%; background: <?= e($brand['color']) ?>; transition-delay: <?= $i * 80 ?>ms;"
                             title="<?= e($brand['name']) ?> — <?= $brand['share'] ?>%">
                            <svg style="position:absolute; inset:0; width:100%; height:100%;" aria-hidden="true"><rect width="100%" height="100%" fill="url(#<?= e($brand['pattern']) ?>)"/></svg>
                            <span><?= $brand['share'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="iv-legend">
                    <?php foreach ($brandShares as $brand): ?>
                        <div class="iv-legend__item">
                            <svg class="iv-legend__swatch" viewBox="0 0 26 18" aria-hidden="true">
                                <rect width="26" height="18" fill="<?= e($brand['color']) ?>"/>
                                <rect width="26" height="18" fill="url(#<?= e($brand['pattern']) ?>)"/>
                            </svg>
                            <span><?= e($brand['name']) ?></span>
                            <span class="iv-legend__value"><?= $brand['share'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="muted small" style="margin: 14px 0 0;">Доли внутри японского сегмента. Janome ведёт за счёт бытовой линейки и сервисной сети, Juki держит промышленное направление.</p>
            </div>

            <!-- Сегменты -->
            <div class="iv-chart-panel" id="market-segments" hidden>
                <div class="iv-hbars reveal is-visible">
                    <?php foreach ($segments as $i => $segment): ?>
                        <div class="iv-hbar">
                            <div class="iv-hbar__head"><span><?= e($segment['name']) ?></span><b><?= $segment['share'] ?>%</b></div>
                            <div class="iv-hbar__track">
                                <div class="iv-hbar__fill" style="--w: <?= $segment['share'] ?>%; background: <?= e($segment['color']) ?>; transition-delay: <?= $i * 90 ?>ms; position: relative;">
                                    <svg style="position:absolute; inset:0; width:100%; height:100%;" aria-hidden="true"><rect width="100%" height="100%" fill="url(#<?= e($segment['pattern']) ?>)"/></svg>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="muted small" style="margin: 14px 0 0;">Бытовые машины дают объём, вышивальные и промышленные — маржу. Наша стратегия: расти в среднем и верхнем бытовом сегменте, где сервис решает выбор.</p>
            </div>
        </div>

        <div class="iv-two" style="margin-top: 20px;" data-stagger>
            <div class="card iv-list-card reveal">
                <h3 class="iv-list-title">
                    <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M4 17l5-6 4 3 7-8" fill="none" stroke="#2E7D4F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 6h5v5" fill="none" stroke="#2E7D4F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Драйверы роста
                </h3>
                <ul class="iv-list">
                    <li><span class="iv-dot" style="background:#2E7D4F"></span><span><b>Микробренды одежды.</b> Малые швейные производства закупают технику раз в 3–4 года и требуют сервис рядом.</span></li>
                    <li><span class="iv-dot" style="background:#3A6EA5"></span><span><b>Домашнее шитьё.</b> Устойчивый спрос на бытовые машины среднего чека ₽ 45–80 тыс.</span></li>
                    <li><span class="iv-dot" style="background:#B01B2E"></span><span><b>Сервис как аргумент.</b> Наличие узлов и инженера важнее скидки в 5%.</span></li>
                    <li><span class="iv-dot" style="background:#B8860B"></span><span><b>Обновление парка.</b> Производства меняют машины 2010-х годов на прямой привод и полусухую головку.</span></li>
                </ul>
            </div>
            <div class="card iv-list-card reveal">
                <h3 class="iv-list-title">
                    <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M12 4 2.8 19.5h18.4L12 4z" fill="none" stroke="#C0392B" stroke-width="2" stroke-linejoin="round"/><path d="M12 10v4.2" stroke="#C0392B" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.8" r="1" fill="#C0392B"/></svg>
                    Риски и как мы их закрываем
                </h3>
                <ul class="iv-list">
                    <li><span class="iv-dot" style="background:#C0392B"></span><span><b>Курсовая волатильность.</b> Закупка партиями на 6–8 недель, валютная оговорка в контрактах.</span></li>
                    <li><span class="iv-dot" style="background:#B8860B"></span><span><b>Сроки поставок.</b> Страховой запас по ходовым SKU и расходникам на 46 дней.</span></li>
                    <li><span class="iv-dot" style="background:#6B5558"></span><span><b>Дешёвый сегмент.</b> Не конкурируем ценой с китайскими брендами, играем в сервис и подбор.</span></li>
                    <li><span class="iv-dot" style="background:#3A6EA5"></span><span><b>Дефицит инженеров.</b> Своё обучение и наставничество, целевая команда — 9 инженеров к 2027.</span></li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ================= МОДЕЛЬ РАЗВИТИЯ ================= -->
<section class="iv-section" id="model">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Модель развития</h2>
            <span class="muted small">Три горизонта · от ядра к платформе</span>
        </div>
        <div class="iv-horizons" data-stagger>
            <?php foreach ($horizons as $horizon): ?>
                <article class="card iv-horizon reveal">
                    <span class="iv-horizon__tag"><?= e($horizon['tag']) ?></span>
                    <h3 class="iv-horizon__title"><?= e($horizon['title']) ?></h3>
                    <p class="iv-horizon__text"><?= e($horizon['text']) ?></p>
                    <div class="iv-horizon__metrics">
                        <?php foreach ($horizon['metrics'] as $metric): ?>
                            <div class="iv-metric"><span><?= e($metric[0]) ?></span><i></i><b><?= e($metric[1]) ?></b></div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="card card--static iv-chart-card reveal" style="margin-top: 20px;">
            <div class="iv-chart-head">
                <h3 class="iv-chart-title">Каналы привлечения и стоимость клиента</h3>
                <span class="iv-unit-badge">Смешанный CAC ₽ 6 800</span>
            </div>
            <div class="iv-hbars">
                <?php foreach ($channels as $i => $channel): ?>
                    <div class="iv-hbar">
                        <div class="iv-hbar__head">
                            <span><?= e($channel['name']) ?> · доля <?= $channel['share'] ?>%</span>
                            <b>CAC ₽ <?= number_format($channel['cac'], 0, ',', ' ') ?></b>
                        </div>
                        <div class="iv-hbar__track">
                            <div class="iv-hbar__fill" style="--w: <?= $channel['share'] ?>%; background: <?= $i % 2 === 0 ? '#7A1220' : '#B01B2E' ?>; transition-delay: <?= $i * 90 ?>ms; position: relative;">
                                <svg style="position:absolute; inset:0; width:100%; height:100%;" aria-hidden="true"><rect width="100%" height="100%" fill="url(#<?= $i % 2 === 0 ? 'inv-p-diag' : 'inv-p-dots' ?>)"/></svg>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted small" style="margin: 14px 0 0;">Рекомендации и контент дают 53% новых клиентов при CAC ниже ₽ 2 500 — поэтому инвестиции в сервис и обучение окупаются быстрее платного трафика.</p>
        </div>
    </div>
</section>

<!-- ================= ЮНИТ-ЭКОНОМИКА ================= -->
<section class="iv-section section--linen" id="unit">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Юнит-экономика вживую</h2>
            <span class="muted small">Двигайте ползунки — модель пересчитывается сразу</span>
        </div>

        <div class="iv-unit">
            <div class="card card--static iv-controls reveal">
                <div class="iv-controls__head">
                    <h3 class="iv-chart-title">Параметры модели</h3>
                    <div class="iv-presets">
                        <button class="iv-preset" type="button" data-preset="conservative">Консервативный</button>
                        <button class="iv-preset is-active" type="button" data-preset="base">Базовый</button>
                        <button class="iv-preset" type="button" data-preset="aggressive">Агрессивный</button>
                        <button class="iv-preset" type="button" id="unit-reset">Сброс</button>
                    </div>
                </div>

                <?php
                $sliders = [
                    ['id' => 'check', 'label' => 'Средний чек', 'min' => 20000, 'max' => 120000, 'step' => 500, 'value' => 62400, 'unit' => ' ₽'],
                    ['id' => 'frequency', 'label' => 'Покупок в год', 'min' => 0.5, 'max' => 3, 'step' => 0.05, 'value' => 1.15, 'unit' => ''],
                    ['id' => 'lifetime', 'label' => 'Срок жизни клиента', 'min' => 1, 'max' => 6, 'step' => 0.1, 'value' => 2.4, 'unit' => ' лет'],
                    ['id' => 'margin', 'label' => 'Валовая маржа', 'min' => 20, 'max' => 55, 'step' => 0.5, 'value' => 38.2, 'unit' => '%'],
                    ['id' => 'service', 'label' => 'Сервис и расходники в год', 'min' => 0, 'max' => 15000, 'step' => 200, 'value' => 4200, 'unit' => ' ₽'],
                    ['id' => 'cac', 'label' => 'Стоимость привлечения (CAC)', 'min' => 1000, 'max' => 20000, 'step' => 100, 'value' => 6800, 'unit' => ' ₽'],
                ];
                foreach ($sliders as $slider): ?>
                    <div class="iv-slider range-wrap">
                        <div class="iv-slider__head">
                            <label class="iv-slider__label" for="u-<?= e($slider['id']) ?>"><?= e($slider['label']) ?></label>
                            <span class="iv-slider__value" id="uv-<?= e($slider['id']) ?>"></span>
                        </div>
                        <input type="range" id="u-<?= e($slider['id']) ?>" data-unit="<?= e($slider['id']) ?>"
                               min="<?= e((string)$slider['min']) ?>" max="<?= e((string)$slider['max']) ?>"
                               step="<?= e((string)$slider['step']) ?>" value="<?= e((string)$slider['value']) ?>"
                               data-unit-suffix="<?= e($slider['unit']) ?>">
                        <div class="tape" aria-hidden="true"></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card card--static iv-unit-out reveal">
                <h3 class="iv-chart-title" style="margin-bottom: 16px;">Результат</h3>
                <div class="iv-out-grid">
                    <div class="iv-out">
                        <span class="iv-out__label">LTV (валовая прибыль)</span>
                        <span class="iv-out__value" id="out-ltv">—</span>
                        <span class="iv-out__hint">за весь срок жизни клиента</span>
                    </div>
                    <div class="iv-out">
                        <span class="iv-out__label">LTV / CAC</span>
                        <span class="iv-out__value" id="out-ratio">—</span>
                        <span class="iv-out__hint">здоровый уровень — от 3,0</span>
                    </div>
                    <div class="iv-out">
                        <span class="iv-out__label">Окупаемость CAC</span>
                        <span class="iv-out__value" id="out-payback">—</span>
                        <span class="iv-out__hint">месяцев валовой прибыли</span>
                    </div>
                    <div class="iv-out">
                        <span class="iv-out__label">Вклад на клиента</span>
                        <span class="iv-out__value" id="out-contribution">—</span>
                        <span class="iv-out__hint">LTV минус стоимость привлечения</span>
                    </div>
                </div>

                <div class="iv-compare">
                    <div class="iv-compare__row">
                        <span>LTV</span>
                        <span class="iv-compare__track"><span class="iv-compare__fill" id="bar-ltv" style="background: linear-gradient(90deg, #7A1220, #D42A3F);"></span></span>
                        <span class="iv-compare__value" id="bar-ltv-value">—</span>
                    </div>
                    <div class="iv-compare__row">
                        <span>CAC</span>
                        <span class="iv-compare__track"><span class="iv-compare__fill" id="bar-cac" style="background: linear-gradient(90deg, #3A6EA5, #6FA0CE);"></span></span>
                        <span class="iv-compare__value" id="bar-cac-value">—</span>
                    </div>
                </div>

                <div class="iv-verdict" id="unit-verdict">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8.5 12.2l2.4 2.4 4.6-4.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span id="unit-verdict-text">—</span>
                </div>
                <p class="muted small" style="margin: 14px 0 0;">LTV = (чек × частота + сервис) × срок жизни × валовая маржа. Модель учебного проекта, без дисконтирования и оттока.</p>
            </div>
        </div>
    </div>
</section>

<!-- ================= ФИНАНСЫ ================= -->
<section class="iv-section" id="financial">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Финансовая таблица</h2>
            <span class="iv-unit-badge" id="fin-unit-badge">₽ млн</span>
        </div>

        <div class="card card--static iv-chart-card reveal">
            <div class="iv-fin-head">
                <h3 class="iv-chart-title">Отчёт о результатах, 2023–2026П</h3>
                <div class="iv-fin-actions">
                    <div class="iv-switch" role="group" aria-label="Единицы измерения">
                        <button class="iv-switch__btn is-active" type="button" data-fin-mode="abs">₽ млн</button>
                        <button class="iv-switch__btn" type="button" data-fin-mode="pct">% к выручке</button>
                    </div>
                    <button class="btn btn--ghost btn--sm" type="button" id="fin-copy">Скопировать</button>
                    <button class="btn btn--primary btn--sm" type="button" id="fin-csv">Скачать CSV</button>
                </div>
            </div>

            <div class="iv-fin-wrap">
                <table class="iv-fin" id="fin-table">
                    <caption class="visually-hidden">Финансовые показатели магазина по годам</caption>
                    <thead>
                    <tr>
                        <th scope="col">Показатель</th>
                        <?php foreach ($years as $year): ?>
                            <th scope="col"><?= e($year) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($finTable as $row): ?>
                        <tr class="<?= $row['strong'] ? 'is-strong' : '' ?><?= $row['kind'] === 'percent' ? ' is-ratio' : '' ?>">
                            <td><?= e($row['label']) ?></td>
                            <?php foreach ($row['cells'] as $cell): ?>
                                <td data-abs="<?= e($cell['abs']) ?>" data-pct="<?= e($cell['pct']) ?>"><?= e($cell['abs']) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="iv-fin__note">2026П — прогноз учебного проекта. Свободный денежный поток учитывает изменение оборотного капитала. Отрицательные значения — расходы. Все цифры выдуманы.</p>
        </div>
    </div>
</section>

<!-- ================= РАУНД ================= -->
<section class="iv-section section--linen" id="round">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Раунд A и использование средств</h2>
            <span class="muted small">₽ 45 млн за 12,5% · post-money ₽ 360 млн</span>
        </div>

        <div class="iv-round">
            <div class="card iv-round-card reveal">
                <span class="iv-round__label">Привлекаем</span>
                <span class="iv-round__amount">₽ 45 млн</span>
                <div class="iv-round__rows">
                    <div class="iv-round__row"><span>Доля инвестора</span><i></i><b>12,5%</b></div>
                    <div class="iv-round__row"><span>Pre-money</span><i></i><b>₽ 315 млн</b></div>
                    <div class="iv-round__row"><span>Post-money</span><i></i><b>₽ 360 млн</b></div>
                    <div class="iv-round__row"><span>Транши</span><i></i><b>2 × ₽ 22,5 млн</b></div>
                    <div class="iv-round__row"><span>Горизонт выхода</span><i></i><b>4–5 лет</b></div>
                    <div class="iv-round__row"><span>Целевая выручка 2028</span><i></i><b>₽ 500 млн</b></div>
                </div>
                <div class="tape tape--light" style="margin-top: 18px;" aria-hidden="true"></div>
            </div>

            <div class="card card--static iv-funds reveal">
                <h3 class="iv-chart-title" style="margin-bottom: 16px;">Куда пойдут деньги</h3>
                <div class="iv-hbars">
                    <?php foreach ($funds as $i => $fund): ?>
                        <div class="iv-hbar">
                            <div class="iv-hbar__head">
                                <span><?= e($fund['name']) ?></span>
                                <b><?= $fund['share'] ?>% · ₽ <?= $fmt1($roundAmount * $fund['share'] / 100) ?> млн</b>
                            </div>
                            <div class="iv-hbar__track">
                                <div class="iv-hbar__fill" style="--w: <?= $fund['share'] ?>%; background: <?= e($fund['color']) ?>; transition-delay: <?= $i * 90 ?>ms;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="iv-milestones">
                    <div class="iv-ms"><span class="iv-ms__tag">2026 Q4</span><span><b>Три шоурума</b> и склад на 90 дней покрытия по ходовым SKU.</span></div>
                    <div class="iv-ms"><span class="iv-ms__tag">2027 Q2</span><span><b>Сервисная сеть в 6 городах</b>, 9 инженеров в команде.</span></div>
                    <div class="iv-ms"><span class="iv-ms__tag">2027 Q4</span><span><b>Выручка ₽ 320 млн</b>, доля B2B 22%.</span></div>
                    <div class="iv-ms"><span class="iv-ms__tag">2028 Q4</span><span><b>Платформа подбора и аренды</b>, выручка ₽ 500 млн.</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= IR-КОНТАКТЫ ================= -->
<section class="iv-section" id="ir">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Контакты для инвесторов</h2>
            <span class="muted small">Отвечаем в течение двух рабочих дней</span>
        </div>

        <div class="iv-ir" data-stagger>
            <div class="card iv-ir-card reveal">
                <div class="iv-ir-person">
                    <span class="avatar iv-ir-avatar avatar--initials" aria-hidden="true">ИС</span>
                    <span>
                        <span class="iv-ir-name">Ирина Соколова</span>
                        <span class="iv-ir-role">Директор по развитию и работе с инвесторами</span>
                    </span>
                </div>
                <div class="iv-ir-line">
                    <div class="iv-ir-line__value">
                        <a href="tel:+74951203459">+7 (495) 120-34-59</a>
                        <span class="iv-ir-line__sub">Пн–Пт 10:00–18:00, прямая линия IR</span>
                    </div>
                    <button class="iv-copy" type="button" data-copy="+7 (495) 120-34-59" aria-label="Скопировать телефон IR" title="Скопировать">
                        <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 15V6a2 2 0 0 1 2-2h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </button>
                </div>
                <div class="iv-ir-line">
                    <div class="iv-ir-line__value">
                        ir@janome.example
                        <span class="iv-ir-line__sub">Почта копируется кнопкой — писем магазин не шлёт</span>
                    </div>
                    <button class="iv-copy" type="button" data-copy="ir@janome.example" aria-label="Скопировать почту IR" title="Скопировать">
                        <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 15V6a2 2 0 0 1 2-2h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </button>
                </div>
                <div class="iv-ir-line">
                    <div class="iv-ir-line__value">
                        Москва, ул. Швейная, 12, пом. 4
                        <span class="iv-ir-line__sub">Встречи по записи, шоурум с тестовым столом</span>
                    </div>
                    <button class="iv-copy" type="button" data-copy="Москва, ул. Швейная, 12, пом. 4" aria-label="Скопировать адрес" title="Скопировать">
                        <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 15V6a2 2 0 0 1 2-2h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </button>
                </div>
                <a class="btn btn--primary btn--block" style="margin-top: 16px;" href="feedback.php">Запросить презентацию и финмодель</a>
            </div>

            <div class="card iv-ir-card reveal">
                <h3 class="iv-chart-title" style="margin-bottom: 14px;">Материалы</h3>
                <div class="iv-docs">
                    <div class="iv-doc">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 3h8l4 4v14H6z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M14 3v4h4M9 13h6M9 17h4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        <span>
                            <span class="iv-doc__name">Финансовая модель 2023–2026П</span>
                            <span class="iv-doc__meta">CSV, разделитель «;», кодировка UTF-8</span>
                        </span>
                        <button class="btn btn--ghost btn--sm" type="button" id="fin-csv-2">Скачать</button>
                    </div>
                    <div class="iv-doc">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 17l5-6 4 3 7-8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 6h5v5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>
                            <span class="iv-doc__name">Презентация раунда A</span>
                            <span class="iv-doc__meta">Готовим по запросу через форму обращений</span>
                        </span>
                        <a class="btn btn--ghost btn--sm" href="feedback.php">Запросить</a>
                    </div>
                    <div class="iv-doc">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>
                            <span class="iv-doc__name">Отчётность и обновления</span>
                            <span class="iv-doc__meta">Квартально, в течение 30 дней после закрытия периода</span>
                        </span>
                        <a class="btn btn--ghost btn--sm" href="about.php">О компании</a>
                    </div>
                </div>
                <div class="tape" style="margin-top: 18px;" aria-hidden="true"></div>
            </div>
        </div>

        <div class="iv-final-note reveal">
            <b>Дисклеймер.</b> Страница подготовлена в рамках учебного курсового проекта. Компания, показатели, рыночные оценки, условия раунда и контакты выдуманы и приведены для демонстрации интерфейса. Материалы не являются офертой, проспектом ценных бумаг или инвестиционной рекомендацией. Платежи и привлечение средств не проводятся.
        </div>
    </div>
</section>

<script id="investor-config" type="application/json"><?= json_encode($investorConfig, $jsonFlags) ?></script>

<script>
// Инвесторам: навигация, графики, юнит-экономика, таблица и экспорт CSV
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('investor-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function nf(value, decimals) {
        return Number(value).toLocaleString('ru-RU', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    /* ---------- Липкая навигация: активный раздел ---------- */
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('[data-nav]'));
    var navBar = document.getElementById('iv-nav');
    var sections = navLinks.map(function (link) { return document.getElementById(link.dataset.nav); }).filter(Boolean);

    function setActiveNav(id) {
        navLinks.forEach(function (link) { link.classList.toggle('is-active', link.dataset.nav === id); });
    }
    if ('IntersectionObserver' in window && sections.length) {
        var navObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { setActiveNav(entry.target.id); }
            });
        }, { threshold: 0.12, rootMargin: '-25% 0px -55% 0px' });
        sections.forEach(function (section) { navObserver.observe(section); });
    }
    navLinks.forEach(function (link) {
        link.addEventListener('click', function () { setActiveNav(link.dataset.nav); });
    });

    /* ---------- KPI: набор чисел ---------- */
    function animateValue(el) {
        var target = parseFloat(el.dataset.countTo) || 0;
        var decimals = parseInt(el.dataset.decimals, 10) || 0;
        var suffix = el.dataset.suffix || '';
        if (reduced) { el.textContent = nf(target, decimals) + suffix; return; }
        var start = null;
        function step(ts) {
            if (start === null) { start = ts; }
            var p = Math.min((ts - start) / 1000, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = nf(target * eased, decimals) + suffix;
            if (p < 1) { requestAnimationFrame(step); }
        }
        requestAnimationFrame(step);
    }
    var kpiValues = document.querySelectorAll('.iv-kpi__value');
    if ('IntersectionObserver' in window) {
        var kpiObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { animateValue(entry.target); kpiObserver.unobserve(entry.target); }
            });
        }, { threshold: 0.4 });
        kpiValues.forEach(function (el) { kpiObserver.observe(el); });
    } else {
        kpiValues.forEach(animateValue);
    }

    /* ---------- Графики рынка: вкладки ---------- */
    var marketBtns = Array.prototype.slice.call(document.querySelectorAll('[data-market]'));
    var marketTitles = { size: 'Объём рынка, ₽ млрд', brands: 'Доли брендов в японском сегменте', segments: 'Сегменты рынка по выручке' };
    var marketTitle = document.getElementById('market-title');

    function showMarket(key) {
        ['size', 'brands', 'segments'].forEach(function (name) {
            var panel = document.getElementById('market-' + name);
            if (panel) { panel.hidden = name !== key; }
        });
        marketBtns.forEach(function (btn) {
            var active = btn.dataset.market === key;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (marketTitle) { marketTitle.textContent = marketTitles[key]; }
    }
    marketBtns.forEach(function (btn) {
        btn.addEventListener('click', function () { showMarket(btn.dataset.market); });
    });

    /* ---------- Подсказки на столбиках ---------- */
    var tip = document.getElementById('market-tip');
    var tipHost = document.getElementById('market-size');
    if (tip && tipHost) {
        tipHost.querySelectorAll('[data-tip]').forEach(function (hit) {
            function show(e) {
                var rect = tipHost.getBoundingClientRect();
                var x = (e.clientX !== undefined ? e.clientX : rect.left + rect.width / 2) - rect.left;
                var y = (e.clientY !== undefined ? e.clientY : rect.top + 40) - rect.top;
                tip.innerHTML = '';
                tip.appendChild(document.createTextNode(hit.dataset.tip));
                var sub = document.createElement('small');
                sub.textContent = hit.dataset.tipSub || '';
                tip.appendChild(sub);
                tip.style.left = Math.max(70, Math.min(x, rect.width - 70)) + 'px';
                tip.style.top = Math.max(34, y) + 'px';
                tip.classList.add('is-on');
            }
            hit.addEventListener('pointerenter', show);
            hit.addEventListener('pointermove', show);
            hit.addEventListener('pointerleave', function () { tip.classList.remove('is-on'); });
        });
    }

    /* ---------- Юнит-экономика ---------- */
    var unitInputs = Array.prototype.slice.call(document.querySelectorAll('[data-unit]'));
    var presetBtns = Array.prototype.slice.call(document.querySelectorAll('[data-preset]'));
    var outLtv = document.getElementById('out-ltv');
    var outRatio = document.getElementById('out-ratio');
    var outPayback = document.getElementById('out-payback');
    var outContribution = document.getElementById('out-contribution');
    var barLtv = document.getElementById('bar-ltv');
    var barCac = document.getElementById('bar-cac');
    var barLtvValue = document.getElementById('bar-ltv-value');
    var barCacValue = document.getElementById('bar-cac-value');
    var verdict = document.getElementById('unit-verdict');
    var verdictText = document.getElementById('unit-verdict-text');

    function readUnit() {
        var values = {};
        unitInputs.forEach(function (input) { values[input.dataset.unit] = parseFloat(input.value) || 0; });
        return values;
    }

    function paintSliderLabels(values) {
        unitInputs.forEach(function (input) {
            var key = input.dataset.unit;
            var suffix = input.dataset.unitSuffix || '';
            var label = document.getElementById('uv-' + key);
            if (!label) { return; }
            var decimals = (key === 'frequency') ? 2 : (key === 'lifetime' || key === 'margin') ? 1 : 0;
            label.textContent = nf(values[key], decimals) + suffix;
        });
    }

    function recalcUnit() {
        var v = readUnit();
        paintSliderLabels(v);

        var annualRevenue = v.check * v.frequency + v.service;
        var ltv = annualRevenue * v.lifetime * (v.margin / 100);
        var ratio = v.cac > 0 ? ltv / v.cac : 0;
        var monthlyGross = (annualRevenue * (v.margin / 100)) / 12;
        var payback = monthlyGross > 0 ? v.cac / monthlyGross : 0;
        var contribution = ltv - v.cac;

        outLtv.textContent = nf(Math.round(ltv), 0) + ' ₽';
        outRatio.textContent = nf(ratio, 1) + '×';
        outPayback.textContent = nf(payback, 1) + ' мес';
        outContribution.textContent = nf(Math.round(contribution), 0) + ' ₽';

        var maxBar = Math.max(ltv, v.cac, 1);
        barLtv.style.width = (ltv / maxBar * 100) + '%';
        barCac.style.width = (v.cac / maxBar * 100) + '%';
        barLtvValue.textContent = nf(Math.round(ltv), 0) + ' ₽';
        barCacValue.textContent = nf(v.cac, 0) + ' ₽';

        verdict.classList.remove('is-watch', 'is-risk');
        var text;
        if (ratio >= 3 && payback <= 12) {
            text = 'Модель здоровая: LTV/CAC ' + nf(ratio, 1) + '× при окупаемости ' + nf(payback, 1) + ' мес. Можно масштабировать привлечение.';
        } else if (ratio >= 2) {
            verdict.classList.add('is-watch');
            text = 'Пограничная зона: LTV/CAC ' + nf(ratio, 1) + '×. Нужен рост маржи, частоты покупок или снижение CAC.';
        } else {
            verdict.classList.add('is-risk');
            text = 'Модель не сходится: LTV/CAC ' + nf(ratio, 1) + '×. Привлечение съедает валовую прибыль — масштабировать нельзя.';
        }
        verdictText.textContent = text;
    }

    function applyPreset(name) {
        var preset = cfg.presets[name];
        if (!preset) { return; }
        unitInputs.forEach(function (input) {
            var key = input.dataset.unit;
            if (preset[key] !== undefined) { input.value = preset[key]; }
        });
        presetBtns.forEach(function (btn) { btn.classList.toggle('is-active', btn.dataset.preset === name); });
        recalcUnit();
    }

    unitInputs.forEach(function (input) {
        input.addEventListener('input', function () {
            presetBtns.forEach(function (btn) { btn.classList.remove('is-active'); });
            recalcUnit();
        });
    });
    presetBtns.forEach(function (btn) {
        btn.addEventListener('click', function () { applyPreset(btn.dataset.preset); });
    });
    var resetBtn = document.getElementById('unit-reset');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            applyPreset('base');
            J.toast('Параметры сброшены к базовому сценарию', 'info', 2200);
        });
    }
    recalcUnit();

    /* ---------- Финансовая таблица: единицы, копирование, CSV ---------- */
    var finTable = document.getElementById('fin-table');
    var finBadge = document.getElementById('fin-unit-badge');
    var finModeBtns = Array.prototype.slice.call(document.querySelectorAll('[data-fin-mode]'));
    var finMode = 'abs';

    function applyFinMode(mode) {
        finMode = mode;
        if (finTable) {
            finTable.querySelectorAll('tbody td[data-abs]').forEach(function (cell) {
                cell.textContent = mode === 'pct' ? cell.dataset.pct : cell.dataset.abs;
            });
        }
        finModeBtns.forEach(function (btn) { btn.classList.toggle('is-active', btn.dataset.finMode === mode); });
        if (finBadge) { finBadge.textContent = mode === 'pct' ? '% к выручке' : '₽ млн'; }
    }
    finModeBtns.forEach(function (btn) {
        btn.addEventListener('click', function () { applyFinMode(btn.dataset.finMode); });
    });

    function tableText() {
        var lines = ['Показатель\t' + cfg.financial.years.join('\t')];
        cfg.financial.rows.forEach(function (row) {
            var cells = row.cells.map(function (cell) { return finMode === 'pct' ? cell.pct : cell.abs; });
            lines.push(row.label + '\t' + cells.join('\t'));
        });
        return lines.join('\n');
    }

    function copyText(text, okMessage) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () {
                J.toast(okMessage, 'success', 2400);
            }).catch(function () { fallbackCopy(text, okMessage); });
        }
        fallbackCopy(text, okMessage);
        return Promise.resolve();
    }
    function fallbackCopy(text, okMessage) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); J.toast(okMessage, 'success', 2400); }
        catch (err) { J.toast('Не удалось скопировать', 'error'); }
        area.remove();
    }

    var finCopy = document.getElementById('fin-copy');
    if (finCopy) {
        finCopy.addEventListener('click', function () { copyText(tableText(), 'Таблица скопирована'); });
    }

    function downloadCsv() {
        var lines = ['Показатель;' + cfg.financial.years.join(';')];
        cfg.financial.rows.forEach(function (row) {
            var cells = row.cells.map(function (cell) {
                var raw = finMode === 'pct' ? cell.pct : cell.abs;
                return raw.replace(/\s/g, ' ');
            });
            lines.push(row.label + ';' + cells.join(';'));
        });
        lines.push('');
        lines.push('Учебный проект;цифры выдуманы;не оферта');

        var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'janome-finmodel-2023-2026.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        J.toast('CSV выгружен: janome-finmodel-2023-2026.csv', 'success');
    }
    ['fin-csv', 'fin-csv-2'].forEach(function (id) {
        var btn = document.getElementById(id);
        if (btn) { btn.addEventListener('click', downloadCsv); }
    });

    /* ---------- Копирование IR-контактов ---------- */
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copyText(btn.dataset.copy || '', 'Скопировано: ' + btn.dataset.copy).then(function () {
                btn.classList.add('is-copied');
                setTimeout(function () { btn.classList.remove('is-copied'); }, 1400);
            });
        });
    });

    /* ---------- Прогресс-нить и тень навигации ---------- */
    var progress = document.getElementById('thread-progress');
    var ticking = false;
    function onScroll() {
        var doc = document.documentElement;
        var max = doc.scrollHeight - window.innerHeight;
        if (progress) { progress.style.transform = 'scaleX(' + (max > 0 ? window.scrollY / max : 0) + ')'; }
        if (navBar) {
            var headerH = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-h')) || 72;
            navBar.classList.toggle('is-stuck', navBar.getBoundingClientRect().top <= headerH + 1);
        }
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { requestAnimationFrame(onScroll); ticking = true; }
    }, { passive: true });
    onScroll();
});
</script>

<?php render_footer(); ?>
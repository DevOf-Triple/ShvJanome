<?php
/**
 * pages/about.php — «О нас»: история, принципы отбора, сервис, команда.
 * Цифры каталога берутся из БД, история и команда — редакционный контент
 * учебного проекта. Интерактив: липкая лента истории, вкладки принципов,
 * подбор техники по ткани (реальные товары из БД).
 */

require_once __DIR__ . '/../config/helpers.php';

/* ---------- Реальные показатели из БД ---------- */
$stmt = $pdo->prepare(
    'SELECT (SELECT COUNT(*) FROM brands) AS brands_count,
            (SELECT COUNT(*) FROM categories) AS categories_count,
            (SELECT COUNT(*) FROM products WHERE is_active = 1) AS products_count,
            (SELECT COALESCE(SUM(stock), 0) FROM products WHERE is_active = 1) AS stock_sum,
            (SELECT COUNT(*) FROM users) AS users_count,
            (SELECT COUNT(*) FROM orders) AS orders_count,
            (SELECT COUNT(*) FROM feedback) AS feedback_count'
);
$stmt->execute();
$stats = $stmt->fetch() ?: [];

$brandsCount = (int)($stats['brands_count'] ?? 0);
$categoriesCount = (int)($stats['categories_count'] ?? 0);
$productsCount = (int)($stats['products_count'] ?? 0);
$stockSum = (int)($stats['stock_sum'] ?? 0);
$usersCount = (int)($stats['users_count'] ?? 0);
$ordersCount = (int)($stats['orders_count'] ?? 0);
$feedbackCount = (int)($stats['feedback_count'] ?? 0);

/* ---------- Товары для подбора по ткани ---------- */
$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.model, p.slug, p.price, p.old_price, p.stock, p.image_path, p.specs,
            b.name AS brand_name, c.slug AS category_slug
     FROM products p
     JOIN brands b ON b.id = p.brand_id
     JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1
     ORDER BY p.id ASC'
);
$stmt->execute();
$catalog = [];
foreach ($stmt->fetchAll() as $row) {
    $row['specs_array'] = json_decode((string)$row['specs'], true) ?: [];
    $catalog[] = $row;
}

// Описание тканей и правила отбора: чем выше вес, тем лучше подходит
$fabrics = [
    'denim' => [
        'label' => 'Джинса и кожзам',
        'title' => 'Нужен усиленный прокол и жёсткая подача',
        'text' => 'Двенадцать слоёв денима не прощает слабой иглы: смотрим на металлическую станину, длину стежка от 4 мм и специальные лапки для толстых швов.',
        'tips' => ['Игла №100–110 и нить №30', 'Лапка для джинсы с ровной подошвой', 'Шаговый узел не должен «клевать» на шве'],
    ],
    'knit' => [
        'label' => 'Трикотаж и футер',
        'title' => 'Решает дифференциальная подача',
        'text' => 'Эластичное полотно тянется под лапкой. Оверлок с дифференциалом и роликовым швом даёт ровный край без волны, а плоский шов держит растяжение.',
        'tips' => ['Дифференциал 1,3–1,5 для кулирки', 'Роликовый шов для низа изделия', 'Шариковая игла stretch №75'],
    ],
    'delicate' => [
        'label' => 'Шифон и шёлк',
        'title' => 'Точность важнее скорости',
        'text' => 'Тонкие ткани требуют короткого стежка, мягкого прижима лапки и стабильного натяжения. Компьютерная машина держит строчку там, где механика «садит» полотно.',
        'tips' => ['Длина стежка 1,8–2,2 мм', 'Тонкая игла microtex №60–70', 'Стабилизатор или бумага под шов'],
    ],
    'quilting' => [
        'label' => 'Квилтинг и лоскут',
        'title' => 'Верхний транспортёр и большое поле',
        'text' => 'Слои ватина и хлопка должны идти синхронно. Двойная подача вроде AcuFeed Flex убирает посадку, а рабочее поле и коленоподъёмник дают свободу на больших полотнах.',
        'tips' => ['Шагающая лапка или AcuFeed', 'Стежок 2,5–3 мм по трём слоям', 'Коленоподъёмник освобождает руки'],
    ],
];

$scoreProduct = static function (array $product, string $fabric): int {
    $specs = $product['specs_array'];
    $category = (string)$product['category_slug'];
    $score = 0;

    if ($fabric === 'denim') {
        if (isset($specs['Шьёт джинсу до (слоёв)'])) { $score += 60; }
        if (($specs['Тип управления'] ?? '') === 'электромеханическая') { $score += 18; }
        if ($category === 'promyshlennye-mashiny') { $score += 22; }
        if ((float)($specs['Макс. длина стежка (мм)'] ?? 0) >= 4) { $score += 10; }
        if ($category === 'shveynye-mashiny') { $score += 6; }
    } elseif ($fabric === 'knit') {
        if ($category === 'overloki') { $score += 55; }
        if (!empty($specs['Дифференциальная подача'])) { $score += 25; }
        if (!empty($specs['Роликовый шов'])) { $score += 14; }
        if (!empty($specs['Flatlock'])) { $score += 8; }
    } elseif ($fabric === 'delicate') {
        if ($category === 'shveynye-mashiny') { $score += 30; }
        if (($specs['Тип управления'] ?? '') === 'компьютеризированная') { $score += 26; }
        if (!empty($specs['Нитевдеватель'])) { $score += 8; }
        if ((int)($specs['Количество операций'] ?? 0) >= 100) { $score += 10; }
    } elseif ($fabric === 'quilting') {
        if (isset($specs['Верхний транспортёр'])) { $score += 60; }
        if (!empty($specs['Коленоподъёмник'])) { $score += 16; }
        if ($category === 'shveyno-vyshivalnye-mashiny') { $score += 18; }
        if ((float)($specs['Макс. ширина стежка (мм)'] ?? 0) >= 7) { $score += 8; }
    }

    // Небольшой бонус за наличие — рекомендуем то, что реально можно купить
    if ((int)$product['stock'] > 0) { $score += 4; }
    return $score;
};

$fabricPicks = [];
foreach ($fabrics as $key => $fabric) {
    $scored = [];
    foreach ($catalog as $product) {
        $scored[] = ['score' => $scoreProduct($product, $key), 'product' => $product];
    }
    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    $picks = [];
    foreach (array_slice($scored, 0, 3) as $entry) {
        $product = $entry['product'];
        $picks[] = [
            'name' => $product['name'],
            'model' => $product['model'],
            'brand' => $product['brand_name'],
            'price' => (float)$product['price'],
            'stock' => (int)$product['stock'],
            'image' => '../' . $product['image_path'],
            'url' => 'product.php?slug=' . rawurlencode($product['slug']),
        ];
    }

    $fabricPicks[$key] = [
        'label' => $fabric['label'],
        'title' => $fabric['title'],
        'text' => $fabric['text'],
        'tips' => $fabric['tips'],
        'items' => $picks,
    ];
}

/* ---------- Редакционный контент ---------- */
$history = [
    ['year' => '2016', 'title' => 'Один стол на Швейной', 'text' => 'Начали с ремонта японских машин в мастерской на 18 м². Первые клиенты приходили за восстановленными Janome и оставались за подбором.'],
    ['year' => '2018', 'title' => 'Прямой контракт и каталог', 'text' => 'Подписали прямой контракт на поставки Janome, собрали каталог из 40 моделей и перестали продавать «то, что привезли».'],
    ['year' => '2020', 'title' => 'Онлайн и сервисный участок', 'text' => 'Запустили интернет-магазин с доставкой по России и открыли сервисный участок: диагностика, чистка челнока, замена узлов.'],
    ['year' => '2022', 'title' => 'Шоурум с тестовым столом', 'text' => 'Переехали в шоурум 240 м². Главное — тестовый стол: покупатель прошивает свою ткань до оплаты, а не после.'],
    ['year' => '2024', 'title' => 'Пять брендов и B2B', 'text' => 'Добавили Juki, Brother, Toyota и Baby Lock, собрали склад расходников и открыли направление для швейных производств.'],
    ['year' => '2026', 'title' => 'Платформа магазина', 'text' => 'Каталог с фильтрами и избранным, личный кабинет, резерв остатка в момент оформления и отмена заказа без звонка.'],
];

$principles = [
    ['key' => 'mechanics', 'label' => 'Механика', 'title' => 'Смотрим на узлы, а не на количество строчек', 'text' => 'Металлическая станина, горизонтальный ротационный челнок, доступ к регулировкам. Машину должно быть можно обслужить через пять лет, а не выбросить.', 'checks' => ['Станина и каркас: металл, а не тонкий пластик', 'Челнок: доступ для чистки без разборки половины корпуса', 'Регулировка натяжения и давления лапки', 'Шум и вибрация на максимальной скорости']],
    ['key' => 'feed', 'label' => 'Подача и прокол', 'title' => 'Ткань должна идти ровно', 'text' => 'Проверяем на трёх слоях джинсы, на кулирке и на шифоне. Если полотно садит, тянет или коверкает — модель в каталог не попадает.', 'checks' => ['Дифференциальная подача для трикотажа', 'Верхний транспортёр для многослойных проектов', 'Усиленный прокол на плотных материалах', 'Стабильная длина стежка при смене толщины']],
    ['key' => 'electronics', 'label' => 'Электроника', 'title' => 'Функции, которые экономят руки', 'text' => 'Нитевдеватель, старт/стоп, коленоподъёмник, фиксация строчки — это не украшение карточки. Всё, что не помогает шить, в описании не выделяем.', 'checks' => ['Реальная скорость, а не пиковая из брошюры', 'Понятная панель без вложенных меню на три уровня', 'Работа без педали и с педалью', 'Поведение при обрыве нити и на толстом шве']],
    ['key' => 'service', 'label' => 'Сервис', 'title' => 'Запчасти должны быть через пять лет', 'text' => 'Берём линейки, которые бренды держат годами: иглы, лапки, шпульки, пяльцы и платы доступны, а не «под заказ из Японии на три месяца».', 'checks' => ['Наличие расходников на складе', 'Схема разборки и сервисная документация', 'Срок поставки узлов до 14 дней', 'Совместимость со стандартными лапками']],
    ['key' => 'honesty', 'label' => 'Честные specs', 'title' => 'Характеристики без маркетинга', 'text' => 'Каждая карточка собрана из паспорта модели: скорость, челнок, поле вышивки, подача. Никаких «профессиональный уровень» без цифр.', 'checks' => ['Скорость указана для реальной строчки', 'Поле вышивки — рабочее, а не максимальное с рамкой', 'Комплектация перечислена полностью', 'Отличия ревизий модели отмечены']],
];

$service = [
    ['name' => 'Диагностика с заключением', 'price' => 1200, 'time' => '1 рабочий день', 'note' => 'Бесплатно, если ремонт делаем мы'],
    ['name' => 'ТО челночного устройства', 'price' => 2400, 'time' => '2 рабочих дня', 'note' => 'Чистка, смазка, настройка натяжения'],
    ['name' => 'Замена узлов и плат', 'price' => 3500, 'time' => '3–5 рабочих дней', 'note' => 'Стоимость узла считается отдельно'],
    ['name' => 'Выезд инженера по Москве', 'price' => 2900, 'time' => 'по записи', 'note' => 'Для промышленных машин и производств'],
    ['name' => 'Настройка под ткань клиента', 'price' => 900, 'time' => 'в день обращения', 'note' => 'Бесплатно при покупке в шоуруме'],
];

$team = [
    ['name' => 'Фёдор Лазарев', 'role' => 'Основатель, генеральный директор', 'tags' => ['закупки', 'стратегия'], 'quote' => 'Машину выбирают под ткань. Всё остальное — маркетинг.'],
    ['name' => 'Марина Ковалёва', 'role' => 'Руководитель каталога', 'tags' => ['specs', 'ассортимент'], 'quote' => 'Если характеристику нельзя проверить паспортом — её не будет в карточке.'],
    ['name' => 'Игорь Стрельцов', 'role' => 'Главный сервис-инженер', 'tags' => ['механика', 'Juki'], 'quote' => 'Полусухая головка и прямой привод — это тишина и чистая ткань.'],
    ['name' => 'Алина Ветрова', 'role' => 'Подбор и консультации', 'tags' => ['квилтинг', 'вышивка'], 'quote' => 'Приносите свою ткань — прошьём при вас и покажем разницу.'],
    ['name' => 'Пётр Гусев', 'role' => 'Логистика и склад', 'tags' => ['остатки', 'доставка'], 'quote' => 'Остаток в каталоге — это остаток на полке, а не обещание поставщика.'],
    ['name' => 'Ольга Наумова', 'role' => 'Клиентский сервис', 'tags' => ['заказы', 'обращения'], 'quote' => 'Отменить новый заказ можно самому. Звонить и уговаривать не нужно.'],
];

$aboutConfig = [
    'fabrics' => $fabricPicks,
    'catalogUrl' => 'catalog.php',
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$fallbackImg = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/></svg>'
);

render_header('О нас', [
    'description' => 'История магазина Janome, принципы отбора техники, сервисный участок и команда. Реальные показатели каталога из базы магазина.',
    'body_class' => 'page-about',
]);
?>

<style>
/* ============================================================
   «О нас»: липкая лента истории, вкладки принципов,
   подбор по ткани, сервисная таблица, команда-монограммы.
   ============================================================ */

.thread-progress { position: fixed; top: 0; left: 0; z-index: 60; height: 3px; width: 100%; transform: scaleX(0); transform-origin: left; background: linear-gradient(90deg, var(--wine-900), var(--wine-500) 45%, var(--wine-300)); }

.crumbs { padding: 18px 0 6px; }
.crumbs__list { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; list-style: none; margin: 0; padding: 0; font-size: 12.5px; }
.crumbs__list a { color: var(--muted); text-decoration: none; transition: color .2s; }
.crumbs__list a:hover { color: var(--wine-700); }
.crumbs__sep { color: var(--line); }
.crumbs__current { color: var(--wine-700); font-weight: 600; }

/* ---------- Hero ---------- */
.ab-hero { position: relative; overflow: hidden; padding: 46px 0 34px;
    background: linear-gradient(122deg, rgba(176, 27, 46, .075) 0%, rgba(212, 42, 63, .035) 38%, rgba(255, 255, 255, 0) 72%); }
.ab-hero::before { content: ""; position: absolute; inset: 0; pointer-events: none;
    background-image: repeating-linear-gradient(90deg, rgba(122, 18, 32, .05) 0 2px, transparent 2px 118px);
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 20%, #000 80%, transparent);
    mask-image: linear-gradient(90deg, transparent, #000 20%, #000 80%, transparent); }
.ab-hero__grid { position: relative; z-index: 2; display: grid; grid-template-columns: 1.15fr .85fr; gap: 44px; align-items: center; }
.ab-hero__title { max-width: 17ch; margin-bottom: 16px; }
.ab-hero__title em { font-style: italic; color: var(--wine-500); }
.ab-hero__lead { color: var(--muted); font-size: 16.5px; max-width: 56ch; margin-bottom: 22px; }
.ab-hero__cta { display: flex; gap: 12px; flex-wrap: wrap; }
.ab-hero__art { position: relative; justify-self: end; width: min(380px, 100%); }
.ab-hero__art svg { display: block; width: 100%; height: auto; }
.ab-art-ring { fill: none; stroke: var(--line); stroke-width: 2; stroke-dasharray: 7 7; transform-origin: 150px 150px; animation: ab-spin 44s linear infinite; }
@keyframes ab-spin { to { transform: rotate(360deg); } }
.ab-art-thread { fill: none; stroke: url(#ab-thread-grad); stroke-width: 2.6; stroke-linecap: round; stroke-dasharray: 700; stroke-dashoffset: 700; animation: ab-draw 2.8s ease .3s forwards; }
@keyframes ab-draw { to { stroke-dashoffset: 0; } }
.ab-hero .tape { margin-top: 32px; position: relative; z-index: 2; }

/* ---------- Цифры ---------- */
.ab-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.ab-stat { padding: 18px 18px; position: relative; }
.ab-stat__num { display: block; font: 700 30px/1.1 var(--font-serif); color: var(--wine-700); }
.ab-stat__label { display: block; font: 500 12px/1.4 var(--font-sans); color: var(--muted); letter-spacing: .04em; margin-top: 4px; }
.ab-stat__icon { position: absolute; top: 16px; right: 16px; color: var(--line); }
.ab-stat__src { display: block; font-size: 10.5px; color: var(--line); margin-top: 8px; letter-spacing: .06em; text-transform: uppercase; }

/* ---------- История ---------- */
.ab-history { display: grid; grid-template-columns: 300px 1fr; gap: 44px; align-items: start; }
.ab-rail { position: sticky; top: calc(var(--header-h) + 24px); }
.ab-rail__lead { color: var(--muted); font-size: 14.5px; margin-bottom: 18px; }
.ab-years { display: grid; gap: 4px; }
.ab-year {
    position: relative; display: flex; align-items: center; gap: 12px; width: 100%;
    padding: 10px 12px; background: transparent; border: 1px solid transparent; border-radius: var(--radius);
    cursor: pointer; text-align: left; font: 600 14px/1.2 var(--font-sans); color: var(--muted);
    transition: background-color .22s, color .22s, border-color .22s, transform .18s;
}
.ab-year::before { content: ""; position: absolute; left: 0; top: 9px; bottom: 9px; width: 3px; border-radius: 2px; background: linear-gradient(180deg, var(--wine-500), var(--wine-300)); transform: scaleY(0); transition: transform .28s cubic-bezier(.22,.61,.36,1); }
.ab-year:hover { background: var(--linen); border-color: var(--line); color: var(--ink); transform: translateX(2px); }
.ab-year.is-active { background: var(--wine-050); border-color: rgba(176, 27, 46, .3); color: var(--wine-700); }
.ab-year.is-active::before { transform: scaleY(1); }
.ab-year__num { font-family: var(--font-serif); font-size: 17px; font-weight: 700; }
.ab-year__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--line); flex: none; transition: background-color .25s, transform .25s; }
.ab-year.is-active .ab-year__dot { background: var(--wine-500); transform: scale(1.3); }

.ab-track { position: relative; padding-left: 6px; }
.ab-track::before { content: ""; position: absolute; left: 25px; top: 22px; bottom: 22px; border-left: 2px dashed var(--line); }
.ab-mile { position: relative; padding-left: 66px; margin-bottom: 18px; scroll-margin-top: calc(var(--header-h) + 28px); }
.ab-mile__badge {
    position: absolute; left: 0; top: 14px; z-index: 1; width: 52px; height: 52px; border-radius: 50%;
    display: grid; place-items: center; background: var(--white); border: 2px solid var(--line);
    font: 700 13px/1 var(--font-serif); color: var(--wine-700);
    transition: border-color .35s, background-color .35s, color .35s, transform .35s;
}
.ab-mile.is-active .ab-mile__badge { border-color: var(--wine-500); background: var(--wine-500); color: var(--white); transform: scale(1.06); }
.ab-mile__card { padding: 18px 20px; }
.ab-mile__title { display: block; font: 700 17px/1.3 var(--font-serif); color: var(--wine-900); margin-bottom: 7px; }
.ab-mile__text { display: block; font-size: 14px; color: var(--muted); }
.ab-mile__year { display: block; font: 700 11px/1 var(--font-sans); letter-spacing: .14em; color: var(--wine-500); margin-bottom: 8px; }

/* ---------- Принципы ---------- */
.ab-principles { display: grid; grid-template-columns: 250px 1fr; gap: 26px; align-items: start; }
.ab-tabs { padding: 10px; display: grid; gap: 4px; position: sticky; top: calc(var(--header-h) + 24px); }
.ab-tab {
    display: flex; align-items: center; gap: 11px; width: 100%; padding: 12px 13px;
    background: transparent; border: 1px solid transparent; border-radius: var(--radius);
    cursor: pointer; text-align: left; font: 600 13.5px/1.25 var(--font-sans); color: var(--ink);
    transition: background-color .22s, color .22s, border-color .22s, transform .18s;
}
.ab-tab:hover { background: var(--linen); border-color: var(--line); transform: translateX(2px); }
.ab-tab.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); box-shadow: var(--shadow-btn); }
.ab-tab__num { width: 26px; height: 26px; flex: none; border-radius: 50%; display: grid; place-items: center; background: var(--linen); font: 700 11.5px/1 var(--font-serif); color: var(--wine-700); transition: background-color .22s, color .22s; }
.ab-tab.is-active .ab-tab__num { background: rgba(255, 255, 255, .22); color: var(--white); }
.ab-panel { padding: 24px 22px; animation: ab-panel-in .38s cubic-bezier(.22,.61,.36,1) both; }
.ab-panel[hidden] { display: none; }
@keyframes ab-panel-in { from { opacity: 0; transform: translateY(12px); } }
.ab-panel__title { font: 700 20px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 10px; }
.ab-panel__text { color: var(--muted); font-size: 14.5px; margin: 0 0 18px; }
.ab-checks { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.ab-check { display: flex; align-items: flex-start; gap: 11px; font-size: 13.5px; color: var(--ink); }
.ab-check__mark { width: 22px; height: 22px; flex: none; border-radius: 50%; background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .35); display: grid; place-items: center; color: var(--wine-500); margin-top: 1px; }

/* ---------- Подбор по ткани ---------- */
.ab-fabric-chips { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
.ab-chip {
    display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px;
    background: var(--white); border: 1px dashed var(--line); border-radius: 999px;
    font: 600 13px/1 var(--font-sans); color: var(--ink); cursor: pointer;
    transition: background-color .22s, color .22s, border-color .22s, transform .2s, box-shadow .22s;
}
.ab-chip:hover { border-color: var(--wine-300); transform: translateY(-2px); }
.ab-chip.is-active { background: var(--wine-500); border-color: var(--wine-500); color: var(--white); box-shadow: var(--shadow-btn); }
.ab-fabric { display: grid; grid-template-columns: 1fr 1.25fr; gap: 26px; align-items: start; padding: 22px 20px; }
.ab-fabric__title { font: 700 19px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 10px; }
.ab-fabric__text { color: var(--muted); font-size: 14px; margin: 0 0 16px; }
.ab-tips { list-style: none; margin: 0; padding: 0; display: grid; gap: 9px; }
.ab-tip { display: flex; align-items: flex-start; gap: 10px; font-size: 13px; color: var(--ink); }
.ab-tip::before { content: ""; width: 8px; height: 8px; margin-top: 6px; flex: none; border-radius: 50%; border: 2px solid var(--wine-500); }
.ab-picks { display: grid; gap: 12px; }
.ab-pick { display: grid; grid-template-columns: 76px 1fr auto; gap: 14px; align-items: center; padding: 12px 14px; background: var(--white); border: 1px solid var(--line); border-radius: var(--radius); text-decoration: none; transition: border-color .22s, transform .22s, box-shadow .22s; animation: ab-pick-in .4s cubic-bezier(.22,.61,.36,1) both; animation-delay: calc(var(--i, 0) * 70ms); }
@keyframes ab-pick-in { from { opacity: 0; transform: translateY(12px); } }
.ab-pick:hover { border-color: var(--wine-300); transform: translateY(-3px); box-shadow: var(--shadow-sm); }
.ab-pick__media { aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); }
.ab-pick__media img { width: 100%; height: 100%; object-fit: cover; }
.ab-pick__brand { display: block; font: 600 10px/1 var(--font-sans); letter-spacing: .13em; text-transform: uppercase; color: var(--muted); margin-bottom: 5px; }
.ab-pick__name { display: block; font: 700 14px/1.35 var(--font-serif); color: var(--wine-900); }
.ab-pick__model { display: block; font-size: 11.5px; color: var(--muted); margin-top: 3px; }
.ab-pick__price { display: block; font: 700 16px/1 var(--font-serif); color: var(--wine-700); white-space: nowrap; text-align: right; }
.ab-pick__stock { display: block; font-size: 10.5px; color: var(--muted); margin-top: 5px; text-align: right; }

/* ---------- Сервис ---------- */
.ab-service { display: grid; grid-template-columns: 1.25fr .75fr; gap: 26px; align-items: start; }
.ab-srv-note { padding: 20px 18px; }
.ab-srv-note__title { font: 700 16px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 12px; }
.ab-srv-facts { display: grid; gap: 11px; margin-bottom: 16px; }
.ab-srv-fact { display: flex; align-items: flex-start; gap: 10px; font-size: 13px; color: var(--muted); }
.ab-srv-fact svg { color: var(--wine-500); flex: none; margin-top: 2px; }
.ab-srv-fact b { color: var(--ink); }

/* ---------- Команда ---------- */
.ab-team { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.ab-person { padding: 20px 18px; display: flex; flex-direction: column; gap: 12px; }
.ab-person__top { display: flex; align-items: center; gap: 13px; }
.ab-person__avatar { position: relative; width: 56px; height: 56px; font-size: 19px; flex: none; }
.ab-person__avatar::after { content: ""; position: absolute; inset: -5px; border-radius: 50%; border: 2px dashed var(--line); transition: border-color .3s, transform .4s; }
.ab-person:hover .ab-person__avatar::after { border-color: var(--wine-300); transform: rotate(12deg); }
.ab-person__name { display: block; font: 700 15.5px/1.3 var(--font-serif); color: var(--wine-900); }
.ab-person__role { display: block; font-size: 12px; color: var(--muted); margin-top: 3px; }
.ab-person__quote { display: block; font: italic 400 13.5px/1.6 var(--font-serif); color: var(--ink); padding: 12px 14px; background: var(--linen); border-left: 3px solid var(--line); border-radius: var(--radius-sm); transition: border-color .3s, background-color .3s; }
.ab-person:hover .ab-person__quote { border-left-color: var(--wine-500); background: var(--wine-050); }
.ab-person__tags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: auto; }
.ab-tag { font: 600 10.5px/1.4 var(--font-sans); letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 4px 9px; background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm); }

/* ---------- Призыв ---------- */
.ab-cta { position: relative; overflow: hidden; background-color: var(--wine-900); background-image: var(--weave-light); color: var(--white); padding: 0 0 52px; margin-top: 40px; }
.ab-cta__inner { display: grid; grid-template-columns: 1.2fr .8fr; gap: 36px; align-items: center; padding-top: 46px; }
.ab-cta__title { color: var(--white); font-size: clamp(24px, 3vw, 34px); margin-bottom: 12px; max-width: 20ch; }
.ab-cta__text { color: rgba(255, 255, 255, .72); font-size: 15px; max-width: 52ch; margin: 0; }
.ab-cta__actions { display: flex; flex-direction: column; gap: 12px; }
.ab-cta .btn--ghost { background: transparent; color: var(--white); border-color: rgba(255, 255, 255, .35); --stitch-c: rgba(255, 255, 255, .45); }
.ab-cta .btn--ghost:hover { background: rgba(255, 255, 255, .1); border-color: var(--wine-300); }
.ab-cta__note { font-size: 12px; color: rgba(255, 255, 255, .55); text-align: center; }

/* ---------- Адаптив ---------- */
@media (max-width: 1080px) {
    .ab-hero__grid { grid-template-columns: 1fr; gap: 30px; }
    .ab-hero__art { justify-self: start; width: min(320px, 100%); }
    .ab-stats { grid-template-columns: repeat(2, 1fr); }
    .ab-history, .ab-principles, .ab-fabric, .ab-service { grid-template-columns: 1fr; gap: 24px; }
    .ab-rail, .ab-tabs { position: static; }
    .ab-years { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 6px; scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen); }
    .ab-years::-webkit-scrollbar { height: 6px; }
    .ab-years::-webkit-scrollbar-track { background: var(--linen); border-radius: 3px; }
    .ab-years::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 3px; }
    .ab-year { flex: none; }
    .ab-year::before { left: 12px; right: 12px; top: auto; bottom: 3px; width: auto; height: 3px; transform: scaleX(0); }
    .ab-year.is-active::before { transform: scaleX(1); }
    .ab-tabs { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: thin; scrollbar-color: var(--wine-500) var(--linen); }
    .ab-tabs::-webkit-scrollbar { height: 6px; }
    .ab-tabs::-webkit-scrollbar-track { background: var(--linen); border-radius: 3px; }
    .ab-tabs::-webkit-scrollbar-thumb { background: var(--wine-500); border-radius: 3px; }
    .ab-tab { flex: none; }
    .ab-team { grid-template-columns: repeat(2, 1fr); }
    .ab-cta__inner { grid-template-columns: 1fr; gap: 26px; }
    .ab-cta__actions { flex-direction: row; flex-wrap: wrap; }
    .ab-cta__actions .btn { flex: 1 1 200px; }
    .ab-cta__note { flex-basis: 100%; }
}
@media (max-width: 620px) {
    .ab-stats, .ab-team { grid-template-columns: 1fr; }
    .ab-mile { padding-left: 56px; }
    .ab-mile__badge { width: 44px; height: 44px; font-size: 12px; }
    .ab-track::before { left: 21px; }
    .ab-pick { grid-template-columns: 62px 1fr; }
    .ab-pick__price, .ab-pick__stock { grid-column: 2; text-align: left; }
    .ab-hero__cta .btn { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .ab-art-ring, .ab-art-thread, .ab-panel, .ab-pick { animation: none; }
    .ab-art-thread { stroke-dashoffset: 0; }
    .ab-year, .ab-tab, .ab-chip, .ab-pick, .ab-person__avatar::after { transition: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<nav class="crumbs" aria-label="Навигация по разделам">
    <div class="container">
        <ol class="crumbs__list">
            <li><a href="index.php">Главная</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li class="crumbs__current" aria-current="page">О нас</li>
        </ol>
    </div>
</nav>

<!-- ================= HERO ================= -->
<section class="ab-hero">
    <div class="container">
        <div class="ab-hero__grid" data-stagger>
            <div>
                <span class="label reveal">О нас · с 2016 года</span>
                <h1 class="ab-hero__title reveal">Магазин, который <em>прошивает</em> свою репутацию</h1>
                <p class="ab-hero__lead reveal">Мы начинали с ремонта японских машин в мастерской на 18 м², а сегодня держим каталог из <?= $productsCount ?> моделей, сервисный участок и шоурум с тестовым столом. Принцип не изменился: сначала ткань и задача, потом машинка.</p>
                <div class="ab-hero__cta reveal">
                    <a class="btn btn--primary btn--lg" href="catalog.php">Смотреть каталог</a>
                    <a class="btn btn--ghost btn--lg" href="contacts.php">Приехать в шоурум</a>
                </div>
            </div>
            <div class="ab-hero__art reveal" aria-hidden="true">
                <svg viewBox="0 0 300 300" focusable="false">
                    <defs>
                        <linearGradient id="ab-thread-grad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" stop-color="#B01B2E"/>
                            <stop offset=".55" stop-color="#D42A3F"/>
                            <stop offset="1" stop-color="#7A1220"/>
                        </linearGradient>
                    </defs>
                    <circle class="ab-art-ring" cx="150" cy="150" r="118"/>
                    <circle cx="150" cy="150" r="92" fill="none" stroke="#E8D5D8" stroke-width="2"/>
                    <circle cx="150" cy="150" r="66" fill="#FFFFFF" stroke="#7A1220" stroke-width="3"/>
                    <circle cx="150" cy="150" r="28" fill="none" stroke="#B01B2E" stroke-width="3"/>
                    <circle cx="150" cy="150" r="10" fill="#4E0B15"/>
                    <path d="M22 236C86 194 132 272 194 226S272 180 286 200" fill="none" stroke="#D42A3F" stroke-width="2" stroke-dasharray="6 6" opacity=".5"/>
                    <path class="ab-art-thread" d="M22 236C86 194 132 272 194 226S272 180 286 200"/>
                    <g transform="translate(232 84) rotate(22)">
                        <line x1="0" y1="0" x2="0" y2="78" stroke="#4E0B15" stroke-width="5" stroke-linecap="round"/>
                        <line x1="0" y1="78" x2="0" y2="94" stroke="#7A1220" stroke-width="3" stroke-linecap="round"/>
                        <ellipse cx="0" cy="14" rx="4.5" ry="10" fill="none" stroke="#7A1220" stroke-width="2.4"/>
                    </g>
                    <path d="M178 126C206 102 216 94 228 98" fill="none" stroke="url(#ab-thread-grad)" stroke-width="2.6" stroke-linecap="round"/>
                </svg>
            </div>
        </div>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<!-- ================= ЦИФРЫ ИЗ БД ================= -->
<section class="section">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Магазин в цифрах</h2>
            <span class="muted small">Считается из базы прямо сейчас, не из брошюры</span>
        </div>
        <div class="ab-stats" data-stagger>
            <div class="card ab-stat reveal">
                <span class="ab-stat__icon"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8.5 9h7M8.5 13h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>
                <span class="ab-stat__num" data-count-to="<?= $productsCount ?>">0</span>
                <span class="ab-stat__label">активных моделей в каталоге</span>
                <span class="ab-stat__src">products</span>
            </div>
            <div class="card ab-stat reveal">
                <span class="ab-stat__icon"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.7"/></svg></span>
                <span class="ab-stat__num" data-count-to="<?= $brandsCount ?>">0</span>
                <span class="ab-stat__label">японских брендов · <?= $categoriesCount ?> категорий</span>
                <span class="ab-stat__src">brands</span>
            </div>
            <div class="card ab-stat reveal">
                <span class="ab-stat__icon"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M4 8l8-4 8 4-8 4z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M4 12l8 4 8-4M4 16l8 4 8-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg></span>
                <span class="ab-stat__num" data-count-to="<?= $stockSum ?>">0</span>
                <span class="ab-stat__label">машин на складе в наличии</span>
                <span class="ab-stat__src">stock</span>
            </div>
            <div class="card ab-stat reveal">
                <span class="ab-stat__icon"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="8.5" r="3.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M5 20c1.2-3.6 4-5.5 7-5.5s5.8 1.9 7 5.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>
                <span class="ab-stat__num" data-count-to="<?= $usersCount ?>">0</span>
                <span class="ab-stat__label">аккаунтов · <?= $ordersCount ?> заказов · <?= $feedbackCount ?> обращений</span>
                <span class="ab-stat__src">users / orders</span>
            </div>
        </div>
    </div>
</section>

<!-- ================= ИСТОРИЯ ================= -->
<section class="section section--linen" id="history">
    <div class="container">
        <div class="ab-history">
            <div class="ab-rail reveal">
                <span class="label">История</span>
                <h2 class="section-title">Десять лет по одной строчке</h2>
                <p class="ab-rail__lead">От стола в мастерской до платформы с резервом остатка. Нажмите год — лента прокрутится к нужному стежку.</p>
                <div class="ab-years" id="ab-years" role="tablist" aria-label="Годы истории">
                    <?php foreach ($history as $i => $mile): ?>
                        <button class="ab-year<?= $i === 0 ? ' is-active' : '' ?>" type="button" data-year-target="mile-<?= $i ?>" aria-label="Перейти к <?= e($mile['year']) ?> году">
                            <span class="ab-year__dot" aria-hidden="true"></span>
                            <span class="ab-year__num"><?= e($mile['year']) ?></span>
                            <span><?= e($mile['title']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ab-track" id="ab-track">
                <?php foreach ($history as $i => $mile): ?>
                    <article class="ab-mile<?= $i === 0 ? ' is-active' : '' ?>" id="mile-<?= $i ?>" data-mile="<?= $i ?>">
                        <span class="ab-mile__badge" aria-hidden="true"><?= e($mile['year']) ?></span>
                        <div class="card ab-mile__card reveal">
                            <span class="ab-mile__year"><?= e($mile['year']) ?></span>
                            <span class="ab-mile__title"><?= e($mile['title']) ?></span>
                            <span class="ab-mile__text"><?= e($mile['text']) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ================= ПРИНЦИПЫ ОТБОРА ================= -->
<section class="section" id="principles">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Как мы отбираем технику</h2>
            <span class="muted small">Пять фильтров, через которые проходит каждая модель</span>
        </div>

        <div class="ab-principles">
            <div class="card card--static ab-tabs reveal" role="tablist" aria-label="Принципы отбора">
                <?php foreach ($principles as $i => $principle): ?>
                    <button class="ab-tab<?= $i === 0 ? ' is-active' : '' ?>" type="button" role="tab"
                            id="ptab-<?= e($principle['key']) ?>" data-principle="<?= e($principle['key']) ?>"
                            aria-controls="ppanel-<?= e($principle['key']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                        <span class="ab-tab__num" aria-hidden="true"><?= $i + 1 ?></span>
                        <span><?= e($principle['label']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div>
                <?php foreach ($principles as $i => $principle): ?>
                    <div class="card card--static ab-panel" id="ppanel-<?= e($principle['key']) ?>" role="tabpanel"
                         aria-labelledby="ptab-<?= e($principle['key']) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
                        <h3 class="ab-panel__title"><?= e($principle['title']) ?></h3>
                        <p class="ab-panel__text"><?= e($principle['text']) ?></p>
                        <ul class="ab-checks">
                            <?php foreach ($principle['checks'] as $check): ?>
                                <li class="ab-check">
                                    <span class="ab-check__mark" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="12" height="12"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span><?= e($check) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ================= ПОДБОР ПО ТКАНИ ================= -->
<section class="section section--linen" id="fabric">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Что мы советуем под вашу ткань</h2>
            <a class="link" href="catalog.php">Подобрать в каталоге</a>
        </div>
        <p class="muted reveal" style="max-width: 62ch; margin-bottom: 20px;">Выберите материал — покажем, на что смотреть, и три модели из наличия, которые мы бы поставили на тестовый стол.</p>

        <div class="ab-fabric-chips reveal" id="fabric-chips" role="tablist" aria-label="Тип ткани">
            <?php $first = true; foreach ($fabricPicks as $key => $fabric): ?>
                <button class="ab-chip<?= $first ? ' is-active' : '' ?>" type="button" role="tab"
                        data-fabric="<?= e($key) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>">
                    <?= e($fabric['label']) ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>

        <div class="card card--static ab-fabric reveal" id="fabric-panel" aria-live="polite"></div>
    </div>
</section>

<!-- ================= СЕРВИС ================= -->
<section class="section" id="service">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Сервисный участок</h2>
            <span class="muted small">Швейная, 12 · Пн–Пт 10:00–18:00</span>
        </div>

        <div class="ab-service">
            <div class="reveal">
                <div class="table-wrap">
                    <table class="table">
                        <caption class="visually-hidden">Услуги сервисного участка и сроки</caption>
                        <thead>
                        <tr><th>Работа</th><th>Стоимость</th><th>Срок</th><th>Условия</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($service as $row): ?>
                            <tr>
                                <td><b><?= e($row['name']) ?></b></td>
                                <td><?= $row['price'] > 0 ? 'от ' . e(format_price($row['price'])) : '—' ?></td>
                                <td><?= e($row['time']) ?></td>
                                <td class="muted"><?= e($row['note']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="muted small" style="margin-top: 12px;">Цены учебного проекта, приближены к рыночным. Гарантия на выполненные работы — 6 месяцев.</p>
            </div>

            <div class="card card--static ab-srv-note reveal">
                <h3 class="ab-srv-note__title">Что важно знать</h3>
                <div class="ab-srv-facts">
                    <div class="ab-srv-fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 11.8l2.2 2.2 4-4.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><b>Диагностика бесплатно</b>, если ремонт остаётся у нас.</span>
                    </div>
                    <div class="ab-srv-fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M8 4h8v16H8z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8 9h8M8 15h8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        <span><b>Расходники на складе:</b> иглы, шпульки, лапки, пяльцы ходовых линеек.</span>
                    </div>
                    <div class="ab-srv-fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><b>Срочный ремонт</b> — в день обращения, если узел в наличии.</span>
                    </div>
                    <div class="ab-srv-fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.7" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="18" r="1.7" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                        <span><b>Выезд к производству</b> для промышленных машин по записи.</span>
                    </div>
                </div>
                <a class="btn btn--primary btn--block" href="feedback.php">Записаться в сервис</a>
                <div class="tape" style="margin-top: 16px;" aria-hidden="true"></div>
            </div>
        </div>

        <div class="grid grid--2" style="margin-top: 22px;" data-stagger>
            <details class="card card--static accordion reveal">
                <summary>Что взять с собой в сервис</summary>
                <div class="accordion__body">
                    <p>Машину, педаль, шнур питания и пару образцов ткани, на которых виден дефект строчки. Если проблема плавающая — снимите короткое видео: это экономит день диагностики.</p>
                </div>
            </details>
            <details class="card card--static accordion reveal">
                <summary>Чините ли вы машины не из нашего каталога</summary>
                <div class="accordion__body">
                    <p>Да. Берём японские бытовые и промышленные машины любых лет, включая восстановленные. Отказываем только если узел снят с производства и аналогов нет — тогда честно предлагаем замену.</p>
                </div>
            </details>
        </div>
    </div>
</section>

<!-- ================= КОМАНДА ================= -->
<section class="section section--linen" id="team">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Команда</h2>
            <span class="muted small">Шесть человек, которые отвечают за подбор и сервис</span>
        </div>
        <div class="ab-team" data-stagger>
            <?php foreach ($team as $person): ?>
                <article class="card ab-person reveal">
                    <div class="ab-person__top">
                        <span class="avatar ab-person__avatar avatar--initials" aria-hidden="true"><?= e(user_initials($person['name'])) ?></span>
                        <span>
                            <span class="ab-person__name"><?= e($person['name']) ?></span>
                            <span class="ab-person__role"><?= e($person['role']) ?></span>
                        </span>
                    </div>
                    <span class="ab-person__quote">«<?= e($person['quote']) ?>»</span>
                    <div class="ab-person__tags">
                        <?php foreach ($person['tags'] as $tag): ?>
                            <span class="ab-tag"><?= e($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="muted small reveal" style="margin-top: 16px;">Учебный курсовой проект: имена и роли выдуманы, совпадения случайны.</p>
    </div>
</section>

<!-- ================= ПРИЗЫВ ================= -->
<section class="ab-cta">
    <div class="tape tape--light" aria-hidden="true"></div>
    <div class="container">
        <div class="ab-cta__inner">
            <div class="reveal">
                <span class="label" style="color: var(--wine-300);">Дальше</span>
                <h2 class="ab-cta__title">Приносите ткань — подберём машину при вас</h2>
                <p class="ab-cta__text">Тестовый стол в шоуруме работает без записи в часы работы. Или начните с каталога: фильтры по типу, бренду и цене сузят выбор до трёх моделей.</p>
            </div>
            <div class="ab-cta__actions reveal">
                <a class="btn btn--primary btn--lg" href="catalog.php">Открыть каталог</a>
                <a class="btn btn--ghost btn--lg" href="feedback.php">Задать вопрос</a>
                <span class="ab-cta__note">Шоурум: Москва, ул. Швейная, 12 · Пн–Сб 10:00–20:00</span>
            </div>
        </div>
    </div>
</section>

<script id="about-config" type="application/json"><?= json_encode($aboutConfig, $jsonFlags) ?></script>

<script>
// «О нас»: лента истории, вкладки принципов, подбор по ткани, счётчики
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('about-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var esc = J.escapeHtml;
    var money = J.formatPrice;

    /* ---------- Лента истории: активный год по скроллу ---------- */
    var years = Array.prototype.slice.call(document.querySelectorAll('.ab-year'));
    var miles = Array.prototype.slice.call(document.querySelectorAll('.ab-mile'));

    function setActiveYear(index) {
        years.forEach(function (btn, i) { btn.classList.toggle('is-active', i === index); });
        miles.forEach(function (mile, i) { mile.classList.toggle('is-active', i === index); });
    }

    years.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.dataset.yearTarget);
            if (target) { target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' }); }
        });
    });

    if ('IntersectionObserver' in window && miles.length) {
        var mileObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { setActiveYear(parseInt(entry.target.dataset.mile, 10) || 0); }
            });
        }, { threshold: 0.55, rootMargin: '-20% 0px -35% 0px' });
        miles.forEach(function (mile) { mileObserver.observe(mile); });
    }

    /* ---------- Вкладки принципов ---------- */
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.ab-tab'));
    function activatePrinciple(key) {
        tabs.forEach(function (tab) {
            var active = tab.dataset.principle === key;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            var panel = document.getElementById('ppanel-' + tab.dataset.principle);
            if (panel) { panel.hidden = !active; }
        });
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { activatePrinciple(tab.dataset.principle); });
        tab.addEventListener('keydown', function (e) {
            var dir = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 0);
            if (!dir) { return; }
            e.preventDefault();
            var next = tabs[(index + dir + tabs.length) % tabs.length];
            next.focus();
            activatePrinciple(next.dataset.principle);
        });
    });

    /* ---------- Подбор по ткани ---------- */
    var chips = Array.prototype.slice.call(document.querySelectorAll('[data-fabric]'));
    var fabricPanel = document.getElementById('fabric-panel');

    function renderFabric(key) {
        var fabric = cfg.fabrics[key];
        if (!fabric || !fabricPanel) { return; }

        var tips = fabric.tips.map(function (tip) {
            return '<li class="ab-tip"><span>' + esc(tip) + '</span></li>';
        }).join('');

        var picks = fabric.items.map(function (item, i) {
            var stock = item.stock > 0 ? 'в наличии: ' + item.stock + ' шт.' : 'под заказ';
            return '<a class="ab-pick" href="' + esc(item.url) + '" style="--i:' + i + '">' +
                '<span class="ab-pick__media"><img src="' + esc(item.image) + '" alt="' + esc(item.name) + '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + esc(fallbackImage()) + '\'"></span>' +
                '<span><span class="ab-pick__brand">' + esc(item.brand) + '</span>' +
                    '<span class="ab-pick__name">' + esc(item.name) + '</span>' +
                    '<span class="ab-pick__model">' + esc(item.model) + '</span></span>' +
                '<span><span class="ab-pick__price">' + esc(money(item.price)) + '</span>' +
                    '<span class="ab-pick__stock">' + esc(stock) + '</span></span>' +
            '</a>';
        }).join('');

        fabricPanel.innerHTML =
            '<div><span class="label">' + esc(fabric.label) + '</span>' +
                '<h3 class="ab-fabric__title">' + esc(fabric.title) + '</h3>' +
                '<p class="ab-fabric__text">' + esc(fabric.text) + '</p>' +
                '<ul class="ab-tips">' + tips + '</ul></div>' +
            '<div><span class="label">Из наличия</span><div class="ab-picks">' + picks + '</div>' +
                '<a class="btn btn--ghost btn--sm btn--block" style="margin-top:12px;" href="' + esc(cfg.catalogUrl) + '">Сравнить в каталоге</a></div>';

        chips.forEach(function (chip) {
            var active = chip.dataset.fabric === key;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    function fallbackImage() {
        return 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/></svg>');
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () { renderFabric(chip.dataset.fabric); });
    });
    if (chips.length) { renderFabric(chips[0].dataset.fabric); }

    /* ---------- Счётчики из БД ---------- */
    function animateCount(el) {
        var target = parseInt(el.dataset.countTo, 10) || 0;
        if (reduced || target === 0) { el.textContent = target.toLocaleString('ru-RU'); return; }
        var start = null;
        var duration = 950;
        function step(ts) {
            if (start === null) { start = ts; }
            var p = Math.min((ts - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased).toLocaleString('ru-RU');
            if (p < 1) { requestAnimationFrame(step); }
        }
        requestAnimationFrame(step);
    }
    var counters = document.querySelectorAll('[data-count-to]');
    if ('IntersectionObserver' in window) {
        var countObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { animateCount(entry.target); countObserver.unobserve(entry.target); }
            });
        }, { threshold: 0.4 });
        counters.forEach(function (el) { countObserver.observe(el); });
    } else {
        counters.forEach(animateCount);
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
});
</script>

<?php render_footer(); ?>
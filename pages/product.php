<?php
/**
 * pages/product.php — карточка товара «Стежок».
 * Фото с лупой и лайтбоксом, характеристики из specs JSON, цена и старая цена,
 * наличие, количество, «В корзину», сердечко, похожие товары.
 * Гостя обе кнопки отправляют на вход с возвратом на эту страницу.
 */

require_once __DIR__ . '/../config/helpers.php';

// Тип машинки — тот же вывод, что в каталоге
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
    'other' => 'Прочее',
];

$slug = trim((string)($_GET['slug'] ?? ''));
$id = (int)($_GET['id'] ?? 0);

$selectSql = 'SELECT p.id, p.brand_id, p.category_id, p.name, p.model, p.slug, p.description,
                     p.specs, p.price, p.old_price, p.stock, p.image_path, p.created_at,
                     b.name AS brand_name, b.slug AS brand_slug,
                     c.name AS category_name, c.slug AS category_slug,
                     (' . $typeExpr . ') AS machine_type,
                     (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id) AS sold
              FROM products p
              JOIN brands b ON b.id = p.brand_id
              JOIN categories c ON c.id = p.category_id';

$product = null;
if ($slug !== '') {
    $stmt = $pdo->prepare($selectSql . ' WHERE p.slug = ? AND p.is_active = 1 LIMIT 1');
    $stmt->execute([$slug]);
    $product = $stmt->fetch();
} elseif ($id > 0) {
    $stmt = $pdo->prepare($selectSql . ' WHERE p.id = ? AND p.is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $product = $stmt->fetch();
}

// Товар не найден — честная 404 внутри дизайна
if (!$product) {
    http_response_code(404);
    render_header('Товар не найден', ['description' => 'Такого товара нет в каталоге Janome.']);
    ?>
    <section class="section">
        <div class="container container--narrow">
            <div class="card card--static empty-state">
                <h1 class="empty-state__title">Такой машинки у нас нет</h1>
                <p class="empty-state__text">Возможно, товар снят с продажи или ссылка устарела. Загляните в каталог — там все актуальные модели.</p>
                <a class="btn btn--primary" href="catalog.php">Вернуться в каталог</a>
            </div>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

$user = current_user();
$loginUrl = login_url();
$productId = (int)$product['id'];

// Избранное и текущее количество в корзине
$isFavorite = false;
$inCart = 0;
if ($user) {
    $stmt = $pdo->prepare('SELECT id FROM favorites WHERE user_id = ? AND product_id = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $productId]);
    $isFavorite = (bool)$stmt->fetch();

    $stmt = $pdo->prepare('SELECT quantity FROM cart_items WHERE user_id = ? AND product_id = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $productId]);
    $inCart = (int)$stmt->fetchColumn();
}

// Похожие: та же категория, затем тот же бренд, затем близкая цена
$favJoin = '';
$favSelect = '0';
$favParams = [];
if ($user) {
    $favJoin = 'LEFT JOIN favorites f ON f.product_id = p.id AND f.user_id = ?';
    $favSelect = '(f.id IS NOT NULL)';
    $favParams[] = (int)$user['id'];
}

$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.model, p.slug, p.price, p.old_price, p.stock, p.image_path, p.specs,
            b.name AS brand_name, c.name AS category_name,
            (' . $typeExpr . ') AS machine_type,
            (' . $favSelect . ') AS is_favorite
     FROM products p
     JOIN brands b ON b.id = p.brand_id
     JOIN categories c ON c.id = p.category_id ' . $favJoin . '
     WHERE p.is_active = 1 AND p.id <> ?
     ORDER BY (p.category_id = ?) DESC, (p.brand_id = ?) DESC, is_favorite DESC,
              ABS(p.price - ?) ASC, p.id ASC
     LIMIT 4'
);
$stmt->execute(array_merge($favParams, [
    $productId,
    (int)$product['category_id'],
    (int)$product['brand_id'],
    (float)$product['price'],
]));
$similar = $stmt->fetchAll();

/* ---------- Производные значения ---------- */
$specs = json_decode((string)$product['specs'], true) ?: [];
$price = (float)$product['price'];
$oldPrice = $product['old_price'] !== null ? (float)$product['old_price'] : null;
$stock = (int)$product['stock'];
$sold = (int)$product['sold'];
$typeLabel = $typeLabels[$product['machine_type']] ?? 'Прочее';

$discount = 0;
$save = 0.0;
if ($oldPrice !== null && $oldPrice > $price) {
    $discount = (int)round(($oldPrice - $price) / $oldPrice * 100);
    $save = $oldPrice - $price;
}

if ($stock <= 0) {
    $stockClass = 'out';
    $stockText = 'Нет в наличии';
} elseif ($stock <= 3) {
    $stockClass = 'low';
    $stockText = 'Осталось ' . $stock . ' шт.';
} else {
    $stockClass = 'in';
    $stockText = 'В наличии: ' . $stock . ' шт.';
}

// Значение характеристики в читаемый вид
$specValue = static function ($value): string {
    if (is_bool($value)) {
        return $value ? 'Да' : 'Нет';
    }
    if (is_int($value)) {
        return number_format((float)$value, 0, ',', ' ');
    }
    if (is_float($value)) {
        return floor($value) === $value
            ? number_format($value, 0, ',', ' ')
            : number_format($value, 1, ',', ' ');
    }
    return (string)$value;
};

// Иконка строки характеристики по ключевому слову
$specIcon = static function (string $key): string {
    $k = mb_strtolower($key);
    $path = '<circle cx="12" cy="12" r="3.2"/>';
    if (str_contains($k, 'скорость')) {
        $path = '<path d="M4 17a8 8 0 1 1 16 0"/><path d="M12 17l4.5-5"/>';
    } elseif (str_contains($k, 'операци') || str_contains($k, 'строчек') || str_contains($k, 'программ')) {
        $path = '<path d="M5 7h14M5 12h14M5 17h9"/>';
    } elseif (str_contains($k, 'петля')) {
        $path = '<ellipse cx="12" cy="12" rx="4" ry="7"/><path d="M12 5v14"/>';
    } elseif (str_contains($k, 'челнок')) {
        $path = '<circle cx="12" cy="12" r="7.5"/><circle cx="12" cy="12" r="2.6"/>';
    } elseif (str_contains($k, 'ните') || str_contains($k, 'заправк')) {
        $path = '<path d="M8 4h8v16H8z"/><path d="M8 9h8M8 15h8"/>';
    } elseif (str_contains($k, 'поле вышивки')) {
        $path = '<rect x="4.5" y="6.5" width="15" height="11" rx="1.5"/><path d="M8 10h8M8 14h5"/>';
    } elseif (str_contains($k, 'дизайн') || str_contains($k, 'шрифт')) {
        $path = '<path d="M12 4l2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5L4.8 9.3l5-.7z"/>';
    } elseif (str_contains($k, 'длина стежка')) {
        $path = '<path d="M4 12h16"/><path d="M7 9v6M12 9v6M17 9v6"/>';
    } elseif (str_contains($k, 'ширина')) {
        $path = '<path d="M4 12h16M7 9l-3 3 3 3M17 9l3 3-3 3"/>';
    } elseif (str_contains($k, 'дисплей') || str_contains($k, 'экран')) {
        $path = '<rect x="3.5" y="5.5" width="17" height="11" rx="1.5"/><path d="M9 20h6"/>';
    } elseif (str_contains($k, 'wi-fi') || str_contains($k, 'usb') || str_contains($k, 'формат')) {
        $path = '<path d="M5 11a10 10 0 0 1 14 0M8 14.5a6 6 0 0 1 8 0"/><circle cx="12" cy="18" r="1.2"/>';
    } elseif (str_contains($k, 'подача') || str_contains($k, 'транспортёр') || str_contains($k, 'дифференциал')) {
        $path = '<path d="M4 9h12M4 15h12"/><path d="M16 6l4 3-4 3M16 12l4 3-4 3"/>';
    } elseif (str_contains($k, 'привод') || str_contains($k, 'мотор')) {
        $path = '<path d="M13 3 5 14h6l-1 7 8-11h-6z"/>';
    } elseif (str_contains($k, 'смазк')) {
        $path = '<path d="M12 4s5.5 6 5.5 9.5a5.5 5.5 0 0 1-11 0C6.5 10 12 4 12 4z"/>';
    } elseif (str_contains($k, 'обрезк')) {
        $path = '<circle cx="7" cy="17" r="2.4"/><circle cx="17" cy="17" r="2.4"/><path d="M8.6 15.2 18 5M15.4 15.2 6 5"/>';
    } elseif (str_contains($k, 'игл')) {
        $path = '<path d="M12 3v13M12 16v4"/><ellipse cx="12" cy="6.5" rx="1.8" ry="3"/>';
    } elseif (str_contains($k, 'тип') || str_contains($k, 'управление') || str_contains($k, 'назначение')) {
        $path = '<rect x="6.5" y="6.5" width="11" height="11" rx="2"/><path d="M10 3.5v3M14 3.5v3M10 17.5v3M14 17.5v3M3.5 10h3M3.5 14h3M17.5 10h3M17.5 14h3"/>';
    } elseif (str_contains($k, 'подсветк')) {
        $path = '<path d="M9 17h6M10 20h4"/><path d="M12 3a6 6 0 0 1 3.5 10.9V17h-7v-3.1A6 6 0 0 1 12 3z"/>';
    } elseif (str_contains($k, 'коленоподъёмник') || str_contains($k, 'нитевдеватель') || str_contains($k, 'кнопка')) {
        $path = '<path d="M5 12.5l4.5 4.5L19 7.5"/>';
    } elseif (str_contains($k, 'слоёв') || str_contains($k, 'пялец')) {
        $path = '<path d="M4 8l8-4 8 4-8 4z"/><path d="M4 12l8 4 8-4M4 16l8 4 8-4"/>';
    }
    return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
};

// Ключевые метрики для плиток над таблицей
$highlightKeys = [
    ['Количество операций', 'Операций'],
    ['Швейных операций', 'Операций'],
    ['Скорость шитья (ст/мин)', 'Скорость, ст/мин'],
    ['Скорость (ст/мин)', 'Скорость, ст/мин'],
    ['Скорость вышивки (ст/мин)', 'Вышивка, ст/мин'],
    ['Макс. поле вышивки (мм)', 'Поле вышивки, мм'],
    ['Нитей', 'Нитей'],
    ['Встроенных дизайнов', 'Дизайнов'],
    ['Дизайнов вышивки', 'Дизайнов'],
    ['Макс. ширина стежка (мм)', 'Ширина стежка, мм'],
    ['Макс. длина стежка (мм)', 'Длина стежка, мм'],
];
$highlights = [];
foreach ($highlightKeys as $rule) {
    if (count($highlights) >= 4) { break; }
    if (!array_key_exists($rule[0], $specs)) { continue; }
    $label = $rule[1];
    $already = false;
    foreach ($highlights as $h) {
        if ($h['label'] === $label) { $already = true; break; }
    }
    if ($already) { continue; }
    $highlights[] = ['label' => $label, 'value' => $specValue($specs[$rule[0]]), 'key' => $rule[0]];
}

// Короткие тезисы из характеристик для блока описания
$descPoints = [];
foreach ($highlightKeys as $rule) {
    if (count($descPoints) >= 3) { break; }
    if (!array_key_exists($rule[0], $specs)) { continue; }
    $descPoints[] = $rule[1] . ' — ' . $specValue($specs[$rule[0]]);
}

$fallbackImg = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/><text x="400" y="420" text-anchor="middle" font-family="Georgia,serif" font-size="30" fill="#6B5558">Фото готовится</text></svg>'
);

$productConfig = [
    'isAuth' => (bool)$user,
    'loginUrl' => $loginUrl,
    'product' => [
        'id' => $productId,
        'name' => $product['name'],
        'price' => $price,
        'stock' => $stock,
        'inCart' => $inCart,
        'favorite' => $isFavorite,
        'image' => '../' . $product['image_path'],
    ],
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$availability = $stock <= 0 ? 'OutOfStock' : ($stock <= 3 ? 'LimitedAvailability' : 'InStock');
$ldJson = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product['name'],
    'model' => $product['model'],
    'description' => $product['description'],
    'brand' => ['@type' => 'Brand', 'name' => $product['brand_name']],
    'category' => $product['category_name'],
    'offers' => [
        '@type' => 'Offer',
        'price' => number_format($price, 2, '.', ''),
        'priceCurrency' => 'RUB',
        'availability' => 'https://schema.org/' . $availability,
    ],
];

render_header($product['name'], [
    'description' => mb_strimwidth((string)$product['description'], 0, 160, '…', 'UTF-8'),
    'body_class' => 'page-product',
]);
?>

<style>
/* ============================================================
   Карточка товара «Стежок»: галерея с лупой, липкая панель
   покупки, техпаспорт характеристик, похожие товары.
   ============================================================ */

.thread-progress {
    position: fixed; top: 0; left: 0; z-index: 60; height: 3px; width: 100%;
    transform: scaleX(0); transform-origin: left;
    background: linear-gradient(90deg, var(--wine-900), var(--wine-500) 45%, var(--wine-300));
}

/* ---------- Хлебные крошки ---------- */
.crumbs { padding: 18px 0 6px; }
.crumbs__list { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; list-style: none; margin: 0; padding: 0; font-size: 12.5px; }
.crumbs__list a { color: var(--muted); text-decoration: none; transition: color .2s; }
.crumbs__list a:hover { color: var(--wine-700); }
.crumbs__sep { color: var(--line); }
.crumbs__current { color: var(--wine-700); font-weight: 600; }

/* ---------- Раскладка ---------- */
.product-top { padding: 10px 0 46px; position: relative; overflow: hidden;
    background: linear-gradient(120deg, rgba(176, 27, 46, .055) 0%, rgba(212, 42, 63, .028) 38%, rgba(255, 255, 255, 0) 70%); }
.product-layout { display: grid; grid-template-columns: 1.12fr .88fr; gap: 40px; align-items: start; }

/* ---------- Галерея ---------- */
.gallery { position: relative; }
.gallery__bobbin {
    position: absolute; top: -38px; left: -34px; width: 140px; height: 140px; z-index: 0; opacity: .45;
    animation: bobbin-spin 38s linear infinite; transform-origin: 50% 50%; pointer-events: none;
}
@keyframes bobbin-spin { to { transform: rotate(360deg); } }
.gallery__frame { position: relative; z-index: 1; padding: 14px; }
.gallery__media {
    position: relative; aspect-ratio: 4 / 3; border-radius: var(--radius-sm);
    overflow: hidden; background: var(--linen); cursor: zoom-in;
}
.gallery__img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s cubic-bezier(.22,.61,.36,1); }
.gallery__media:hover .gallery__img { transform: scale(1.03); }
.gallery__media::before {
    content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 8px; z-index: 3;
    background: repeating-linear-gradient(45deg, rgba(176, 27, 46, .55) 0 4px, transparent 4px 8px);
}
.gallery__media::after {
    content: ""; position: absolute; inset: 8px; z-index: 3; pointer-events: none;
    border: 2px dashed rgba(255, 255, 255, .8); border-radius: var(--radius-sm);
    opacity: 0; transition: opacity .3s;
}
.gallery__media:hover::after { opacity: 1; }
.gallery__brand {
    position: absolute; top: 11px; left: 11px; z-index: 4; padding: 5px 11px;
    background: var(--wine-900); color: var(--white); border-radius: var(--radius-sm);
    font: 700 10px/1.4 var(--font-sans); letter-spacing: .14em; text-transform: uppercase;
}
.gallery__type {
    position: absolute; bottom: 14px; left: 11px; z-index: 4; padding: 5px 11px;
    background: rgba(255, 255, 255, .94); color: var(--wine-700); border-radius: var(--radius-sm);
    font: 600 11px/1.4 var(--font-sans);
}
.gallery__sale {
    position: absolute; bottom: 14px; right: 11px; z-index: 4; padding: 6px 12px;
    background: var(--wine-500); color: var(--white); border-radius: var(--radius-sm);
    font: 700 13px/1.2 var(--font-sans); transform: rotate(-3deg); box-shadow: var(--shadow-sm);
    transition: transform .3s;
}
.gallery__media:hover .gallery__sale { transform: rotate(0deg) scale(1.06); }
.gallery__fav {
    position: absolute; top: 10px; right: 10px; z-index: 5; width: 42px; height: 42px;
    border-radius: 50%; background: rgba(255, 255, 255, .95); border: 1px solid var(--line);
    color: var(--wine-700); display: grid; place-items: center; cursor: pointer; text-decoration: none;
    transition: transform .18s, border-color .2s, color .2s;
}
.gallery__fav::before { content: ""; position: absolute; inset: 4px; border: 1px dashed rgba(176, 27, 46, .3); border-radius: 50%; }
.gallery__fav:hover { border-color: var(--wine-300); transform: scale(1.08); }
.gallery__fav:active { transform: scale(.9); }
.gallery__fav.is-active { color: var(--wine-500); border-color: var(--wine-500); }
.gallery__fav.is-active svg path { fill: currentColor; }
.gallery__fav.is-burst::after {
    content: ""; position: absolute; inset: -5px; border-radius: 50%;
    border: 2px solid var(--wine-300); animation: fav-burst .55s ease-out forwards; pointer-events: none;
}
@keyframes fav-burst { to { transform: scale(1.7); opacity: 0; } }
.gallery__fav.is-busy { opacity: .6; pointer-events: none; }

/* Лупа */
.lens {
    position: absolute; z-index: 6; width: 156px; height: 156px; border-radius: 50%;
    border: 2px solid var(--wine-500); box-shadow: 0 10px 26px rgba(78, 11, 21, .25);
    background-repeat: no-repeat; background-color: var(--white);
    pointer-events: none; opacity: 0; transition: opacity .18s; display: none;
}
.lens.is-on { opacity: 1; }
.gallery__hint {
    display: flex; align-items: center; gap: 7px; margin-top: 12px;
    font: 500 12px/1.4 var(--font-sans); color: var(--muted);
}
.gallery__tools { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
.gallery__tape { margin-top: 16px; }

/* ---------- Панель покупки ---------- */
.buy { position: sticky; top: calc(var(--header-h) + 20px); }
.buy__inner { padding: 24px 22px; }
.buy__chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.buy__chip {
    padding: 5px 11px; background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .3);
    border-radius: 999px; text-decoration: none; color: var(--wine-700);
    font: 600 11.5px/1.4 var(--font-sans); transition: background-color .2s, color .2s, transform .2s;
}
.buy__chip:hover { background: var(--wine-500); color: var(--white); transform: translateY(-2px); }
.buy__title { font-size: clamp(23px, 2.6vw, 31px); margin-bottom: 10px; }
.buy__model {
    display: inline-block; margin-bottom: 16px; padding: 4px 11px;
    background: var(--linen); border: 1px solid var(--line); border-radius: var(--radius-sm);
    font: 600 11px/1.5 var(--font-sans); letter-spacing: .12em; text-transform: uppercase; color: var(--muted);
}
.buy__price {
    display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap;
    padding: 16px 18px; margin-bottom: 14px;
    background: var(--wine-050); border: 1px dashed rgba(176, 27, 46, .3); border-radius: var(--radius);
}
.buy__price-now { font: 700 33px/1.1 var(--font-serif); color: var(--wine-700); }
.buy__price-old { font: 400 16px/1 var(--font-sans); color: var(--muted); text-decoration: line-through; }
.buy__save { margin-left: auto; padding: 5px 11px; background: var(--wine-500); color: var(--white); border-radius: var(--radius-sm); font: 700 11.5px/1.4 var(--font-sans); }
.buy__stock-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.buy__sold { font: 600 11.5px/1 var(--font-sans); color: var(--success); }

.buy__qty-label { font: 700 11.5px/1 var(--font-sans); letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 9px; display: block; }
.qty { display: flex; align-items: stretch; gap: 0; width: fit-content; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; background: var(--white); }
.qty__btn {
    width: 44px; border: 0; background: var(--linen); color: var(--wine-700);
    font: 400 21px/1 var(--font-serif); cursor: pointer; transition: background-color .2s, color .2s;
}
.qty__btn:hover:not(:disabled) { background: var(--wine-500); color: var(--white); }
.qty__btn:active:not(:disabled) { transform: scale(.94); }
.qty__btn:disabled { opacity: .4; cursor: not-allowed; }
.qty__input {
    width: 68px; border: 0; border-left: 1px solid var(--line); border-right: 1px solid var(--line);
    text-align: center; font: 700 17px/1 var(--font-serif); color: var(--wine-900); padding: 12px 4px;
    /*-moz-appearance: textfield;*/
}
.qty__input::-webkit-outer-spin-button, .qty__input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.qty__input:focus { outline: none; background: var(--wine-050); }
.qty__max { align-self: center; margin-left: 12px; font-size: 12.5px; color: var(--muted); }
.qty-row { display: flex; align-items: center; flex-wrap: wrap; }
.qty-tape { margin-top: 10px; max-width: 220px; }
.buy__total { display: flex; align-items: baseline; gap: 9px; margin: 16px 0 14px; font-size: 13.5px; color: var(--muted); }
.buy__total strong { font: 700 23px/1 var(--font-serif); color: var(--wine-900); }
.buy__actions { display: flex; gap: 10px; }
.buy__cart { flex: 1; }
.buy__cart.is-added { background: var(--success); }
.buy__fav-btn { width: 50px; flex: none; padding-inline: 0; }
.buy__fav-btn.is-active { color: var(--wine-500); border-color: var(--wine-500); background: var(--wine-050); }
.buy__fav-btn.is-active svg path { fill: currentColor; }
.buy__incart { margin-top: 14px; display: none; }
.buy__incart.is-visible { display: block; animation: chip-in .3s ease both; }
@keyframes chip-in { from { opacity: 0; transform: translateY(-6px); } }
.buy__facts { margin-top: 18px; padding-top: 16px; border-top: 2px dashed var(--line); display: grid; gap: 11px; }
.buy__fact { display: flex; align-items: flex-start; gap: 10px; font-size: 13px; color: var(--muted); }
.buy__fact svg { color: var(--wine-500); flex: none; margin-top: 1px; }
.buy__fact b { color: var(--ink); font-weight: 600; }

/* ---------- Техпаспорт ---------- */
.spec-highlights { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 26px; }
.spec-high { padding: 16px 18px; position: relative; }
.spec-high__value { font: 700 25px/1.15 var(--font-serif); color: var(--wine-700); display: block; margin-bottom: 4px; }
.spec-high__label { font: 500 11.5px/1.35 var(--font-sans); color: var(--muted); letter-spacing: .04em; }
.spec-high__icon { position: absolute; top: 14px; right: 14px; color: var(--line); }
.specs-head { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.specs-table td:first-child { width: 46px; color: var(--wine-500); }
.specs-table th.specs-key { width: 46%; font: 500 13.5px/1.4 var(--font-sans); text-transform: none; letter-spacing: 0; color: var(--muted); background: transparent; border-bottom: 1px solid var(--line); }
.specs-table td.specs-val { font-weight: 600; color: var(--ink); }
.specs-table tr:hover td.specs-val { color: var(--wine-700); }
.specs-bool { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; border-radius: var(--radius-sm); font: 700 10.5px/1.4 var(--font-sans); letter-spacing: .06em; text-transform: uppercase; }
.specs-bool--yes { background: var(--success-bg); color: var(--success); }
.specs-bool--no { background: var(--linen); color: var(--muted); }

/* ---------- Описание ---------- */
.desc-layout { display: grid; grid-template-columns: 1.15fr .85fr; gap: 40px; align-items: start; }
.desc-text { font-size: 15.5px; color: var(--ink); }
.desc-points { list-style: none; margin: 20px 0 0; padding: 0; display: grid; gap: 10px; }
.desc-points li { display: flex; align-items: flex-start; gap: 11px; font-size: 14px; color: var(--muted); }
.desc-points li::before { content: ""; width: 9px; height: 9px; margin-top: 6px; flex: none; border-radius: 50%; border: 2px solid var(--wine-500); }
.desc-quote {
    margin-top: 22px; padding: 18px 20px; background: var(--wine-900); color: rgba(255, 255, 255, .85);
    border-radius: var(--radius); font: italic 400 15px/1.6 var(--font-serif); position: relative;
}
.desc-quote::before { content: ""; position: absolute; inset: 6px; border: 1px dashed rgba(255, 255, 255, .22); border-radius: var(--radius-sm); pointer-events: none; }

/* ---------- Похожие ---------- */
.similar-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
.scard { display: flex; flex-direction: column; padding: 12px; animation: scard-in .5s cubic-bezier(.22,.61,.36,1) both; animation-delay: calc(var(--i, 0) * 70ms); }
@keyframes scard-in { from { opacity: 0; transform: translateY(20px); } }
.scard__media { position: relative; aspect-ratio: 4 / 3; border-radius: var(--radius-sm); overflow: hidden; background: var(--linen); margin-bottom: 11px; }
.scard__media img { width: 100%; height: 100%; object-fit: cover; transition: transform .45s ease; }
.scard:hover .scard__media img { transform: scale(1.06); }
.scard__media::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 6px; background: repeating-linear-gradient(45deg, rgba(176, 27, 46, .5) 0 4px, transparent 4px 8px); }
.scard__fav {
    position: absolute; top: 8px; right: 8px; z-index: 3; width: 32px; height: 32px; border-radius: 50%;
    background: rgba(255, 255, 255, .95); border: 1px solid var(--line); color: var(--wine-700);
    display: grid; place-items: center; cursor: pointer; text-decoration: none; transition: transform .18s, color .2s, border-color .2s;
}
.scard__fav:hover { transform: scale(1.1); border-color: var(--wine-300); }
.scard__fav.is-active { color: var(--wine-500); border-color: var(--wine-500); }
.scard__fav.is-active svg path { fill: currentColor; }
.scard__fav.is-burst::after { content: ""; position: absolute; inset: -4px; border-radius: 50%; border: 2px solid var(--wine-300); animation: fav-burst .55s ease-out forwards; }
.scard__fav.is-busy { opacity: .6; pointer-events: none; }
.scard__brand { font: 600 10px/1 var(--font-sans); letter-spacing: .14em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.scard__name { font: 700 15px/1.35 var(--font-serif); margin: 0 0 8px; }
.scard__name a { color: var(--wine-900); text-decoration: none; }
.scard__name a:hover { color: var(--wine-700); }
.scard__price { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 9px; }
.scard__price b { font: 700 18px/1 var(--font-serif); color: var(--wine-700); }
.scard__price s { font-size: 12.5px; color: var(--muted); }
.scard__foot { margin-top: auto; display: grid; gap: 9px; }
.scard__add.is-added { background: var(--success); }

/* ---------- Липкая мобильная панель ---------- */
.mbuy {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 70; display: none;
    align-items: center; gap: 12px; padding: 11px 16px;
    background: rgba(255, 255, 255, .98); border-top: 2px dashed var(--line);
    box-shadow: 0 -8px 24px rgba(78, 11, 21, .12);
    transform: translateY(110%); transition: transform .3s cubic-bezier(.22,.61,.36,1);
}
.mbuy.is-visible { transform: none; }
.mbuy__price { font: 700 20px/1 var(--font-serif); color: var(--wine-700); white-space: nowrap; }
.mbuy__btn { flex: 1; }
.mbuy__btn.is-added { background: var(--success); }

/* ---------- Лайтбокс ---------- */
.lightbox {
    position: fixed; inset: 0; z-index: 200; display: none;
    background: rgba(42, 16, 19, .92); padding: 24px;
    align-items: center; justify-content: center;
}
.lightbox.is-open { display: flex; animation: lb-in .25s ease; }
@keyframes lb-in { from { opacity: 0; } }
.lightbox__stage { position: relative; max-width: min(1100px, 92vw); max-height: 82vh; overflow: hidden; border-radius: var(--radius); background: var(--white); box-shadow: 0 24px 60px rgba(0, 0, 0, .4); }
.lightbox__img { display: block; max-width: 100%; max-height: 82vh; transform-origin: center; transition: transform .18s ease; cursor: grab; }
.lightbox__img.is-drag { cursor: grabbing; transition: none; }
.lightbox__bar {
    position: absolute; left: 50%; bottom: 18px; transform: translateX(-50%);
    display: flex; gap: 8px; padding: 8px; background: rgba(255, 255, 255, .95);
    border: 1px solid var(--line); border-radius: 999px; box-shadow: var(--shadow);
}
.lightbox__bar .icon-btn { width: 38px; height: 38px; border-radius: 50%; }
.lightbox__close { position: absolute; top: 18px; right: 18px; width: 44px; height: 44px; border-radius: 50%; }
.lightbox__caption { position: absolute; top: 20px; left: 22px; color: rgba(255, 255, 255, .8); font: 600 13px/1.4 var(--font-sans); max-width: 60vw; }

/* ---------- Адаптив ---------- */
@media (max-width: 1180px) {
    .similar-grid { grid-template-columns: repeat(2, 1fr); }
    .spec-highlights { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1080px) {
    .product-layout { grid-template-columns: 1fr; gap: 28px; }
    .buy { position: static; }
    .desc-layout { grid-template-columns: 1fr; gap: 28px; }
    .gallery__bobbin { display: none; }
}
@media (max-width: 860px) {
    .mbuy { display: flex; }
    .page-product .site-footer { padding-bottom: 70px; }
}
@media (max-width: 620px) {
    .similar-grid { grid-template-columns: 1fr; }
    .spec-highlights { grid-template-columns: 1fr; }
    .buy__price-now { font-size: 28px; }
    .lightbox { padding: 12px; }
    .lightbox__caption { display: none; }
}
@media (prefers-reduced-motion: reduce) {
    .gallery__bobbin, .scard { animation: none; }
    .gallery__img, .scard__media img, .gallery__sale { transition: none; }
    .lens { display: none !important; }
}
</style>

<script type="application/ld+json"><?= json_encode($ldJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<!-- ================= ХЛЕБНЫЕ КРОШКИ ================= -->
<nav class="crumbs" aria-label="Навигация по разделам">
    <div class="container">
        <ol class="crumbs__list">
            <li><a href="index.php">Главная</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li><a href="catalog.php">Каталог</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li><a href="catalog.php?category=<?= e($product['category_slug']) ?>"><?= e($product['category_name']) ?></a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li class="crumbs__current" aria-current="page"><?= e($product['model']) ?></li>
        </ol>
    </div>
</nav>

<!-- ================= ФОТО + ПОКУПКА ================= -->
<section class="product-top">
    <div class="container product-layout">

        <!-- Галерея -->
        <div class="gallery reveal">
            <svg class="gallery__bobbin" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                <circle cx="50" cy="50" r="46" fill="none" stroke="#E8D5D8" stroke-width="2" stroke-dasharray="6 6"/>
                <circle cx="50" cy="50" r="33" fill="none" stroke="#D42A3F" stroke-width="2" opacity=".5"/>
                <circle cx="50" cy="50" r="11" fill="none" stroke="#7A1220" stroke-width="3"/>
            </svg>

            <div class="card card--static gallery__frame">
                <div class="gallery__media" id="gallery-media" role="button" tabindex="0"
                     aria-label="Открыть фото в полном размере">
                    <img class="gallery__img" id="gallery-img" src="../<?= e($product['image_path']) ?>"
                         alt="<?= e($product['name']) ?>" decoding="async"
                         onerror="this.onerror=null;this.src='<?= e($fallbackImg) ?>';">
                    <span class="gallery__brand"><?= e($product['brand_name']) ?></span>
                    <span class="gallery__type"><?= e($typeLabel) ?></span>
                    <?php if ($discount > 0): ?><span class="gallery__sale">−<?= $discount ?>%</span><?php endif; ?>
                    <div class="lens" id="lens" aria-hidden="true"></div>
                </div>

                <?php if ($user): ?>
                    <button class="gallery__fav<?= $isFavorite ? ' is-active' : '' ?>" id="main-fav" type="button"
                            data-fav="<?= $productId ?>" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                            aria-label="<?= $isFavorite ? 'Убрать из избранного' : 'В избранное' ?>"
                            title="<?= $isFavorite ? 'Убрать из избранного' : 'В избранное' ?>">
                        <svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    </button>
                <?php else: ?>
                    <a class="gallery__fav" href="<?= e($loginUrl) ?>" aria-label="Войдите, чтобы добавить в избранное"
                       title="Войдите, чтобы добавить в избранное">
                        <svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    </a>
                <?php endif; ?>

                <div class="gallery__hint">
                    <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5M9 11h4M11 9v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Наведите на фото — появится лупа. Клик — полный размер.
                </div>

                <div class="gallery__tools">
                    <button class="btn btn--ghost btn--sm" id="open-lightbox" type="button">
                        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 9V4h5M20 15v5h-5M15 4h5v5M9 20H4v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Полный размер
                    </button>
                    <button class="btn btn--ghost btn--sm" id="share-btn" type="button">
                        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="18" cy="5.5" r="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="6" cy="12" r="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="18" cy="18.5" r="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8.2 10.8l7.6-4M8.2 13.2l7.6 4" stroke="currentColor" stroke-width="1.8"/></svg>
                        Поделиться
                    </button>
                    <a class="btn btn--ghost btn--sm" href="catalog.php?brand=<?= e($product['brand_slug']) ?>">Все <?= e($product['brand_name']) ?></a>
                </div>

                <div class="tape gallery__tape" aria-hidden="true"></div>
            </div>
        </div>

        <!-- Панель покупки -->
        <aside class="buy reveal" id="buy-panel">
            <div class="card card--static buy__inner">
                <div class="buy__chips">
                    <a class="buy__chip" href="catalog.php?brand=<?= e($product['brand_slug']) ?>"><?= e($product['brand_name']) ?></a>
                    <a class="buy__chip" href="catalog.php?category=<?= e($product['category_slug']) ?>"><?= e($product['category_name']) ?></a>
                    <a class="buy__chip" href="catalog.php?type=<?= e($product['machine_type']) ?>"><?= e($typeLabel) ?></a>
                </div>

                <h1 class="buy__title"><?= e($product['name']) ?></h1>
                <span class="buy__model">Модель <?= e($product['model']) ?></span>

                <div class="buy__price">
                    <span class="buy__price-now"><?= e(format_price($price)) ?></span>
                    <?php if ($oldPrice !== null && $oldPrice > $price): ?>
                        <span class="buy__price-old"><?= e(format_price($oldPrice)) ?></span>
                        <span class="buy__save">выгода <?= e(format_price($save)) ?></span>
                    <?php endif; ?>
                </div>

                <div class="buy__stock-row">
                    <span class="stock stock--<?= e($stockClass) ?>"><?= e($stockText) ?></span>
                    <?php if ($sold > 0): ?><span class="buy__sold">заказано <?= $sold ?> шт.</span><?php endif; ?>
                </div>

                <?php if ($stock > 0): ?>
                    <span class="buy__qty-label">Количество</span>
                    <div class="qty-row">
                        <div class="qty">
                            <button class="qty__btn" id="qty-minus" type="button" aria-label="Уменьшить количество">−</button>
                            <label class="visually-hidden" for="qty-input">Количество</label>
                            <input class="qty__input" id="qty-input" type="number" value="1" min="1" max="<?= $stock ?>" step="1" inputmode="numeric">
                            <button class="qty__btn" id="qty-plus" type="button" aria-label="Увеличить количество">+</button>
                        </div>
                        <span class="qty__max">максимум <?= $stock ?> шт.</span>
                    </div>
                    <div class="tape qty-tape" aria-hidden="true"></div>

                    <div class="buy__total">
                        <span>Итого:</span>
                        <strong id="total-price"><?= e(format_price($price)) ?></strong>
                    </div>
                <?php else: ?>
                    <div class="notice notice--warning">Модель закончилась. Загляните в похожие — там есть близкие варианты.</div>
                <?php endif; ?>

                <div class="buy__actions">
                    <?php if (!$user): ?>
                        <a class="btn btn--primary btn--lg buy__cart" href="<?= e($loginUrl) ?>">В корзину</a>
                        <a class="btn btn--ghost btn--lg buy__fav-btn" href="<?= e($loginUrl) ?>"
                           aria-label="Войдите, чтобы добавить в избранное" title="Войдите, чтобы добавить в избранное">
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                        </a>
                    <?php else: ?>
                        <button class="btn btn--primary btn--lg buy__cart" id="add-to-cart" type="button"
                                data-add="<?= $productId ?>"<?= $stock <= 0 ? ' disabled' : '' ?>>
                            <?= $stock > 0 ? 'В корзину' : 'Нет в наличии' ?>
                        </button>
                        <button class="btn btn--ghost btn--lg buy__fav-btn<?= $isFavorite ? ' is-active' : '' ?>"
                                type="button" data-fav="<?= $productId ?>"
                                aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                                aria-label="<?= $isFavorite ? 'Убрать из избранного' : 'В избранное' ?>"
                                title="<?= $isFavorite ? 'Убрать из избранного' : 'В избранное' ?>">
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="notice notice--success buy__incart<?= $inCart > 0 ? ' is-visible' : '' ?>" id="in-cart-note">
                    В корзине: <strong id="in-cart-qty"><?= $inCart ?></strong> шт.
                    <a class="link" href="cart.php">Перейти в корзину</a>
                </div>

                <?php if (!$user): ?>
                    <div class="notice notice--info" style="margin-top: 14px; margin-bottom: 0;">
                        Корзина и избранное доступны после входа.
                        <a class="link" href="<?= e($loginUrl) ?>">Войти</a> — вернём вас на эту страницу.
                    </div>
                <?php endif; ?>

                <div class="buy__facts">
                    <div class="buy__fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
                        <span><b>Курьер или самовывоз</b> — выбирается при оформлении заказа.</span>
                    </div>
                    <div class="buy__fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.7"/></svg>
                        <span><b>Оплата картой или при получении</b> — как удобнее.</span>
                    </div>
                    <div class="buy__fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 11.8l2.2 2.2 4-4.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><b>Сервис японских брендов</b> — расходники и лапки доступны годами.</span>
                    </div>
                    <div class="buy__fact">
                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M5 4h14v16H5z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M8.5 9h7M8.5 13h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        <span>Артикул: <b><?= e($product['model']) ?></b> · добавлено <?= e(date('d.m.Y', strtotime((string)$product['created_at']))) ?></span>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</section>

<!-- ================= ХАРАКТЕРИСТИКИ ================= -->
<section class="section section--linen" id="specs">
    <div class="container">
        <div class="specs-head reveal">
            <h2 class="section-title">Техпаспорт модели</h2>
            <button class="btn btn--ghost btn--sm" id="copy-specs" type="button">Скопировать характеристики</button>
        </div>

        <?php if ($highlights): ?>
            <div class="spec-highlights" data-stagger>
                <?php foreach ($highlights as $h): ?>
                    <div class="card spec-high reveal">
                        <span class="spec-high__icon"><?= $specIcon($h['key']) ?></span>
                        <span class="spec-high__value"><?= e($h['value']) ?></span>
                        <span class="spec-high__label"><?= e($h['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="table-wrap reveal">
            <table class="table specs-table" id="specs-table">
                <caption class="visually-hidden">Характеристики <?= e($product['name']) ?></caption>
                <tbody>
                <?php foreach ($specs as $key => $value): ?>
                    <tr>
                        <td><?= $specIcon((string)$key) ?></td>
                        <th class="specs-key" scope="row"><?= e((string)$key) ?></th>
                        <td class="specs-val">
                            <?php if (is_bool($value)): ?>
                                <span class="specs-bool specs-bool--<?= $value ? 'yes' : 'no' ?>"><?= $value ? 'Да' : 'Нет' ?></span>
                            <?php else: ?>
                                <?= e($specValue($value)) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$specs): ?>
                    <tr><td colspan="3" class="muted">Характеристики уточняются.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ================= ОПИСАНИЕ ================= -->
<section class="section" id="description">
    <div class="container desc-layout">
        <div class="reveal">
            <span class="label">О модели</span>
            <h2 class="section-title">Что это за машинка</h2>
            <p class="desc-text"><?= e($product['description']) ?></p>

            <?php if ($descPoints): ?>
                <ul class="desc-points">
                    <?php foreach ($descPoints as $point): ?>
                        <li><?= e($point) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="desc-quote">
                «<?= e($product['brand_name']) ?> <?= e($product['model']) ?>» — из категории
                «<?= e($product['category_name']) ?>». Характеристики выше взяты из паспорта модели,
                без округлений и маркетинга.
            </div>
        </div>

        <div class="reveal">
            <details class="card card--static accordion" open>
                <summary>Доставка и оплата</summary>
                <div class="accordion__body">
                    <p>Курьер по городу или самовывоз из пункта выдачи — способ выбирается на странице оформления. Оплата картой онлайн или при получении.</p>
                    <p>Заказ резервирует остаток: пока он оформлен, машина не уйдёт другому покупателю.</p>
                </div>
            </details>
            <details class="card card--static accordion">
                <summary>Гарантия и сервис</summary>
                <div class="accordion__body">
                    <p>Японские бренды держат линейки десятилетиями, поэтому иглы, лапки, шпульки и пяльцы к этой модели доступны и через несколько лет.</p>
                    <p>Регламентное обслуживание — чистка челночного устройства и замена иглы — выполняется самостоятельно по инструкции производителя.</p>
                </div>
            </details>
            <details class="card card--static accordion">
                <summary>Как подобрать под ткань</summary>
                <div class="accordion__body">
                    <p>Для трикотажа смотрите на дифференциальную подачу и роликовый шов, для джинсы — на усиленный прокол и специальные лапки, для квилтинга — на верхний транспортёр и рабочее поле.</p>
                    <p>Сомневаетесь — напишите в <a class="link" href="feedback.php">обратную связь</a>, подскажем по вашей задаче.</p>
                </div>
            </details>
        </div>
    </div>
</section>

<!-- ================= ПОХОЖИЕ ================= -->
<?php if ($similar): ?>
<section class="section section--linen" id="similar">
    <div class="container">
        <div class="section-head reveal">
            <h2 class="section-title">Похожие машинки</h2>
            <a class="link" href="catalog.php?category=<?= e($product['category_slug']) ?>">Вся категория</a>
        </div>
        <div class="similar-grid" id="similar-grid">
            <?php foreach ($similar as $i => $item):
                $sPrice = (float)$item['price'];
                $sOld = $item['old_price'] !== null ? (float)$item['old_price'] : null;
                $sStock = (int)$item['stock'];
                $sFav = (bool)$item['is_favorite'];
                $sType = $typeLabels[$item['machine_type']] ?? 'Прочее';
                if ($sStock <= 0) { $sClass = 'out'; $sText = 'Нет в наличии'; }
                elseif ($sStock <= 3) { $sClass = 'low'; $sText = 'Осталось ' . $sStock . ' шт.'; }
                else { $sClass = 'in'; $sText = 'В наличии'; }
                $sUrl = 'product.php?slug=' . rawurlencode($item['slug']);
            ?>
                <article class="card scard" style="--i: <?= $i ?>">
                    <div class="scard__media">
                        <a href="<?= e($sUrl) ?>" tabindex="-1" aria-hidden="true">
                            <img src="../<?= e($item['image_path']) ?>" alt="<?= e($item['name']) ?>" loading="lazy" decoding="async"
                                 onerror="this.onerror=null;this.src='<?= e($fallbackImg) ?>';">
                        </a>
                        <?php if ($user): ?>
                            <button class="scard__fav<?= $sFav ? ' is-active' : '' ?>" type="button" data-fav="<?= (int)$item['id'] ?>"
                                    aria-pressed="<?= $sFav ? 'true' : 'false' ?>"
                                    aria-label="<?= $sFav ? 'Убрать из избранного' : 'В избранное' ?>"
                                    title="<?= $sFav ? 'Убрать из избранного' : 'В избранное' ?>">
                                <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            </button>
                        <?php else: ?>
                            <a class="scard__fav" href="<?= e($loginUrl) ?>" aria-label="Войдите, чтобы добавить в избранное" title="Войдите, чтобы добавить в избранное">
                                <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="scard__brand"><?= e($item['brand_name']) ?> · <?= e($sType) ?></div>
                    <h3 class="scard__name"><a href="<?= e($sUrl) ?>"><?= e($item['name']) ?></a></h3>
                    <div class="scard__price">
                        <b><?= e(format_price($sPrice)) ?></b>
                        <?php if ($sOld !== null && $sOld > $sPrice): ?><s><?= e(format_price($sOld)) ?></s><?php endif; ?>
                    </div>
                    <div class="scard__foot">
                        <span class="stock stock--<?= e($sClass) ?>"><?= e($sText) ?></span>
                        <?php if (!$user): ?>
                            <a class="btn btn--primary btn--sm btn--block" href="<?= e($loginUrl) ?>">В корзину</a>
                        <?php else: ?>
                            <button class="btn btn--primary btn--sm btn--block scard__add" type="button"
                                    data-add="<?= (int)$item['id'] ?>"<?= $sStock <= 0 ? ' disabled' : '' ?>>
                                <?= $sStock > 0 ? 'В корзину' : 'Нет в наличии' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Липкая панель покупки на мобильных -->
<div class="mbuy" id="mbuy">
    <span class="mbuy__price"><?= e(format_price($price)) ?></span>
    <?php if (!$user): ?>
        <a class="btn btn--primary mbuy__btn" href="<?= e($loginUrl) ?>">В корзину</a>
    <?php else: ?>
        <button class="btn btn--primary mbuy__btn" id="mbuy-add" type="button" data-add="<?= $productId ?>"<?= $stock <= 0 ? ' disabled' : '' ?>>
            <?= $stock > 0 ? 'В корзину' : 'Нет в наличии' ?>
        </button>
    <?php endif; ?>
</div>

<!-- Лайтбокс -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Фото товара в полном размере">
    <span class="lightbox__caption"><?= e($product['name']) ?></span>
    <button class="icon-btn lightbox__close" id="lb-close" type="button" aria-label="Закрыть">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <div class="lightbox__stage" id="lb-stage">
        <img class="lightbox__img" id="lb-img" src="../<?= e($product['image_path']) ?>" alt="<?= e($product['name']) ?>"
             onerror="this.onerror=null;this.src='<?= e($fallbackImg) ?>';">
    </div>
    <div class="lightbox__bar">
        <button class="icon-btn" id="lb-out" type="button" aria-label="Уменьшить">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
        <button class="icon-btn" id="lb-reset" type="button" aria-label="Сбросить масштаб">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 9V4h5M20 15v5h-5M15 4h5v5M9 20H4v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button class="icon-btn" id="lb-in" type="button" aria-label="Увеличить">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
    </div>
</div>

<script id="product-config" type="application/json"><?= json_encode($productConfig, $jsonFlags) ?></script>

<script>
// Карточка товара: количество, корзина, избранное, лупа, лайтбокс, шаринг
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('product-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var money = J.formatPrice;

    /* ---------- Количество и итоговая сумма ---------- */
    var qtyInput = document.getElementById('qty-input');
    var qtyMinus = document.getElementById('qty-minus');
    var qtyPlus = document.getElementById('qty-plus');
    var totalEl = document.getElementById('total-price');
    var maxQty = cfg.product.stock > 0 ? cfg.product.stock : 1;
    var shownTotal = cfg.product.price;

    function currentQty() {
        if (!qtyInput) { return 1; }
        var n = parseInt(qtyInput.value, 10);
        if (isNaN(n) || n < 1) { n = 1; }
        if (n > maxQty) { n = maxQty; }
        return n;
    }

    function paintTotal(target) {
        if (!totalEl) { return; }
        if (reduced) { totalEl.textContent = money(target); shownTotal = target; return; }
        var from = shownTotal;
        var start = null;
        var duration = 320;
        function step(ts) {
            if (start === null) { start = ts; }
            var p = Math.min((ts - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            totalEl.textContent = money(from + (target - from) * eased);
            if (p < 1) { requestAnimationFrame(step); } else { shownTotal = target; }
        }
        requestAnimationFrame(step);
    }

    function syncQty() {
        if (!qtyInput) { return; }
        var n = currentQty();
        qtyInput.value = n;
        qtyMinus.disabled = n <= 1;
        qtyPlus.disabled = n >= maxQty;
        paintTotal(cfg.product.price * n);
    }

    if (qtyInput) {
        qtyMinus.addEventListener('click', function () { qtyInput.value = currentQty() - 1; syncQty(); });
        qtyPlus.addEventListener('click', function () { qtyInput.value = currentQty() + 1; syncQty(); });
        qtyInput.addEventListener('input', function () {
            var n = parseInt(qtyInput.value, 10);
            if (!isNaN(n) && n >= 1 && n <= maxQty) { paintTotal(cfg.product.price * n); }
            qtyMinus.disabled = currentQty() <= 1;
            qtyPlus.disabled = currentQty() >= maxQty;
        });
        qtyInput.addEventListener('change', syncQty);
        syncQty();
    }

    /* ---------- Добавление в корзину ---------- */
    var inCartNote = document.getElementById('in-cart-note');
    var inCartQty = document.getElementById('in-cart-qty');

    function addProduct(btn, productId, quantity) {
        if (!cfg.isAuth) { window.location.href = cfg.loginUrl; return; }
        if (btn.dataset.busy === '1' || btn.disabled) { return; }

        var original = btn.innerHTML;
        btn.dataset.busy = '1';
        btn.disabled = true;
        btn.innerHTML = 'Добавляем…';

        J.api('../api/cart_add.php', { method: 'POST', body: { product_id: productId, quantity: quantity } })
            .then(function (data) {
                J.setCounts({ cart: data.cart_count });
                J.toast(
                    data.capped ? 'Добавили не всё — остаток ' + data.item_quantity + ' шт.' : 'Товар в корзине',
                    data.capped ? 'warning' : 'success'
                );

                if (productId === cfg.product.id && inCartNote && inCartQty) {
                    inCartQty.textContent = data.item_quantity;
                    inCartNote.classList.add('is-visible');
                }

                btn.innerHTML = '✓ В корзине';
                btn.classList.add('is-added');
                setTimeout(function () {
                    btn.innerHTML = original;
                    btn.classList.remove('is-added');
                    btn.disabled = false;
                    btn.dataset.busy = '';
                }, 1700);
            })
            .catch(function (err) {
                btn.innerHTML = original;
                btn.disabled = false;
                btn.dataset.busy = '';
                if (err.status === 401) { window.location.href = cfg.loginUrl; return; }
                J.toast(err.message, 'error');
            });
    }

    document.addEventListener('click', function (e) {
        var addBtn = e.target.closest('[data-add]');
        if (!addBtn) { return; }
        var id = parseInt(addBtn.dataset.add, 10);
        var qty = (id === cfg.product.id) ? currentQty() : 1;
        addProduct(addBtn, id, qty);
    });

    /* ---------- Избранное ---------- */
    function toggleFavorite(btn) {
        if (!cfg.isAuth) { window.location.href = cfg.loginUrl; return; }
        if (btn.classList.contains('is-busy')) { return; }

        var id = parseInt(btn.dataset.fav, 10);
        btn.classList.add('is-busy');

        J.api('../api/favorite_toggle.php', { method: 'POST', body: { product_id: id } })
            .then(function (data) {
                btn.classList.remove('is-busy');
                // Синхронизируем все сердечки этого товара на странице
                document.querySelectorAll('[data-fav="' + id + '"]').forEach(function (node) {
                    node.classList.toggle('is-active', data.favorite);
                    node.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
                    var label = data.favorite ? 'Убрать из избранного' : 'В избранное';
                    node.setAttribute('aria-label', label);
                    node.title = label;
                });

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

    document.addEventListener('click', function (e) {
        var favBtn = e.target.closest('button[data-fav]');
        if (favBtn) { e.preventDefault(); toggleFavorite(favBtn); }
    });

    /* ---------- Лупа на фото ---------- */
    var media = document.getElementById('gallery-media');
    var img = document.getElementById('gallery-img');
    var lens = document.getElementById('lens');
    var lensSize = 156;
    var zoom = 2.4;

    if (media && img && lens && !reduced && window.matchMedia('(pointer: fine)').matches) {
        lens.style.display = 'block';

        media.addEventListener('pointerenter', function () {
            lens.style.backgroundImage = 'url("' + img.currentSrc || img.src + '")';
            lens.classList.add('is-on');
        });
        media.addEventListener('pointermove', function (e) {
            var rect = media.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var y = e.clientY - rect.top;
            var half = lensSize / 2;
            var lx = Math.max(half, Math.min(x, rect.width - half));
            var ly = Math.max(half, Math.min(y, rect.height - half));
            lens.style.left = (lx - half) + 'px';
            lens.style.top = (ly - half) + 'px';
            lens.style.backgroundSize = (rect.width * zoom) + 'px ' + (rect.height * zoom) + 'px';
            lens.style.backgroundPosition = (-(x * zoom - half)) + 'px ' + (-(y * zoom - half)) + 'px';
        });
        media.addEventListener('pointerleave', function () { lens.classList.remove('is-on'); });
    }

    /* ---------- Лайтбокс ---------- */
    var lightbox = document.getElementById('lightbox');
    var lbImg = document.getElementById('lb-img');
    var lbClose = document.getElementById('lb-close');
    var lbIn = document.getElementById('lb-in');
    var lbOut = document.getElementById('lb-out');
    var lbReset = document.getElementById('lb-reset');
    var scale = 1, panX = 0, panY = 0;
    var dragging = false, dragStartX = 0, dragStartY = 0, startPanX = 0, startPanY = 0;
    var lastFocus = null;

    function applyTransform() {
        lbImg.style.transform = 'translate(' + panX + 'px, ' + panY + 'px) scale(' + scale + ')';
    }
    function setScale(next) {
        scale = Math.max(1, Math.min(4, next));
        if (scale === 1) { panX = 0; panY = 0; }
        applyTransform();
    }
    function openLightbox() {
        lastFocus = document.activeElement;
        lightbox.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        setScale(1);
        lbClose.focus();
    }
    function closeLightbox() {
        lightbox.classList.remove('is-open');
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    if (media) {
        media.addEventListener('click', openLightbox);
        media.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(); }
        });
    }
    var openBtn = document.getElementById('open-lightbox');
    if (openBtn) { openBtn.addEventListener('click', openLightbox); }

    lbClose.addEventListener('click', closeLightbox);
    lbIn.addEventListener('click', function () { setScale(scale + 0.5); });
    lbOut.addEventListener('click', function () { setScale(scale - 0.5); });
    lbReset.addEventListener('click', function () { setScale(1); });
    lightbox.addEventListener('click', function (e) { if (e.target === lightbox) { closeLightbox(); } });
    document.addEventListener('keydown', function (e) {
        if (!lightbox.classList.contains('is-open')) { return; }
        if (e.key === 'Escape') { closeLightbox(); }
        if (e.key === '+' || e.key === '=') { setScale(scale + 0.5); }
        if (e.key === '-') { setScale(scale - 0.5); }
    });
    lightbox.addEventListener('wheel', function (e) {
        if (!lightbox.classList.contains('is-open')) { return; }
        e.preventDefault();
        setScale(scale + (e.deltaY < 0 ? 0.25 : -0.25));
    }, { passive: false });

    lbImg.addEventListener('pointerdown', function (e) {
        if (scale <= 1) { return; }
        dragging = true;
        lbImg.classList.add('is-drag');
        dragStartX = e.clientX; dragStartY = e.clientY;
        startPanX = panX; startPanY = panY;
        lbImg.setPointerCapture(e.pointerId);
    });
    lbImg.addEventListener('pointermove', function (e) {
        if (!dragging) { return; }
        panX = startPanX + (e.clientX - dragStartX);
        panY = startPanY + (e.clientY - dragStartY);
        applyTransform();
    });
    lbImg.addEventListener('pointerup', function () { dragging = false; lbImg.classList.remove('is-drag'); });
    lbImg.addEventListener('pointercancel', function () { dragging = false; lbImg.classList.remove('is-drag'); });

    /* ---------- Поделиться и скопировать характеристики ---------- */
    function copyText(text, okMessage) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                J.toast(okMessage, 'success', 2400);
            }).catch(function () { fallbackCopy(text, okMessage); });
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
        try { document.execCommand('copy'); J.toast(okMessage, 'success', 2400); }
        catch (err) { J.toast('Не удалось скопировать', 'error'); }
        area.remove();
    }

    var shareBtn = document.getElementById('share-btn');
    if (shareBtn) {
        shareBtn.addEventListener('click', function () {
            var url = window.location.href;
            if (navigator.share) {
                navigator.share({ title: cfg.product.name, url: url }).catch(function () { /* отмена пользователем */ });
            } else {
                copyText(url, 'Ссылка на товар скопирована');
            }
        });
    }

    var copySpecsBtn = document.getElementById('copy-specs');
    var specsTable = document.getElementById('specs-table');
    if (copySpecsBtn && specsTable) {
        copySpecsBtn.addEventListener('click', function () {
            var lines = [cfg.product.name, ''];
            specsTable.querySelectorAll('tr').forEach(function (row) {
                var key = row.querySelector('.specs-key');
                var val = row.querySelector('.specs-val');
                if (key && val) { lines.push(key.textContent.trim() + ': ' + val.textContent.trim()); }
            });
            copyText(lines.join('\n'), 'Характеристики скопированы');
        });
    }

    /* ---------- Липкая мобильная панель и прогресс-нить ---------- */
    var mbuy = document.getElementById('mbuy');
    var buyPanel = document.getElementById('buy-panel');
    if (mbuy && buyPanel && 'IntersectionObserver' in window) {
        var buyObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                mbuy.classList.toggle('is-visible', !entry.isIntersecting && window.innerWidth <= 860);
            });
        }, { threshold: 0, rootMargin: '-80px 0px 0px 0px' });
        buyObserver.observe(buyPanel);
        window.addEventListener('resize', function () {
            if (window.innerWidth > 860) { mbuy.classList.remove('is-visible'); }
        });
    }

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
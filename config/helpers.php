<?php
/**
 * config/helpers.php — общие функции проекта.
 * Сессия, роли, CSRF, JSON-ответы, тосты, шапка и подвал.
 *
 * Использование в pages/*.php:
 *   require_once __DIR__ . '/../config/helpers.php';
 *   render_header('Каталог', ['description' => '...']);
 *   ... контент страницы ...
 *   render_footer();
 *
 * Использование в api/*.php:
 *   require_once __DIR__ . '/../config/helpers.php';
 *   $user = api_require_login();          // 401 JSON, если гость
 *   $admin = api_require_admin();         // 403 JSON, если не админ
 *   json_ok(['items' => $items]);
 */

declare(strict_types=1);

// Подключение к БД: файл создаётся из db.example.php вручную
if (!is_file(__DIR__ . '/db.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Нет config/db.php. Скопируй config/db.example.php в config/db.php и поправь доступы.');
}
require_once __DIR__ . '/db.php';

// Сессия с защищённой кукой
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('janome_sid');
    session_start();
}

/* ============================================================
   Вывод и форматирование
   ============================================================ */

// Экранирование всего вывода через htmlspecialchars
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Цена: 45990 → «45 990 ₽»
function format_price(float|string $value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' ₽';
}

// Инициалы для аватара-заглушки: «Фёдор Лазарев» → «ФЛ»
function user_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_substr($part, 0, 1, 'UTF-8');
    }
    return mb_strtoupper($initials !== '' ? $initials : 'U', 'UTF-8');
}

// Аватар: картинка или кружок с инициалами. Путь в БД от корня проекта,
// страницы лежат в pages/, поэтому добавляем ../
function user_avatar(array $user, string $extraClass = ''): string
{
    $class = 'avatar' . ($extraClass !== '' ? ' ' . $extraClass : '');
    if (!empty($user['avatar_path'])) {
        return '<span class="' . e($class) . '"><img src="../' . e($user['avatar_path'])
            . '" alt="Аватар: ' . e($user['full_name'] ?? '') . '"></span>';
    }
    return '<span class="' . e($class) . ' avatar--initials" aria-hidden="true">'
        . e(user_initials((string)($user['full_name'] ?? ''))) . '</span>';
}

/* ============================================================
   Авторизация и роли
   ============================================================ */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

// Кладём пользователя в сессию после password_verify
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'login' => $user['login'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'avatar_path' => $user['avatar_path'] ?? null,
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Гард для страниц: гостя отправляем на вход с возвратом
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . login_url());
        exit;
    }
}

// Гард для pages/admin.php: не админа — на главную с тостом
function require_admin(): void
{
    if (!is_logged_in()) {
        header('Location: ' . login_url());
        exit;
    }
    if (!is_admin()) {
        flash_toast('Раздел доступен только администратору', 'error');
        header('Location: index.php');
        exit;
    }
}

/* ============================================================
   CSRF
   ============================================================ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// Скрытое поле для форм
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

// Токен из POST-поля или заголовка X-CSRF-Token (fetch)
function csrf_check(?string $token = null): bool
{
    $token ??= $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

/* ============================================================
   JSON-ответы для api/*.php
   ============================================================ */

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = [], int $status = 200): never
{
    json_response(['ok' => true] + $data, $status);
}

function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_response(['ok' => false, 'error' => $message] + $extra, $status);
}

// Тело запроса из fetch с Content-Type: application/json
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : [];
}

// Гарды для API: проверка роли на сервере, а не скрытием кнопок
function api_require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_error('Требуется вход в аккаунт', 401);
    }
    return $user;
}

function api_require_admin(): array
{
    $user = api_require_login();
    if (($user['role'] ?? '') !== 'admin') {
        json_error('Доступ только для администратора', 403);
    }
    return $user;
}

function api_require_csrf(): void
{
    if (!csrf_check()) {
        json_error('Недействительный токен формы. Обнови страницу.', 403);
    }
}

/* ============================================================
   Тосты (flash через сессию)
   ============================================================ */

function flash_toast(string $message, string $type = 'success'): void
{
    $_SESSION['toasts'][] = ['message' => $message, 'type' => $type];
}

function take_flash_toasts(): array
{
    $toasts = $_SESSION['toasts'] ?? [];
    unset($_SESSION['toasts']);
    return $toasts;
}

/* ============================================================
   Ссылки и счётчики
   ============================================================ */

function current_url(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/';
}

// Вход с возвратом на исходную страницу
function login_url(?string $return = null): string
{
    $return ??= current_url();
    return 'login.php?return=' . urlencode($return);
}

// Только локальные пути возврата, без внешних редиректов и петель на login/register
function safe_return_url(?string $url): string
{
    if (is_string($url) && $url !== '' && $url[0] === '/' && ($url[1] ?? '') !== '/'
        && !str_contains($url, 'login.php') && !str_contains($url, 'register.php')) {
        return $url;
    }
    return 'index.php';
}

function page_url(string $page, array $query = []): string
{
    return $page . '.php' . ($query ? '?' . http_build_query($query) : '');
}

// Куда ведут корзина/избранное/кабинет: гость — на вход с возвратом
function nav_targets(): array
{
    if (is_logged_in()) {
        return [
            'cart' => 'cart.php',
            'favorites' => 'cabinet.php?tab=favorites',
            'cabinet' => 'cabinet.php',
        ];
    }
    $login = login_url();
    return ['cart' => $login, 'favorites' => $login, 'cabinet' => $login];
}

// Счётчики для шапки: сумма количеств в корзине и число избранного
function user_counts(): array
{
    global $pdo;
    $counts = ['cart' => 0, 'favorites' => 0];
    $user = current_user();
    if (!$user || !isset($pdo)) {
        return $counts;
    }
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $counts['cart'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $counts['favorites'] = (int)$stmt->fetchColumn();

    return $counts;
}

/* ============================================================
   Шапка
   ============================================================ */

function render_header(string $title = 'Главная', array $options = []): void
{
    $user = current_user();
    $counts = user_counts();
    $targets = nav_targets();
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $description = $options['description']
        ?? 'Интернет-магазин японских швейных машин Janome: каталог, корзина, заказы. Дизайн-концепция «Стежок».';
    $bodyClass = $options['body_class'] ?? '';

    $nav = [
        'index' => 'Главная',
        'catalog' => 'Каталог',
        'about' => 'О нас',
        'investors' => 'Инвесторам',
        'contacts' => 'Контакты',
        'feedback' => 'Обратная связь',
    ];
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — Janome</title>
<meta name="description" content="<?= e($description) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=PT+Serif:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/base.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='13' fill='%23FBF6F6' stroke='%237A1220' stroke-width='2'/%3E%3Ccircle cx='16' cy='16' r='5' fill='none' stroke='%23B01B2E' stroke-width='2'/%3E%3Ccircle cx='16' cy='16' r='2' fill='%234E0B15'/%3E%3C/svg%3E">
<script>document.documentElement.classList.add('js');</script>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#main">К содержимому</a>

<header class="site-header" id="site-header">
    <div class="container header__inner">

        <!-- Логотип: игла с нитью и шпульный контур «змеиный глаз» -->
        <a class="logo" href="index.php" aria-label="Janome — на главную">
            <svg class="logo__mark" viewBox="0 0 48 48" width="40" height="40" aria-hidden="true" focusable="false">
                <defs>
                    <linearGradient id="janome-thread" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0" stop-color="#B01B2E"/>
                        <stop offset="1" stop-color="#7A1220"/>
                    </linearGradient>
                </defs>
                <circle class="logo__ring" cx="20" cy="27" r="16.5"/>
                <circle class="logo__bobbin" cx="20" cy="27" r="12"/>
                <circle class="logo__inner" cx="20" cy="27" r="5.5"/>
                <circle class="logo__core" cx="20" cy="27" r="2.4"/>
                <g transform="translate(35 6) rotate(18)">
                    <line class="logo__needle" x1="0" y1="0" x2="0" y2="20"/>
                    <line class="logo__tip" x1="0" y1="20" x2="0" y2="25"/>
                    <ellipse class="logo__eye" cx="0" cy="5" rx="2" ry="4"/>
                </g>
                <path class="logo__thread" d="M25.5 22C30 14 31 10 34.6 10.6" pathLength="60"/>
            </svg>
            <span class="logo__text">
                <span class="logo__word">Janome</span>
                <span class="logo__tag">японские швейные машины</span>
            </span>
        </a>

        <!-- Меню -->
        <nav class="site-nav" id="site-nav" aria-label="Основное меню">
            <?php foreach ($nav as $slug => $label): ?>
                <a class="nav-link<?= $current === $slug ? ' is-active' : '' ?>"
                   href="<?= e($slug) ?>.php"<?= $current === $slug ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if (is_admin()): ?>
                <a class="nav-link nav-link--admin<?= $current === 'admin' ? ' is-active' : '' ?>" href="admin.php">
                    <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9.3 11.6l2 2 3.4-3.6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Админка
                </a>
            <?php endif; ?>
        </nav>

        <!-- Действия: избранное, корзина, вход/кабинет, выход, бургер -->
        <div class="header__actions">
            <a class="icon-btn" href="<?= e($targets['favorites']) ?>"
               aria-label="Избранное" title="<?= $user ? 'Избранное' : 'Войдите, чтобы открыть избранное' ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <span class="icon-btn__badge" data-count="favorites"<?= $counts['favorites'] > 0 ? '' : ' hidden' ?>><?= (int)$counts['favorites'] ?></span>
            </a>

            <a class="icon-btn" href="<?= e($targets['cart']) ?>"
               aria-label="Корзина" title="<?= $user ? 'Корзина' : 'Войдите, чтобы открыть корзину' ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6.2 8h11.6l-1 12H7.2l-1-12z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 8V6a3 3 0 0 1 6 0v2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <span class="icon-btn__badge" data-count="cart"<?= $counts['cart'] > 0 ? '' : ' hidden' ?>><?= (int)$counts['cart'] ?></span>
            </a>

            <?php if ($user): ?>
                <a class="user-chip" href="cabinet.php" title="Личный кабинет">
                    <?= user_avatar($user) ?>
                    <span class="user-chip__name"><?= e($user['full_name']) ?></span>
                    <span class="badge <?= $user['role'] === 'admin' ? 'badge--admin' : 'badge--user' ?>">
                        <?= $user['role'] === 'admin' ? 'админ' : 'покупатель' ?>
                    </span>
                </a>
                <form class="logout-form" method="post" action="login.php">
                    <input type="hidden" name="logout" value="1">
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" aria-label="Выйти из аккаунта" title="Выйти">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M14 4h5v16h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 8 6 12l4 4M6 12h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </form>
            <?php else: ?>
                <a class="btn btn--primary btn--sm header__login" href="<?= e(login_url()) ?>">Войти</a>
            <?php endif; ?>

            <button class="burger" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Открыть меню">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>

<main id="main" class="site-main">
<?php
}

/* ============================================================
   Подвал
   ============================================================ */

function render_footer(): void
{
    $targets = nav_targets();
    $toasts = take_flash_toasts();
    $config = [
        'isAuth' => is_logged_in(),
        'isAdmin' => is_admin(),
        'loginUrl' => login_url(),
        'csrf' => csrf_token(),
    ];
    // HEX-флаги: данные не могут разорвать script-блок
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    ?>
</main>

<footer class="site-footer">
    <div class="tape tape--light footer__tape" aria-hidden="true"></div>
    <div class="container footer__grid">
        <div class="footer__brand">
            <a class="logo logo--footer" href="index.php" aria-label="Janome — на главную">
                <svg class="logo__mark" viewBox="0 0 48 48" width="36" height="36" aria-hidden="true" focusable="false">
                    <circle class="logo__ring" cx="20" cy="27" r="16.5"/>
                    <circle class="logo__bobbin" cx="20" cy="27" r="12"/>
                    <circle class="logo__inner" cx="20" cy="27" r="5.5"/>
                    <circle class="logo__core" cx="20" cy="27" r="2.4"/>
                    <g transform="translate(35 6) rotate(18)">
                        <line class="logo__needle" x1="0" y1="0" x2="0" y2="20"/>
                        <line class="logo__tip" x1="0" y1="20" x2="0" y2="25"/>
                        <ellipse class="logo__eye" cx="0" cy="5" rx="2" ry="4"/>
                    </g>
                    <path class="logo__thread" d="M25.5 22C30 14 31 10 34.6 10.6" pathLength="60"/>
                </svg>
                <span class="logo__text">
                    <span class="logo__word">Janome</span>
                    <span class="logo__tag">японские швейные машины</span>
                </span>
            </a>
            <p class="footer__about">Janome, Juki, Brother, Toyota и Baby Lock. Светлое полотно и багровая нить — дизайн-концепция «Стежок».</p>
        </div>

        <nav class="footer__col" aria-label="Покупателям">
            <h3 class="footer__title">Покупателям</h3>
            <a href="catalog.php">Каталог</a>
            <a href="<?= e($targets['cart']) ?>">Корзина</a>
            <a href="<?= e($targets['favorites']) ?>">Избранное</a>
            <a href="<?= e($targets['cabinet']) ?>">Личный кабинет</a>
            <a href="feedback.php">Обратная связь</a>
        </nav>

        <nav class="footer__col" aria-label="Компания">
            <h3 class="footer__title">Компания</h3>
            <a href="about.php">О нас</a>
            <a href="investors.php">Инвесторам</a>
            <a href="contacts.php">Контакты</a>
            <?php if (!is_logged_in()): ?>
                <a href="register.php">Регистрация</a>
            <?php endif; ?>
        </nav>

        <div class="footer__col footer__contacts">
            <h3 class="footer__title">Контакты</h3>
            <p>Москва, ул. Швейная, 12</p>
            <a href="tel:+74951203456">+7 (495) 120-34-56</a>
            <a href="mailto:hello@janome.example">hello@janome.example</a>
            <p class="footer__hours">Пн–Сб: 10:00–20:00</p>
        </div>
    </div>

    <div class="container footer__bottom">
        <span>© 2026 Janome · Курсовой проект</span>
        <span class="footer__note">Цены и наличие демонстрационные</span>
    </div>
</footer>

<!-- Контейнер тостов и данные для main.js -->
<div class="toast-stack" id="toast-stack" aria-live="polite"></div>
<script id="app-config" type="application/json"><?= json_encode($config, $jsonFlags) ?></script>
<script id="flash-toasts" type="application/json"><?= json_encode($toasts, $jsonFlags) ?></script>
<script src="../assets/js/main.js" defer></script>
</body>
</html>
<?php
}
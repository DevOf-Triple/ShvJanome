<?php
/*
 * pages/login.php — вход по ЛОГИНУ (не по email).
 * Здесь же обрабатывается POST-выход из шапки (logout=1).
 * Отправка формы — fetch в api/login.php, возврат через параметр return.
 */

require_once __DIR__ . '/../config/helpers.php';

// Выход из аккаунта: обычная POST-форма из шапки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['logout'] ?? '') === '1') {
    if (csrf_check()) {
        logout_user();
        // Сессия уничтожена — поднимаем новую, чтобы положить flash-тост
        session_start();
        session_regenerate_id(true);
        flash_toast('Вы вышли из аккаунта', 'info');
    }
    header('Location: index.php');
    exit;
}

// Авторизованному на странице входа делать нечего
if (is_logged_in()) {
    $target = isset($_GET['return']) ? safe_return_url($_GET['return']) : 'cabinet.php';
    header('Location: ' . $target);
    exit;
}

// Куда вернуть после входа: только локальные пути, без петель на login/register
$returnRaw = (string)($_GET['return'] ?? '');
$return = safe_return_url($returnRaw !== '' ? $returnRaw : null);

render_header('Вход', [
    'description' => 'Вход в интернет-магазин Janome по логину.',
    'body_class' => 'page-auth',
]);
?>

<section class="hero">
    <div class="container container--narrow">
        <span class="label reveal">Аккаунт</span>
        <h1 class="hero__title reveal">Вход в магазин</h1>
        <p class="hero__lead reveal">Корзина, избранное и история заказов живут в вашем аккаунте. Входите по логину — и нить потянется дальше.</p>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<section class="section" style="padding-top: 8px;">
    <div class="container container--narrow">
        <div class="card card--static reveal">
            <div class="card__body">
                <noscript>
                    <div class="notice notice--warning">Для входа нужен включённый JavaScript — форма отправляется через fetch.</div>
                </noscript>

                <form id="login-form" data-return="<?= e($return) ?>" novalidate>
                    <div class="field">
                        <label class="field__label" for="login">Логин</label>
                        <input class="input" id="login" name="login" type="text" autocomplete="username"
                               maxlength="50" placeholder="например, user" required>
                        <span class="field__error" data-error-for="login" hidden></span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="password">Пароль</label>
                        <input class="input" id="password" name="password" type="password"
                               autocomplete="current-password" maxlength="72" placeholder="Ваш пароль" required>
                        <span class="field__error" data-error-for="password" hidden></span>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn--primary btn--block" type="submit">Войти</button>
                    </div>
                </form>

                <hr class="stitch">
                <p class="muted small" style="margin-bottom: 0;">
                    Ещё нет аккаунта?
                    <a class="link" href="register.php<?= $returnRaw !== '' ? '?return=' . urlencode($returnRaw) : '' ?>">Зарегистрироваться</a>
                </p>
            </div>
        </div>

        <div class="notice notice--info reveal" style="margin-top: 18px;">
            Тестовые аккаунты из <strong>seed_admin.php</strong>: покупатель <strong>user / user123</strong>,
            администратор <strong>admin / admin123</strong>.
        </div>
    </div>
</section>

<script>
// Клиентская валидация и отправка формы входа
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    if (!window.Janome) { return; }

    var form = document.getElementById('login-form');
    if (!form) { return; }

    var submitBtn = form.querySelector('button[type="submit"]');
    var btnText = submitBtn.textContent;

    // Показать/скрыть ошибку конкретного поля
    function setFieldError(name, message) {
        var input = form.elements[name];
        var slot = form.querySelector('[data-error-for="' + name + '"]');
        if (input) { input.classList.toggle('is-invalid', Boolean(message)); }
        if (slot) {
            slot.textContent = message || '';
            slot.hidden = !message;
        }
    }

    function validate() {
        var errors = {};
        var login = form.elements.login.value.trim();
        var password = form.elements.password.value;

        if (login === '') { errors.login = 'Введите логин'; }
        if (password === '') { errors.password = 'Введите пароль'; }

        ['login', 'password'].forEach(function (name) {
            setFieldError(name, errors[name] || '');
        });
        return errors;
    }

    // Сброс ошибки при правке поля
    ['login', 'password'].forEach(function (name) {
        form.elements[name].addEventListener('input', function () { setFieldError(name, ''); });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var errors = validate();
        if (Object.keys(errors).length > 0) {
            window.Janome.toast('Заполни поля формы', 'warning');
            form.elements[Object.keys(errors)[0]].focus();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Входим…';

        window.Janome.api('../api/login.php', {
            method: 'POST',
            body: {
                login: form.elements.login.value.trim(),
                password: form.elements.password.value,
                return: form.dataset.return || 'index.php'
            }
        }).then(function (data) {
            // Flash-тост приветствия покажется на следующей странице
            window.location.href = data.redirect || 'index.php';
        }).catch(function (err) {
            var fieldErrors = (err.data && err.data.errors) || {};
            Object.keys(fieldErrors).forEach(function (name) { setFieldError(name, fieldErrors[name]); });
            window.Janome.toast(err.message, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = btnText;
        });
    });
});
</script>

<?php render_footer(); ?>
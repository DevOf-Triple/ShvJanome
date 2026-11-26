<?php
/*
 * pages/register.php — регистрация покупателя.
 * Роль всегда user: админов создаёт только seed_admin.php / БД.
 * После успешной регистрации — автоматический вход и возврат через return.
 */

require_once __DIR__ . '/../config/helpers.php';

// Авторизованному регистрация не нужна
if (is_logged_in()) {
    header('Location: cabinet.php');
    exit;
}

// Возврат после регистрации: по умолчанию в кабинет
$returnRaw = (string)($_GET['return'] ?? '');
$return = $returnRaw !== '' ? safe_return_url($returnRaw) : 'cabinet.php';

render_header('Регистрация', [
    'description' => 'Создание аккаунта в интернет-магазине Janome.',
    'body_class' => 'page-auth',
]);
?>

<section class="hero">
    <div class="container container--narrow">
        <span class="label reveal">Аккаунт</span>
        <h1 class="hero__title reveal">Регистрация</h1>
        <p class="hero__lead reveal">Один аккаунт — корзина, избранное, заказы и смена пароля в кабинете. Логин придумайте свой: по нему будете входить.</p>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<section class="section" style="padding-top: 8px;">
    <div class="container container--narrow">
        <div class="card card--static reveal">
            <div class="card__body">
                <noscript>
                    <div class="notice notice--warning">Для регистрации нужен включённый JavaScript — форма отправляется через fetch.</div>
                </noscript>

                <form id="register-form" data-return="<?= e($return) ?>" novalidate>
                    <div class="form-grid">
                        <div class="field">
                            <label class="field__label" for="login">Логин</label>
                            <input class="input" id="login" name="login" type="text" autocomplete="username"
                                   maxlength="50" placeholder="латиница, цифры, _ и -" required>
                            <span class="field__hint">3–50 символов: латиница, цифры, дефис, подчёркивание</span>
                            <span class="field__error" data-error-for="login" hidden></span>
                        </div>

                        <div class="field">
                            <label class="field__label" for="full_name">Имя и фамилия</label>
                            <input class="input" id="full_name" name="full_name" type="text" autocomplete="name"
                                   maxlength="150" placeholder="Фёдор Лазарев" required>
                            <span class="field__error" data-error-for="full_name" hidden></span>
                        </div>
                    </div>

                    <div class="field">
                        <label class="field__label" for="email">Email</label>
                        <input class="input" id="email" name="email" type="email" autocomplete="email"
                               maxlength="150" placeholder="you@example.com" required>
                        <span class="field__error" data-error-for="email" hidden></span>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label class="field__label" for="password">Пароль</label>
                            <input class="input" id="password" name="password" type="password"
                                   autocomplete="new-password" maxlength="72" placeholder="минимум 6 символов" required>
                            <span class="field__error" data-error-for="password" hidden></span>
                        </div>

                        <div class="field">
                            <label class="field__label" for="password_repeat">Повтор пароля</label>
                            <input class="input" id="password_repeat" name="password_repeat" type="password"
                                   autocomplete="new-password" maxlength="72" placeholder="ещё раз" required>
                            <span class="field__error" data-error-for="password_repeat" hidden></span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn--primary btn--block" type="submit">Создать аккаунт</button>
                    </div>
                </form>

                <hr class="stitch">
                <p class="muted small" style="margin-bottom: 0;">
                    Уже есть аккаунт?
                    <a class="link" href="login.php<?= $returnRaw !== '' ? '?return=' . urlencode($returnRaw) : '' ?>">Войти</a>
                </p>
            </div>
        </div>
    </div>
</section>

<script>
// Клиентская валидация регистрации — правила зеркалят серверные
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    if (!window.Janome) { return; }

    var form = document.getElementById('register-form');
    if (!form) { return; }

    var submitBtn = form.querySelector('button[type="submit"]');
    var btnText = submitBtn.textContent;
    var encoder = new TextEncoder();

    var LOGIN_RE = /^[a-zA-Z0-9_-]{3,50}$/;
    var NAME_RE = /^[\p{L}\s'.-]{2,150}$/u;
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

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
        var fullName = form.elements.full_name.value.trim();
        var email = form.elements.email.value.trim();
        var password = form.elements.password.value;
        var repeat = form.elements.password_repeat.value;

        if (!LOGIN_RE.test(login)) { errors.login = 'Логин: 3–50 символов, латиница, цифры, _ и -'; }
        if (!NAME_RE.test(fullName)) { errors.full_name = 'Имя: 2–150 символов, буквы, пробелы, дефис'; }
        if (email === '' || !EMAIL_RE.test(email) || email.length > 150) { errors.email = 'Введите корректный email'; }
        // bcrypt режет всё длиннее 72 байт — проверяем именно байты
        if (password.length < 6) { errors.password = 'Пароль: минимум 6 символов'; }
        else if (encoder.encode(password).length > 72) { errors.password = 'Пароль слишком длинный (до 72 байт)'; }
        if (repeat !== password || repeat === '') { errors.password_repeat = 'Пароли не совпадают'; }

        ['login', 'full_name', 'email', 'password', 'password_repeat'].forEach(function (name) {
            setFieldError(name, errors[name] || '');
        });
        return errors;
    }

    ['login', 'full_name', 'email', 'password', 'password_repeat'].forEach(function (name) {
        form.elements[name].addEventListener('input', function () { setFieldError(name, ''); });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var errors = validate();
        if (Object.keys(errors).length > 0) {
            window.Janome.toast('Проверь выделенные поля', 'warning');
            form.elements[Object.keys(errors)[0]].focus();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Создаём…';

        window.Janome.api('../api/register.php', {
            method: 'POST',
            body: {
                login: form.elements.login.value.trim(),
                full_name: form.elements.full_name.value.trim(),
                email: form.elements.email.value.trim(),
                password: form.elements.password.value,
                password_repeat: form.elements.password_repeat.value,
                return: form.dataset.return || 'cabinet.php'
            }
        }).then(function (data) {
            // Регистрация сразу логинит — flash-тост покажется на следующей странице
            window.location.href = data.redirect || 'cabinet.php';
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
<?php
/**
 * pages/feedback.php — обратная связь «Стежок».
 * Пишет в таблицу feedback, honeypot + троттлинг на сервере,
 * тост об успехе, для авторизованного имя и email из профиля.
 * Почта не отправляется: всё хранится в БД.
 */

require_once __DIR__ . '/../config/helpers.php';

$user = current_user();

// Префилл из профиля: имя из сессии, email добираем из БД
$prefillName = '';
$prefillEmail = '';
if ($user) {
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    $prefillName = (string)$user['full_name'];
    $prefillEmail = (string)$stmt->fetchColumn();
}

// Обращений за сегодня — живая цифра из БД
$stmt = $pdo->prepare('SELECT COUNT(*) FROM feedback WHERE DATE(created_at) = CURDATE()');
$stmt->execute();
$todayCount = (int)$stmt->fetchColumn();

$formTs = time();

$feedbackConfig = [
    'isAuth' => (bool)$user,
    'loginUrl' => login_url(),
    'minLength' => 10,
    'maxLength' => 2000,
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

render_header('Обратная связь', [
    'description' => 'Напишите в магазин Janome: подбор машины, сервис, запчасти, опт. Обращение попадает в базу, менеджер связывается сам.',
    'body_class' => 'page-feedback',
]);
?>

<style>
/* ============================================================
   Обратная связь «Стежок»: форма-лоскут, honeypot вне экрана,
   шаги обработки, счётчик символов, схема ответа.
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
.fb-hero { padding: 34px 0 26px; position: relative; overflow: hidden;
    background: linear-gradient(116deg, rgba(176, 27, 46, .06) 0%, rgba(212, 42, 63, .03) 40%, rgba(255, 255, 255, 0) 72%); }
.fb-hero__row { display: flex; align-items: flex-end; justify-content: space-between; gap: 26px; flex-wrap: wrap; }
.fb-hero__lead { color: var(--muted); max-width: 58ch; margin-bottom: 0; }
.fb-pills { display: flex; gap: 10px; flex-wrap: wrap; }
.fb-pill { padding: 9px 14px; background: var(--white); border: 1px dashed var(--line); border-radius: var(--radius); font: 600 12px/1.25 var(--font-sans); color: var(--muted); }
.fb-pill strong { display: block; font-family: var(--font-serif); font-size: 19px; color: var(--wine-700); }
.fb-hero .tape { margin-top: 24px; }

/* ---------- Раскладка ---------- */
.fb-layout { display: grid; grid-template-columns: 1fr 356px; gap: 28px; align-items: start; padding: 26px 0 60px; }

/* ---------- Форма ---------- */
.fb-card { padding: 24px 22px; }
.fb-card__title { font: 700 20px/1.25 var(--font-serif); color: var(--wine-900); margin: 0 0 6px; }
.fb-card__sub { font-size: 13px; color: var(--muted); margin: 0 0 18px; }
.fb-login-chip {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    padding: 11px 14px; margin-bottom: 18px;
    background: var(--linen); border: 1px dashed var(--line); border-radius: var(--radius);
    font-size: 13px; color: var(--muted);
}
.fb-login-chip b { color: var(--ink); }
.fb-login-chip svg { color: var(--wine-500); flex: none; }

/* Honeypot: уводим за экран, человек не видит и не заполняет */
.hp-field { position: absolute !important; left: -9999px !important; top: auto; width: 1px; height: 1px; overflow: hidden; }

.fb-counter { font: 600 11.5px/1 var(--font-sans); color: var(--muted); text-align: right; margin-top: 5px; transition: color .2s; }
.fb-counter.is-warn { color: var(--warning); }
.fb-counter.is-over { color: var(--danger); }
.fb-consent { align-items: flex-start; font-size: 12.5px; color: var(--muted); }
.fb-consent input { margin-top: 2px; }
.fb-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
.fb-actions .btn--primary { min-width: 210px; }
.fb-done { display: none; margin-top: 18px; }
.fb-done.is-visible { display: block; animation: done-in .35s cubic-bezier(.22,.61,.36,1) both; }
@keyframes done-in { from { opacity: 0; transform: translateY(-8px); } }
.fb-done__num { font: 700 15px/1.3 var(--font-serif); color: var(--wine-700); }

/* ---------- Сайдбар ---------- */
.fb-side { position: sticky; top: calc(var(--header-h) + 20px); display: grid; gap: 16px; }
.fb-steps { padding: 20px 18px; }
.fb-steps__title { font: 700 16px/1.3 var(--font-serif); color: var(--wine-900); margin: 0 0 14px; }
.fb-step { position: relative; display: flex; gap: 13px; padding-bottom: 16px; }
.fb-step:last-child { padding-bottom: 0; }
.fb-step::before { content: ""; position: absolute; left: 15px; top: 34px; bottom: 2px; border-left: 2px dashed var(--line); }
.fb-step:last-child::before { display: none; }
.fb-step__num {
    position: relative; z-index: 1; width: 32px; height: 32px; flex: none; border-radius: 50%;
    display: grid; place-items: center; background: var(--white); border: 2px solid var(--line);
    font: 700 13px/1 var(--font-serif); color: var(--wine-700);
    transition: border-color .3s, background-color .3s, color .3s;
}
.fb-step:hover .fb-step__num { border-color: var(--wine-500); background: var(--wine-500); color: var(--white); }
.fb-step__title { display: block; font: 700 13.5px/1.3 var(--font-sans); color: var(--ink); margin-bottom: 3px; }
.fb-step__text { display: block; font-size: 12.5px; color: var(--muted); }
.fb-note { padding: 16px 18px; font-size: 12.5px; color: var(--muted); }
.fb-note b { display: flex; align-items: center; gap: 8px; color: var(--ink); font-size: 13px; margin-bottom: 7px; }
.fb-note b svg { color: var(--wine-500); flex: none; }

/* ---------- Адаптив ---------- */
@media (max-width: 1080px) {
    .fb-layout { grid-template-columns: 1fr; }
    .fb-side { position: static; }
}
@media (max-width: 560px) {
    .fb-card { padding: 20px 16px; }
    .fb-actions .btn { width: 100%; }
    .fb-pills { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .fb-done.is-visible { animation: none; }
    .fb-step__num { transition: none; }
}
</style>

<div class="thread-progress" id="thread-progress" aria-hidden="true"></div>

<nav class="crumbs" aria-label="Навигация по разделам">
    <div class="container">
        <ol class="crumbs__list">
            <li><a href="index.php">Главная</a></li>
            <li class="crumbs__sep" aria-hidden="true">/</li>
            <li class="crumbs__current" aria-current="page">Обратная связь</li>
        </ol>
    </div>
</nav>

<section class="fb-hero">
    <div class="container">
        <div class="fb-hero__row">
            <div class="reveal">
                <span class="label">Обратная связь</span>
                <h1>Напишите нам — ответим по делу</h1>
                <p class="fb-hero__lead">Подбор машины под ткань, сервис, запчасти, опт и партнёрство. Сообщение падает в базу магазина, менеджер связывается по указанному телефону сам.</p>
            </div>
            <div class="fb-pills reveal">
                <span class="fb-pill"><strong id="pill-today"><?= $todayCount ?></strong> обращений сегодня</span>
                <span class="fb-pill"><strong>1 день</strong> срок ответа</span>
                <span class="fb-pill"><strong>без писем</strong> всё через базу</span>
            </div>
        </div>
        <div class="tape reveal" aria-hidden="true"></div>
    </div>
</section>

<div class="container fb-layout">
    <!-- Форма -->
    <section aria-label="Форма обратной связи">
        <div class="card card--static fb-card reveal">
            <h2 class="fb-card__title">Форма обращения</h2>
            <p class="fb-card__sub">Поля с именем и почтой нужны, чтобы ответить. Телефон можете оставить в тексте — так быстрее.</p>

            <?php if ($user): ?>
                <div class="fb-login-chip">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="8.5" r="3.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M5 20c1.2-3.6 4-5.5 7-5.5s5.8 1.9 7 5.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    <span>Вы вошли как <b>@<?= e($user['login']) ?></b> — имя и email подставлены из профиля, обращение привяжется к аккаунту.</span>
                </div>
            <?php else: ?>
                <div class="fb-login-chip">
                    <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    <span>Можно без входа. А если <a class="link" href="<?= e(login_url()) ?>">войти</a> — подставим имя и email сами.</span>
                </div>
            <?php endif; ?>

            <form id="feedback-form" novalidate>
                <!-- Honeypot: скрытое поле-ловушка, человек его не видит -->
                <div class="hp-field" aria-hidden="true">
                    <label for="fb-website">Оставьте это поле пустым</label>
                    <input type="text" id="fb-website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" id="fb-ts" name="form_ts" value="<?= $formTs ?>">

                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="fb-name">Имя</label>
                        <input class="input" id="fb-name" name="name" type="text" autocomplete="name" maxlength="100"
                               placeholder="Как к вам обращаться" value="<?= e($prefillName) ?>" required>
                        <span class="field__error" data-error-for="name" hidden></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="fb-email">Email</label>
                        <input class="input" id="fb-email" name="email" type="email" autocomplete="email" maxlength="150"
                               placeholder="you@example.com" value="<?= e($prefillEmail) ?>" required>
                        <span class="field__error" data-error-for="email" hidden></span>
                    </div>
                </div>

                <div class="field">
                    <label class="field__label" for="fb-message">Сообщение</label>
                    <textarea class="textarea" id="fb-message" name="message" rows="6" maxlength="2000"
                              placeholder="Опишите задачу: какую ткань шьёте, какой бюджет, что уже смотрели. Чем подробнее — тем точнее подбор."></textarea>
                    <div class="fb-counter" id="fb-counter">0 / 2000</div>
                    <span class="field__error" data-error-for="message" hidden></span>
                </div>

                <label class="checkbox fb-consent">
                    <input type="checkbox" id="fb-consent" name="consent">
                    <span>Согласен на обработку персональных данных: имя и email используем только чтобы ответить на это обращение.</span>
                </label>
                <span class="field__error" data-error-for="consent" hidden style="display:block; margin-top:6px;"></span>

                <div class="fb-actions">
                    <button class="btn btn--primary btn--lg" type="submit" id="fb-submit">Отправить обращение</button>
                    <button class="btn btn--ghost btn--lg" type="button" id="fb-clear">Очистить</button>
                </div>

                <div class="notice notice--success fb-done" id="fb-done" role="status">
                    <span class="fb-done__num" id="fb-done-num"></span><br>
                    Менеджер свяжется с вами в рабочий день по телефону или на почту из формы. Следить за ответом письмом не нужно — писем мы не шлём.
                </div>
            </form>
        </div>
    </section>

    <!-- Сайдбар -->
    <aside class="fb-side">
        <div class="card card--static fb-steps reveal">
            <h3 class="fb-steps__title">Что происходит после отправки</h3>
            <div class="fb-step">
                <span class="fb-step__num" aria-hidden="true">1</span>
                <span>
                    <span class="fb-step__title">Проверка формы</span>
                    <span class="fb-step__text">Браузер и сервер сверяют поля, honeypot отсекает ботов.</span>
                </span>
            </div>
            <div class="fb-step">
                <span class="fb-step__num" aria-hidden="true">2</span>
                <span>
                    <span class="fb-step__title">Запись в базу</span>
                    <span class="fb-step__text">Обращение попадает в таблицу feedback с номером и датой.</span>
                </span>
            </div>
            <div class="fb-step">
                <span class="fb-step__num" aria-hidden="true">3</span>
                <span>
                    <span class="fb-step__title">Ответ менеджера</span>
                    <span class="fb-step__text">Звоним по телефону из сообщения или пишем на почту в течение рабочего дня.</span>
                </span>
            </div>
        </div>

        <div class="card card--static accordion reveal">
            <summary>Частые вопросы</summary>
            <div class="accordion__body">
                <p><b>Подберёте машину под задачу?</b> Да, напишите ткань, бюджет и опыт — предложим 2–3 модели из каталога.</p>
                <p><b>Есть ли запчасти и лапки?</b> Держим расходники по ходовым линейкам Janome, Juki и Brother. Уточним наличие по модели.</p>
                <p><b>Куда везти на ремонт?</b> В сервис на ул. Швейной, 12. Адрес и часы — на странице <a class="link" href="contacts.php">контактов</a>.</p>
            </div>
        </div>

        <div class="card card--static fb-note reveal">
            <b>
                <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M4 6h16v12H4z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M4 7l8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                Почему без писем
            </b>
            Учебный магазин не подключает почтовый шлюз: обращение хранится в базе, а менеджер связывается сам. Так вы не попадёте в спам и не потеряете ответ.
        </div>
    </aside>
</div>

<script id="feedback-config" type="application/json"><?= json_encode($feedbackConfig, $jsonFlags) ?></script>

<script>
// Обратная связь: валидация, honeypot, счётчик, отправка через fetch, тост
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var cfgEl = document.getElementById('feedback-config');
    var J = window.Janome;
    if (!cfgEl || !J) { return; }

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (err) { return; }

    var form = document.getElementById('feedback-form');
    var nameInput = document.getElementById('fb-name');
    var emailInput = document.getElementById('fb-email');
    var messageInput = document.getElementById('fb-message');
    var consentBox = document.getElementById('fb-consent');
    var honeypot = document.getElementById('fb-website');
    var tsInput = document.getElementById('fb-ts');
    var counter = document.getElementById('fb-counter');
    var submitBtn = document.getElementById('fb-submit');
    var clearBtn = document.getElementById('fb-clear');
    var doneBox = document.getElementById('fb-done');
    var doneNum = document.getElementById('fb-done-num');
    var pillToday = document.getElementById('pill-today');

    var initialName = nameInput.value;
    var initialEmail = emailInput.value;

    function setFieldError(name, message) {
        var slot = form.querySelector('[data-error-for="' + name + '"]');
        var input = form.elements[name];
        if (input && input.classList) { input.classList.toggle('is-invalid', Boolean(message)); }
        if (slot) { slot.textContent = message || ''; slot.hidden = !message; }
    }

    function paintCounter() {
        var len = messageInput.value.length;
        counter.textContent = len + ' / ' + cfg.maxLength;
        counter.classList.toggle('is-warn', len > cfg.maxLength - 100 && len <= cfg.maxLength);
        counter.classList.toggle('is-over', len >= cfg.maxLength);
    }
    messageInput.addEventListener('input', function () {
        paintCounter();
        setFieldError('message', '');
    });
    paintCounter();

    ['name', 'email'].forEach(function (name) {
        form.elements[name].addEventListener('input', function () { setFieldError(name, ''); });
    });
    consentBox.addEventListener('change', function () { setFieldError('consent', ''); });

    function validate() {
        var errors = {};
        var name = nameInput.value.trim();
        var email = emailInput.value.trim();
        var message = messageInput.value.trim();

        if (!/^[\p{L}\s'.-]{2,100}$/u.test(name)) { errors.name = 'Имя: 2–100 символов, буквы, пробелы, дефис'; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 150) { errors.email = 'Введите корректный email'; }
        if (message.length < cfg.minLength) { errors.message = 'Сообщение: минимум ' + cfg.minLength + ' символов'; }
        else if (message.length > cfg.maxLength) { errors.message = 'Сообщение: до ' + cfg.maxLength + ' символов'; }
        if (!consentBox.checked) { errors.consent = 'Отметьте согласие на обработку данных'; }

        ['name', 'email', 'message', 'consent'].forEach(function (k) { setFieldError(k, errors[k] || ''); });
        return errors;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var errors = validate();
        if (Object.keys(errors).length) {
            J.toast('Проверьте выделенные поля', 'warning');
            var first = Object.keys(errors)[0];
            if (first === 'consent') { consentBox.focus(); }
            else if (form.elements[first]) { form.elements[first].focus(); }
            return;
        }

        var original = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Отправляем…';
        doneBox.classList.remove('is-visible');

        J.api('../api/send_feedback.php', {
            method: 'POST',
            body: {
                name: nameInput.value.trim(),
                email: emailInput.value.trim(),
                message: messageInput.value.trim(),
                consent: consentBox.checked,
                website: honeypot.value,
                form_ts: parseInt(tsInput.value, 10) || 0
            }
        })
            .then(function (data) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = original;

                if (data.id > 0) {
                    doneNum.textContent = 'Обращение №' + data.id + ' записано в базу.';
                    if (typeof data.today_count === 'number' && pillToday) { pillToday.textContent = data.today_count; }
                } else {
                    doneNum.textContent = 'Обращение принято.';
                }
                doneBox.classList.add('is-visible');

                J.toast(data.message || 'Обращение принято', 'success');

                // Чистим сообщение и согласие, контакты оставляем
                messageInput.value = '';
                consentBox.checked = false;
                paintCounter();
                doneBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = original;
                if (err.status === 401) { window.location.href = cfg.loginUrl; return; }
                var fieldErrors = (err.data && err.data.errors) || {};
                Object.keys(fieldErrors).forEach(function (k) { setFieldError(k, fieldErrors[k]); });
                J.toast(err.message, 'error');
            });
    });

    clearBtn.addEventListener('click', function () {
        nameInput.value = initialName;
        emailInput.value = initialEmail;
        messageInput.value = '';
        consentBox.checked = false;
        honeypot.value = '';
        ['name', 'email', 'message', 'consent'].forEach(function (k) { setFieldError(k, ''); });
        paintCounter();
        doneBox.classList.remove('is-visible');
        J.toast('Форма очищена', 'info', 2000);
    });

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
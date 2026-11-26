<?php
/*
 * api/logout.php — POST: выход из аккаунта через fetch.
 * Используется кабинетом; POST-форма из шапки обрабатывается в pages/login.php.
 * Ответ: {ok, message}. Сессия уничтожается полностью.
 */

require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_error('Метод не поддерживается', 405);
}

api_require_csrf();

if (!is_logged_in()) {
    json_error('Вы и не входили', 400);
}

logout_user();

json_ok(['message' => 'Вы вышли из аккаунта']);
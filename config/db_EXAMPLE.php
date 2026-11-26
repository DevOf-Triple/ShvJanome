<?php
// config/db.example.php — шаблон подключения к БД.
// Переименуй в db.php вручную и при необходимости поправь доступы.

$host = ''; // адрес MySQL-модуля в OSPanel
$dbname = 'janome_base';
$dbuser = '';
$dbpass = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // Детали ошибки наружу не отдаём — только общий текст
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode([
        'ok' => false,
        'error' => 'Ошибка подключения к базе данных',
    ]));
}
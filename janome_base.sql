-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Хост: MySQL-8.4
-- Время создания: Ноя 28 2026 г., 00:23
-- Версия сервера: 8.4.6
-- Версия PHP: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `janome_base`
--

-- --------------------------------------------------------

--
-- Структура таблицы `brands`
--

CREATE TABLE `brands` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `brands`
--

INSERT INTO `brands` (`id`, `name`, `slug`, `sort_order`) VALUES
(1, 'Janome', 'janome', 1),
(2, 'Juki', 'juki', 2),
(3, 'Brother', 'brother', 3),
(4, 'Toyota', 'toyota', 4),
(5, 'Baby Lock', 'baby-lock', 5);

-- --------------------------------------------------------

--
-- Структура таблицы `cart_items`
--

CREATE TABLE `cart_items` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `cart_items`
--

INSERT INTO `cart_items` (`id`, `user_id`, `product_id`, `quantity`, `added_at`) VALUES
(3, 2, 12, 1, '2026-11-27 23:16:57');

-- --------------------------------------------------------

--
-- Структура таблицы `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `categories`
--

INSERT INTO `categories` (`id`, `name`, `slug`, `sort_order`) VALUES
(1, 'Швейные машины', 'shveynye-mashiny', 1),
(2, 'Оверлоки', 'overloki', 2),
(3, 'Вышивальные машины', 'vyshivalnye-mashiny', 3),
(4, 'Швейно-вышивальные машины', 'shveyno-vyshivalnye-mashiny', 4),
(5, 'Промышленные машины', 'promyshlennye-mashiny', 5);

-- --------------------------------------------------------

--
-- Структура таблицы `favorites`
--

CREATE TABLE `favorites` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `feedback`
--

CREATE TABLE `feedback` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `orders`
--

CREATE TABLE `orders` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `order_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `status` enum('new','processing','shipped','done','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `delivery` enum('courier','pickup') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment` enum('on_delivery','card') COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `order_number`, `total`, `status`, `delivery`, `payment`, `address`, `phone`, `comment`, `created_at`) VALUES
(1, 2, 'JN-20261127-0001', 379970.00, 'done', 'courier', 'on_delivery', 'gegeege', '+71231231212', 'geegegeggagau', '2026-11-27 01:54:35');

-- --------------------------------------------------------

--
-- Структура таблицы `order_items`
--

CREATE TABLE `order_items` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `quantity` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `name`, `price`, `quantity`) VALUES
(1, 1, 11, 'Швейно-вышивальная машина Brother Innov-is M380D', 109990.00, 2),
(2, 1, 12, 'Промышленная швейная машина Juki DDL-9000C-F', 159990.00, 1);

-- --------------------------------------------------------

--
-- Структура таблицы `products`
--

CREATE TABLE `products` (
  `id` int NOT NULL,
  `brand_id` int NOT NULL,
  `category_id` int NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `specs` json NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `old_price` decimal(12,2) DEFAULT NULL,
  `stock` int NOT NULL DEFAULT '0',
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assets/img/products/placeholder.svg',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `products`
--

INSERT INTO `products` (`id`, `brand_id`, `category_id`, `name`, `model`, `slug`, `description`, `specs`, `price`, `old_price`, `stock`, `image_path`, `is_active`, `created_at`) VALUES
(1, 1, 1, 'Швейная машина Janome DC 603', 'DC 603', 'janome-dc-603', 'Компьютеризированная машина с 60 операциями и 7 петлями-автомат. Горизонтальный ротационный челнок, нитевдеватель и кнопка старт/стоп — комфортный старт для повседневного шитья и пэчворка.', '{\"Петля\": \"автомат, 7 видов\", \"Челнок\": \"горизонтальный ротационный\", \"Нитевдеватель\": true, \"Тип управления\": \"компьютеризированная\", \"Кнопка старт/стоп\": true, \"Количество операций\": 60, \"Макс. длина стежка (мм)\": 5, \"Скорость шитья (ст/мин)\": 650, \"Макс. ширина стежка (мм)\": 7}', 45990.00, 52990.00, 8, 'assets/img/products/1333b5bc453c739ddc40bd531f410012.png', 1, '2026-11-26 19:29:40'),
(2, 1, 1, 'Швейная машина Janome Memory Craft 6700P', 'Memory Craft 6700P', 'janome-memory-craft-6700p', 'Профессиональная машина с металлической станиной: 1200 стежков в минуту и двойная подача AcuFeed Flex для точного продвижения ткани. 200 операций, 5 шрифтов и коленоподъёмник для серьёзных квилт-проектов.', '{\"Петля\": \"автомат, 9 видов\", \"Челнок\": \"горизонтальный ротационный\", \"Шрифтов\": 5, \"Тип управления\": \"компьютеризированная\", \"Коленоподъёмник\": true, \"Верхний транспортёр\": \"AcuFeed Flex\", \"Количество операций\": 200, \"Макс. длина стежка (мм)\": 5, \"Скорость шитья (ст/мин)\": 1200, \"Макс. ширина стежка (мм)\": 9}', 189990.00, NULL, 4, 'assets/img/products/3b5c2de4fd2133f40ed8aac4e0f6b602.jpg', 1, '2026-11-26 19:29:40'),
(3, 4, 1, 'Швейная машина Toyota Super Jeans 34', 'Super Jeans 34', 'toyota-super-jeans-34', 'Электромеханическая рабочая лошадка с усиленным проколом иглы: шьёт до 12 слоёв джинсы. 34 операции, горизонтальный челнок и специальная лапка для джинсовых тканей в комплекте.', '{\"Петля\": \"полуавтомат\", \"Челнок\": \"горизонтальный ротационный\", \"Тип управления\": \"электромеханическая\", \"Количество операций\": 34, \"Шьёт джинсу до (слоёв)\": 12, \"Макс. длина стежка (мм)\": 4, \"Макс. ширина стежка (мм)\": 5}', 23990.00, 27990.00, 15, 'assets/img/products/6c3faf0363a1c3760875a56fb3a0502a.jpg', 1, '2026-11-26 19:29:40'),
(4, 3, 1, 'Швейная машина Brother Innov-is A150', 'Innov-is A150', 'brother-innov-is-a150', 'Компьютеризированная машина со 150 операциями и 10 петлями-автомат. Быстрая заправка нити, 4 монограммных шрифта и широкий выбор эластичных и декоративных строчек.', '{\"Петля\": \"автомат, 10 видов\", \"Челнок\": \"горизонтальный ротационный\", \"Шрифтов\": 4, \"Нитевдеватель\": true, \"Тип управления\": \"компьютеризированная\", \"Количество операций\": 150, \"Макс. длина стежка (мм)\": 5, \"Макс. ширина стежка (мм)\": 7}', 62990.00, NULL, 9, 'assets/img/products/79dcc04cc61b211093fdee5ea3af91ef.jpg', 1, '2026-11-26 19:29:40'),
(5, 2, 2, 'Оверлок Juki MO-654DE', 'MO-654DE', 'juki-mo-654de', 'Классический оверлок на 2/3/4 нити со скоростью 1500 стежков в минуту. Дифференциальная подача не даёт трикотажу растягиваться, роликовый шов выполняется без смены лапки.', '{\"Нитей\": \"2/3/4\", \"Подсветка\": \"LED\", \"Роликовый шов\": true, \"Скорость (ст/мин)\": 1500, \"Количество операций\": 15, \"Дифференциальная подача\": true}', 36990.00, 41990.00, 11, 'assets/img/products/1510ea9cc5083986df544c495320a605.png', 1, '2026-11-26 19:29:40'),
(6, 1, 2, 'Оверлок Janome MyLock 714D', 'MyLock 714D', 'janome-mylock-714d', 'Надёжный оверлок на 2/3/4 нити с 13 краеобмёточными программами. Дифференциальная подача, роликовый шов и flatlock — всё для аккуратной обработки трикотажа.', '{\"Flatlock\": true, \"Нитей\": \"2/3/4\", \"Роликовый шов\": true, \"Скорость (ст/мин)\": 1300, \"Количество операций\": 13, \"Макс. ширина строчки (мм)\": 7, \"Дифференциальная подача\": true}', 34990.00, NULL, 10, 'assets/img/products/a7fd6f73482e6c427c0b3a8b0eaa1d16.jpg', 1, '2026-11-26 19:29:40'),
(7, 5, 2, 'Оверлок Baby Lock Acclaim BLES4', 'Acclaim BLES4', 'baby-lock-acclaim-bles4', 'Премиальный оверлок Baby Lock с воздушной заправкой петлителей RevolutionAir. 13 операций на скорости 1500 стежков в минуту — фабричное качество швов на любых тканях.', '{\"Нитей\": \"2/3/4\", \"Роликовый шов\": true, \"Скорость (ст/мин)\": 1500, \"Заправка петлителей\": \"воздушная RevolutionAir\", \"Количество операций\": 13, \"Дифференциальная подача\": true}', 139990.00, NULL, 3, 'assets/img/products/d356fde7aefb1b5af6f9a18d3263cbfc.jpg', 1, '2026-11-26 19:29:40'),
(8, 1, 3, 'Вышивальная машина Janome Memory Craft 550E', 'Memory Craft 550E', 'janome-memory-craft-550e', 'Вышивальная машина с огромным полем 200×360 мм — почти формат А4. 180 встроенных дизайнов, 6 шрифтов и 4 пяльца в комплекте для проектов любого масштаба.', '{\"Дисплей\": \"цветной сенсорный\", \"Шрифтов\": 6, \"Пялец в комплекте\": 4, \"Встроенных дизайнов\": 180, \"Макс. поле вышивки (мм)\": \"200×360\", \"Скорость вышивки (ст/мин)\": 860}', 164990.00, 184990.00, 5, 'assets/img/products/b1f11126cf0dca8da0ae4df0ba8eacac.jpg', 1, '2026-11-26 19:29:40'),
(9, 3, 3, 'Вышивальная машина Brother Innov-is V3LE', 'Innov-is V3LE', 'brother-innov-is-v3le', 'Только вышивка: поле 300×200 мм и скорость 1050 стежков в минуту. 227 встроенных дизайнов и 7-дюймовый цветной сенсорный экран для комфортного редактирования.', '{\"USB\": true, \"Дисплей\": \"цветной сенсорный 7 дюймов\", \"Встроенных дизайнов\": 227, \"Макс. поле вышивки (мм)\": \"300×200\", \"Скорость вышивки (ст/мин)\": 1050}', 149990.00, NULL, 4, 'assets/img/products/ab86a7c697ea7ad082abf6db6dce7630.jpg', 1, '2026-11-26 19:29:40'),
(10, 1, 4, 'Швейно-вышивальная машина Janome Skyline S9', 'Skyline S9', 'janome-skyline-s9', 'Флагманский швейно-вышивальный комбайн: 300 строчек, 250 дизайнов и Wi-Fi для передачи узоров с компьютера. Поле вышивки 170×200 мм и 11 петель-автомат.', '{\"Wi-Fi\": true, \"Петля\": \"автомат, 11 видов\", \"Дисплей\": \"сенсорный\", \"Форматы вышивки\": \".jef, .jef+\", \"Дизайнов вышивки\": 250, \"Швейных операций\": 300, \"Макс. поле вышивки (мм)\": \"170×200\"}', 279990.00, NULL, 2, 'assets/img/products/3ac813caab518cf6a78fcdfd10ee0047.jpg', 1, '2026-11-26 19:29:40'),
(11, 3, 4, 'Швейно-вышивальная машина Brother Innov-is M380D', 'Innov-is M380D', 'brother-innov-is-m380d', 'Компактная швейно-вышивальная машина с 200 дизайнами, 65 из них — Disney. 181 строчка и поле вышивки 100×100 мм — идеально для персонализации и детских проектов.', '{\"Петля\": \"автомат, 10 видов\", \"Дизайнов Disney\": 65, \"Дизайнов вышивки\": 200, \"Швейных операций\": 181, \"Макс. поле вышивки (мм)\": \"100×100\", \"Скорость шитья (ст/мин)\": 800}', 109990.00, 124990.00, 4, 'assets/img/products/39842b6716920301f2d8c8da5fa7c74c.jpg', 1, '2026-11-26 19:29:40'),
(12, 2, 5, 'Промышленная швейная машина Juki DDL-9000C-F', 'DDL-9000C-F', 'juki-ddl-9000c-f', 'Промышленная одноигольная машина челночного стежка с прямым сервоприводом: 5000 стежков в минуту. Полусухая головка, автоматическая обрезка нити и цифровая регулировка подачи для средних тканей.', '{\"Тип\": \"одноигольная, челночного стежка\", \"Привод\": \"прямой, сервомотор\", \"Назначение\": \"средние ткани\", \"Система игл\": \"DBx1\", \"Обрезка нити\": true, \"Смазка головки\": \"полусухая\", \"Скорость (ст/мин)\": 5000, \"Макс. длина стежка (мм)\": 5}', 159990.00, NULL, 2, 'assets/img/products/f4397b5b068d1457fb466e45f73fb19d.jpg', 1, '2026-11-26 19:29:40');

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `login` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('user','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `login`, `password_hash`, `full_name`, `email`, `phone`, `address`, `avatar_path`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$SuCB.WpqmbEsVQvn0fMPreza./YdYx/xEsw3ik4cLtCKDyPXrwacy', 'Администратор магазина', 'admin@janome.local', NULL, NULL, NULL, 'admin', '2026-11-26 22:31:55'),
(2, 'user', '$2y$10$GDSR925XZAF8sRModNyRZutFfowwbXcQ9rPg6DvUbiAwvXqP1LTQO', 'Тестовый покупатель', 'user@janome.local', '+71231231212', 'gegeege', 'assets/img/avatars/2552c73df7c02f76fb6cbc75e051ca19.jpg', 'user', '2026-11-26 22:31:55');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_brands_slug` (`slug`),
  ADD KEY `idx_brands_sort` (`sort_order`);

--
-- Индексы таблицы `cart_items`
--
ALTER TABLE `cart_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cart_user_product` (`user_id`,`product_id`),
  ADD KEY `idx_cart_product` (`product_id`);

--
-- Индексы таблицы `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_categories_slug` (`slug`),
  ADD KEY `idx_categories_sort` (`sort_order`);

--
-- Индексы таблицы `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_favorites_user_product` (`user_id`,`product_id`),
  ADD KEY `idx_favorites_product` (`product_id`);

--
-- Индексы таблицы `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_feedback_user` (`user_id`),
  ADD KEY `idx_feedback_read` (`is_read`);

--
-- Индексы таблицы `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_orders_number` (`order_number`),
  ADD KEY `idx_orders_user` (`user_id`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_created` (`created_at`);

--
-- Индексы таблицы `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_items_order` (`order_id`),
  ADD KEY `idx_order_items_product` (`product_id`);

--
-- Индексы таблицы `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_products_slug` (`slug`),
  ADD KEY `idx_products_brand` (`brand_id`),
  ADD KEY `idx_products_category` (`category_id`),
  ADD KEY `idx_products_price` (`price`),
  ADD KEY `idx_products_active` (`is_active`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_login` (`login`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT для таблицы `cart_items`
--
ALTER TABLE `cart_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT для таблицы `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT для таблицы `favorites`
--
ALTER TABLE `favorites`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT для таблицы `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT для таблицы `products`
--
ALTER TABLE `products`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `cart_items`
--
ALTER TABLE `cart_items`
  ADD CONSTRAINT `fk_cart_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cart_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `fk_favorites_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

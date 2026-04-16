-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Время создания: Апр 13 2026 г., 17:43
-- Версия сервера: 10.11.7-MariaDB-1:10.11.7+maria~ubu2204-log
-- Версия PHP: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `binance`
--

-- --------------------------------------------------------

--
-- Структура таблицы `cross_pairs`
--

CREATE TABLE `cross_pairs` (
  `ticker` varchar(16) NOT NULL,
  `base_id` int(11) NOT NULL,
  `quote_id` int(11) NOT NULL,
  `flags` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `cross_pairs`
--

INSERT INTO `cross_pairs` (`ticker`, `base_id`, `quote_id`, `flags`) VALUES
('eurusd', 5, 31, 0);

-- --------------------------------------------------------

--
-- Структура таблицы `data_config`
--

CREATE TABLE `data_config` (
  `id_ticker` int(11) NOT NULL,
  `load_candles` int(11) NOT NULL DEFAULT 0,
  `load_depth` int(11) NOT NULL DEFAULT 0,
  `load_ticks` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

--
-- Дамп данных таблицы `data_config`
--

INSERT INTO `data_config` (`id_ticker`, `load_candles`, `load_depth`, `load_ticks`) VALUES
(1, 2, 0, 0),
(2, 2, 0, 0),
(3, 2, 0, 2),
(4, 2, 0, 0),
(5, 2, 0, 3),
(6, 2, 0, 0),
(7, 2, 0, 0),
(8, 2, 0, 2),
(9, 2, 0, 0),
(10, 2, 0, 0),
(11, 2, 0, 0),
(12, 2, 0, 2),
(13, 2, 0, 0),
(14, 2, 0, 0),
(15, 2, 0, 2),
(16, 2, 0, 0),
(17, 2, 0, 0),
(18, 2, 0, 2),
(19, 2, 0, 0),
(20, 2, 0, 0),
(21, 2, 0, 0),
(22, 2, 0, 0),
(23, 2, 0, 0),
(24, 2, 0, 0),
(25, 2, 0, 0),
(26, 2, 0, 0),
(27, 2, 0, 0),
(29, 2, 0, 0),
(31, 2, 0, 0),
(34, 2, 0, 0),
(35, 2, 0, 0),
(36, 2, 0, 0),
(38, 2, 0, 0),
(72, 32, 0, 0);

-- --------------------------------------------------------

--
-- Структура таблицы `ticker_map`
--

CREATE TABLE `ticker_map` (
  `id` int(11) NOT NULL,
  `ticker` varchar(16) NOT NULL,
  `symbol` varchar(16) NOT NULL,
  `pair_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

--
-- Дамп данных таблицы `ticker_map`
--

INSERT INTO `ticker_map` (`id`, `ticker`, `symbol`, `pair_id`) VALUES
(1, 'adausd', 'ADAUSDT', 40),
(2, 'algusd', 'ALGOUSDT', 6),
(3, 'atomusd', 'ATOMUSDT', 7),
(4, 'avaxusd', 'AVAXUSDT', 17),
(5, 'btcusd', 'BTCUSDT', 1),
(6, 'dogeusd', 'DOGEUSDT', 50),
(7, 'dotusd', 'DOTUSDT', 51),
(8, 'ethusd', 'ETHUSDT', 3),
(9, 'filusd', 'FILUSDT', 16),
(10, 'icpusd', 'ICPUSDT', 52),
(11, 'linkusd', 'LINKUSDT', 19),
(12, 'ltcusd', 'LTCUSDT', 4),
(13, 'dashusd', 'DASHUSDT', 13),
(14, 'maticusd', 'MATICUSDT', 37),
(15, 'nearusd', 'NEARUSDT', 25),
(16, 'solusd', 'SOLUSDT', 54),
(17, 'trxusd', 'TRXUSDT', 64),
(18, 'uniusd', 'UNIUSDT', 27),
(19, 'xlmusd', 'XLMUSDT', 28),
(20, 'xrpusd', 'XRPUSDT', 22),
(21, 'bchusd', 'BCHUSDT', 9),
(22, 'aptusd', 'APTUSDT', 8),
(23, 'arbusd', 'ARBUSDT', 18),
(24, 'suiusd', 'SUIUSDT', 24),
(25, 'ethbtc', 'ETHBTC', 112),
(26, 'pepeusd', 'PEPEUSDT', 76),
(27, '1inchusd', '1INCHUSDT', 41),
(29, 'xautusd', 'PAXGUSDT', 2),
(31, 'btceur', 'BTCEUR', 91),
(34, 'bnbusd', 'BNBUSDT', 34),
(35, 'xmrusd', 'XMRUSDT', 22),
(36, 'seiusd', 'SEIUSDT', 36),
(38, 'etcusd', 'ETCUSDT', 15),
(39, 'dydxusd', 'DYDXUSDT', 66),
(72, 'eurusd', 'EURUSD', 172);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `cross_pairs`
--
ALTER TABLE `cross_pairs`
  ADD PRIMARY KEY (`ticker`);

--
-- Индексы таблицы `data_config`
--
ALTER TABLE `data_config`
  ADD PRIMARY KEY (`id_ticker`);

--
-- Индексы таблицы `ticker_map`
--
ALTER TABLE `ticker_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticker` (`ticker`),
  ADD UNIQUE KEY `symbol` (`symbol`),
  ADD KEY `pair_id` (`pair_id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `ticker_map`
--
ALTER TABLE `ticker_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=513713;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

CREATE TABLE `ticker_history` (
  `ts` timestamp(3) NOT NULL,
  `pair_id` int(11) NOT NULL,
  `ask` float NOT NULL,
  `bid` float NOT NULL,
  `last` float NOT NULL,
  `fair_price` float NOT NULL DEFAULT 0,
  `daily_vol` float NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `ticker_history`
--
ALTER TABLE `ticker_history`
  ADD PRIMARY KEY (`ts`,`pair_id`);
COMMIT;

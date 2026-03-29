CREATE DATABASE IF NOT EXISTS `datafeed`;
USE `datafeed`;

CREATE TABLE IF NOT EXISTS `loader_control` (
  `loader_key` varchar(64) NOT NULL,
  `exchange` varchar(32) NOT NULL,
  `script_name` varchar(96) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `period_seconds` int(10) unsigned NOT NULL DEFAULT 3600,
  `timeout_seconds` int(10) unsigned NOT NULL DEFAULT 3900,
  `last_started_at` datetime DEFAULT NULL,
  `last_finished_at` datetime DEFAULT NULL,
  `last_exit_code` int(11) DEFAULT NULL,
  `last_pid` int(11) DEFAULT NULL,
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`loader_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `loader_activity` (
  `name` varchar(64) NOT NULL,
  `host` varchar(64) NOT NULL,
  `pid` int(11) NOT NULL,
  `state` varchar(24) NOT NULL,
  `active_count` int(10) unsigned NOT NULL DEFAULT 0,
  `note` varchar(255) NOT NULL DEFAULT '',
  `ts_alive` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`,`host`,`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `loader_control` (`loader_key`, `exchange`, `script_name`, `enabled`, `period_seconds`, `timeout_seconds`) VALUES
('binance_candles', 'binance', 'bnc_candles_dl.php', 1, 3600, 3900),
('binance_ticks', 'binance', 'bnc_ticks_dl.php', 1, 3600, 3900),
('bitmex_candles', 'bitmex', 'bmx_candles_dl.php', 1, 3600, 3900),
('bitmex_ticks', 'bitmex', 'bmx_ticks_dl.php', 1, 3600, 3900),
('bitfinex_candles', 'bitfinex', 'bfx_candles_dl.php', 1, 3600, 3900),
('bitfinex_ticks', 'bitfinex', 'bfx_ticks_dl.php', 1, 3600, 3900),
('bybit_candles', 'bybit', 'bbt_candles_dl.php', 1, 3600, 3900),
('coinmarketcap_update', 'meta', 'cm_update.php', 0, 300, 240);

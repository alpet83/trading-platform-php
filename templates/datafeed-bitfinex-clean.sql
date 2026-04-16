-- Clean template: Bitfinex BTC/ETH only
-- Source of truth: trading.bitfinex__pairs_map (pair_id 1,3)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE IF NOT EXISTS `cross_pairs` (
    `ticker` varchar(16) NOT NULL,
    `base_id` int(11) NOT NULL,
    `quote_id` int(11) NOT NULL,
    `flags` int(10) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`ticker`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `data_config` (
    `id_ticker` int(11) NOT NULL,
    `load_candles` int(11) NOT NULL DEFAULT 0,
    `load_depth` int(11) NOT NULL DEFAULT 0,
    `load_ticks` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_ticker`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `ticker_map` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `ticker` varchar(16) NOT NULL,
    `symbol` varchar(32) NOT NULL,
    `pair_id` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ticker` (`ticker`),
    UNIQUE KEY `symbol` (`symbol`),
    KEY `pair_id` (`pair_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

DELETE FROM `cross_pairs`;
DELETE FROM `data_config`;
DELETE FROM `ticker_map`;

INSERT INTO `cross_pairs` (`ticker`, `base_id`, `quote_id`, `flags`) VALUES
    ('btcusd', 5, 1, 0),
    ('ethusd', 8, 1, 0);

INSERT INTO `data_config` (`id_ticker`, `load_candles`, `load_depth`, `load_ticks`) VALUES
    (1, 2, 0, 0),
    (3, 2, 0, 0);

INSERT INTO `ticker_map` (`id`, `ticker`, `symbol`, `pair_id`) VALUES
    (1, 'btcusd', 'tBTCUSD', 1),
    (3, 'ethusd', 'tETHUSD', 3);

ALTER TABLE `ticker_map` AUTO_INCREMENT = 4;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

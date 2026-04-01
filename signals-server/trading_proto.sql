/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.13-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: trading
-- ------------------------------------------------------
-- Server version	11.3.2-MariaDB-1:11.3.2+maria~ubu2204

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `channels`
--

DROP TABLE IF EXISTS `channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `channels` (
  `chat_id` bigint(11) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `users` varchar(32) NOT NULL DEFAULT '0',
  `ts_last` timestamp NOT NULL,
  PRIMARY KEY (`chat_id`),
  UNIQUE KEY `channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_log`
--

DROP TABLE IF EXISTS `chat_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_log` (
  `id` int(11) NOT NULL,
  `event_id` int(10) unsigned NOT NULL DEFAULT 0,
  `chat` bigint(11) NOT NULL,
  `tag` varchar(15) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_del` timestamp NOT NULL,
  PRIMARY KEY (`id`,`chat`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_users`
--

DROP TABLE IF EXISTS `chat_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_users` (
  `chat_id` bigint(16) unsigned NOT NULL,
  `user_name` varchar(32) NOT NULL,
  `last_cmd` int(11) unsigned NOT NULL DEFAULT 0,
  `last_msg` int(10) unsigned NOT NULL DEFAULT 0,
  `last_notify` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `enabled` int(11) NOT NULL DEFAULT 1,
  `rights` varchar(48) DEFAULT '',
  `auth_pass` int(10) NOT NULL DEFAULT 0,
  `base_setup` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'Start of user setup range (base_setup..base_setup+9)',
  PRIMARY KEY (`chat_id`),
  UNIQUE KEY `user_name` (`user_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` int(11) NOT NULL,
  `tag` varchar(8) DEFAULT NULL,
  `event` varchar(2048) DEFAULT NULL,
  `value` double NOT NULL,
  `flags` int(11) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `attach` mediumblob /*!100301 COMPRESSED*/ DEFAULT NULL,
  `chat` bigint(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_id` (`host`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `events_archive`
--

DROP TABLE IF EXISTS `events_archive`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `events_archive` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` int(11) NOT NULL,
  `tag` varchar(8) DEFAULT NULL,
  `event` varchar(2048) NOT NULL,
  `value` double NOT NULL,
  `flags` int(11) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `attach` mediumblob DEFAULT NULL,
  `chat` bigint(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_id` (`host`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `events_daily`
--

DROP TABLE IF EXISTS `events_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `events_daily` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` int(11) NOT NULL,
  `tag` varchar(8) DEFAULT NULL,
  `event` varchar(2048) DEFAULT NULL,
  `value` double NOT NULL,
  `flags` int(11) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `attach` mediumblob /*!100301 COMPRESSED*/ DEFAULT NULL,
  `chat` bigint(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_id` (`host`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hosts`
--

DROP TABLE IF EXISTS `hosts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hosts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(16) NOT NULL,
  `name` varchar(32) DEFAULT NULL,
  `comment` varchar(64) DEFAULT NULL,
  `alive_ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `importance` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='host index and summary';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bot_hosts`
--

DROP TABLE IF EXISTS `bot_hosts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bot_hosts` (
  `host_id` int(11) NOT NULL AUTO_INCREMENT,
  `host_name` varchar(64) NOT NULL,
  `stats_url` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_ts` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`host_id`),
  UNIQUE KEY `host_name` (`host_name`),
  UNIQUE KEY `stats_url` (`stats_url`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Trading bots backends hosts';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hype_history_51__1`
--

DROP TABLE IF EXISTS `hype_history_51__1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hype_history_51__1` (
  `ts` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  `value` double NOT NULL,
  `source` varchar(16) NOT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hype_history_51__3`
--

DROP TABLE IF EXISTS `hype_history_51__3`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hype_history_51__3` (
  `ts` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  `value` double NOT NULL,
  `source` varchar(16) NOT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hype_history_51__4`
--

DROP TABLE IF EXISTS `hype_history_51__4`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hype_history_51__4` (
  `ts` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  `value` double NOT NULL,
  `source` varchar(16) NOT NULL,
  PRIMARY KEY (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hype_last`
--

DROP TABLE IF EXISTS `hype_last`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hype_last` (
  `ts` timestamp(3) NULL DEFAULT NULL,
  `ts_checked` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  `account_id` int(11) NOT NULL,
  `setup` int(11) NOT NULL DEFAULT 0,
  `pair_id` int(11) NOT NULL,
  `value` double NOT NULL,
  `value_change` double NOT NULL DEFAULT 0,
  `source` varchar(16) NOT NULL,
  UNIQUE KEY `account_id_2` (`account_id`,`pair_id`),
  UNIQUE KEY `account_id` (`account_id`,`pair_id`,`setup`) USING BTREE,
  KEY `ts` (`ts`),
  KEY `ts_checked` (`ts_checked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `last_messages`
--

DROP TABLE IF EXISTS `last_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `last_messages` (
  `update_id` int(11) NOT NULL,
  `chat` bigint(11) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `message` varchar(2048) DEFAULT NULL,
  PRIMARY KEY (`update_id`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `levels_map`
--

DROP TABLE IF EXISTS `levels_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `levels_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pair_id` int(11) NOT NULL,
  `level` float NOT NULL,
  `amount` float NOT NULL DEFAULT 1,
  `ts_valid` timestamp NULL DEFAULT NULL,
  `ts_notify` timestamp NOT NULL DEFAULT '2019-12-31 21:00:00',
  `last_price` float NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pair_id` (`pair_id`,`level`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pairs_map`
--

DROP TABLE IF EXISTS `pairs_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pairs_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `symbol` varchar(32) NOT NULL,
  `binance_pair` varchar(16) NOT NULL,
  `bitfinex_pair` varchar(16) NOT NULL,
  `bitmex_pair` varchar(16) NOT NULL,
  `deribit_pair` varchar(32) DEFAULT NULL,
  `coinm_fut` tinyint(1) NOT NULL DEFAULT 0,
  `contract_ratio` float NOT NULL DEFAULT 1,
  `color` varchar(15) NOT NULL DEFAULT 'none',
  PRIMARY KEY (`id`),
  UNIQUE KEY `symbol` (`symbol`),
  KEY `binance_pair` (`binance_pair`),
  KEY `bitfinex_pair` (`bitfinex_pair`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `pairs_map` (`id`, `symbol`, `binance_pair`, `bitfinex_pair`, `bitmex_pair`, `deribit_pair`, `coinm_fut`, `contract_ratio`, `color`) VALUES
(1, 'BTCUSD', 'BTCUSDC', 'tBTCUSD', 'XBTUSD', 'BTC-PERPETUAL', 0, 0.01, '#F7FF00'),
(3, 'ETHUSD', 'ETHUSDC', 'tETHUSD', 'ETHUSD', 'ETH-PERPETUAL', 0, 1, '#B8860B');

--
-- Table structure for table `post_queue`
--

DROP TABLE IF EXISTS `post_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `post_queue` (
  `event_id` int(11) NOT NULL,
  `chat` bigint(11) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `tag` varchar(8) DEFAULT NULL,
  `event` varchar(2048) DEFAULT NULL,
  PRIMARY KEY (`event_id`,`chat`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `signals`
--

DROP TABLE IF EXISTS `signals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `signals` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `signal_no` int(11) NOT NULL,
  `setup` int(11) NOT NULL DEFAULT 0,
  `pair_id` int(11) NOT NULL,
  `trader_id` int(11) DEFAULT NULL COMMENT 'Source identification',
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `buy` tinyint(1) NOT NULL,
  `source_ip` varchar(16) NOT NULL,
  `mult` float NOT NULL,
  `limit_price` float NOT NULL DEFAULT 0,
  `take_profit` float NOT NULL DEFAULT 0,
  `stop_loss` float NOT NULL DEFAULT 0,
  `ttl` int(11) NOT NULL DEFAULT 10,
  `qty` int(11) NOT NULL DEFAULT 0 COMMENT 'Grid orders quantity',
  `exec_prio` float NOT NULL DEFAULT 0,
  `flags` int(10) unsigned NOT NULL DEFAULT 0,
  `comment` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`,`setup`) USING BTREE,
  UNIQUE KEY `idx` (`id`) USING BTREE,
  UNIQUE KEY `trade_no` (`signal_no`,`pair_id`,`setup`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=250 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `trader__sessions`
--

DROP TABLE IF EXISTS `trader__sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trader__sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ts` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'chat_id for TG',
  `user_id` int(11) NOT NULL,
  `IP` varchar(16) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`IP`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-22 17:14:31

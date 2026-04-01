CREATE TABLE IF NOT EXISTS `signals_stats` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpoint` VARCHAR(64) NOT NULL,
    `remote_ip` VARCHAR(64) NOT NULL,
    `remote_host` VARCHAR(255) NOT NULL DEFAULT '',
    `src_account` INT NOT NULL DEFAULT 0,
    `setup_raw` VARCHAR(255) NOT NULL DEFAULT '',
    `view_name` VARCHAR(32) NOT NULL DEFAULT 'json',
    `out_format` VARCHAR(32) NOT NULL DEFAULT 'json',
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_endpoint_ip` (`endpoint`,`remote_ip`),
    KEY `idx_endpoint_last_seen` (`endpoint`,`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

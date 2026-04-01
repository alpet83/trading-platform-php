INSERT INTO `signals_stats`
(`endpoint`, `remote_ip`, `remote_host`, `src_account`, `setup_raw`, `view_name`, `out_format`, `user_agent`)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  `remote_host` = ?,
  `src_account` = ?,
  `setup_raw` = ?,
  `view_name` = ?,
  `out_format` = ?,
  `user_agent` = ?,
  `hits` = `hits` + 1,
  `last_seen` = CURRENT_TIMESTAMP;

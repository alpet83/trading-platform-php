<?php
    function fmt_auth_token(string $ts, int $user_id, string $IP): string {
        $row = sprintf("'%s', %d, '%s'", $ts, $user_id, $IP);
        return md5($row);
    }

 
  
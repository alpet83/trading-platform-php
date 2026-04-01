<?php
    file_put_contents("logs/globals.txt", var_export(array_keys($GLOBALS), true));

    $list = get_class_methods($telegram);
    file_put_contents("logs/telegram.txt", print_r($list, true));
    $chat = $telegram->getChat(['chat_id' => $admin_id]);
    if (is_object($chat))
      file_put_contents("logs/chat.txt", print_r(get_class_methods($chat), true));

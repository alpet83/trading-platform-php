<?php

    require_once('../../api_helper.php');

    $user_rights = get_user_rights();

    if (!str_in($user_rights, 'admin')) {
    send_error('Forbidden: only admin can access this resource', 403);
    exit;
    }

    $mysqli = init_remote_db('sigsys');
    if (!$mysqli) {
    send_error('Database inaccessible', 500);
    exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
    send_error('Method not allowed', 405);
    exit;
    }

    $query = "SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users";
    $result = $mysqli->query($query);

    if (!$result) {
    send_error('Database query failed', 500);
    exit;
    }

    $users = [];
    while ($row = $result->fetch_assoc()) {
    $row['id'] = $row['chat_id'];
    unset($row['chat_id']);
    $row['rights'] = !empty($row['rights']) ? explode(',', $row['rights']) : [];
    $row['enabled'] = intval($row['enabled']);
    $row['base_setup'] = intval($row['base_setup'] ?? 0);
    $users[] = $row;
    }

    send_response($users);

<?php

    require_once('../../../api_helper.php');

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

    if ($method !== 'POST') {
    send_error("Method '$method' not allowed", 405);
    exit;
    }

    if (!isset($_POST['id']) || !isset($_POST['user_name'])) {
    send_error('Missing required fields: id, user_name', 400);
    exit;
    }

    $id = intval($_POST['id']);
    $user_name = trim(str_replace('@', '', $_POST['user_name']));
    $rights_input = $_POST['rights'] ?? [];
    $enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 1;

    if ($id <= 0) {
    send_error('Invalid id', 400);
    exit;
    }

    if (empty($user_name)) {
    send_error('Invalid user_name', 400);
    exit;
    }

    $valid_rights = ['view', 'trade', 'admin'];

    if (is_string($rights_input)) {
    $rights_input = array_filter(array_map('trim', explode(',', $rights_input)));
    }

    if (!is_array($rights_input)) {
    $rights_input = empty($rights_input) ? [] : [$rights_input];
    }

    foreach ($rights_input as $right) {
    if (!in_array($right, $valid_rights)) {
        send_error('Invalid rights value. Must be one or more of: ' . implode(', ', $valid_rights), 400);
        exit;
    }
    }

    if (!in_array($enabled, [0, 1])) {
    send_error('Invalid enabled value. Must be 0 or 1', 400);
    exit;
    }

    $rights_str = implode(',', $rights_input);

    $user_name_escaped = $mysqli->real_escape_string($user_name);

    $exists = $mysqli->select_row(
    'chat_id, user_name, rights, enabled, base_setup',
    'chat_users',
    "WHERE chat_id = $id OR user_name = '$user_name_escaped' LIMIT 1",
    MYSQLI_ASSOC,
    );

    if ($exists) {
    $existing_rights = $exists['rights'] ? array_filter(array_map('trim', explode(',', $exists['rights']))) : [];
    send_response([
        'success' => true,
        'user' => [
            'id' => intval($exists['chat_id']),
            'user_name' => $exists['user_name'],
            'rights' => array_values($existing_rights),
            'enabled' => intval($exists['enabled']),
            'base_setup' => intval($exists['base_setup'] ?? 0),
        ]
    ], 201);
    exit;
    }

    $users_count = intval($mysqli->select_value('COUNT(*)', 'chat_users'));
    $base_setup = max(0, $users_count) * 10;

    $query = sprintf(
    "INSERT INTO chat_users (chat_id, user_name, rights, enabled, base_setup) VALUES (%d, '%s', '%s', %d, %d)",
    $id,
    $user_name_escaped,
    $mysqli->real_escape_string($rights_str),
    $enabled,
    $base_setup
    );

    if ($mysqli->try_query($query)) {
    send_response([
        'success' => true,
        'user' => [
            'id' => $id,
            'user_name' => $user_name,
            'rights' => $rights_input,
            'enabled' => $enabled,
            'base_setup' => $base_setup,
        ]
    ], 201);
    } else {
    send_error('Failed to create user', 500);
    }

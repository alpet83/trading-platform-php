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
    send_error('Method not allowed', 405);
    exit;
    }

    if (!isset($_POST['id']) || !isset($_POST['rights']) || !isset($_POST['enabled'])) {
    send_error('Missing required fields: id, rights, enabled', 400);
    exit;
    }

    $id = intval($_POST['id']);
    $rights_input = $_POST['rights'] ?? [];
    $enabled = intval($_POST['enabled']);
    $base_setup_input = $_POST['base_setup'] ?? null;

    if ($id <= 0) {
    send_error('Invalid id', 400);
    exit;
    }

    $valid_rights = ['view', 'trade', 'admin'];

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

    $user = $mysqli->select_row('chat_id, user_name, base_setup', 'chat_users', "WHERE chat_id = $id", MYSQLI_ASSOC);

    if (!$user) {
    send_error('User not found', 404);
    exit;
    }

    $rights_str = implode(',', $rights_input);

    if ($base_setup_input === null || $base_setup_input === '') {
    $base_setup = intval($user['base_setup'] ?? 0);
    } else {
    $base_setup = intval($base_setup_input);
    if ($base_setup < 0) {
        send_error('Invalid base_setup value. Must be >= 0', 400);
        exit;
    }
    }

    $query = sprintf(
    "UPDATE chat_users SET rights = '%s', enabled = %d, base_setup = %d WHERE chat_id = %d",
    $mysqli->real_escape_string($rights_str),
    $enabled,
    $base_setup,
    $id
    );

    if ($mysqli->try_query($query)) {
    send_response([
        'success' => true,
        'user' => [
            'id' => $id,
            'user_name' => $user['user_name'],
            'rights' => $rights_input,
            'enabled' => $enabled,
            'base_setup' => $base_setup,
        ]
    ]);
    } else {
    send_error('Failed to update user', 500);
    }

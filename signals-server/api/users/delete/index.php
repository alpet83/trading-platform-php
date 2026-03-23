<?php

require_once('../../../api_helper.php');

$user_rights = get_user_rights();

if (!str_in($user_rights, 'admin')) {
    send_error('Forbidden: only admin can access this resource', 403);
    exit;
}

$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('Database inaccessible', 500);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    send_error('Method not allowed', 405);
    exit;
}

if (!isset($_POST['id'])) {
    send_error('Missing required field: id', 400);
    exit;
}

$id = intval($_POST['id']);

if ($id <= 0) {
    send_error('Invalid id', 400);
    exit;
}

$user = $mysqli->select_row('chat_id', 'chat_users', "WHERE chat_id = $id", MYSQLI_ASSOC);

if (!$user) {
    send_error('User not found', 404);
    exit;
}

$query = sprintf(
    "DELETE FROM chat_users WHERE chat_id = %d",
    $id
);

if ($mysqli->try_query($query)) {
    send_response([
        'success' => true,
        'message' => 'User deleted'
    ]);
} else {
    send_error('Failed to delete user', 500);
}

<?php

declare(strict_types=1);

session_start();

$token = $_POST['csrf_token'] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    || !isset($_SESSION['user_id'], $_SESSION['csrf_token'])
    || !is_string($_SESSION['csrf_token'])
    || !is_string($token)
    || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    exit('Invalid request.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}

session_destroy();
header('Location: /', true, 303);
exit;

<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function start_camagru_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = in_array($_SERVER['HTTPS'] ?? null, ['on', '1'], true)
        || str_starts_with((string) getenv('APP_URL'), 'https://');

    session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_httponly' => 1,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $secure,
    ]);
}

function authentication_marker(string $email, string $verifiedAt, string $passwordHash): string
{
    return hash('sha256', $email . "\0" . $verifiedAt . "\0" . $passwordHash);
}

function authenticated_user(): bool
{
    start_camagru_session();

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (!isset($_SESSION['username'], $_SESSION['auth_marker'])
        || !is_int($_SESSION['user_id']) || !is_string($_SESSION['username'])
        || !is_string($_SESSION['auth_marker'])) {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['auth_marker'], $_SESSION['csrf_token']);
        return false;
    }

    try {
        $statement = database_connection()->prepare(
            'SELECT username, email, password_hash, email_verified_at FROM users WHERE id = :id'
        );
        $statement->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
        $statement->execute();
        $user = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException | RuntimeException) {
        return false;
    }

    if (!is_array($user) || $user['email_verified_at'] === null
        || !hash_equals($_SESSION['auth_marker'], authentication_marker(
            $user['email'], $user['email_verified_at'], $user['password_hash']
        ))) {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['auth_marker'], $_SESSION['csrf_token']);
        return false;
    }

    $_SESSION['username'] = $user['username'];
    return true;
}

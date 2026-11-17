<?php

declare(strict_types=1);

function send_email(string $to, string $subject, string $body): bool
{
    $sender = getenv('MAIL_FROM');
    if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
        || strpbrk($sender, "\r\n") !== false
        || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    return @mail($to, $subject, $body, [
        'From' => $sender,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
}

function application_base_url(): string|false
{
    $baseUrl = getenv('APP_URL');
    if (!is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($baseUrl);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['path']) && $parts['path'] !== '/')) {
        return false;
    }

    return rtrim($baseUrl, '/');
}

function send_verification_email(string $to, string $token): bool
{
    $baseUrl = application_base_url();
    if ($baseUrl === false) {
        return false;
    }

    $link = $baseUrl . '/verify_email.php?token=' . $token;
    $body = "Welcome to Camagru!\n\n"
        . "Open this link to verify your email address:\n{$link}\n\n"
        . "This link expires in 24 hours. If you did not create an account, ignore this email.\n";

    return send_email($to, 'Verify your Camagru account', $body);
}

function send_password_reset_email(string $to, string $token): bool
{
    $baseUrl = application_base_url();
    if ($baseUrl === false) {
        return false;
    }

    $link = $baseUrl . '/reset_password.php?token=' . $token;
    $body = "A password reset was requested for your Camagru account.\n\n"
        . "Open this link to set a new password:\n{$link}\n\n"
        . "This link expires in 1 hour. If you did not request a reset, ignore this email.\n";

    return send_email($to, 'Reset your Camagru password', $body);
}

function send_comment_notification_email(string $to, string $commenter, int $photoId, int $page): bool
{
    $baseUrl = application_base_url();
    if ($baseUrl === false) {
        return false;
    }

    $link = $baseUrl . ($page === 1 ? '/' : '/?page=' . $page) . '#photo-' . $photoId;
    $body = "{$commenter} commented on your Camagru photo.\n\n"
        . "View the photo and comment:\n{$link}\n";

    return send_email($to, 'New comment on your Camagru photo', $body);
}

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

function send_verification_email(string $to, string $token): bool
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

    $link = rtrim($baseUrl, '/') . '/verify_email.php?token=' . $token;
    $body = "Welcome to Camagru!\n\n"
        . "Open this link to verify your email address:\n{$link}\n\n"
        . "This link expires in 24 hours. If you did not create an account, ignore this email.\n";

    return send_email($to, 'Verify your Camagru account', $body);
}

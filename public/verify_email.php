<?php

declare(strict_types=1);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
http_response_code(400);

require_once __DIR__ . '/../src/database.php';

$verified = false;
$message = 'This verification link is invalid, expired, or has already been used.';
$token = $_GET['token'] ?? null;

if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
    $database = null;
    try {
        $database = database_connection();
        $database->beginTransaction();
        $tokenHash = hash('sha256', $token);

        $statement = $database->prepare(
            'SELECT user_id FROM email_verification_tokens WHERE token_hash = :token_hash'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $userId = $statement->fetchColumn();

        if ($userId !== false) {
            $statement = $database->prepare('SELECT email_verified_at FROM users WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => (int) $userId]);
            $user = $statement->fetch();

            if (is_array($user) && $user['email_verified_at'] === null) {
                $statement = $database->prepare(
                    'SELECT user_id FROM email_verification_tokens
                     WHERE user_id = :user_id AND token_hash = :token_hash
                       AND expires_at > clock_timestamp()
                     FOR UPDATE'
                );
                $statement->execute(['user_id' => (int) $userId, 'token_hash' => $tokenHash]);

                if ($statement->fetchColumn() !== false) {
                    $statement = $database->prepare(
                        'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND email_verified_at IS NULL'
                    );
                    $statement->execute(['id' => (int) $userId]);
                    if ($statement->rowCount() !== 1) {
                        throw new RuntimeException('Verification update failed.');
                    }

                    $statement = $database->prepare(
                        'DELETE FROM email_verification_tokens
                         WHERE user_id = :user_id AND token_hash = :token_hash'
                    );
                    $statement->execute(['user_id' => (int) $userId, 'token_hash' => $tokenHash]);
                    if ($statement->rowCount() !== 1) {
                        throw new RuntimeException('Verification token removal failed.');
                    }

                    $database->commit();
                    $verified = true;
                    $message = 'Your email is verified. You can now log in.';
                    http_response_code(200);
                }
            }
        }

        if ($database->inTransaction()) {
            $database->rollBack();
        }
    } catch (Throwable) {
        if ($database instanceof PDO && $database->inTransaction()) {
            $database->rollBack();
        }
        $message = 'Email verification is unavailable right now. Please try again later.';
        http_response_code(503);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify email | Camagru</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/nav.js" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="/" aria-label="Camagru home">
                <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 7h3l1.5-2h7L17 7h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z" />
                    <circle cx="12" cy="14" r="4" />
                </svg>
                <strong>Camagru</strong>
            </a>
            <button class="menu-toggle" type="button" aria-controls="main-navigation" aria-expanded="false" aria-label="Open menu"><span></span><span></span><span></span></button>
            <nav class="site-nav" id="main-navigation" aria-label="Main navigation">
                <div class="nav-links">
                    <a href="/"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="8" cy="9" r="1.5" /><path d="m3 17 5-5 4 4 3-3 6 6" /></svg>Gallery</a>
                    <a href="/login.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" /><path d="M3 12h13m-4-4 4 4-4 4" /></svg>Login</a>
                </div>
            </nav>
        </div>
    </header>

    <main class="registration-page">
        <div class="register-heading">
            <span class="register-pill">Camagru account</span>
            <h1>Email verification</h1>
        </div>
        <section class="register-panel" aria-label="Verification result">
            <?php if ($verified): ?>
                <div class="registration-success" role="status">
                    <h2>Email verified</h2>
                    <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <a class="back-link" href="/login.php">Go to login</a>
                </div>
            <?php else: ?>
                <p class="form-message-error" role="alert"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p class="auth-switch"><a href="/login.php">Go to login</a></p>
            <?php endif; ?>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <span class="footer-brand">Camagru</span>
            <span>A simple app to create, customize and share your photos.</span>
            <span>Made for creative moments.</span>
        </div>
    </footer>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
header('Cache-Control: no-store');

if (authenticated_user()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../src/email.php';

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$email = '';
$error = '';
$notice = $_SESSION['password_reset_notice'] ?? null;
unset($_SESSION['password_reset_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tokenInput = $_POST['csrf_token'] ?? null;
    $emailInput = $_POST['email'] ?? null;
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';

    if (!is_string($tokenInput) || !hash_equals($_SESSION['csrf_token'], $tokenInput)) {
        $error = 'Your session expired. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address.';
    } else {
        // Keep the response identical for known and unknown addresses, including mail failures.
        $_SESSION['password_reset_notice'] = 'Request received. If this address has an account, check your inbox for a reset link. If none arrives, try again later. Please wait one minute between requests.';
        $database = null;

        try {
            $database = database_connection();
            $database->beginTransaction();

            $statement = $database->prepare('SELECT id, email FROM users WHERE email = :email FOR UPDATE');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if (is_array($user)) {
                $token = bin2hex(random_bytes(32));
                $statement = $database->prepare(
                    "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                     VALUES (:user_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL '1 hour')
                     ON CONFLICT (user_id) DO UPDATE SET
                         token_hash = EXCLUDED.token_hash,
                         expires_at = EXCLUDED.expires_at,
                         created_at = CURRENT_TIMESTAMP
                     WHERE password_reset_tokens.created_at <= CURRENT_TIMESTAMP - INTERVAL '60 seconds'
                     RETURNING user_id"
                );
                $statement->execute([
                    'user_id' => (int) $user['id'],
                    'token_hash' => hash('sha256', $token),
                ]);

                if ($statement->fetchColumn() !== false
                    && !send_password_reset_email($user['email'], $token)) {
                    throw new RuntimeException('Email delivery failed.');
                }
            }

            $database->commit();
        } catch (Throwable) {
            if ($database instanceof PDO) {
                try {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                } catch (Throwable) {
                    // The connection may already be unavailable.
                }
            }
        }

        header('Location: /forgot_password.php', true, 303);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password | Camagru</title>
    <link rel="stylesheet" href="/assets/css/style.css">
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
            <nav class="site-nav" aria-label="Main navigation">
                <div class="nav-links">
                    <a href="/"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="8" cy="9" r="1.5" /><path d="m3 17 5-5 4 4 3-3 6 6" /></svg>Gallery</a>
                    <a href="/login.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" /><path d="M3 12h13m-4-4 4 4-4 4" /></svg>Login</a>
                </div>
            </nav>
        </div>
    </header>

    <main class="registration-page">
        <div class="register-heading">
            <span class="register-pill">Account access</span>
            <h1>Forgot your password?</h1>
            <p>Enter your email address to request a reset link.</p>
        </div>

        <section class="register-panel" aria-label="Request password reset">
            <?php if ($error !== ''): ?>
                <p class="form-message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if (is_string($notice) && $notice !== ''): ?>
                <p class="form-message-notice" role="status"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>

            <form method="post" action="/forgot_password.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="form-field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" required maxlength="254" value="<?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
                <button class="submit-button" type="submit">Send reset link</button>
            </form>
            <p class="auth-switch"><a href="/login.php">Back to login</a></p>
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

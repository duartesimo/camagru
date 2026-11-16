<?php

declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'], $_SESSION['username'])
    && is_int($_SESSION['user_id'])
    && is_string($_SESSION['username'])) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/email.php';

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$identity = '';
$resendEmail = '';
$error = '';
$resendNotice = $_SESSION['resend_notice'] ?? '';
unset($_SESSION['resend_notice']);
$emailChangeNotice = $_SESSION['email_change_notice'] ?? false;
unset($_SESSION['email_change_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tokenInput = $_POST['csrf_token'] ?? null;
    $formAction = $_POST['form_action'] ?? 'login';

    if (!is_string($tokenInput) || !hash_equals($_SESSION['csrf_token'], $tokenInput)) {
        $error = 'Your session expired. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } elseif ($formAction === 'resend') {
        $emailInput = $_POST['resend_email'] ?? null;
        $resendEmail = is_string($emailInput) ? strtolower(trim($emailInput)) : '';

        if (strlen($resendEmail) > 254 || filter_var($resendEmail, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Enter a valid email address to request a verification link.';
        } else {
            // Use the same neutral notice for every valid request. A distinct mail
            // error would reveal which addresses have unverified accounts.
            $_SESSION['resend_notice'] = 'Request received. If this address has an unverified account, check your inbox for a link. If none arrives, try again later. Please wait one minute between requests.';
            $database = null;
            try {
                $database = database_connection();
                $database->beginTransaction();

                $statement = $database->prepare(
                    'SELECT id, email FROM users
                     WHERE email = :email AND email_verified_at IS NULL
                     FOR UPDATE'
                );
                $statement->execute(['email' => $resendEmail]);
                $user = $statement->fetch();

                if (is_array($user)) {
                    $token = bin2hex(random_bytes(32));
                    $statement = $database->prepare(
                        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                         VALUES (:user_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL '24 hours')
                         ON CONFLICT (user_id) DO UPDATE SET
                             token_hash = EXCLUDED.token_hash,
                             expires_at = EXCLUDED.expires_at,
                             created_at = CURRENT_TIMESTAMP
                         WHERE email_verification_tokens.created_at <= CURRENT_TIMESTAMP - INTERVAL '60 seconds'
                         RETURNING user_id"
                    );
                    $statement->execute([
                        'user_id' => (int) $user['id'],
                        'token_hash' => hash('sha256', $token),
                    ]);

                    if ($statement->fetchColumn() !== false
                        && !send_verification_email($user['email'], $token)) {
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

            header('Location: /login.php', true, 303);
            exit;
        }
    } else {
        $identityInput = $_POST['identity'] ?? null;
        $passwordInput = $_POST['password'] ?? null;
        $identity = is_string($identityInput) ? trim($identityInput) : '';
        $password = is_string($passwordInput) ? $passwordInput : '';

        if ($identity === '' || $password === '') {
            $error = 'Invalid email, username or password.';
        } else {
            try {
                $statement = database_connection()->prepare(
                    'SELECT id, username, password_hash, email_verified_at
                     FROM users
                     WHERE username = :username OR email = :email
                     LIMIT 1'
                );
                $statement->execute([
                    'username' => $identity,
                    'email' => strtolower($identity),
                ]);
                $user = $statement->fetch();

                if (is_array($user) && password_verify($password, $user['password_hash'])) {
                    if ($user['email_verified_at'] === null) {
                        $error = 'Verify your email before logging in. You can request a new link below.';
                    } else {
                        if (!session_regenerate_id(true)) {
                            throw new RuntimeException('Could not renew the session.');
                        }

                        $_SESSION['user_id'] = (int) $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        header('Location: /', true, 303);
                        exit;
                    }
                } else {
                    $error = 'Invalid email, username or password.';
                }
            } catch (PDOException | RuntimeException) {
                $error = 'Login is unavailable right now. Please try again later.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Log in to your Camagru account.">
    <title>Login | Camagru</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="/" aria-label="Camagru home">
                <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 7h3l1.5-2h7L17 7h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/>
                    <circle cx="12" cy="14" r="4"/>
                </svg>
                <strong>Camagru</strong>
            </a>
            <nav class="site-nav" aria-label="Main navigation">
                <a href="/">Gallery</a>
                <a class="nav-active" href="/login.php" aria-current="page">Login</a>
                <a href="/register.php">Register</a>
            </nav>
        </div>
    </header>

    <main class="registration-page">
        <div class="register-heading">
            <span class="register-pill">Welcome back</span>
            <h1>Log in to Camagru</h1>
            <p>Enter your username or email and password to continue.</p>
        </div>

        <section class="register-panel" aria-label="Login">
            <?php if ($emailChangeNotice === true): ?>
                <p class="form-message-notice" role="status">Your email address changed. Verify the new address before logging in again.</p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="form-message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if (is_string($resendNotice) && $resendNotice !== ''): ?>
                <p class="form-message-notice" role="status"><?= htmlspecialchars($resendNotice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>

            <form method="post" action="/login.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-field">
                    <label for="identity">Email or username</label>
                    <input id="identity" name="identity" type="text" autocomplete="username" required maxlength="254" value="<?= htmlspecialchars($identity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>

                <div class="form-field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>

                <button class="submit-button" type="submit">Log in</button>
            </form>
            <p class="auth-switch"><a href="/forgot_password.php">Forgot your password?</a></p>
            <p class="auth-switch">Don't have an account? <a href="/register.php">Sign up</a></p>

            <section class="resend-verification" aria-labelledby="resend-title">
                <h2 id="resend-title">Need another verification link?</h2>
                <form method="post" action="/login.php">
                    <input type="hidden" name="form_action" value="resend">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div class="form-field">
                        <label for="resend-email">Email address</label>
                        <input id="resend-email" name="resend_email" type="email" autocomplete="email" required maxlength="254" value="<?= htmlspecialchars($resendEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <button class="submit-button" type="submit">Resend verification email</button>
                </form>
            </section>
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

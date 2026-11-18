<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
start_camagru_session();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/../src/database.php';

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$resetComplete = $_SESSION['password_reset_success'] ?? false;
unset($_SESSION['password_reset_success']);
$token = $_GET['token'] ?? null;
$tokenValid = false;
$serviceUnavailable = false;
$errors = [];
$database = null;
$tokenHash = '';

if (!$resetComplete && is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
    $tokenHash = hash('sha256', $token);
    try {
        $database = database_connection();
        $statement = $database->prepare(
            'SELECT user_id FROM password_reset_tokens
             WHERE token_hash = :token_hash AND expires_at > clock_timestamp()'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $tokenValid = $statement->fetchColumn() !== false;
    } catch (Throwable) {
        $serviceUnavailable = true;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $tokenValid) {
    $tokenInput = $_POST['csrf_token'] ?? null;
    $passwordInput = $_POST['password'] ?? null;
    $confirmationInput = $_POST['password_confirmation'] ?? null;
    $password = is_string($passwordInput) ? $passwordInput : '';
    $confirmation = is_string($confirmationInput) ? $confirmationInput : '';

    if (!is_string($tokenInput) || !hash_equals($_SESSION['csrf_token'], $tokenInput)) {
        $errors['form'] = 'Your session expired. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        if (
            strlen($password) < 12 || strlen($password) > 72
            || str_contains($password, "\0")
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1
            || preg_match('/[^A-Za-z0-9]/', $password) !== 1
        ) {
            $errors['password'] = 'Use 12 to 72 bytes with uppercase and lowercase letters, a number, and a symbol.';
        }

        if ($confirmation === '' || $password !== $confirmation) {
            $errors['password_confirmation'] = 'Passwords must match.';
        }
    }

    if ($errors === []) {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Password hashing failed.');
            }

            $database->beginTransaction();
            $statement = $database->prepare(
                'SELECT user_id FROM password_reset_tokens WHERE token_hash = :token_hash'
            );
            $statement->execute(['token_hash' => $tokenHash]);
            $userId = $statement->fetchColumn();

            if ($userId !== false) {
                // Lock the user first, as the reset request page does before replacing a token.
                $statement = $database->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
                $statement->execute(['id' => (int) $userId]);

                if ($statement->fetchColumn() !== false) {
                    $statement = $database->prepare(
                        'SELECT user_id FROM password_reset_tokens
                         WHERE user_id = :user_id AND token_hash = :token_hash
                           AND expires_at > clock_timestamp()
                         FOR UPDATE'
                    );
                    $statement->execute(['user_id' => (int) $userId, 'token_hash' => $tokenHash]);

                    if ($statement->fetchColumn() !== false) {
                        $statement = $database->prepare(
                            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
                        );
                        $statement->execute(['password_hash' => $passwordHash, 'id' => (int) $userId]);
                        if ($statement->rowCount() !== 1) {
                            throw new RuntimeException('Password update failed.');
                        }

                        $statement = $database->prepare(
                            'DELETE FROM password_reset_tokens
                             WHERE user_id = :user_id AND token_hash = :token_hash'
                        );
                        $statement->execute(['user_id' => (int) $userId, 'token_hash' => $tokenHash]);
                        if ($statement->rowCount() !== 1) {
                            throw new RuntimeException('Reset token removal failed.');
                        }

                        $database->commit();
                        $_SESSION['password_reset_success'] = true;
                        header('Location: /reset_password.php', true, 303);
                        exit;
                    }
                }
            }

            $database->rollBack();
            $tokenValid = false;
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
            $serviceUnavailable = true;
        }
    }
}

if ($serviceUnavailable) {
    http_response_code(503);
} elseif (!$resetComplete && !$tokenValid) {
    http_response_code(400);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password | Camagru</title>
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
            <h1>Reset your password</h1>
            <p>Choose a new password for your Camagru account.</p>
        </div>

        <section class="register-panel" aria-label="Reset password">
            <?php if ($resetComplete): ?>
                <div class="registration-success" role="status">
                    <h2>Password updated</h2>
                    <p>You can now log in with your new password.</p>
                    <a class="back-link" href="/login.php">Go to login</a>
                </div>
            <?php elseif ($serviceUnavailable): ?>
                <p class="form-message-error" role="alert">Password reset is unavailable right now. Please try again later.</p>
                <p class="auth-switch"><a href="/forgot_password.php">Request another link</a></p>
            <?php elseif (!$tokenValid): ?>
                <p class="form-message-error" role="alert">This reset link is invalid, expired, or has already been used.</p>
                <p class="auth-switch"><a href="/forgot_password.php">Request another link</a></p>
            <?php else: ?>
                <?php if (isset($errors['form'])): ?>
                    <p class="form-message-error" role="alert"><?= htmlspecialchars($errors['form'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>

                <form method="post" action="/reset_password.php?token=<?= htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                    <div class="form-field">
                        <label for="password">New password</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>" <?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
                        <p class="field-hint" id="password-hint">12–72 bytes, with uppercase and lowercase letters, a number and a symbol.</p>
                        <?php if (isset($errors['password'])): ?>
                            <p class="field-error" id="password-error"><?= htmlspecialchars($errors['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="password_confirmation">Confirm new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required <?= isset($errors['password_confirmation']) ? ' aria-invalid="true" aria-describedby="confirmation-error"' : '' ?>>
                        <?php if (isset($errors['password_confirmation'])): ?>
                            <p class="field-error" id="confirmation-error"><?= htmlspecialchars($errors['password_confirmation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <button class="submit-button" type="submit">Update password</button>
                </form>
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

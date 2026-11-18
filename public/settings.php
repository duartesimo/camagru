<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

if (!authenticated_user()) {
    header('Location: /login.php');
    exit;
}

header('Cache-Control: no-store');

require_once __DIR__ . '/../src/email.php';

function end_current_session(): void
{
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
}

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = $_SESSION['user_id'];
$database = null;
$user = null;
$loadError = false;
$errors = [];
$notice = $_SESSION['settings_notice'] ?? null;
unset($_SESSION['settings_notice']);

try {
    $database = database_connection();
    $statement = $database->prepare(
        'SELECT username, email, email_notifications_enabled::int AS notifications_enabled
         FROM users WHERE id = :id'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();
} catch (PDOException | RuntimeException) {
    $loadError = true;
}

if (!$loadError && !is_array($user)) {
    end_current_session();
    header('Location: /login.php', true, 303);
    exit;
}

$usernameValue = is_array($user) ? $user['username'] : '';
$emailValue = is_array($user) ? $user['email'] : '';
$notificationsEnabled = is_array($user) && (int) $user['notifications_enabled'] === 1;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$loadError) {
    $csrfInput = $_POST['csrf_token'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!is_string($csrfInput) || !hash_equals($_SESSION['csrf_token'], $csrfInput)) {
        $errors['form'] = 'Your session expired. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } elseif ($action === 'username') {
        $input = $_POST['username'] ?? null;
        $usernameValue = is_string($input) ? trim($input) : '';

        if (preg_match('/\A[A-Za-z0-9_]{3,30}\z/', $usernameValue) !== 1) {
            $errors['username'] = 'Use 3 to 30 letters, numbers, or underscores.';
        } elseif ($usernameValue === $user['username']) {
            $notice = 'Your username is unchanged.';
        } else {
            try {
                $statement = $database->prepare('UPDATE users SET username = :username WHERE id = :id');
                $statement->execute(['username' => $usernameValue, 'id' => $userId]);
                $_SESSION['username'] = $usernameValue;
                $_SESSION['settings_notice'] = 'Username updated.';
                header('Location: /settings.php', true, 303);
                exit;
            } catch (Throwable $exception) {
                $errors['username'] = $exception instanceof PDOException && $exception->getCode() === '23505'
                    ? 'That username is already in use.'
                    : 'Could not update your username. Please try again later.';
            }
        }
    } elseif ($action === 'email') {
        $input = $_POST['email'] ?? null;
        $passwordInput = $_POST['current_email_password'] ?? null;
        $emailValue = is_string($input) ? strtolower(trim($input)) : '';
        $currentPassword = is_string($passwordInput) ? $passwordInput : '';

        if (strlen($emailValue) > 254 || filter_var($emailValue, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($currentPassword === '') {
            $errors['email_password'] = 'Enter your current password.';
        }

        if ($errors === []) {
            $emailChanged = false;
            try {
                $database->beginTransaction();
                $statement = $database->prepare(
                    'SELECT email, email_verified_at, password_hash FROM users WHERE id = :id FOR UPDATE'
                );
                $statement->execute(['id' => $userId]);
                $currentUser = $statement->fetch();
                if (!is_array($currentUser) || $currentUser['email_verified_at'] === null) {
                    throw new RuntimeException('Account unavailable.');
                }

                if (!password_verify($currentPassword, $currentUser['password_hash'])) {
                    $database->rollBack();
                    $errors['email_password'] = 'Current password is incorrect.';
                } elseif ($emailValue === $currentUser['email']) {
                    $database->rollBack();
                    $notice = 'Your email address is unchanged.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $statement = $database->prepare(
                        'UPDATE users SET email = :email, email_verified_at = NULL WHERE id = :id'
                    );
                    $statement->execute(['email' => $emailValue, 'id' => $userId]);

                    $statement = $database->prepare(
                        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                         VALUES (:user_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL '24 hours')
                         ON CONFLICT (user_id) DO UPDATE SET
                             token_hash = EXCLUDED.token_hash,
                             expires_at = EXCLUDED.expires_at,
                             created_at = CURRENT_TIMESTAMP"
                    );
                    $statement->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token)]);

                    $statement = $database->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
                    $statement->execute(['user_id' => $userId]);

                    if (!send_verification_email($emailValue, $token)) {
                        throw new RuntimeException('Email delivery failed.');
                    }

                    $database->commit();
                    $emailChanged = true;
                }
            } catch (Throwable $exception) {
                try {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                } catch (Throwable) {
                    // The connection may already be unavailable.
                }
                $errors['email'] = $exception instanceof PDOException && $exception->getCode() === '23505'
                    ? 'That email address is already registered.'
                    : 'Could not change your email or send its verification link. Please try again later.';
            }

            if ($emailChanged) {
                $_SESSION = [];
                if (!session_regenerate_id(true)) {
                    end_current_session();
                } else {
                    $_SESSION['email_change_notice'] = true;
                }
                header('Location: /login.php', true, 303);
                exit;
            }
        }
    } elseif ($action === 'password') {
        $currentInput = $_POST['current_password'] ?? null;
        $passwordInput = $_POST['password'] ?? null;
        $confirmationInput = $_POST['password_confirmation'] ?? null;
        $currentPassword = is_string($currentInput) ? $currentInput : '';
        $password = is_string($passwordInput) ? $passwordInput : '';
        $confirmation = is_string($confirmationInput) ? $confirmationInput : '';

        if ($currentPassword === '') {
            $errors['current_password'] = 'Enter your current password.';
        }
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

        if ($errors === []) {
            $passwordChanged = false;
            try {
                $database->beginTransaction();
                $statement = $database->prepare(
                    'SELECT email, email_verified_at, password_hash FROM users WHERE id = :id FOR UPDATE'
                );
                $statement->execute(['id' => $userId]);
                $currentUser = $statement->fetch();
                if (!is_array($currentUser) || $currentUser['email_verified_at'] === null) {
                    throw new RuntimeException('Account unavailable.');
                }

                if (!password_verify($currentPassword, $currentUser['password_hash'])) {
                    $database->rollBack();
                    $errors['current_password'] = 'Current password is incorrect.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    if ($passwordHash === false) {
                        throw new RuntimeException('Password hashing failed.');
                    }

                    $statement = $database->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                    $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);

                    $statement = $database->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
                    $statement->execute(['user_id' => $userId]);

                    $database->commit();
                    $passwordChanged = true;
                }
            } catch (Throwable) {
                try {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                } catch (Throwable) {
                    // The connection may already be unavailable.
                }
                $errors['form'] = 'Could not update your password. Please try again later.';
            }

            if ($passwordChanged) {
                if (!session_regenerate_id(true)) {
                    end_current_session();
                    header('Location: /login.php', true, 303);
                    exit;
                }
                $_SESSION['auth_marker'] = authentication_marker(
                    $currentUser['email'], $currentUser['email_verified_at'], $passwordHash
                );
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['settings_notice'] = 'Password updated.';
                header('Location: /settings.php', true, 303);
                exit;
            }
        }
    } elseif ($action === 'notifications') {
        $enabledInput = $_POST['email_notifications_enabled'] ?? null;
        if ($enabledInput !== null && $enabledInput !== '1') {
            $errors['notifications'] = 'Choose a valid notification preference.';
        } else {
            $enabled = $enabledInput === '1';
            if ($enabled === $notificationsEnabled) {
                $notice = 'Your notification preference is unchanged.';
            } else {
                try {
                    $statement = $database->prepare(
                        'UPDATE users SET email_notifications_enabled = :enabled WHERE id = :id'
                    );
                    $statement->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
                    $statement->bindValue(':id', $userId, PDO::PARAM_INT);
                    $statement->execute();
                    $_SESSION['settings_notice'] = 'Notification preference updated.';
                    header('Location: /settings.php', true, 303);
                    exit;
                } catch (Throwable) {
                    $errors['notifications'] = 'Could not update your preference. Please try again later.';
                }
            }
        }
    } else {
        $errors['form'] = 'Invalid settings request.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings | Camagru</title>
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
                    <a href="/editor.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l1.5-2h7L17 7h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z" /><circle cx="12" cy="14" r="4" /></svg>Editor</a>
                    <a class="nav-active" href="/settings.php" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.2 2h3.6l.5 2.2 1.5.6 1.9-1.2 2.5 2.5-1.2 1.9.6 1.5 2.2.5v3.6l-2.2.5-.6 1.5 1.2 1.9-2.5 2.5-1.9-1.2-1.5.6-.5 2.2h-3.6l-.5-2.2-1.5-.6-1.9 1.2-2.5-2.5 1.2-1.9-.6-1.5L2 13.8v-3.6l2.2-.5.6-1.5-1.2-1.9 2.5-2.5 1.9 1.2 1.5-.6L10.2 2Z" /><circle cx="12" cy="12" r="3" /></svg>Settings</a>
                </div>
                <div class="nav-account">
                    <span class="nav-username"><span class="nav-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5" /><path d="M5 21v-1a7 7 0 0 1 14 0v1" /></svg></span><strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
                    <form class="logout-form" action="/logout.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <button class="logout-button" type="submit" aria-label="Log out"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4" /><path d="m15 7 5 5-5 5M20 12H9" /></svg></button>
                    </form>
                </div>
            </nav>
        </div>
    </header>

    <main class="registration-page">
        <div class="register-heading">
            <span class="register-pill">Your account</span>
            <h1>Settings</h1>
            <p>Manage your account details and email preferences.</p>
        </div>

        <div class="register-panel">
            <?php if ($loadError): ?>
                <p class="form-message-error" role="alert">Settings are unavailable right now. Please try again later.</p>
            <?php else: ?>
                <?php if (is_string($notice) && $notice !== ''): ?>
                    <p class="form-message-notice" role="status"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (isset($errors['form'])): ?>
                    <p class="form-message-error" role="alert"><?= htmlspecialchars($errors['form'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>

                <section class="settings-section" aria-labelledby="username-title">
                    <h2 id="username-title">Username</h2>
                    <p class="settings-current">Current: <?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <form method="post" action="/settings.php">
                        <input type="hidden" name="action" value="username">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div class="form-field">
                            <label for="username">New username</label>
                            <input id="username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="30" value="<?= htmlspecialchars($usernameValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= isset($errors['username']) ? ' aria-invalid="true" aria-describedby="username-error"' : '' ?>>
                            <?php if (isset($errors['username'])): ?>
                                <p class="field-error" id="username-error"><?= htmlspecialchars($errors['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <button class="submit-button" type="submit">Save username</button>
                    </form>
                </section>

                <section class="settings-section" aria-labelledby="email-title">
                    <h2 id="email-title">Email address</h2>
                    <p class="settings-current">Current: <?= htmlspecialchars($user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <form method="post" action="/settings.php">
                        <input type="hidden" name="action" value="email">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div class="form-field">
                            <label for="email">New email address</label>
                            <input id="email" name="email" type="email" autocomplete="email" required maxlength="254" value="<?= htmlspecialchars($emailValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= isset($errors['email']) ? ' aria-invalid="true" aria-describedby="email-error"' : '' ?>>
                            <?php if (isset($errors['email'])): ?>
                                <p class="field-error" id="email-error"><?= htmlspecialchars($errors['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-field">
                            <label for="current-email-password">Current password</label>
                            <input id="current-email-password" name="current_email_password" type="password" autocomplete="current-password" required <?= isset($errors['email_password']) ? ' aria-invalid="true" aria-describedby="email-password-error"' : '' ?>>
                            <?php if (isset($errors['email_password'])): ?>
                                <p class="field-error" id="email-password-error"><?= htmlspecialchars($errors['email_password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <button class="submit-button" type="submit">Change email</button>
                    </form>
                </section>

                <section class="settings-section" aria-labelledby="password-title">
                    <h2 id="password-title">Password</h2>
                    <form method="post" action="/settings.php">
                        <input type="hidden" name="action" value="password">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div class="form-field">
                            <label for="current-password">Current password</label>
                            <input id="current-password" name="current_password" type="password" autocomplete="current-password" required <?= isset($errors['current_password']) ? ' aria-invalid="true" aria-describedby="current-password-error"' : '' ?>>
                            <?php if (isset($errors['current_password'])): ?>
                                <p class="field-error" id="current-password-error"><?= htmlspecialchars($errors['current_password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-field">
                            <label for="password">New password</label>
                            <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" aria-describedby="settings-password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>" <?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
                            <p class="field-hint" id="settings-password-hint">12–72 bytes, with uppercase and lowercase letters, a number and a symbol.</p>
                            <?php if (isset($errors['password'])): ?>
                                <p class="field-error" id="password-error"><?= htmlspecialchars($errors['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-field">
                            <label for="password-confirmation">Confirm new password</label>
                            <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required <?= isset($errors['password_confirmation']) ? ' aria-invalid="true" aria-describedby="confirmation-error"' : '' ?>>
                            <?php if (isset($errors['password_confirmation'])): ?>
                                <p class="field-error" id="confirmation-error"><?= htmlspecialchars($errors['password_confirmation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <button class="submit-button" type="submit">Change password</button>
                    </form>
                </section>

                <section class="settings-section" aria-labelledby="notifications-title">
                    <h2 id="notifications-title">Comment notifications</h2>
                    <form method="post" action="/settings.php">
                        <input type="hidden" name="action" value="notifications">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <label class="settings-checkbox" for="email-notifications">
                            <input id="email-notifications" name="email_notifications_enabled" type="checkbox" value="1" <?= $notificationsEnabled ? 'checked' : '' ?>>
                            Email me when someone comments on my photos
                        </label>
                        <?php if (isset($errors['notifications'])): ?>
                            <p class="field-error" role="alert"><?= htmlspecialchars($errors['notifications'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <button class="submit-button" type="submit">Save preference</button>
                    </form>
                </section>
            <?php endif; ?>
        </div>
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

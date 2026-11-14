<?php

declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../src/database.php';

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$username = '';
$email = '';
$success = $_SESSION['registration_success'] ?? null;
unset($_SESSION['registration_success']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $usernameInput = $_POST['username'] ?? null;
    $emailInput = $_POST['email'] ?? null;
    $passwordInput = $_POST['password'] ?? null;
    $confirmationInput = $_POST['password_confirmation'] ?? null;
    $tokenInput = $_POST['csrf_token'] ?? null;

    $username = is_string($usernameInput) ? trim($usernameInput) : '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $confirmation = is_string($confirmationInput) ? $confirmationInput : '';

    if (!is_string($tokenInput) || !hash_equals($_SESSION['csrf_token'], $tokenInput)) {
        $errors['form'] = 'Your session expired. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        if (preg_match('/\A[A-Za-z0-9_]{3,30}\z/', $username) !== 1) {
            $errors['username'] = 'Use 3 to 30 letters, numbers, or underscores.';
        }

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
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
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if ($passwordHash === false) {
                    throw new RuntimeException('Password hashing failed.');
                }

                $statement = database_connection()->prepare(
                    'INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)'
                );
                $statement->execute([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                ]);

                $_SESSION['registration_success'] = 'Account created successfully. Your email is not verified yet.';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: /register.php', true, 303);
                exit;
            } catch (PDOException $exception) {
                $errors['form'] = $exception->getCode() === '23505'
                    ? 'That username or email is already registered.'
                    : 'Registration is unavailable right now. Please try again later.';
            } catch (RuntimeException) {
                $errors['form'] = 'Registration is unavailable right now. Please try again later.';
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
    <meta name="description" content="Create your Camagru account and share your photos.">
    <title>Register | Camagru</title>
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
                <a href="/">Gallery</a>
                <a href="/login.php">Login</a>
                <a class="nav-active" href="/register.php" aria-current="page">Register</a>
            </nav>
        </div>
    </header>

    <main class="registration-page">
        <div class="register-heading">
            <span class="register-pill">Join Camagru</span>
            <h1>Create your account</h1>
            <p>Sign up to create fun photos, add effects, and share your creations with the community.</p>
        </div>

        <section class="register-panel" aria-label="Registration">
            <?php if (is_string($success)): ?>
                <div class="registration-success" role="status">
                    <span class="success-icon" aria-hidden="true">✓</span>
                    <h2>Account created!</h2>
                    <p><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <a href="/" class="back-link">Back to gallery</a>
                </div>
            <?php else: ?>
                <?php if (isset($errors['form'])): ?>
                    <p class="form-message-error" role="alert"><?= htmlspecialchars($errors['form'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>

                <form method="post" action="/register.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                    <div class="form-field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="30" placeholder="Choose a username" value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= isset($errors['username']) ? ' aria-invalid="true" aria-describedby="username-error"' : '' ?>>
                        <?php if (isset($errors['username'])): ?>
                            <p class="field-error" id="username-error"><?= htmlspecialchars($errors['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" autocomplete="email" required maxlength="254" placeholder="Enter your email address" value="<?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= isset($errors['email']) ? ' aria-invalid="true" aria-describedby="email-error"' : '' ?>>
                        <?php if (isset($errors['email'])): ?>
                            <p class="field-error" id="email-error"><?= htmlspecialchars($errors['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" placeholder="Create a strong password" aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>" <?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
                        <p class="field-hint" id="password-hint">12–72 bytes, with uppercase and lowercase letters, a number and a symbol.</p>
                        <?php if (isset($errors['password'])): ?>
                            <p class="field-error" id="password-error"><?= htmlspecialchars($errors['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required placeholder="Confirm your password" <?= isset($errors['password_confirmation']) ? ' aria-invalid="true" aria-describedby="confirmation-error"' : '' ?>>
                        <?php if (isset($errors['password_confirmation'])): ?>
                            <p class="field-error" id="confirmation-error"><?= htmlspecialchars($errors['password_confirmation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <button class="submit-button" type="submit">Create account</button>
                </form>
                <p class="auth-switch">Already have an account? <a href="/login.php">Log in</a></p>
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

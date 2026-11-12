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

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$identity = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $identityInput = $_POST['identity'] ?? null;
    $passwordInput = $_POST['password'] ?? null;
    $tokenInput = $_POST['csrf_token'] ?? null;

    $identity = is_string($identityInput) ? trim($identityInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';

    if (!is_string($tokenInput) || !hash_equals($_SESSION['csrf_token'], $tokenInput)) {
        $error = 'Your session expired. Please try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } elseif ($identity === '' || $password === '') {
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

            // TODO: Require email_verified_at to be non-NULL when email verification is implemented.
            if (is_array($user) && password_verify($password, $user['password_hash'])) {
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('Could not renew the session.');
                }

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: /', true, 303);
                exit;
            }

            $error = 'Invalid email, username or password.';
        } catch (PDOException | RuntimeException) {
            $error = 'Login is unavailable right now. Please try again later.';
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
            <?php if ($error !== ''): ?>
                <p class="form-message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
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

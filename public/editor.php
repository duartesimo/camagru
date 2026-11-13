<?php

declare(strict_types=1);

session_start();

$isLoggedIn = isset($_SESSION['user_id'], $_SESSION['username'])
    && is_int($_SESSION['user_id'])
    && is_string($_SESSION['username']);

if (!$isLoggedIn) {
    header('Location: /login.php');
    exit;
}

header('Cache-Control: no-store');

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Capture a webcam photo with a Camagru overlay.">
    <title>Editor | Camagru</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <script src="/assets/js/editor.js" defer></script>
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
                <a class="nav-active" href="/editor.php" aria-current="page">Editor</a>
                <span class="nav-username">
                    Welcome, <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                </span>
                <form class="logout-form" action="/logout.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <button class="logout-button" type="submit">Logout</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="container editor-page">
        <div class="editor-heading">
            <span class="register-pill">Photo editor</span>
            <h1>Camera preview</h1>
            <p>Turn on your camera, choose an overlay, and take a photo.</p>
        </div>

        <section class="editor-panel" aria-labelledby="camera-title">
            <h2 id="camera-title">Live camera</h2>
            <div class="camera-frame">
                <video id="camera-preview" autoplay muted playsinline aria-label="Live webcam preview"></video>
                <img class="camera-overlay" id="camera-overlay" alt="" hidden>
            </div>
            <div class="camera-controls">
                <button class="submit-button" id="start-camera" type="button">Start camera</button>
                <button class="camera-stop-button" id="stop-camera" type="button" disabled>Stop camera</button>
                <button class="submit-button" id="take-photo" type="button" disabled>Take photo</button>
            </div>
            <p class="camera-status" id="camera-status" role="status" aria-live="polite">Camera is off.</p>
            <p class="capture-message" id="capture-message" role="alert" hidden></p>
            <section class="capture-result" id="capture-result" aria-labelledby="capture-result-title" hidden>
                <h3 id="capture-result-title">Last captured photo</h3>
                <img id="final-photo" alt="Captured photo with the selected overlay">
            </section>
            <noscript>
                <p class="camera-status">Enable JavaScript to use the camera preview.</p>
            </noscript>
        </section>
        <fieldset class="overlay-picker">
            <legend>Choose an overlay</legend>
            <p>Select one PNG to preview over the camera. Selection also works while the camera is off.</p>
            <div class="overlay-options">
                <input class="overlay-radio" type="radio" name="overlay" id="overlay-hat" value="hat" data-src="/assets/images/overlays/hat.png">
                <label class="overlay-choice" for="overlay-hat">
                    <img src="/assets/images/overlays/hat.png" alt="" width="96" height="72">
                    <span>Hat</span>
                </label>

                <input class="overlay-radio" type="radio" name="overlay" id="overlay-sunglasses" value="sunglasses" data-src="/assets/images/overlays/sunglasses.png">
                <label class="overlay-choice" for="overlay-sunglasses">
                    <img src="/assets/images/overlays/sunglasses.png" alt="" width="96" height="72">
                    <span>Sunglasses</span>
                </label>

                <input class="overlay-radio" type="radio" name="overlay" id="overlay-frame" value="frame" data-src="/assets/images/overlays/frame.png">
                <label class="overlay-choice" for="overlay-frame">
                    <img src="/assets/images/overlays/frame.png" alt="" width="96" height="72">
                    <span>Frame</span>
                </label>
            </div>
            <button class="camera-stop-button clear-overlay-button" id="clear-overlay" type="button" disabled>Clear overlay</button>
        </fieldset>
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

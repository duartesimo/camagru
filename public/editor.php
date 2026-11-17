<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

if (!authenticated_user()) {
    header('Location: /login.php');
    exit;
}

header('Cache-Control: no-store');

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$deleteStatus = $_SESSION['photo_delete_status'] ?? null;
unset($_SESSION['photo_delete_status']);

$personalPhotos = [];
$historyError = false;
try {
    $statement = database_connection()->prepare(
        'SELECT id FROM photos WHERE user_id = :user_id ORDER BY created_at DESC, id DESC'
    );
    $statement->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $statement->execute();
    $personalPhotos = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException | RuntimeException) {
    $historyError = true;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Create a photo from your webcam or an uploaded image with a Camagru overlay.">
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
                <a href="/settings.php">Settings</a>
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
            <h1>Create a photo</h1>
            <p>Use your camera or choose an image, then add an overlay to create a photo.</p>
        </div>

        <?php if ($deleteStatus === 'success'): ?>
            <p class="editor-notice editor-notice-success" role="status">Photo deleted.</p>
        <?php elseif ($deleteStatus === 'error'): ?>
            <p class="editor-notice editor-notice-error" role="alert">Could not delete the photo. Please try again.</p>
        <?php endif; ?>

        <div class="editor-workspace">
            <div class="editor-main">
                <section class="editor-panel" aria-labelledby="camera-title">
                    <h2 id="camera-title">Photo preview</h2>
                    <fieldset class="photo-source-picker">
                        <legend>Image source</legend>
                        <label><input id="source-webcam" type="radio" name="photo-source" value="webcam" checked> Webcam</label>
                        <label><input id="source-upload" type="radio" name="photo-source" value="upload"> Upload an image</label>
                    </fieldset>
                    <div class="upload-controls" id="upload-controls" hidden>
                        <label for="upload-image">Choose a PNG or JPEG image</label>
                        <input id="upload-image" type="file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" aria-describedby="upload-hint">
                        <p id="upload-hint">Maximum 10 MB and 24 megapixels. The center will be cropped to 4:3.</p>
                    </div>
                    <div class="camera-frame">
                        <video id="camera-preview" autoplay muted playsinline aria-label="Live webcam preview"></video>
                        <img id="upload-preview" alt="Selected image preview" hidden>
                        <img class="camera-overlay" id="camera-overlay" alt="" hidden>
                    </div>
                    <div class="camera-controls">
                        <div class="camera-buttons" id="camera-buttons">
                            <button class="submit-button" id="start-camera" type="button">Start camera</button>
                            <button class="camera-stop-button" id="stop-camera" type="button" disabled>Stop camera</button>
                        </div>
                        <button class="submit-button" id="take-photo" type="button" disabled>Take photo</button>
                    </div>
                    <p class="camera-status" id="camera-status" role="status" aria-live="polite">Camera is off.</p>
                    <p class="capture-message" id="capture-message" role="alert" hidden></p>
                    <section class="capture-result" id="capture-result" aria-labelledby="capture-result-title" hidden>
                        <h3 id="capture-result-title">Last photo</h3>
                        <img id="final-photo" alt="Photo with the selected overlay">
                        <p class="capture-success" id="capture-success" role="status" hidden>Photo saved to your account.</p>
                    </section>
                    <noscript>
                        <p class="camera-status">Enable JavaScript to use the photo editor.</p>
                    </noscript>
                </section>
                <fieldset class="overlay-picker">
                    <legend>Choose an overlay</legend>
                    <p>Select one PNG to preview over your image. You can choose an overlay before starting the camera or selecting an image.</p>
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
            </div>

            <aside class="editor-sidebar" aria-labelledby="personal-photos-title">
                <h2 id="personal-photos-title">My photos</h2>
                <?php if ($historyError): ?>
                    <p>Your photos are unavailable right now.</p>
                <?php elseif ($personalPhotos === []): ?>
                    <p>You have not saved any photos yet.</p>
                <?php else: ?>
                    <ul class="personal-photo-list">
                        <?php foreach ($personalPhotos as $photo): ?>
                            <li class="personal-photo">
                                <img src="/photo.php?id=<?= (int) $photo['id'] ?>" alt="Your saved photo <?= (int) $photo['id'] ?>" width="640" height="480" loading="lazy">
                                <form action="/delete_photo.php" method="post">
                                    <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <button type="submit" aria-label="Delete photo <?= (int) $photo['id'] ?>">Delete</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </aside>
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

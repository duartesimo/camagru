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

$pageSize = 6;
$page = 1;
$pageInput = $_GET['page'] ?? null;
if (is_string($pageInput) && preg_match('/\A[1-9][0-9]*\z/', $pageInput) === 1) {
    $validPage = filter_var($pageInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, $pageSize)],
    ]);
    if ($validPage !== false) {
        $page = $validPage;
    }
}

$photos = [];
$totalPages = 1;
$galleryError = false;
try {
    $database = database_connection();
    $countStatement = $database->prepare('SELECT COUNT(*) FROM photos WHERE user_id = :user_id');
    $countStatement->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $countStatement->execute();
    $totalPhotos = (int) $countStatement->fetchColumn();
    $totalPages = max(1, intdiv($totalPhotos - 1, $pageSize) + 1);
    $page = min($page, $totalPages);

    $statement = $database->prepare(
        'SELECT id FROM photos WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
    $statement->execute();
    $photos = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException | RuntimeException) {
    $galleryError = true;
}

$firstNumber = max(2, $page - 1);
$lastNumber = min($totalPages - 1, $page + 1);
if ($page <= 2) {
    $lastNumber = min($totalPages - 1, 3);
}
if ($page >= $totalPages - 1) {
    $firstNumber = max(2, $totalPages - 2);
}
if ($firstNumber === 3) {
    $firstNumber = 2;
}
if ($lastNumber === $totalPages - 2) {
    $lastNumber = $totalPages - 1;
}
$pageNumbers = [1];
for ($number = $firstNumber; $number <= $lastNumber; $number++) {
    $pageNumbers[] = $number;
}
if ($totalPages > 1) {
    $pageNumbers[] = $totalPages;
}

$author = htmlspecialchars($_SESSION['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Browse the photos you have created with Camagru.">
    <title>My photos | Camagru</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/gallery.js" defer></script>
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
                    <a href="/settings.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.2 2h3.6l.5 2.2 1.5.6 1.9-1.2 2.5 2.5-1.2 1.9.6 1.5 2.2.5v3.6l-2.2.5-.6 1.5 1.2 1.9-2.5 2.5-1.9-1.2-1.5.6-.5 2.2h-3.6l-.5-2.2-1.5-.6-1.9 1.2-2.5-2.5 1.2-1.9-.6-1.5L2 13.8v-3.6l2.2-.5.6-1.5-1.2-1.9 2.5-2.5 1.9 1.2 1.5-.6L10.2 2Z" /><circle cx="12" cy="12" r="3" /></svg>Settings</a>
                </div>
                <div class="nav-account">
                    <span class="nav-username"><span class="nav-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5" /><path d="M5 21v-1a7 7 0 0 1 14 0v1" /></svg></span><strong><?= $author ?></strong></span>
                    <form class="logout-form" action="/logout.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <button class="logout-button" type="submit" aria-label="Log out"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4" /><path d="m15 7 5 5-5 5M20 12H9" /></svg></button>
                    </form>
                </div>
            </nav>
        </div>
    </header>

    <main>
        <section class="intro container">
            <p class="eyebrow">Your collection</p>
            <h1>My photos</h1>
            <p class="intro-copy">Photos you have created with Camagru.</p>
        </section>

        <section class="gallery container" id="gallery" aria-labelledby="gallery-title">
            <div class="section-heading">
                <h2 id="gallery-title">Your photos</h2>
            </div>

            <?php if ($deleteStatus === 'success'): ?>
                <p class="editor-notice editor-notice-success" role="status">Photo deleted.</p>
            <?php elseif ($deleteStatus === 'error'): ?>
                <p class="editor-notice editor-notice-error" role="alert">Could not delete the photo. Please try again.</p>
            <?php endif; ?>

            <?php if ($galleryError): ?>
                <p class="gallery-empty" role="alert">Your photos are unavailable right now. Please try again later.</p>
            <?php elseif ($photos === []): ?>
                <p class="gallery-empty"><?= $page === 1 ? 'You have not saved any photos yet.' : 'No photos on this page.' ?> <a href="/editor.php">Create a photo</a></p>
            <?php else: ?>
                <div class="photo-grid">
                    <?php foreach ($photos as $photo): ?>
                        <figure class="photo-card" id="photo-<?= (int) $photo['id'] ?>">
                            <button class="photo-open" type="button" data-author="<?= $author ?>" aria-label="Enlarge photo by <?= $author ?>">
                                <img src="/photo.php?id=<?= (int) $photo['id'] ?>" alt="" width="640" height="480" loading="lazy">
                            </button>
                            <figcaption>
                                <span>By <strong><?= $author ?></strong></span>
                                <form action="/delete_photo.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                    <input type="hidden" name="return_to" value="my_gallery">
                                    <input type="hidden" name="page" value="<?= $page ?>">
                                    <button class="my-gallery-delete" type="submit" aria-label="Delete photo <?= (int) $photo['id'] ?>">Delete</button>
                                </form>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$galleryError && $totalPages > 1): ?>
                <nav class="gallery-pagination" aria-label="My photo pages">
                    <?php if ($page > 1): ?>
                        <a class="pagination-step" href="/my_gallery.php?page=<?= $page - 1 ?>#gallery" rel="prev">Previous</a>
                    <?php endif; ?>
                    <?php $previousNumber = 0; ?>
                    <?php foreach ($pageNumbers as $number): ?>
                        <?php if ($previousNumber !== 0 && $number > $previousNumber + 1): ?>
                            <span class="pagination-ellipsis" aria-hidden="true">…</span>
                        <?php endif; ?>
                        <?php if ($number === $page): ?>
                            <span class="pagination-number pagination-current" aria-current="page"><?= $number ?></span>
                        <?php else: ?>
                            <a class="pagination-number" href="/my_gallery.php?page=<?= $number ?>#gallery" aria-label="Page <?= $number ?>"><?= $number ?></a>
                        <?php endif; ?>
                        <?php $previousNumber = $number; ?>
                    <?php endforeach; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="pagination-step" href="/my_gallery.php?page=<?= $page + 1 ?>#gallery" rel="next">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>

    <dialog class="photo-lightbox" id="photo-lightbox" aria-label="Photo viewer">
        <div class="lightbox-header">
            <button class="lightbox-arrow lightbox-prev" id="lightbox-prev" type="button" aria-label="Previous photo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg></button>
            <button class="lightbox-arrow lightbox-next" id="lightbox-next" type="button" aria-label="Next photo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg></button>
            <button class="lightbox-close" id="lightbox-close" type="button">Close ×</button>
        </div>
        <div class="lightbox-stage">
            <img class="lightbox-photo" id="lightbox-image" alt="">
        </div>
        <div class="lightbox-caption">
            <span id="lightbox-author"></span>
            <span id="lightbox-counter"></span>
        </div>
    </dialog>

    <footer class="site-footer">
        <div class="container footer-inner">
            <span class="footer-brand">Camagru</span>
            <span>A simple app to create, customize and share your photos.</span>
            <span>Made for creative moments.</span>
        </div>
    </footer>
</body>

</html>

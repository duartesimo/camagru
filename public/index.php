<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

$isLoggedIn = authenticated_user();

$commentErrorPhotoId = $_SESSION['comment_error_photo_id'] ?? null;
unset($_SESSION['comment_error_photo_id']);

if ($isLoggedIn && (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']))) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
$commentsByPhoto = [];
$totalPages = 1;
$galleryError = false;

try {
    $database = database_connection();
    $countStatement = $database->prepare('SELECT COUNT(*) FROM photos');
    $countStatement->execute();
    $totalPhotos = (int) $countStatement->fetchColumn();
    $totalPages = max(1, intdiv($totalPhotos - 1, $pageSize) + 1);
    $page = min($page, $totalPages);

    $statement = $database->prepare(
        'SELECT p.id, u.username,
                COUNT(l.user_id) AS like_count,
                MAX(CASE WHEN l.user_id = :viewer_id THEN 1 ELSE 0 END) AS liked_by_me
         FROM (
             SELECT id, user_id, created_at
             FROM photos
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset
         ) p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN likes l ON l.photo_id = p.id
         GROUP BY p.id, p.created_at, u.username
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $statement->bindValue(':viewer_id', $isLoggedIn ? $_SESSION['user_id'] : 0, PDO::PARAM_INT);
    $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
    $statement->execute();
    $photos = $statement->fetchAll(PDO::FETCH_ASSOC);

    if ($photos !== []) {
        $placeholders = implode(', ', array_fill(0, count($photos), '?'));
        $commentsStatement = $database->prepare(
            'SELECT c.photo_id, c.content, c.created_at, u.username
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.photo_id IN (' . $placeholders . ')
             ORDER BY c.photo_id, c.created_at, c.id'
        );
        foreach ($photos as $index => $photo) {
            $commentsStatement->bindValue($index + 1, (int) $photo['id'], PDO::PARAM_INT);
        }
        $commentsStatement->execute();
        while ($comment = $commentsStatement->fetch(PDO::FETCH_ASSOC)) {
            $commentsByPhoto[(int) $comment['photo_id']][] = $comment;
        }
    }
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
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Camagru is a place to create and share photos.">
    <title>Camagru | Gallery</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/nav.js" defer></script>
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
            <button class="menu-toggle" type="button" aria-controls="main-navigation" aria-expanded="false" aria-label="Open menu"><span></span><span></span><span></span></button>
            <nav class="site-nav" id="main-navigation" aria-label="Main navigation">
                <div class="nav-links">
                    <a class="nav-active" href="/" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="8" cy="9" r="1.5" /><path d="m3 17 5-5 4 4 3-3 6 6" /></svg>Gallery</a>
                    <?php if ($isLoggedIn): ?>
                        <a href="/editor.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l1.5-2h7L17 7h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z" /><circle cx="12" cy="14" r="4" /></svg>Editor</a>
                        <a href="/settings.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.2 2h3.6l.5 2.2 1.5.6 1.9-1.2 2.5 2.5-1.2 1.9.6 1.5 2.2.5v3.6l-2.2.5-.6 1.5 1.2 1.9-2.5 2.5-1.9-1.2-1.5.6-.5 2.2h-3.6l-.5-2.2-1.5-.6-1.9 1.2-2.5-2.5 1.2-1.9-.6-1.5L2 13.8v-3.6l2.2-.5.6-1.5-1.2-1.9 2.5-2.5 1.9 1.2 1.5-.6L10.2 2Z" /><circle cx="12" cy="12" r="3" /></svg>Settings</a>
                    <?php else: ?>
                        <a href="/login.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" /><path d="M3 12h13m-4-4 4 4-4 4" /></svg>Login</a>
                    <?php endif; ?>
                </div>
                <?php if ($isLoggedIn): ?>
                    <div class="nav-account">
                        <span class="nav-username"><span class="nav-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5" /><path d="M5 21v-1a7 7 0 0 1 14 0v1" /></svg></span><strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
                        <form class="logout-form" action="/logout.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <button class="logout-button" type="submit" aria-label="Log out"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4" /><path d="m15 7 5 5-5 5M20 12H9" /></svg></button>
                        </form>
                    </div>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main>
        <section class="intro container">
            <p class="eyebrow">The community gallery</p>
            <h1>Every photo tells a story.</h1>
            <p class="intro-copy">
                Capture, create and share your favorite moments with the Camagru community.
            </p>
        </section>

        <section class="gallery container" id="gallery" aria-labelledby="gallery-title">
            <div class="section-heading">
                <h2 id="gallery-title">Explore the gallery</h2>
                <p>Photos shared by the community.</p>
            </div>

            <?php if ($galleryError): ?>
                <p class="gallery-empty" role="alert">The gallery is unavailable right now. Please try again later.</p>
            <?php elseif ($photos === []): ?>
                <p class="gallery-empty"><?= $page === 1 ? 'No photos have been shared yet.' : 'No photos on this page.' ?></p>
            <?php else: ?>
                <div class="photo-grid">
                    <?php foreach ($photos as $photo): ?>
                        <?php
                        $author = htmlspecialchars((string) $photo['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $likeCount = (int) $photo['like_count'];
                        $likedByMe = (int) $photo['liked_by_me'] === 1;
                        ?>
                        <figure class="photo-card" id="photo-<?= (int) $photo['id'] ?>">
                            <button class="photo-open" type="button" data-author="<?= $author ?>" aria-label="Enlarge photo by <?= $author ?>">
                                <img src="/photo.php?id=<?= (int) $photo['id'] ?>" alt="" width="640" height="480" loading="lazy">
                            </button>
                            <figcaption>
                                <span>By <strong><?= $author ?></strong></span>
                                <div class="photo-likes">
                                    <span>❤️ <strong><?= $likeCount ?></strong> </span>
                                    <?php if ($isLoggedIn): ?>
                                        <form action="/like.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <button type="submit" aria-pressed="<?= $likedByMe ? 'true' : 'false' ?>"><?= $likedByMe ? '♥ Unlike' : '♡ Like' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <section class="photo-comments" aria-label="Comments on this photo">
                                    <h3>Comments</h3>
                                    <?php if (empty($commentsByPhoto[(int) $photo['id']])): ?>
                                        <p>No comments yet.</p>
                                    <?php else: ?>
                                        <ul class="comment-list">
                                            <?php foreach ($commentsByPhoto[(int) $photo['id']] as $comment): ?>
                                                <?php $commentDate = (new DateTimeImmutable((string) $comment['created_at']))
                                                    ->setTimezone(new DateTimeZone('Europe/Madrid')); ?>
                                                <li>
                                                    <div class="comment-meta">
                                                        <strong><?= htmlspecialchars((string) $comment['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                                        <time datetime="<?= htmlspecialchars($commentDate->format(DATE_ATOM), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($commentDate->format('d/m/Y, H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></time>
                                                    </div>
                                                    <p><?= htmlspecialchars((string) $comment['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <?php if ($isLoggedIn): ?>
                                        <?php $hasCommentError = $commentErrorPhotoId === (int) $photo['id']; ?>
                                        <form action="/comment.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <div class="form-field">
                                                <label for="comment-<?= (int) $photo['id'] ?>">Your comment</label>
                                                <textarea id="comment-<?= (int) $photo['id'] ?>" name="content" aria-describedby="comment-hint-<?= (int) $photo['id'] ?><?= $hasCommentError ? ' comment-error-' . (int) $photo['id'] : '' ?>" <?= $hasCommentError ? 'aria-invalid="true"' : '' ?> required></textarea>
                                                <p class="field-hint" id="comment-hint-<?= (int) $photo['id'] ?>">Maximum 500 characters.</p>
                                                <?php if ($hasCommentError): ?>
                                                    <p class="field-error" id="comment-error-<?= (int) $photo['id'] ?>" role="alert">Enter a comment of 1 to 500 characters.</p>
                                                <?php endif; ?>
                                            </div>
                                            <button type="submit">Post comment</button>
                                        </form>
                                    <?php endif; ?>
                                </section>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$galleryError && $totalPages > 1): ?>
                <nav class="gallery-pagination" aria-label="Gallery pages">
                    <?php if ($page > 1): ?>
                        <a class="pagination-step" href="/?page=<?= $page - 1 ?>#gallery" rel="prev">Previous</a>
                    <?php endif; ?>
                    <?php $previousNumber = 0; ?>
                    <?php foreach ($pageNumbers as $number): ?>
                        <?php if ($previousNumber !== 0 && $number > $previousNumber + 1): ?>
                            <span class="pagination-ellipsis" aria-hidden="true">…</span>
                        <?php endif; ?>
                        <?php if ($number === $page): ?>
                            <span class="pagination-number pagination-current" aria-current="page"><?= $number ?></span>
                        <?php else: ?>
                            <a class="pagination-number" href="/?page=<?= $number ?>#gallery" aria-label="Page <?= $number ?>"><?= $number ?></a>
                        <?php endif; ?>
                        <?php $previousNumber = $number; ?>
                    <?php endforeach; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="pagination-step" href="/?page=<?= $page + 1 ?>#gallery" rel="next">Next</a>
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

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
$hasNextPage = false;
$galleryError = false;

try {
    $database = database_connection();
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
    $statement->bindValue(':limit', $pageSize + 1, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
    $statement->execute();
    $photos = $statement->fetchAll(PDO::FETCH_ASSOC);

    if (count($photos) > $pageSize) {
        $hasNextPage = true;
        array_pop($photos);
    }

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
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Camagru is a place to create and share photos.">
    <title>Camagru | Gallery</title>
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
                <a class="nav-active" href="/" aria-current="page">Gallery</a>
                <?php if ($isLoggedIn): ?>
                    <a href="/editor.php">Editor</a>
                    <a href="/settings.php">Settings</a>
                    <span class="nav-username">
                        Welcome, <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </span>
                    <form class="logout-form" action="/logout.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <button class="logout-button" type="submit">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="/login.php">Login</a>
                    <a href="/register.php">Register</a>
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
                            <img src="/photo.php?id=<?= (int) $photo['id'] ?>" alt="Photo shared by <?= $author ?>" width="640" height="480" loading="lazy">
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

            <?php if (!$galleryError && ($page > 1 || $hasNextPage)): ?>
                <nav class="gallery-pagination" aria-label="Gallery pages">
                    <?php if ($page > 1): ?>
                        <a href="/?page=<?= $page - 1 ?>#gallery" rel="prev">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= $page ?></span>
                    <?php if ($hasNextPage): ?>
                        <a href="/?page=<?= $page + 1 ?>#gallery" rel="next">Next</a>
                    <?php endif; ?>
                </nav>
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

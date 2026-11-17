<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

session_start();

if (!isset($_SESSION['user_id'], $_SESSION['username'])
    || !is_int($_SESSION['user_id'])
    || !is_string($_SESSION['username'])) {
    header('Location: /login.php', true, 303);
    exit;
}

$token = $_POST['csrf_token'] ?? null;
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])
    || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    exit('Invalid request.');
}

$idInput = $_POST['photo_id'] ?? null;
if (!is_string($idInput) || preg_match('/\A[1-9][0-9]*\z/', $idInput) !== 1) {
    http_response_code(400);
    exit('Invalid photo.');
}
$photoId = filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($photoId === false) {
    http_response_code(400);
    exit('Invalid photo.');
}

$page = 1;
$pageInput = $_POST['page'] ?? null;
if (is_string($pageInput) && preg_match('/\A[1-9][0-9]*\z/', $pageInput) === 1) {
    $validPage = filter_var($pageInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 6)],
    ]);
    if ($validPage !== false) {
        $page = $validPage;
    }
}

$location = ($page === 1 ? '/' : '/?page=' . $page) . '#photo-' . $photoId;

$contentInput = $_POST['content'] ?? null;
$content = is_string($contentInput) && preg_match('//u', $contentInput) === 1
    ? preg_replace('/(*UCP)\A\s+|\s+\z/u', '', $contentInput)
    : null;
$length = $content === null ? false : preg_match_all('/./us', $content);
if ($content === null || $content === '' || strlen($content) > 2000
    || $length === false || $length > 500) {
    $_SESSION['comment_error_photo_id'] = $photoId;
    header('Location: ' . $location, true, 303);
    exit;
}

require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/email.php';

try {
    $database = database_connection();
    $statement = $database->prepare('SELECT id FROM photos WHERE id = :id');
    $statement->bindValue(':id', $photoId, PDO::PARAM_INT);
    $statement->execute();
    if ($statement->fetchColumn() === false) {
        http_response_code(404);
        exit('Photo not found.');
    }

    $statement = $database->prepare(
        'INSERT INTO comments (photo_id, user_id, content) VALUES (:photo_id, :user_id, :content)'
    );
    $statement->bindValue(':photo_id', $photoId, PDO::PARAM_INT);
    $statement->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $statement->bindValue(':content', $content, PDO::PARAM_STR);
    $statement->execute();
} catch (PDOException | RuntimeException) {
    http_response_code(503);
    exit('Could not post the comment. Please try again later.');
}

try {
    $statement = $database->prepare(
        'SELECT u.email, u.email_notifications_enabled::int AS notifications_enabled,
                (SELECT COUNT(*) FROM photos newer
                 WHERE newer.created_at > p.created_at
                    OR (newer.created_at = p.created_at AND newer.id > p.id)) AS newer_count
         FROM photos p
         JOIN users u ON u.id = p.user_id
         WHERE p.id = :photo_id'
    );
    $statement->bindValue(':photo_id', $photoId, PDO::PARAM_INT);
    $statement->execute();
    $owner = $statement->fetch(PDO::FETCH_ASSOC);

    if (is_array($owner) && (int) $owner['notifications_enabled'] === 1) {
        $galleryPage = intdiv((int) $owner['newer_count'], 6) + 1;
        send_comment_notification_email($owner['email'], $_SESSION['username'], $photoId, $galleryPage);
    }
} catch (Throwable) {
    // The comment is already saved; email delivery must not prevent the redirect.
}

unset($_SESSION['comment_error_photo_id']);
header('Location: ' . $location, true, 303);
exit;

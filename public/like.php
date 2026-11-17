<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

require_once __DIR__ . '/../src/auth.php';

if (!authenticated_user()) {
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

try {
    $database = database_connection();
    $statement = $database->prepare('SELECT id FROM photos WHERE id = :id');
    $statement->bindValue(':id', $photoId, PDO::PARAM_INT);
    $statement->execute();
    if ($statement->fetchColumn() === false) {
        http_response_code(404);
        exit('Photo not found.');
    }

    $statement = $database->prepare('DELETE FROM likes WHERE user_id = :user_id AND photo_id = :photo_id');
    $statement->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $statement->bindValue(':photo_id', $photoId, PDO::PARAM_INT);
    $statement->execute();

    if ($statement->rowCount() === 0) {
        $statement = $database->prepare(
            'INSERT INTO likes (user_id, photo_id) VALUES (:user_id, :photo_id)
             ON CONFLICT (user_id, photo_id) DO NOTHING'
        );
        $statement->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $statement->bindValue(':photo_id', $photoId, PDO::PARAM_INT);
        $statement->execute();
    }
} catch (PDOException | RuntimeException) {
    http_response_code(503);
    exit('Could not update the like. Please try again later.');
}

$location = $page === 1 ? '/#gallery' : '/?page=' . $page . '#gallery';
header('Location: ' . $location, true, 303);
exit;

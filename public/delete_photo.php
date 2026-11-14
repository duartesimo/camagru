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
$idInput = $_POST['photo_id'] ?? null;
$validToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])
    && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
$photoId = false;
if (is_string($idInput) && preg_match('/\A[1-9][0-9]*\z/', $idInput) === 1) {
    $photoId = filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}

$deleted = false;
if ($validToken && $photoId !== false) {
    require_once __DIR__ . '/../src/database.php';

    $database = null;
    try {
        $database = database_connection();
        $database->beginTransaction();

        $select = $database->prepare(
            'SELECT filename FROM photos WHERE id = :id AND user_id = :user_id FOR UPDATE'
        );
        $select->bindValue(':id', $photoId, PDO::PARAM_INT);
        $select->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $select->execute();
        $photo = $select->fetch(PDO::FETCH_ASSOC);
        $filename = is_array($photo) ? ($photo['filename'] ?? null) : null;

        if (!is_string($filename) || preg_match('/\A[a-f0-9]{32}\.png\z/', $filename) !== 1) {
            throw new RuntimeException('Invalid photo.');
        }

        $path = '/var/lib/camagru/photos/' . $filename;
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Invalid photo file.');
        }

        $delete = $database->prepare('DELETE FROM photos WHERE id = :id AND user_id = :user_id');
        $delete->bindValue(':id', $photoId, PDO::PARAM_INT);
        $delete->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $delete->execute();

        if ($delete->rowCount() !== 1 || (is_file($path) && !@unlink($path))) {
            throw new RuntimeException('Could not delete photo.');
        }

        $database->commit();
        $deleted = true;
    } catch (PDOException | RuntimeException) {
        if ($database instanceof PDO) {
            try {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
            } catch (PDOException) {
                // Keep the user-facing error generic if the connection is already lost.
            }
        }
    }
}

$_SESSION['photo_delete_status'] = $deleted ? 'success' : 'error';
header('Location: /editor.php', true, 303);
exit;

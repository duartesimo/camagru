<?php

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=UTF-8');

$idInput = $_GET['id'] ?? null;
if (!is_string($idInput) || preg_match('/\A[1-9][0-9]*\z/', $idInput) !== 1) {
    http_response_code(404);
    exit('Photo not found.');
}

$photoId = filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($photoId === false) {
    http_response_code(404);
    exit('Photo not found.');
}

require_once __DIR__ . '/../src/database.php';

try {
    $statement = database_connection()->prepare('SELECT filename FROM photos WHERE id = :id');
    $statement->bindValue(':id', $photoId, PDO::PARAM_INT);
    $statement->execute();
    $photo = $statement->fetch(PDO::FETCH_ASSOC);
} catch (PDOException | RuntimeException) {
    http_response_code(503);
    exit('Photo temporarily unavailable.');
}

$filename = is_array($photo) ? ($photo['filename'] ?? null) : null;
if (!is_string($filename) || preg_match('/\A[a-f0-9]{32}\.png\z/', $filename) !== 1) {
    http_response_code(404);
    exit('Photo not found.');
}

$path = '/var/lib/camagru/photos/' . $filename;
if (is_link($path)) {
    http_response_code(404);
    exit('Photo not found.');
}

$data = @file_get_contents($path);
if ($data === false || strncmp($data, "\x89PNG\r\n\x1a\n", 8) !== 0) {
    http_response_code(404);
    exit('Photo not found.');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($data));
echo $data;

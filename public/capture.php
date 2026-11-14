<?php

declare(strict_types=1);

session_start();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reject_capture(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    reject_capture(405, 'Use POST to capture a photo.');
}

if (!isset($_SESSION['user_id'], $_SESSION['username'])
    || !is_int($_SESSION['user_id'])
    || !is_string($_SESSION['username'])) {
    reject_capture(401, 'Please log in and try again.');
}

$token = $_POST['csrf_token'] ?? null;
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])
    || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
    reject_capture(403, 'Please reload the editor and try again.');
}

$userId = $_SESSION['user_id'];

$overlays = [
    'hat' => __DIR__ . '/assets/images/overlays/hat.png',
    'sunglasses' => __DIR__ . '/assets/images/overlays/sunglasses.png',
    'frame' => __DIR__ . '/assets/images/overlays/frame.png',
];
$overlayName = $_POST['overlay'] ?? null;
if (!is_string($overlayName) || !isset($overlays[$overlayName])) {
    reject_capture(400, 'Select a valid overlay.');
}

$upload = $_FILES['photo'] ?? null;
if (!is_array($upload)) {
    reject_capture(400, 'Choose a valid photo.');
}

if (($upload['error'] ?? null) === UPLOAD_ERR_INI_SIZE
    || ($upload['error'] ?? null) === UPLOAD_ERR_FORM_SIZE) {
    reject_capture(413, 'The photo is too large.');
}

if (!isset($upload['error'], $upload['size'], $upload['tmp_name'])
    || !is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK
    || !is_int($upload['size']) || $upload['size'] < 1 || $upload['size'] > 2 * 1024 * 1024
    || !is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
    reject_capture(400, 'Choose a valid PNG photo under 2 MB.');
}

$imageInfo = @getimagesize($upload['tmp_name']);
if ($imageInfo === false
    || $imageInfo[0] !== 640 || $imageInfo[1] !== 480
    || $imageInfo[2] !== IMAGETYPE_PNG) {
    reject_capture(400, 'The photo must be a 640 × 480 PNG.');
}

if (!extension_loaded('gd')) {
    reject_capture(503, 'Photo processing is unavailable right now.');
}

session_write_close();

$photo = @imagecreatefrompng($upload['tmp_name']);
$overlay = @imagecreatefrompng($overlays[$overlayName]);
if ($photo === false || $overlay === false
    || imagesx($photo) !== 640 || imagesy($photo) !== 480
    || imagesx($overlay) !== 640 || imagesy($overlay) !== 480) {
    reject_capture(400, 'The photo could not be processed.');
}

imagealphablending($photo, true);
imagesavealpha($photo, true);
if (!imagecopy($photo, $overlay, 0, 0, 0, 0, 640, 480)) {
    reject_capture(500, 'The photo could not be processed.');
}

if (!ob_start()) {
    reject_capture(500, 'The photo could not be processed.');
}
$encoded = @imagepng($photo);
$result = ob_get_clean();

if (!$encoded || !is_string($result) || $result === '') {
    reject_capture(500, 'The photo could not be processed.');
}

require_once __DIR__ . '/../src/database.php';

try {
    $database = database_connection();
} catch (PDOException | RuntimeException) {
    reject_capture(500, 'The photo could not be saved.');
}

try {
    $filename = bin2hex(random_bytes(16)) . '.png';
} catch (Throwable) {
    reject_capture(500, 'The photo could not be saved.');
}

$filePath = '/var/lib/camagru/photos/' . $filename;

$written = @file_put_contents($filePath, $result, LOCK_EX);

if ($written !== strlen($result)) {
    @unlink($filePath);
    reject_capture(500, 'The photo could not be saved.');
}

try {
    $statement = $database->prepare(
        'INSERT INTO photos (user_id, filename) VALUES (:user_id, :filename)'
    );
    $statement->execute([
        'user_id' => $userId,
        'filename' => $filename,
    ]);
} catch (PDOException | RuntimeException) {
    @unlink($filePath);
    reject_capture(500, 'The photo could not be saved.');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($result));
echo $result;

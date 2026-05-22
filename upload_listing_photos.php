<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$listingId = intval($_POST['listing_id'] ?? 0);
$userId    = $_SESSION['user_id'];

if (!$listingId) {
    echo json_encode(['success' => false, 'error' => 'Invalid listing']);
    exit;
}

// Verify listing belongs to this user
$stmt = $pdo->prepare("SELECT ListingID FROM TListings WHERE ListingID = ? AND UserLenderID = ?");
$stmt->execute([$listingId, $userId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$uploadDir = 'uploads/listings/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$uploaded = [];
$allowed  = ['jpg','jpeg','png','gif','webp','mp4','mov','webm'];

foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
    if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;

    $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) continue;

    $filename = uniqid('listing_', true) . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (move_uploaded_file($tmpName, $destPath)) {
        $uploaded[] = $destPath;
    }
}

if (empty($uploaded)) {
    echo json_encode(['success' => false, 'error' => 'No valid files uploaded']);
    exit;
}

// Get current max sort order
$sortStmt = $pdo->prepare("SELECT COALESCE(MAX(SortOrder), 0) as maxSort FROM TListingPhotos WHERE ListingID = ?");
$sortStmt->execute([$listingId]);
$maxSort = $sortStmt->fetch()['maxSort'];

// Insert via stored procedure
$photoStmt = $pdo->prepare("CALL uspAddListingPhoto(?, ?, ?, ?)");
foreach ($uploaded as $i => $path) {
    $photoStmt->execute([$listingId, $userId, $path, $maxSort + $i + 1]);
    $photoStmt->closeCursor();
}

echo json_encode(['success' => true, 'count' => count($uploaded)]);
?>

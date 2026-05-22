<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$data      = json_decode(file_get_contents('php://input'), true);
$photoId   = intval($data['photo_id'] ?? 0);
$listingId = intval($data['listing_id'] ?? 0);
$userId    = $_SESSION['user_id'];

if (!$photoId || !$listingId) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify the listing belongs to this user
$stmt = $pdo->prepare("SELECT ListingID FROM TListings WHERE ListingID = ? AND UserLenderID = ?");
$stmt->execute([$listingId, $userId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $pdo->prepare("CALL uspRemoveListingPhoto(?, ?)");
    $stmt->execute([$photoId, $userId]);
    $stmt->closeCursor();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("delete_listing_photo error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>

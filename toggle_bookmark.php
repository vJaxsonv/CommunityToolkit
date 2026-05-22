<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$data      = json_decode(file_get_contents('php://input'), true);
$listingId = intval($data['listing_id'] ?? 0);

if (!$listingId) {
    echo json_encode(['success' => false, 'error' => 'No listing ID provided']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Check if bookmark already exists
    $checkStmt = $pdo->prepare("SELECT BookmarkID FROM TBookmarks WHERE UserID = ? AND ListingID = ?");
    $checkStmt->execute([$userId, $listingId]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        // Remove bookmark
        $pdo->prepare("DELETE FROM TBookmarks WHERE BookmarkID = ?")->execute([$existing['BookmarkID']]);
        echo json_encode(['success' => true, 'bookmarked' => false]);
    } else {
        // Add bookmark
        $pdo->prepare("INSERT INTO TBookmarks (UserID, ListingID, AddedDate) VALUES (?, ?, NOW())")->execute([$userId, $listingId]);
        echo json_encode(['success' => true, 'bookmarked' => true]);
    }
} catch (PDOException $e) {
    error_log("toggle_bookmark error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>

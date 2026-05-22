<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId    = $_SESSION['user_id'];
$listingId = intval($_GET['id'] ?? 0);

// Must have a valid listing ID
if ($listingId === 0) {
    header('Location: my_items.php');
    exit;
}

// Verify the listing belongs to this user before doing anything
$checkStmt = $pdo->prepare("SELECT ListingID FROM TListings WHERE ListingID = ? AND UserLenderID = ?");
$checkStmt->execute([$listingId, $userId]);

if (!$checkStmt->fetch()) {
    // Listing not found or doesn't belong to this user
    header('Location: my_items.php');
    exit;
}

// Soft delete — set status to 5 (Removed)
$deleteStmt = $pdo->prepare("UPDATE TListings SET ListingStatusID = 5 WHERE ListingID = ? AND UserLenderID = ?");
$deleteStmt->execute([$listingId, $userId]);

header('Location: my_items.php?deleted=1');
exit;
?>

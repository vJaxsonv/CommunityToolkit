<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$rentalId = (int)($_POST['rental_id'] ?? 0);

if ($rentalId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rental.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT r.RentalID, r.UserBorrowerID, r.UserLenderID, r.ListingID, r.RentalStatusID
        FROM TRentals r
        WHERE r.RentalID = ?
        LIMIT 1
    ");
    $stmt->execute([$rentalId]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        echo json_encode(['success' => false, 'message' => 'Rental not found.']);
        exit;
    }

    if ((int)$rental['UserBorrowerID'] !== $userId && (int)$rental['UserLenderID'] !== $userId) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to cancel this rental.']);
        exit;
    }

    $pdo->beginTransaction();

    // Mark rental as Cancelled (status 6, not 5 which is Completed)
    $updateRental = $pdo->prepare("
        UPDATE TRentals
        SET RentalStatusID = 6,
            UpdatedDate = NOW()
        WHERE RentalID = ?
    ");
    $updateRental->execute([$rentalId]);

    // Reset listing to Available and clear the availability link
    $updateListing = $pdo->prepare("
        UPDATE TListings
        SET ListingStatusID = 1,
            ListingAvailabilityID = NULL
        WHERE ListingID = ?
    ");
    $updateListing->execute([$rental['ListingID']]);

    // Remove the availability block that was created when the rental was accepted
    $deleteAvail = $pdo->prepare("
        DELETE la FROM TListingAvailability la
        INNER JOIN TRentalRequests rr ON la.AvailableDate = rr.StartDate
            AND la.UnavailableDate = rr.EndDate
        INNER JOIN TRentals r ON r.RentalRequestID = rr.RentalRequestID
        WHERE r.RentalID = ? AND la.ListingID = ? AND la.BlockReasonID = 1
    ");
    $deleteAvail->execute([$rentalId, $rental['ListingID']]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Rental cancelled successfully.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode(['success' => false, 'message' => 'Could not cancel rental.']);
}
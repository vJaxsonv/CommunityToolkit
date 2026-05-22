<?php
require_once 'config.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }

    $userId   = (int)$_SESSION['user_id'];
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $userRole = strtolower(trim($_POST['user_role'] ?? ''));

    if ($rentalId <= 0 || !in_array($userRole, ['borrower', 'lender'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT RentalID, UserBorrowerID, UserLenderID, RentalStatusID
        FROM TRentals
        WHERE RentalID = ?
        LIMIT 1
    ");
    $stmt->execute([$rentalId]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        echo json_encode(['success' => false, 'message' => 'Rental not found.']);
        exit;
    }

    $borrowerId = (int)$rental['UserBorrowerID'];
    $lenderId   = (int)$rental['UserLenderID'];

    if ($userRole === 'borrower' && $borrowerId !== $userId) {
        echo json_encode([
            'success' => false,
            'message' => 'Borrower mismatch.',
            'debug' => ['sessionUserId' => $userId, 'borrowerId' => $borrowerId]
        ]);
        exit;
    }

    if ($userRole === 'lender' && $lenderId !== $userId) {
        echo json_encode([
            'success' => false,
            'message' => 'Lender mismatch.',
            'debug' => ['sessionUserId' => $userId, 'lenderId' => $lenderId]
        ]);
        exit;
    }

    if ((int)$rental['RentalStatusID'] !== 2) {
        echo json_encode(['success' => false, 'message' => 'Rental is not upcoming.']);
        exit;
    }

    $insert = $pdo->prepare("
        INSERT INTO TRentalCancelConfirmations (RentalID, UserRole, ConfirmedAt)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE ConfirmedAt = NOW()
    ");
    $insert->execute([$rentalId, $userRole]);

    $check = $pdo->prepare("
        SELECT UserRole
        FROM TRentalCancelConfirmations
        WHERE RentalID = ?
    ");
    $check->execute([$rentalId]);
    $rows = $check->fetchAll(PDO::FETCH_ASSOC);

    $roles = array_map('strtolower', array_column($rows, 'UserRole'));
    $borrowerCancel = in_array('borrower', $roles, true);
    $lenderCancel   = in_array('lender', $roles, true);

    if ($borrowerCancel && $lenderCancel) {
        $update = $pdo->prepare("
            UPDATE TRentals
            SET RentalStatusID = 5, UpdatedDate = NOW()
            WHERE RentalID = ?
        ");
        $update->execute([$rentalId]);

        echo json_encode([
            'success' => true,
            'cancel_complete' => true,
            'message' => 'Rental cancelled successfully.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'cancel_complete' => false,
        'message' => 'Cancellation request saved. Waiting for the other user.'
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
}
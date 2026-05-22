<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
    exit;
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$rentalId = (int)($_POST['rental_id'] ?? 0);
$userRole = trim($_POST['user_role'] ?? '');

if ($rentalId <= 0 || !in_array($userRole, ['borrower', 'lender'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

if (
    !isset($_FILES['return_media']) ||
    !isset($_FILES['return_media']['name']) ||
    count(array_filter($_FILES['return_media']['name'])) === 0
) {
    echo json_encode(['success' => false, 'message' => 'Please add at least one photo or video.']);
    exit;
}

$allowed = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'video/mp4',
    'video/webm',
    'video/quicktime'
];

$uploadDir = __DIR__ . '/uploads/returns/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* verify rental belongs to this user/role */
$stmt = $pdo->prepare("
    SELECT RentalID, UserBorrowerID, UserLenderID
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

if (
    ($userRole === 'borrower' && (int)$rental['UserBorrowerID'] !== $userId) ||
    ($userRole === 'lender' && (int)$rental['UserLenderID'] !== $userId)
) {
    echo json_encode(['success' => false, 'message' => 'You are not allowed to confirm this return.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare("
        SELECT ReturnConfirmationID
        FROM TRentalReturnConfirmations
        WHERE RentalID = ? AND UserRole = ?
        LIMIT 1
    ");
    $checkStmt->execute([$rentalId, $userRole]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    $firstFilePath = null;

    if ($existing) {
        $returnConfirmationId = (int)$existing['ReturnConfirmationID'];

        $updateStmt = $pdo->prepare("
            UPDATE TRentalReturnConfirmations
            SET UserID = ?, ConfirmedAt = NOW()
            WHERE ReturnConfirmationID = ?
        ");
        $updateStmt->execute([$userId, $returnConfirmationId]);

        $deleteMediaStmt = $pdo->prepare("
            DELETE FROM TRentalConfirmationMedia
            WHERE ConfirmationType = 'return' AND ConfirmationID = ?
        ");
        $deleteMediaStmt->execute([$returnConfirmationId]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO TRentalReturnConfirmations
            (RentalID, UserID, UserRole, PhotoURL, ConfirmedAt)
            VALUES (?, ?, ?, '', NOW())
        ");
        $insertStmt->execute([$rentalId, $userId, $userRole]);
        $returnConfirmationId = (int)$pdo->lastInsertId();
    }

    $fileCount = count($_FILES['return_media']['name']);
    $maxFileSize = 50 * 1024 * 1024; // 50 MB

    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['return_media']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if ($_FILES['return_media']['size'][$i] > $maxFileSize) {
            throw new Exception('Each file must be 50MB or smaller.');
        }

        $tmpName = $_FILES['return_media']['tmp_name'][$i];
        $originalName = $_FILES['return_media']['name'][$i];
        $fileType = mime_content_type($tmpName);

        if (!in_array($fileType, $allowed, true)) {
            throw new Exception('Only JPG, PNG, WEBP, MP4, WEBM, or MOV files are allowed.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = str_starts_with($fileType, 'video/') ? 'mp4' : 'jpg';
        }

        $fileName = $rentalId . '_' . $userRole . '_return_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $fullPath = $uploadDir . $fileName;
        $relativePath = 'uploads/returns/' . $fileName;

        if (!move_uploaded_file($tmpName, $fullPath)) {
            throw new Exception('Failed to save one of the uploaded files.');
        }

        if ($firstFilePath === null) {
            $firstFilePath = $relativePath;
        }

        $mediaStmt = $pdo->prepare("
            INSERT INTO TRentalConfirmationMedia
            (RentalID, ConfirmationType, ConfirmationID, UserRole, FileURL, FileType)
            VALUES (?, 'return', ?, ?, ?, ?)
        ");
        $mediaStmt->execute([
            $rentalId,
            $returnConfirmationId,
            $userRole,
            $relativePath,
            $fileType
        ]);
    }

    if ($firstFilePath === null) {
        throw new Exception('No valid files were uploaded.');
    }

    $pdo->prepare("
        UPDATE TRentalReturnConfirmations
        SET PhotoURL = ?
        WHERE ReturnConfirmationID = ?
    ")->execute([$firstFilePath, $returnConfirmationId]);

    $rolesStmt = $pdo->prepare("
        SELECT UserRole
        FROM TRentalReturnConfirmations
        WHERE RentalID = ?
    ");
    $rolesStmt->execute([$rentalId]);
    $rows = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

    $roles = array_map('strtolower', array_column($rows, 'UserRole'));
    $borrowerReturned = in_array('borrower', $roles, true);
    $lenderReturned   = in_array('lender', $roles, true);

    if ($borrowerReturned && $lenderReturned) {
        $updateRentalStmt = $pdo->prepare("
            UPDATE TRentals
            SET RentalStatusID = 7,
                UpdatedDate = NOW()
            WHERE RentalID = ?
        ");
        $updateRentalStmt->execute([$rentalId]);

        // ── Charge 90% balance payment on return completion ──────────────────
        $balCheck = $pdo->prepare("
            SELECT TransactionID FROM TTransactions
            WHERE RentalID = ? AND TransactionTypeID = 4
            LIMIT 1
        ");
        $balCheck->execute([$rentalId]);

        if (!$balCheck->fetch()) {
            $costStmt = $pdo->prepare("
                SELECT rr.StartDate, rr.EndDate,
                       l.PricePerDay, l.PricePerHour, l.RateTypeID,
                       r.UserBorrowerID, r.UserLenderID,
                       r.UserBorrowerCardID, r.UserLenderCardID
                FROM TRentals r
                INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
                INNER JOIN TListings l ON r.ListingID = l.ListingID
                WHERE r.RentalID = ?
                LIMIT 1
            ");
            $costStmt->execute([$rentalId]);
            $costRow = $costStmt->fetch(PDO::FETCH_ASSOC);

            if ($costRow) {
                $days    = max(1, (int)ceil((strtotime($costRow['EndDate']) - strtotime($costRow['StartDate'])) / 86400));
                $total   = ((int)$costRow['RateTypeID'] === 2)
                    ? $days * floatval($costRow['PricePerHour'])
                    : $days * floatval($costRow['PricePerDay']);
                $balance = round($total * 0.90, 2);

                if ($balance > 0) {
                    $pdo->prepare("
                        INSERT INTO TTransactions
                            (RentalID, TransactionTypeID, TransactionStatusID,
                             UserBorrowerID, UserLenderID, UserBorrowerCardID, UserLenderCardID, Amount, AddedDate)
                        VALUES (?, 4, 2, ?, ?, ?, ?, ?, NOW())
                    ")->execute([
                        $rentalId,
                        $costRow['UserBorrowerID'],
                        $costRow['UserLenderID'],
                        $costRow['UserBorrowerCardID'] ?: 0,
                        $costRow['UserLenderCardID']   ?: 0,
                        $balance
                    ]);
                }
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        // ── Clean up availability block and reset request status ─────────────
        // Delete the TListingAvailability block that was created at acceptance
        $pdo->prepare("
            DELETE la FROM TListingAvailability la
            INNER JOIN TRentalRequests rr ON la.AvailableDate = rr.StartDate
                AND la.UnavailableDate = rr.EndDate
            INNER JOIN TRentals r ON r.RentalRequestID = rr.RentalRequestID
            WHERE r.RentalID = ? AND la.ListingID = (SELECT ListingID FROM TRentals WHERE RentalID = ?)
            AND la.BlockReasonID = 1
        ")->execute([$rentalId, $rentalId]);

        // Mark the rental request as completed so it no longer blocks calendar dates
        $pdo->prepare("
            UPDATE TRentalRequests rr
            INNER JOIN TRentals r ON r.RentalRequestID = rr.RentalRequestID
            SET rr.RequestStatusID = 4
            WHERE r.RentalID = ?
        ")->execute([$rentalId]);

        // Reset listing to Available
        $pdo->prepare("
            UPDATE TListings
            SET ListingStatusID = 1
            WHERE ListingID = (SELECT ListingID FROM TRentals WHERE RentalID = ?)
        ")->execute([$rentalId]);
        // ─────────────────────────────────────────────────────────────────────

        $pdo->commit();

        echo json_encode([
            'success'   => true,
            'message'   => 'Return completed successfully.',
            'rental_id' => $rentalId
        ]);
        exit;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Return files saved successfully. Waiting on the other user.',
        'rental_id' => $rentalId
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$rentalId = (int)($_POST['rental_id'] ?? 0);
$userRole = trim($_POST['user_role'] ?? '');

if ($rentalId <= 0 || !in_array($userRole, ['borrower', 'lender'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

if (
    !isset($_FILES['pickup_media']) ||
    !isset($_FILES['pickup_media']['name']) ||
    count(array_filter($_FILES['pickup_media']['name'])) === 0
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

$uploadDir = __DIR__ . '/uploads/pickups/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
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
    echo json_encode(['success' => false, 'message' => 'You are not allowed to confirm this pickup.']);
    exit;
}
try {
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare("
        SELECT PickupConfirmationID
        FROM TRentalPickupConfirmations
        WHERE RentalID = ? AND UserRole = ?
        LIMIT 1
    ");
    $checkStmt->execute([$rentalId, $userRole]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    $firstFilePath = null;

    if ($existing) {
        $pickupConfirmationId = (int)$existing['PickupConfirmationID'];

        $updateStmt = $pdo->prepare("
            UPDATE TRentalPickupConfirmations
            SET UserID = ?, ConfirmedAt = NOW()
            WHERE PickupConfirmationID = ?
        ");
        $updateStmt->execute([$userId, $pickupConfirmationId]);

        $deleteMediaStmt = $pdo->prepare("
            DELETE FROM TRentalConfirmationMedia
            WHERE ConfirmationType = 'pickup' AND ConfirmationID = ?
        ");
        $deleteMediaStmt->execute([$pickupConfirmationId]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO TRentalPickupConfirmations (RentalID, UserID, UserRole, PhotoURL, ConfirmedAt)
            VALUES (?, ?, ?, '', NOW())
        ");
        $insertStmt->execute([$rentalId, $userId, $userRole]);
        $pickupConfirmationId = (int)$pdo->lastInsertId();
    }

    $fileCount = count($_FILES['pickup_media']['name']);
    $maxFileSize = 50 * 1024 * 1024; // 50 MB
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['pickup_media']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
    
        if ($_FILES['pickup_media']['size'][$i] > $maxFileSize) {
            throw new Exception('Each file must be 50MB or smaller.');
        }
    
        $tmpName = $_FILES['pickup_media']['tmp_name'][$i];
        $originalName = $_FILES['pickup_media']['name'][$i];
        $fileType = mime_content_type($tmpName);
    
        if (!in_array($fileType, $allowed, true)) {
            throw new Exception('Only JPG, PNG, WEBP, MP4, WEBM, or MOV files are allowed.');
        }
    
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = str_starts_with($fileType, 'video/') ? 'mp4' : 'jpg';
        }
    
        $fileName = $rentalId . '_' . $userRole . '_pickup_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $fullPath = $uploadDir . $fileName;
        $relativePath = 'uploads/pickups/' . $fileName;
    
        if (!move_uploaded_file($tmpName, $fullPath)) {
            throw new Exception('Failed to save one of the uploaded files.');
        }
    
        if ($firstFilePath === null) {
            $firstFilePath = $relativePath;
        }
    
        $mediaStmt = $pdo->prepare("
            INSERT INTO TRentalConfirmationMedia
            (RentalID, ConfirmationType, ConfirmationID, UserRole, FileURL, FileType)
            VALUES (?, 'pickup', ?, ?, ?, ?)
        ");
        $mediaStmt->execute([
            $rentalId,
            $pickupConfirmationId,
            $userRole,
            $relativePath,
            $fileType
        ]);
    }

    if ($firstFilePath === null) {
        throw new Exception('No valid files were uploaded.');
    }
    
    $pdo->prepare("
        UPDATE TRentalPickupConfirmations
        SET PhotoURL = ?
        WHERE PickupConfirmationID = ?
    ")->execute([$firstFilePath, $pickupConfirmationId]);

    $rolesStmt = $pdo->prepare("
        SELECT UserRole
        FROM TRentalPickupConfirmations
        WHERE RentalID = ?
    ");
    $rolesStmt->execute([$rentalId]);
    $rows = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

    $roles = array_map('strtolower', array_column($rows, 'UserRole'));
    $borrowerConfirmed = in_array('borrower', $roles, true);
    $lenderConfirmed   = in_array('lender', $roles, true);

    if ($borrowerConfirmed && $lenderConfirmed) {
        $updateRentalStmt = $pdo->prepare("
            UPDATE TRentals
            SET RentalStatusID = 4,
                UpdatedDate = NOW()
            WHERE RentalID = ?
        ");
        $updateRentalStmt->execute([$rentalId]);

        // ── Charge 10% deposit on pickup completion ──────────────────────────
        // Only insert if no deposit transaction exists yet for this rental
        $depCheck = $pdo->prepare("
            SELECT TransactionID FROM TTransactions
            WHERE RentalID = ? AND TransactionTypeID = 2
            LIMIT 1
        ");
        $depCheck->execute([$rentalId]);

        if (!$depCheck->fetch()) {
            // Get rental cost info
            $costStmt = $pdo->prepare("
                SELECT rr.StartDate, rr.EndDate, l.PricePerDay, l.PricePerHour, l.RateTypeID,
                       r.UserBorrowerID, r.UserLenderID, r.UserBorrowerCardID, r.UserLenderCardID
                FROM TRentals r
                INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
                INNER JOIN TListings l ON r.ListingID = l.ListingID
                WHERE r.RentalID = ?
                LIMIT 1
            ");
            $costStmt->execute([$rentalId]);
            $costRow = $costStmt->fetch(PDO::FETCH_ASSOC);

            if ($costRow) {
                $days  = max(1, (int)ceil((strtotime($costRow['EndDate']) - strtotime($costRow['StartDate'])) / 86400));
                $total = ((int)$costRow['RateTypeID'] === 2)
                    ? $days * floatval($costRow['PricePerHour'])
                    : $days * floatval($costRow['PricePerDay']);
                $deposit = round($total * 0.10, 2);

                if ($deposit > 0) {
                    $pdo->prepare("
                        INSERT INTO TTransactions
                            (RentalID, TransactionTypeID, TransactionStatusID,
                             UserBorrowerID, UserLenderID, UserBorrowerCardID, UserLenderCardID, Amount, AddedDate)
                        VALUES (?, 2, 2, ?, ?, ?, ?, ?, NOW())
                    ")->execute([
                        $rentalId,
                        $costRow['UserBorrowerID'],
                        $costRow['UserLenderID'],
                        $costRow['UserBorrowerCardID'] ?: 0,
                        $costRow['UserLenderCardID']   ?: 0,
                        $deposit
                    ]);
                }
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Pickup completed. Rental is now active.',
            'rental_id' => $rentalId
        ]);
        exit;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Pickup files saved successfully. Waiting on the other user.',
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
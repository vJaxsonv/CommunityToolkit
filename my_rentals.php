<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// ── Helper: fetch pickup/return/deposit state ─────────────────────────────────
function getPickupState($pdo, $rentalId) {
    $stmt = $pdo->prepare("SELECT UserRole FROM TRentalPickupConfirmations WHERE RentalID = ?");
    $stmt->execute([$rentalId]);
    $roles = array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'UserRole'));
    return [in_array('borrower', $roles), in_array('lender', $roles)];
}
function getReturnState($pdo, $rentalId) {
    $stmt = $pdo->prepare("SELECT UserRole FROM TRentalReturnConfirmations WHERE RentalID = ?");
    $stmt->execute([$rentalId]);
    $roles = array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'UserRole'));
    return [in_array('borrower', $roles), in_array('lender', $roles)];
}
function getDepositState($pdo, $rentalId) {
    try {
        $stmt = $pdo->prepare("SELECT Amount FROM TTransactions WHERE RentalID = ? AND TransactionTypeID = 2 AND TransactionStatusID = 2 LIMIT 1");
        $stmt->execute([$rentalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['has_deposit' => true, 'amount' => floatval($row['Amount'])] : ['has_deposit' => false, 'amount' => null];
    } catch (PDOException $e) { return ['has_deposit' => false, 'amount' => null]; }
}

// ── Helper: render progress strip ────────────────────────────────────────────
function renderProgressStrip($context = []) {
    $hasDeposit     = $context['has_deposit']      ?? false;
    $pickupBorrower = $context['pickup_borrower']  ?? false;
    $pickupLender   = $context['pickup_lender']    ?? false;
    $returnBorrower = $context['return_borrower']  ?? false;
    $returnLender   = $context['return_lender']    ?? false;
    $rentalStatus   = $context['rental_status']    ?? 2;
    $isBorrower     = $context['is_borrower']      ?? true;
    $otherName      = htmlspecialchars($context['other_name'] ?? 'Other party');
    $depositAmt     = $context['deposit_amount']   ?? null;
    $isComplete     = ($rentalStatus == 5 || ($rentalStatus == 7 && $returnBorrower && $returnLender));
    $isActive       = ($rentalStatus == 4);

    $youLabel   = 'You';
    $themLabel  = $otherName;
    $steps = [
        'request'  => ['done' => true,          'label' => 'Requested',                   'icon' => 'fa-paper-plane'],
        'deposit'  => ['done' => $hasDeposit,    'label' => 'Deposit Paid',                'icon' => 'fa-credit-card'],
        'pickup_b' => ['done' => $pickupBorrower,'label' => 'Pickup (' . ($isBorrower ? $youLabel : $themLabel) . ')','icon' => 'fa-user'],
        'pickup_l' => ['done' => $pickupLender,  'label' => 'Pickup (' . (!$isBorrower ? $youLabel : $themLabel) . ')','icon' => 'fa-user-tie'],
        'active'   => ['done' => $isActive || $isComplete,'label' => 'Active',             'icon' => 'fa-circle-play'],
        'return_b' => ['done' => $returnBorrower,'label' => 'Return (' . ($isBorrower ? $youLabel : $themLabel) . ')','icon' => 'fa-user'],
        'return_l' => ['done' => $returnLender,  'label' => 'Return (' . (!$isBorrower ? $youLabel : $themLabel) . ')','icon' => 'fa-user-tie'],
        'complete' => ['done' => $isComplete,    'label' => 'Complete',                    'icon' => 'fa-check-circle'],
    ];
    $currentStep = 'complete';
    foreach ($steps as $key => $step) { if (!$step['done']) { $currentStep = $key; break; } }

    echo '<div class="progress-strip">';
    $i = 0; $total = count($steps);
    foreach ($steps as $key => $step) {
        $isDone    = $step['done'];
        $isCurrent = ($key === $currentStep);
        $isYourTurn = $isCurrent && (
            ($key === 'pickup_b' && $isBorrower)  ||
            ($key === 'pickup_l' && !$isBorrower) ||
            ($key === 'return_b' && $isBorrower)  ||
            ($key === 'return_l' && !$isBorrower)
        );
        $cls = 'ps-step';
        if ($isDone)         $cls .= ' ps-done';
        elseif ($isCurrent)  $cls .= ' ps-current';
        else                  $cls .= ' ps-future';
        if ($isYourTurn)      $cls .= ' ps-your-turn';
        echo '<div class="' . $cls . '">';
        echo '<div class="ps-dot"><i class="fas ' . $step['icon'] . '"></i></div>';
        echo '<div class="ps-label">' . $step['label'];
        if ($key === 'deposit' && $depositAmt !== null) echo '<br><span class="ps-sublabel">$' . number_format($depositAmt, 2) . '</span>';
        echo '</div></div>';
        if ($i < $total - 1) echo '<div class="ps-line' . ($isDone ? ' ps-line-done' : '') . '"></div>';
        $i++;
    }
    echo '</div>';
}

// ══════════════════════════════════════════════
// BORROWER queries
// ══════════════════════════════════════════════

// Current Rentals (Active = 4)
$currentStmt = $pdo->prepare("
    SELECT r.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           lender.FirstName as LenderFirstName, lender.LastName as LenderLastName,
           rr.StartDate, rr.EndDate,
           DATEDIFF(rr.EndDate, NOW()) as DaysRemaining,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RentalStatus
    FROM TRentals r
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    INNER JOIN TUsers lender ON r.UserLenderID = lender.UserID
    INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
    WHERE r.UserBorrowerID = ?
    AND r.RentalStatusID IN (4, 7)
   
    ORDER BY rr.EndDate ASC
");
$currentStmt->execute([$userId]);
$currentRentals = $currentStmt->fetchAll(PDO::FETCH_ASSOC);

// Upcoming Rentals (Approved = 2, future start)
$upcomingStmt = $pdo->prepare("
    SELECT r.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           lender.FirstName as LenderFirstName, lender.LastName as LenderLastName,
           rr.StartDate, rr.EndDate,
           DATEDIFF(rr.StartDate, NOW()) as DaysUntil,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RentalStatus
    FROM TRentals r
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    INNER JOIN TUsers lender ON r.UserLenderID = lender.UserID
    INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
    WHERE r.UserBorrowerID = ?
    AND r.RentalStatusID = 2
    AND rr.StartDate >= CURDATE()
    ORDER BY rr.StartDate ASC
");
$upcomingStmt->execute([$userId]);
$upcomingRentals = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

// Pending Rentals (sent to lender, awaiting response)
$pendingStmt = $pdo->prepare("
    SELECT rr.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           lender.FirstName as LenderFirstName, lender.LastName as LenderLastName,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RequestStatus
    FROM TRentalRequests rr
    INNER JOIN TListings l ON rr.ListingID = l.ListingID
    INNER JOIN TUsers lender ON l.UserLenderID = lender.UserID
    INNER JOIN TRequestStatuses rs ON rr.RequestStatusID = rs.RequestStatusID
    WHERE rr.UserBorrowerID = ?
    AND rr.RequestStatusID = 1
    ORDER BY rr.RequestDate DESC
");
$pendingStmt->execute([$userId]);
$pendingRentals = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Pending Review Rentals for Borrower (Status = 7)
$pendingReviewStmt = $pdo->prepare("
    SELECT r.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           lender.FirstName as LenderFirstName, lender.LastName as LenderLastName,
           rr.StartDate, rr.EndDate,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RentalStatus
    FROM TRentals r
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    INNER JOIN TUsers lender ON r.UserLenderID = lender.UserID
    INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
    WHERE r.UserBorrowerID = ?
    AND r.RentalStatusID = 7
    ORDER BY r.UpdatedDate DESC, rr.EndDate DESC
");
$pendingReviewStmt->execute([$userId]);
$pendingReviewRentals = $pendingReviewStmt->fetchAll(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════
// LENDER queries
// ══════════════════════════════════════════════

// Incoming rental requests (pending — awaiting lender response)
$lenderPendingStmt = $pdo->prepare("
    SELECT rr.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           borrower.FirstName as BorrowerFirstName, borrower.LastName as BorrowerLastName,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RequestStatus
    FROM TRentalRequests rr
    INNER JOIN TListings l ON rr.ListingID = l.ListingID
    INNER JOIN TUsers borrower ON rr.UserBorrowerID = borrower.UserID
    INNER JOIN TRequestStatuses rs ON rr.RequestStatusID = rs.RequestStatusID
    WHERE l.UserLenderID = ?
    AND rr.RequestStatusID = 1
    ORDER BY rr.RequestDate DESC
");
$lenderPendingStmt->execute([$userId]);
$lenderPendingRequests = $lenderPendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Active rentals being lent out
$lenderActiveStmt = $pdo->prepare("
    SELECT r.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           borrower.FirstName as BorrowerFirstName, borrower.LastName as BorrowerLastName,
           rr.StartDate, rr.EndDate,
           DATEDIFF(rr.EndDate, NOW()) as DaysRemaining,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RentalStatus
    FROM TRentals r
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    INNER JOIN TUsers borrower ON r.UserBorrowerID = borrower.UserID
    INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
    WHERE r.UserLenderID = ?
    AND r.RentalStatusID IN (4, 7)

    ORDER BY rr.EndDate ASC
");
$lenderActiveStmt->execute([$userId]);
$lenderActiveRentals = $lenderActiveStmt->fetchAll(PDO::FETCH_ASSOC);

// Upcoming confirmed rentals (accepted, future start)
$lenderUpcomingStmt = $pdo->prepare("
    SELECT r.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           borrower.FirstName as BorrowerFirstName, borrower.LastName as BorrowerLastName,
           rr.StartDate, rr.EndDate,
           DATEDIFF(rr.StartDate, NOW()) as DaysUntil,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RentalStatus
    FROM TRentals r
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    INNER JOIN TUsers borrower ON r.UserBorrowerID = borrower.UserID
    INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
    WHERE r.UserLenderID = ?
    AND r.RentalStatusID = 2
    AND rr.StartDate >= CURDATE()
    ORDER BY rr.StartDate ASC
");
$lenderUpcomingStmt->execute([$userId]);
$lenderUpcomingRentals = $lenderUpcomingStmt->fetchAll(PDO::FETCH_ASSOC);

// Pending Review Rentals for Lender (Status = 7)
$lenderPendingReviewStmt = $pdo->prepare("
    SELECT r.*,
           l.Title, l.ListingID, l.PricePerDay, l.PricePerHour, l.RateTypeID,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as PrimaryImage,
           borrower.FirstName as BorrowerFirstName, borrower.LastName as BorrowerLastName,
           rr.StartDate, rr.EndDate,
           DATEDIFF(rr.EndDate, rr.StartDate) as TotalDays,
           rs.Status as RentalStatus
    FROM TRentals r
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    INNER JOIN TUsers borrower ON r.UserBorrowerID = borrower.UserID
    INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
    WHERE r.UserLenderID = ?
    AND r.RentalStatusID = 7
    ORDER BY r.UpdatedDate DESC, rr.EndDate DESC
");
$lenderPendingReviewStmt->execute([$userId]);
$lenderPendingReviewRentals = $lenderPendingReviewStmt->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['extension_action'] ?? '') === 'request_extension') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $newEndDate = trim($_POST['new_end_date'] ?? '');

    if ($rentalId <= 0 || $newEndDate === '') {
        echo json_encode(['success' => false, 'message' => 'Please choose a new return date.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.RentalID, r.UserBorrowerID, rr.EndDate
        FROM TRentals r
        INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
        WHERE r.RentalID = ?
        LIMIT 1
    ");
    $stmt->execute([$rentalId]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        echo json_encode(['success' => false, 'message' => 'Rental not found.']);
        exit;
    }

    if ((int)$rental['UserBorrowerID'] !== $userId) {
        echo json_encode(['success' => false, 'message' => 'Only the borrower can request an extension.']);
        exit;
    }

    $currentEndDate = date('Y-m-d', strtotime($rental['EndDate']));

    if ($newEndDate <= $currentEndDate) {
        echo json_encode(['success' => false, 'message' => 'New date must be after the current return date.']);
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT RentalExtensionID
        FROM TRentalExtensions
        WHERE RentalID = ? AND ExtensionStatusID = 1
        LIMIT 1
    ");
    $checkStmt->execute([$rentalId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'There is already a pending extension request.']);
        exit;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO TRentalExtensions
        (RentalID, ExtensionStatusID, EndDate, RequestDate)
        VALUES (?, 1, ?, NOW())
    ");
    $insertStmt->execute([$rentalId, $newEndDate]);
    $extensionId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Extension request sent successfully.'
    ]);
    exit;
}
// Track rentals this user has already reviewed
$myReviews = [];
$reviewStmt = $pdo->prepare("
    SELECT RentalID
    FROM TReviews
    WHERE UserReviewerID = ?
");
$reviewStmt->execute([$userId]);
while ($row = $reviewStmt->fetch(PDO::FETCH_ASSOC)) {
    $myReviews[(int)$row['RentalID']] = true;
}

// ── Build "Needs Action" items ────────────────────────────────────────────────
$actionItems = [];

// Lender: incoming requests needing response
foreach ($lenderPendingRequests as $r) {
    $actionItems[] = [
        'role'        => 'lender',
        'icon'        => 'fa-inbox',
        'color'       => '#ef4444',
        'label'       => 'New rental request — needs your response',
        'item_name'   => $r['Title'],
        'other_person'=> $r['BorrowerFirstName'],
        'link'        => 'accept_rental.php?request_id=' . $r['RentalRequestID'],
    ];
}

// Borrower: pending requests waiting on lender
foreach ($pendingRentals as $r) {
    $actionItems[] = [
        'role'        => 'borrower',
        'icon'        => 'fa-hourglass-half',
        'color'       => '#f59e0b',
        'label'       => 'Awaiting lender approval',
        'item_name'   => $r['Title'],
        'other_person'=> $r['LenderFirstName'],
        'link'        => '?role=borrower&tab=pending',
    ];
}

// Borrower upcoming: pickup confirmation needed from borrower
foreach ($upcomingRentals as $r) {
    [$pb, $pl] = getPickupState($pdo, $r['RentalID']);
    if (!$pb) {
        $actionItems[] = [
            'role'        => 'borrower',
            'icon'        => 'fa-box',
            'color'       => '#667eea',
            'label'       => $pl ? 'Lender confirmed — confirm pickup when you receive the item' : 'Confirm pickup when you receive the item',
            'item_name'   => $r['Title'],
            'other_person'=> $r['LenderFirstName'],
            'link'        => '?role=borrower&tab=upcoming',
        ];
    }
}

// Lender upcoming: pickup confirmation needed from lender
foreach ($lenderUpcomingRentals as $r) {
    [$pb, $pl] = getPickupState($pdo, $r['RentalID']);
    if (!$pl) {
        $actionItems[] = [
            'role'        => 'lender',
            'icon'        => 'fa-box',
            'color'       => '#667eea',
            'label'       => $pb ? 'Borrower confirmed — confirm handoff on your end' : 'Confirm handoff when borrower picks up',
            'item_name'   => $r['Title'],
            'other_person'=> $r['BorrowerFirstName'],
            'link'        => '?role=lender&tab=upcoming',
        ];
    }
}

// Borrower current: return confirmation needed
foreach ($currentRentals as $r) {
    [$rb, $rl] = getReturnState($pdo, $r['RentalID']);
    if (!$rb) {
        $daysLeft = intval($r['DaysRemaining']);
        $actionItems[] = [
            'role'        => 'borrower',
            'icon'        => 'fa-undo',
            'color'       => $daysLeft <= 1 ? '#ef4444' : ($daysLeft <= 3 ? '#f59e0b' : '#10b981'),
            'label'       => $rl ? 'Lender confirmed return — confirm on your end' : ($daysLeft <= 1 ? 'Return due today or tomorrow!' : 'Return item and confirm'),
            'item_name'   => $r['Title'],
            'other_person'=> $r['LenderFirstName'],
            'link'        => '?role=borrower&tab=current',
        ];
    }
}

// Lender active: return confirmation needed
foreach ($lenderActiveRentals as $r) {
    [$rb, $rl] = getReturnState($pdo, $r['RentalID']);
    if ($rb && !$rl) {
        $actionItems[] = [
            'role'        => 'lender',
            'icon'        => 'fa-undo',
            'color'       => '#ef4444',
            'label'       => 'Borrower has returned the item — confirm receipt',
            'item_name'   => $r['Title'],
            'other_person'=> $r['BorrowerFirstName'],
            'link'        => '?role=lender&tab=active',
        ];
    }
}

// Reviews pending (borrower)
foreach ($pendingReviewRentals as $r) {
    if (!empty($myReviews[(int)$r['RentalID']])) continue;
    $actionItems[] = [
        'role'        => 'borrower',
        'icon'        => 'fa-star',
        'color'       => '#f59e0b',
        'label'       => 'Leave a review to complete this rental',
        'item_name'   => $r['Title'],
        'other_person'=> $r['LenderFirstName'],
        'link'        => 'reviews.php?user_id=' . (int)$r['UserLenderID'] . '&rental_id=' . (int)$r['RentalID'],
    ];
}

// Reviews pending (lender)
foreach ($lenderPendingReviewRentals as $r) {
    if (!empty($myReviews[(int)$r['RentalID']])) continue;
    $actionItems[] = [
        'role'        => 'lender',
        'icon'        => 'fa-star',
        'color'       => '#f59e0b',
        'label'       => 'Leave a review to complete this rental',
        'item_name'   => $r['Title'],
        'other_person'=> $r['BorrowerFirstName'],
        'link'        => 'reviews.php?user_id=' . (int)$r['UserBorrowerID'] . '&rental_id=' . (int)$r['RentalID'],
    ];
}

$totalActionCount   = count($actionItems);
$borrowerActionCount = count(array_filter($actionItems, fn($i) => $i['role'] === 'borrower'));
$lenderActionCount   = count(array_filter($actionItems, fn($i) => $i['role'] === 'lender'));

// Per-tab urgent counts
$urgentReturnBorrower = count(array_filter($actionItems, fn($i) => $i['role']==='borrower' && $i['icon']==='fa-undo'));
$urgentPickupBorrower = count(array_filter($actionItems, fn($i) => $i['role']==='borrower' && $i['icon']==='fa-box'));
$urgentReturnLender   = count(array_filter($actionItems, fn($i) => $i['role']==='lender'   && $i['icon']==='fa-undo'));
$urgentPickupLender   = count(array_filter($actionItems, fn($i) => $i['role']==='lender'   && $i['icon']==='fa-box'));

// Handle extension request POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['extension_action'] ?? '') === 'request_extension') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $newEndDate = trim($_POST['new_end_date'] ?? '');

    if ($rentalId <= 0 || $newEndDate === '') {
        echo json_encode(['success' => false, 'message' => 'Please choose a new return date.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.RentalID, r.UserBorrowerID, rr.EndDate
        FROM TRentals r
        INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
        WHERE r.RentalID = ?
        LIMIT 1
    ");
    $stmt->execute([$rentalId]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        echo json_encode(['success' => false, 'message' => 'Rental not found.']);
        exit;
    }

    if ((int)$rental['UserBorrowerID'] !== $userId) {
        echo json_encode(['success' => false, 'message' => 'Only the borrower can request an extension.']);
        exit;
    }

    $currentEndDate = date('Y-m-d', strtotime($rental['EndDate']));

    if ($newEndDate <= $currentEndDate) {
        echo json_encode(['success' => false, 'message' => 'New date must be after the current return date.']);
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT RentalExtensionID
        FROM TRentalExtensions
        WHERE RentalID = ? AND ExtensionStatusID = 1
        LIMIT 1
    ");
    $checkStmt->execute([$rentalId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'There is already a pending extension request.']);
        exit;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO TRentalExtensions
        (RentalID, ExtensionStatusID, EndDate, RequestDate)
        VALUES (?, 1, ?, NOW())
    ");
    $insertStmt->execute([$rentalId, $newEndDate]);

    echo json_encode([
        'success' => true,
        'message' => 'Extension request sent successfully.'
    ]);
    exit;
}

// Track rentals this user has already reviewed
$myReviews = [];
$reviewStmt = $pdo->prepare("
    SELECT RentalID
    FROM TReviews
    WHERE UserReviewerID = ?
");
$reviewStmt->execute([$userId]);
while ($row = $reviewStmt->fetch(PDO::FETCH_ASSOC)) {
    $myReviews[(int)$row['RentalID']] = true;
}
$activeRole = $_GET['role'] ?? 'borrower';
$activeTab  = $_GET['tab'] ?? 'current';
// Handle lender accept / decline POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($requestId > 0 && in_array($action, ['accept', 'decline'], true)) {
        try {
            $verifyStmt = $pdo->prepare("
                SELECT rr.RentalRequestID, rr.RequestStatusID,
                       rr.ListingID, rr.UserBorrowerID,
                       rr.StartDate, rr.EndDate, l.Title
                FROM TRentalRequests rr
                INNER JOIN TListings l ON rr.ListingID = l.ListingID
                WHERE rr.RentalRequestID = ? AND l.UserLenderID = ?
            ");
            $verifyStmt->execute([$requestId, $userId]);
            $req = $verifyStmt->fetch(PDO::FETCH_ASSOC);

            if ($req && (int)$req['RequestStatusID'] === 1) {
                if ($action === 'accept') {
                    $pdo->beginTransaction();

                    $pdo->prepare("
                        UPDATE TRentalRequests
                        SET RequestStatusID = 2
                        WHERE RentalRequestID = ?
                    ")->execute([$requestId]);

                    $pdo->prepare("
                        INSERT INTO TRentals
                            (ListingID, UserBorrowerID, UserLenderID, RentalRequestID, RentalStatusID,
                             PickUpPhotoURL, ReturnPhotoURL, UserBorrowerCardID, UserLenderCardID, AddedDate, UpdatedDate)
                        VALUES (?, ?, ?, ?, 2, NULL, NULL, NULL, NULL, NOW(), NOW())
                    ")->execute([$req['ListingID'], $req['UserBorrowerID'], $userId, $requestId]);

                    $rentalId = $pdo->lastInsertId();

                    $pdo->prepare("
                        INSERT INTO TConversations (RentalID, AddedDate, LastMessageDate)
                        VALUES (?, NOW(), NOW())
                    ")->execute([$rentalId]);

                    $conversationId = $pdo->lastInsertId();

                    $pdo->prepare("
                        INSERT INTO TUserConversations (ConversationID, UserID, LastReadDate)
                        VALUES (?, ?, NOW())
                    ")->execute([$conversationId, $userId]);

                    $pdo->prepare("
                        INSERT INTO TUserConversations (ConversationID, UserID, LastReadDate)
                        VALUES (?, ?, '2000-01-01 00:00:00')
                    ")->execute([$conversationId, $req['UserBorrowerID']]);

                    $sysMsg = 'Rental request accepted! You can now message each other to arrange pickup.';
                    $pdo->prepare("
                        INSERT INTO TMessages (ConversationID, UserSenderID, MessageBody, SystemMessage, SentDate)
                        VALUES (?, ?, ?, 1, NOW())
                    ")->execute([$conversationId, $userId, $sysMsg]);

                    $msgId = $pdo->lastInsertId();

                    $pdo->prepare("
                        INSERT INTO TListingAvailability (ListingStatusID, StartDate, EndDate, BlockReasonID)
                        VALUES (?, ?, ?, 1)
                    ")->execute([$req['ListingID'], $req['StartDate'], $req['EndDate']]);

                    $availId = $pdo->lastInsertId();

                    $pdo->prepare("
                        UPDATE TListings
                        SET ListingStatusID = 3, ListingAvailabilityID = ?
                        WHERE ListingID = ?
                    ")->execute([$availId, $req['ListingID']]);

                    $pdo->prepare("
                        INSERT INTO TNotifications
                            (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                             RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                        VALUES (?, 2, ?, ?, ?, 0, ?, 0, 'Your rental request has been accepted!', 0, NOW())
                    ")->execute([$req['UserBorrowerID'], $requestId, $conversationId, $msgId, $rentalId]);

                    // ── 10% deposit transaction ───────────────────────────
                    $reqDays = max(1, (int)ceil((strtotime($req['EndDate']) - strtotime($req['StartDate'])) / 86400));
                    $reqTotal = floatval($req['PricePerDay'] ?? 0) * $reqDays;
                    $depositAmount = round($reqTotal * 0.10, 2);

                    $borrowerCard = null;
                    try {
                        $bcStmt = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? AND PrimaryCard = 1 LIMIT 1");
                        $bcStmt->execute([$req['UserBorrowerID']]);
                        $borrowerCard = $bcStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$borrowerCard) {
                            $bcAny = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? ORDER BY AddedDate DESC LIMIT 1");
                            $bcAny->execute([$req['UserBorrowerID']]);
                            $borrowerCard = $bcAny->fetch(PDO::FETCH_ASSOC);
                        }
                    } catch (PDOException $e) {}

                    $lenderCard = null;
                    try {
                        $lcStmt = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? AND PrimaryCard = 1 LIMIT 1");
                        $lcStmt->execute([$userId]);
                        $lenderCard = $lcStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$lenderCard) {
                            $lcAny = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? ORDER BY AddedDate DESC LIMIT 1");
                            $lcAny->execute([$userId]);
                            $lenderCard = $lcAny->fetch(PDO::FETCH_ASSOC);
                        }
                    } catch (PDOException $e) {}

                    $borrowerCardId = $borrowerCard['CardID'] ?? 0;
                    $lenderCardId   = $lenderCard['CardID']  ?? 0;

                    if ($depositAmount > 0) {
                        try {
                            $pdo->prepare("
                                INSERT INTO TTransactions (RentalID, TransactionTypeID, TransactionStatusID,
                                                           UserBorrowerID, UserLenderID, UserBorrowerCardID,
                                                           UserLenderCardID, Amount, AddedDate)
                                VALUES (?, 2, 2, ?, ?, ?, ?, ?, NOW())
                            ")->execute([$rentalId, $req['UserBorrowerID'], $userId, $borrowerCardId, $lenderCardId, $depositAmount]);
                            $pdo->prepare("UPDATE TRentals SET UserBorrowerCardID = ?, UserLenderCardID = ? WHERE RentalID = ?")->execute([$borrowerCardId, $lenderCardId, $rentalId]);
                            $pdo->prepare("
                                INSERT INTO TNotifications (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                                                            RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                                VALUES (?, 2, ?, ?, 0, 0, ?, 0, ?, 0, NOW())
                            ")->execute([
                                $req['UserBorrowerID'], $requestId, $conversationId, $rentalId,
                                'A 10% deposit of $' . number_format($depositAmount, 2) . ' has been charged for your rental of "' . $req['Title'] . '".'
                            ]);
                        } catch (PDOException $e) { error_log('Deposit insert failed: ' . $e->getMessage()); }
                    }
                    // ─────────────────────────────────────────────────────

                    $pdo->commit();
                    // If listing was pending deletion, it's now active - no finalize needed
                } else {
                    $pdo->prepare("
                        UPDATE TRentalRequests
                        SET RequestStatusID = 3
                        WHERE RentalRequestID = ?
                    ")->execute([$requestId]);

                    $pdo->prepare("
                        UPDATE TListings
                        SET ListingStatusID = 1
                        WHERE ListingID = ?
                    ")->execute([$req['ListingID']]);

                    $pdo->prepare("
                        INSERT INTO TNotifications
                            (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                             RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                        VALUES (?, 3, ?, 0, 0, 0, 0, 0, 'Your rental request was declined.', 0, NOW())
                    ")->execute([$req['UserBorrowerID'], $requestId]);
                    finalizePendingDeletion($pdo, $req['ListingID']);
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("my_rentals action error: " . $e->getMessage());
        }
    }
$role = $_POST['role'] ?? 'borrower';
$tab  = $_POST['tab'] ?? 'current';
    header("Location: my_rentals.php?role=$role&tab=$tab");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request_id'])) {
    $requestId = (int)($_POST['cancel_request_id'] ?? 0);

    if ($requestId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT rr.RentalRequestID, rr.RequestStatusID, rr.ListingID, rr.UserBorrowerID
                FROM TRentalRequests rr
                WHERE rr.RentalRequestID = ?
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($req && (int)$req['UserBorrowerID'] === (int)$userId && (int)$req['RequestStatusID'] === 1) {
                $pdo->beginTransaction();

                $pdo->prepare("
                    UPDATE TRentalRequests
                    SET RequestStatusID = 6
                    WHERE RentalRequestID = ?
                ")->execute([$requestId]);

                $pdo->prepare("
                    UPDATE TListings
                    SET ListingStatusID = 1
                    WHERE ListingID = ?
                ")->execute([$req['ListingID']]);

                $pdo->commit();
                finalizePendingDeletion($pdo, $req['ListingID']);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("cancel request error: " . $e->getMessage());
        }
    }

    header("Location: my_rentals.php?role=borrower&tab=pending");
    exit;
}
// Active role from URL
$activeRole = in_array($_GET['role'] ?? '', ['borrower', 'lender']) ? $_GET['role'] : 'borrower';
$activeTab  = 'all'; // kept for URL compatibility only
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Rentals - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/ai_chatbot.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@latest/dist/driver.css"/>
    <style>
        /* ── Tour replay button ──────────────────────────────── */
        .btn-tour-replay {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: 1px solid #d1d5db;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 12px;
            color: #6b7280;
            cursor: pointer;
            transition: border-color 0.15s, color 0.15s;
            margin-left: auto;
        }
        .btn-tour-replay:hover { border-color: #667eea; color: #667eea; }
        .page-sub-row {
            display: flex;
            align-items: center;
            margin-bottom: 18px;
        }
        .page-sub-row .page-sub { margin-bottom: 0; }
    </style>
    <style>
        /* ── Progress Strip ─────────────────────────────────────────── */
        .progress-strip {
            display: flex;
            align-items: flex-start;
            padding: 12px 0 8px;
            overflow-x: auto;
            margin-bottom: 12px;
            border-bottom: 1px solid #f3f4f6;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .progress-strip::-webkit-scrollbar { display: none; }
        .ps-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 58px;
            flex-shrink: 0;
        }
        .ps-dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            border: 2px solid #e5e7eb;
            background: white;
            color: #d1d5db;
            margin-bottom: 4px;
        }
        .ps-done    .ps-dot { background: #10b981; border-color: #10b981; color: white; }
        .ps-current .ps-dot { background: #667eea; border-color: #667eea; color: white; }
        .ps-your-turn .ps-dot {
            background: #ef4444; border-color: #ef4444; color: white;
            animation: pulse-dot 1.5s infinite;
        }
        @keyframes pulse-dot {
            0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
            50%      { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
        }
        .ps-label {
            font-size: 9px;
            text-align: center;
            color: #9ca3af;
            line-height: 1.3;
            max-width: 56px;
        }
        .ps-done     .ps-label { color: #10b981; font-weight: 600; }
        .ps-current  .ps-label { color: #667eea; font-weight: 600; }
        .ps-your-turn .ps-label { color: #ef4444; font-weight: 700; }
        .ps-sublabel { font-size: 9px; opacity: 0.85; }
        .ps-line {
            flex: 1;
            height: 2px;
            background: #e5e7eb;
            margin-top: 13px;
            min-width: 8px;
        }
        .ps-line-done { background: #10b981; }

        /* ── Rental card column layout ───────────────────────────── */
        /* Override style.css flex-row so banner+strip stack above image+body */
        .rental-card {
            display: block !important;
        }
        .rental-card-row {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 16px;
        }
        .rental-card-row .rental-image {
            flex-shrink: 0;
            width: 120px;
            height: 120px;
        }
        .rental-card-row .rental-body {
            padding: 0 !important;
        }

        /* ── Your Turn banner ────────────────────────────────────────── */
        .your-turn-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 14px;
            display: flex;
            align-items: center;
            gap: 7px;
            letter-spacing: 0.2px;
        }
        .your-turn-banner.urgent { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }

        /* ── Needs Action panel ──────────────────────────────────────── */
        .needs-action-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .needs-action-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 13px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
        }
        .needs-action-count {
            background: rgba(255,255,255,0.25);
            border-radius: 12px;
            padding: 1px 9px;
            font-size: 13px;
            font-weight: 700;
            margin-left: auto;
        }
        .action-list { padding: 8px 0; }
        .action-item {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 11px 16px;
            text-decoration: none;
            color: inherit;
            border-bottom: 1px solid #f9fafb;
            transition: background 0.12s;
        }
        .action-item:last-child { border-bottom: none; }
        .action-item:hover { background: #f9fafb; }
        .action-dot {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            color: white;
        }
        .action-info { flex: 1; min-width: 0; }
        .action-title { font-size: 13px; font-weight: 700; color: #1a1a2e; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .action-desc  { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .action-role-badge {
            font-size: 9px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
            flex-shrink: 0;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .badge-as-borrower { background: #e0e7ff; color: #3730a3; }
        .badge-as-lender   { background: #dcfce7; color: #166534; }
        .action-arrow { color: #d1d5db; font-size: 13px; flex-shrink: 0; }
        .all-caught-up {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }
        .all-caught-up i { font-size: 38px; color: #10b981; margin-bottom: 10px; display: block; }
        .all-caught-up h3 { font-size: 17px; color: #1a1a2e; margin-bottom: 6px; }

        /* ── Tab action badges ───────────────────────────────────────── */
        .tab-action-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            margin-left: 4px;
            background: #ef4444;
            color: white;
            vertical-align: middle;
        }
        .tab-action-badge.warn { background: #f59e0b; }
        .tab-action-badge.info { background: #667eea; }

        /* ── Role button action badges ───────────────────────────────── */
        .role-btn .tab-action-badge { background: #ef4444; }

        /* ── Review status badge ─────────────────────────────────────── */
        .badge-review { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

        /* ── Filter / Sort bar ───────────────────────────────────────── */
        .rental-filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            flex-wrap: wrap;
            border-radius: 0 0 0 0;
        }
        .filter-chips { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; }
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            border: 1px solid #e5e7eb;
            background: white;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .filter-chip:hover { border-color: #667eea; color: #667eea; }
        .filter-chip.active { background: #667eea; border-color: #667eea; color: white; }
        .fchip-count {
            background: rgba(0,0,0,0.12);
            border-radius: 10px;
            padding: 0 5px;
            font-size: 10px;
            font-weight: 700;
        }
        .filter-chip.active .fchip-count { background: rgba(255,255,255,0.25); }
        .sort-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #9ca3af;
            font-size: 13px;
            flex-shrink: 0;
        }
        .sort-select {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 12px;
            color: #374151;
            background: white;
            cursor: pointer;
            outline: none;
        }
        .sort-select:focus { border-color: #667eea; }
        .rentals-list { padding: 12px 16px; display: flex; flex-direction: column; gap: 12px; }
        .rentals-list .rental-card { margin-bottom: 0; }
        .filter-empty-msg {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
            display: none;
        }
        .filter-empty-msg i { font-size: 32px; display: block; margin-bottom: 10px; }
    </style>
</head>
<body>

    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height:50px;width:auto;">
                </a>
                  <?php include 'includes/search_bar.php'; ?>
                <nav class="main-nav">
                    <a href="index.php" class="nav-link">
    <i class="fas fa-home"></i>
    <span>Home</span>
</a>
                    <a href="my_items.php" class="nav-link">
                        <i class="fas fa-box"></i><span>My Items</span>
                    </a>
                    <a href="create_listing.php" class="nav-link">
                        <i class="fas fa-plus-circle"></i><span>List Item</span>
                    </a>
                    <a href="map.php" class="nav-link">
                        <i class="fas fa-map-marker-alt"></i><span>Map</span>
                    </a>
                </nav>
                <div class="user-section">
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" style="display:none;"></span>
                    </div>
                    <div class="notification-icon" style="cursor:pointer;" title="Messages">
                        <i class="fas fa-comment-dots"></i>
                        <span class="notification-badge" style="display:none;"></span>
                    </div>
                    <div class="user-menu-container">
                        <div class="user-avatar" onclick="toggleUserMenu()">
                            <?php if (!empty($_SESSION['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>"
                                     alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-dropdown" id="userDropdown">
                            <div class="user-dropdown-header">
                                <strong><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></strong>
                                <small><?php echo htmlspecialchars($_SESSION['email']); ?></small>
                            </div>
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <a href="account_info.php"><i class="fas fa-cog"></i> Account Info</a>
                            <a href="my_bookmarks.php"><i class="fas fa-bookmark"></i> Bookmarked Items</a>
                            <hr>
                            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="rentals-wrap">

            <h1>My Rentals</h1>
            <div class="page-sub-row">
                <p class="page-sub">Manage your rentals as a renter or lender.</p>
                <button class="btn-tour-replay" id="tourReplayBtn" onclick="startRentalsTour()">
                    <i class="fas fa-compass"></i> Take the Tour
                </button>
            </div>

            <?php if (isset($_GET['requested'])): ?>
                <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:14px 20px;margin-bottom:20px;color:#065f46;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-check-circle" style="font-size:20px;flex-shrink:0;margin-top:1px;"></i>
                    <div><div style="font-weight:700;font-size:15px;margin-bottom:3px;">Rental request successfully submitted!</div><div style="font-weight:400;">Your rental request has been successfully submitted to the lender. You will receive a notification e-mail when the request has been reviewed.</div></div>
                </div>
            <?php endif; ?>

            <!-- ── Needs Action Panel ──────────────────────────────── -->
            <div class="needs-action-card">
                <div class="needs-action-header" style="cursor:pointer;" onclick="toggleActionPanel()">
                    <i class="fas fa-bell"></i>
                    Needs Action
                    <?php if ($totalActionCount > 0): ?>
                        <span class="needs-action-count"><?php echo $totalActionCount; ?></span>
                    <?php else: ?>
                        <span class="needs-action-count">0</span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down" id="actionChevron" style="margin-left:8px;font-size:13px;transition:transform 0.2s;"></i>
                </div>
                <div id="actionPanelBody">
                    <?php if (empty($actionItems)): ?>
                        <div class="all-caught-up">
                            <i class="fas fa-check-circle"></i>
                            <h3>You're all caught up!</h3>
                            <p style="font-size:13px;">No actions needed right now.</p>
                        </div>
                    <?php else: ?>
                        <div class="action-list">
                            <?php foreach ($actionItems as $item): ?>
                                <a href="<?php echo htmlspecialchars($item['link']); ?>" class="action-item">
                                    <div class="action-dot" style="background:<?php echo $item['color']; ?>;">
                                        <i class="fas <?php echo $item['icon']; ?>"></i>
                                    </div>
                                    <div class="action-info">
                                        <div class="action-title"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <div class="action-desc"><?php echo htmlspecialchars($item['label']); ?> &middot; <?php echo htmlspecialchars($item['other_person']); ?></div>
                                    </div>
                                    <span class="action-role-badge <?php echo $item['role'] === 'borrower' ? 'badge-as-borrower' : 'badge-as-lender'; ?>">
                                        <?php echo $item['role']; ?>
                                    </span>
                                    <i class="fas fa-chevron-right action-arrow"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Role Switcher ────────────────────────────────────── -->
            <div class="role-switcher">
                <button class="role-btn <?php echo $activeRole === 'borrower' ? 'active' : ''; ?>"
                        onclick="switchRole('borrower', this)">
                    <i class="fas fa-hand-holding"></i> Renter
                    <?php if ($borrowerActionCount > 0): ?>
                        <span class="tab-action-badge"><?php echo $borrowerActionCount; ?></span>
                    <?php endif; ?>
                </button>
                <button class="role-btn <?php echo $activeRole === 'lender' ? 'active' : ''; ?>"
                        onclick="switchRole('lender', this)">
                    <i class="fas fa-tags"></i> Lender
                    <?php if ($lenderActionCount > 0): ?>
                        <span class="tab-action-badge"><?php echo $lenderActionCount; ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- BORROWER PANEL -->
<div class="role-panel <?php echo $activeRole === 'borrower' ? 'active' : ''; ?>" id="role-borrower">

    <!-- Filter / Sort bar -->
    <div class="rental-filter-bar" data-role-filter="borrower">
        <div class="filter-chips">
            <button class="filter-chip active" data-filter="all">
                All
                <span class="fchip-count"><?php echo count($currentRentals) + count($upcomingRentals) + count($pendingRentals); ?></span>
            </button>
            <?php if (!empty($currentRentals)): ?>
            <button class="filter-chip" data-filter="active">
                Active
                <span class="fchip-count"><?php echo count(array_filter($currentRentals, fn($r) => (int)$r['RentalStatusID'] === 4)); ?></span>
            </button>
            <?php endif; ?>
            <?php if (!empty($upcomingRentals)): ?>
            <button class="filter-chip" data-filter="upcoming">
                Upcoming
                <span class="fchip-count"><?php echo count($upcomingRentals); ?></span>
            </button>
            <?php endif; ?>
            <?php if (!empty($pendingRentals)): ?>
            <button class="filter-chip" data-filter="pending">
                Pending
                <span class="fchip-count"><?php echo count($pendingRentals); ?></span>
            </button>
            <?php endif; ?>
            <?php $reviewCount = count(array_filter($currentRentals, fn($r) => (int)$r['RentalStatusID'] === 7)); if ($reviewCount > 0): ?>
            <button class="filter-chip" data-filter="review">
                Review
                <span class="fchip-count"><?php echo $reviewCount; ?></span>
            </button>
            <?php endif; ?>
        </div>
        <div class="sort-wrap">
            <i class="fas fa-sort-amount-down-alt"></i>
            <select class="sort-select" data-list="borrower-list">
                <option value="end-asc">Return date ↑</option>
                <option value="end-desc">Return date ↓</option>
                <option value="start-asc">Start date ↑</option>
                <option value="start-desc">Start date ↓</option>
                <option value="newest">Newest first</option>
            </select>
        </div>
    </div>

    <!-- Flat rentals list -->
    <div class="rentals-list" id="borrower-list">

        <!-- ── Active / Pending Review ── -->
        <?php foreach ($currentRentals as $r):
            $totalDays       = max(1, intval($r['TotalDays']));
            $totalCost       = $r['PricePerDay'] ? $totalDays * floatval($r['PricePerDay']) : null;
            $daysLeft        = intval($r['DaysRemaining']);
            $pillClass       = $daysLeft <= 1 ? 'urgent' : ($daysLeft <= 3 ? 'warn' : 'soon');
            [$borrowerReturned, $lenderReturned] = getReturnState($pdo, $r['RentalID']);
            $returnComplete  = $borrowerReturned && $lenderReturned;
            $alreadyReviewed = !empty($myReviews[(int)$r['RentalID']]);
            $reviewUrl       = 'reviews.php?user_id=' . (int)$r['UserLenderID'] . '&rental_id=' . (int)$r['RentalID'];
            $deposit         = getDepositState($pdo, $r['RentalID']);
            $cardStatus      = ((int)$r['RentalStatusID'] === 7) ? 'review' : 'active';
        ?>
            <div class="rental-card"
                 style="overflow:hidden;"
                 data-status="<?php echo $cardStatus; ?>"
                 data-end="<?php echo htmlspecialchars($r['EndDate']); ?>"
                 data-start="<?php echo htmlspecialchars($r['StartDate']); ?>"
                 data-added="<?php echo htmlspecialchars($r['UpdatedDate'] ?? $r['StartDate']); ?>">
                <?php if (!$borrowerReturned && !$returnComplete): ?>
                    <div class="your-turn-banner <?php echo $daysLeft <= 1 ? 'urgent' : ''; ?>">
                        <i class="fas fa-hand-point-right"></i>
                        <?php echo $daysLeft <= 1 ? 'Return due soon — confirm return today!' : 'Your turn — confirm return when you hand back the item'; ?>
                    </div>
                <?php elseif ($borrowerReturned && !$lenderReturned): ?>
                    <div class="your-turn-banner" style="background:#f59e0b;">
                        <i class="fas fa-clock"></i> Return submitted — waiting on lender to confirm
                    </div>
                <?php endif; ?>
                <?php renderProgressStrip([
                    'has_deposit'     => $deposit['has_deposit'],
                    'deposit_amount'  => $deposit['amount'],
                    'pickup_borrower' => true,
                    'pickup_lender'   => true,
                    'return_borrower' => $borrowerReturned,
                    'return_lender'   => $lenderReturned,
                    'rental_status'   => (int)$r['RentalStatusID'],
                    'is_borrower'     => true,
                    'other_name'      => $r['LenderFirstName'],
                    'total_cost'      => $totalCost,
                ]); ?>
                <div class="rental-card-row">
                    <div class="rental-image">
                        <?php if (!empty($r['PrimaryImage'])): ?>
                            <img src="<?php echo htmlspecialchars($r['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($r['Title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="rental-body" style="flex:1;min-width:0;">
                        <div class="rental-title-row">
                            <a href="item_detail.php?id=<?php echo $r['ListingID']; ?>" class="rental-title">
                                <?php echo htmlspecialchars($r['Title']); ?>
                            </a>
                            <span class="rental-person">
                                Owner: <?php echo htmlspecialchars($r['LenderFirstName'] . ' ' . strtoupper(substr($r['LenderLastName'], 0, 1)) . '.'); ?>
                            </span>
                            <?php if ((int)$r['RentalStatusID'] === 7): ?>
                                <span class="status-badge badge-review">
                                    <i class="fas fa-star"></i> Review Needed
                                </span>
                            <?php else: ?>
                                <span class="status-badge badge-active">
                                    <i class="fas fa-circle" style="font-size:7px;"></i> Active
                                </span>
                            <?php endif; ?>
                            <span class="days-pill <?php echo $pillClass; ?>">
                                <?php echo $daysLeft; ?> day<?php echo $daysLeft != 1 ? 's' : ''; ?> left
                            </span>
                        </div>
                        <div class="rental-info-row">
                            <div><div class="rental-info-label">Pickup Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['StartDate'])); ?></div></div>
                            <div><div class="rental-info-label">Return Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['EndDate'])); ?></div></div>
                            <div><div class="rental-info-label">Price/Day</div><div class="rental-info-value">$<?php echo number_format(floatval($r['PricePerDay']), 2); ?></div></div>
                            <div><div class="rental-info-label">Total</div><div class="rental-info-value price-total"><?php echo $totalCost !== null ? '$' . number_format($totalCost, 2) : '—'; ?></div></div>
                        </div>
                        <div class="rental-actions">
                            <?php if ($returnComplete): ?>
                                <?php if (!$alreadyReviewed): ?>
                                    <a href="<?php echo htmlspecialchars($reviewUrl); ?>" class="btn-primary-action">
                                        <i class="fas fa-star"></i> Review Lender
                                    </a>
                                <?php else: ?>
                                    <span class="btn-outline-action">
                                        <i class="fas fa-check"></i> Review Submitted
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($borrowerReturned && !$lenderReturned): ?>
                                <button type="button" class="btn-outline-action" disabled>
                                    <i class="fas fa-clock"></i> Waiting on Lender
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn-primary-action openReturnModal"
                                        data-rental-id="<?php echo (int)$r['RentalID']; ?>">
                                    <i class="fas fa-undo"></i> Return Item
                                </button>
                            <?php endif; ?>
                            <?php if (!$returnComplete): ?>
                                <button type="button" class="btn-outline-action openExtendModal"
                                        data-rental-id="<?php echo (int)$r['RentalID']; ?>"
                                        data-current-end="<?php echo htmlspecialchars(date('Y-m-d', strtotime($r['EndDate']))); ?>">
                                    <i class="fas fa-clock"></i> Extend Rental
                                </button>
                            <?php endif; ?>
                            <a href="messages.php?profile_id=<?php echo (int)$r['UserLenderID']; ?>" class="btn-outline-action">
                                <i class="fas fa-comment"></i> Message Lender
                            </a>
                        </div>
                    </div><!-- end rental-body -->
                </div><!-- end rental-card-row -->
            </div><!-- end rental-card -->
        <?php endforeach; ?>

        <!-- ── Upcoming ── -->
        <?php foreach ($upcomingRentals as $r):
            $totalDays = max(1, intval($r['TotalDays']));
            $totalCost = $r['PricePerDay'] ? $totalDays * floatval($r['PricePerDay']) : null;
            $daysUntil = intval($r['DaysUntil']);
            [$borrowerConfirmed, $lenderConfirmed] = getPickupState($pdo, $r['RentalID']);
            $pickupComplete = $borrowerConfirmed && $lenderConfirmed;
            $deposit        = getDepositState($pdo, $r['RentalID']);
        ?>
            <div class="rental-card"
                 style="overflow:hidden;"
                 data-status="upcoming"
                 data-end="<?php echo htmlspecialchars($r['EndDate']); ?>"
                 data-start="<?php echo htmlspecialchars($r['StartDate']); ?>"
                 data-added="<?php echo htmlspecialchars($r['UpdatedDate'] ?? $r['StartDate']); ?>">
                <?php if (!$borrowerConfirmed && !$pickupComplete): ?>
                    <div class="your-turn-banner">
                        <i class="fas fa-hand-point-right"></i>
                        <?php echo $lenderConfirmed ? 'Lender confirmed — your turn to confirm pickup' : 'Confirm pickup once you receive the item'; ?>
                    </div>
                <?php elseif ($borrowerConfirmed && !$lenderConfirmed): ?>
                    <div class="your-turn-banner" style="background:#f59e0b;">
                        <i class="fas fa-clock"></i> Pickup confirmed — waiting on lender
                    </div>
                <?php endif; ?>
                <?php renderProgressStrip([
                    'has_deposit'     => $deposit['has_deposit'],
                    'deposit_amount'  => $deposit['amount'],
                    'pickup_borrower' => $borrowerConfirmed,
                    'pickup_lender'   => $lenderConfirmed,
                    'return_borrower' => false,
                    'return_lender'   => false,
                    'rental_status'   => 2,
                    'is_borrower'     => true,
                    'other_name'      => $r['LenderFirstName'],
                    'total_cost'      => $totalCost,
                ]); ?>
                <div class="rental-card-row">
                    <div class="rental-image">
                        <?php if (!empty($r['PrimaryImage'])): ?>
                            <img src="<?php echo htmlspecialchars($r['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($r['Title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="rental-body" style="flex:1;min-width:0;">
                        <div class="rental-title-row">
                            <a href="item_detail.php?id=<?php echo $r['ListingID']; ?>" class="rental-title">
                                <?php echo htmlspecialchars($r['Title']); ?>
                            </a>
                            <span class="rental-person">
                                Owner: <?php echo htmlspecialchars($r['LenderFirstName'] . ' ' . strtoupper(substr($r['LenderLastName'], 0, 1)) . '.'); ?>
                            </span>
                            <span class="status-badge badge-confirmed">
                                <i class="fas fa-check"></i> Confirmed
                            </span>
                            <span class="days-pill soon">
                                In <?php echo $daysUntil; ?> day<?php echo $daysUntil != 1 ? 's' : ''; ?>
                            </span>
                        </div>
                        <div class="rental-info-row">
                            <div><div class="rental-info-label">Pickup Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['StartDate'])); ?></div></div>
                            <div><div class="rental-info-label">Return Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['EndDate'])); ?></div></div>
                            <div><div class="rental-info-label">Price/Day</div><div class="rental-info-value">$<?php echo number_format(floatval($r['PricePerDay']), 2); ?></div></div>
                            <div><div class="rental-info-label">Total</div><div class="rental-info-value price-total"><?php echo $totalCost !== null ? '$' . number_format($totalCost, 2) : '—'; ?></div></div>
                        </div>
                        <div class="rental-actions">
                            <?php if ($pickupComplete): ?>
                                <button type="button" class="btn-green-action" style="background:#16a34a;cursor:default;" disabled>
                                    <i class="fas fa-check-circle"></i> Pickup Complete
                                </button>
                            <?php elseif ($borrowerConfirmed && !$lenderConfirmed): ?>
                                <button type="button" class="btn-outline-action" disabled style="cursor:default;opacity:0.9;">
                                    <i class="fas fa-hourglass-half"></i> Waiting on Lender
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn-green-action openPickupModal"
                                        data-rental-id="<?php echo (int)$r['RentalID']; ?>">
                                    <i class="fas fa-box"></i> Confirm Pickup
                                </button>
                            <?php endif; ?>
                            <a href="messages.php" class="btn-outline-action">
                                <i class="fas fa-comment"></i> Message Owner
                            </a>
                            <?php if (!$pickupComplete): ?>
                                <button type="button" class="btn-outline-action cancelRentalBtn"
                                        data-rental-id="<?php echo (int)$r['RentalID']; ?>">
                                    <i class="fas fa-ban"></i> Cancel
                                </button>
                            <?php endif; ?>
                        </div>
                    </div><!-- end rental-body -->
                </div><!-- end rental-card-row -->
            </div><!-- end rental-card -->
        <?php endforeach; ?>

        <!-- ── Pending Requests ── -->
        <?php foreach ($pendingRentals as $r):
            $totalDays = max(1, intval($r['TotalDays']));
            $totalCost = $r['PricePerDay'] ? $totalDays * floatval($r['PricePerDay']) : null;
        ?>
            <div class="rental-card"
                 data-status="pending"
                 data-end="<?php echo htmlspecialchars($r['EndDate']); ?>"
                 data-start="<?php echo htmlspecialchars($r['StartDate']); ?>"
                 data-added="<?php echo htmlspecialchars($r['RequestDate']); ?>">
                <div class="rental-card-row">
                    <div class="rental-image">
                        <?php if (!empty($r['PrimaryImage'])): ?>
                            <img src="<?php echo htmlspecialchars($r['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($r['Title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="rental-body">
                        <div class="rental-title-row">
                            <a href="item_detail.php?id=<?php echo $r['ListingID']; ?>" class="rental-title">
                                <?php echo htmlspecialchars($r['Title']); ?>
                            </a>
                            <span class="rental-person">
                                Owner: <?php echo htmlspecialchars($r['LenderFirstName'] . ' ' . strtoupper(substr($r['LenderLastName'], 0, 1)) . '.'); ?>
                            </span>
                            <span class="status-badge badge-pending">
                                <i class="fas fa-hourglass-half"></i> Pending
                            </span>
                        </div>
                        <div class="rental-info-row">
                            <div><div class="rental-info-label">Pickup Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['StartDate'])); ?></div></div>
                            <div><div class="rental-info-label">Return Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['EndDate'])); ?></div></div>
                            <div><div class="rental-info-label">Price/Day</div><div class="rental-info-value">$<?php echo number_format(floatval($r['PricePerDay']), 2); ?></div></div>
                            <div><div class="rental-info-label">Est. Total</div><div class="rental-info-value price-total"><?php echo $totalCost !== null ? '$' . number_format($totalCost, 2) : '—'; ?></div></div>
                        </div>
                        <div style="font-size:12px;color:#9ca3af;margin-bottom:12px;">
                            Requested on <?php echo date('M j, Y g:i A', strtotime($r['RequestDate'])); ?>
                        </div>
                        <div class="rental-actions">
                            <form method="POST" action="my_rentals.php?role=borrower&tab=pending" style="display:inline;">
                                <input type="hidden" name="cancel_request_id" value="<?php echo (int)$r['RentalRequestID']; ?>">
                                <button type="submit" class="btn-cancel-action" onclick="return confirm('Cancel this request?');">
                                    <i class="fas fa-times"></i> Cancel Request
                                </button>
                            </form>
                        </div>
                    </div>
                </div><!-- end rental-card-row -->
            </div><!-- end rental-card -->
        <?php endforeach; ?>

        <!-- Empty state -->
        <?php if (empty($currentRentals) && empty($upcomingRentals) && empty($pendingRentals)): ?>
            <div class="empty-rental-state">
                <i class="fas fa-box-open"></i>
                <h3>No rentals yet</h3>
                <p><a href="home.php">Browse items</a> to find something to rent.</p>
            </div>
        <?php endif; ?>

    </div><!-- end borrower-list -->
</div><!-- end role-borrower -->

            <!-- LENDER PANEL -->
            <div class="role-panel <?php echo $activeRole === 'lender' ? 'active' : ''; ?>" id="role-lender">

    <!-- Filter / Sort bar -->
    <div class="rental-filter-bar" data-role-filter="lender">
        <div class="filter-chips">
            <button class="filter-chip active" data-filter="all">
                All
                <span class="fchip-count"><?php echo count($lenderActiveRentals) + count($lenderUpcomingRentals) + count($lenderPendingRequests); ?></span>
            </button>
            <?php if (!empty($lenderActiveRentals)): ?>
            <button class="filter-chip" data-filter="active">
                Active
                <span class="fchip-count"><?php echo count(array_filter($lenderActiveRentals, fn($r) => (int)$r['RentalStatusID'] === 4)); ?></span>
            </button>
            <?php endif; ?>
            <?php if (!empty($lenderUpcomingRentals)): ?>
            <button class="filter-chip" data-filter="upcoming">
                Upcoming
                <span class="fchip-count"><?php echo count($lenderUpcomingRentals); ?></span>
            </button>
            <?php endif; ?>
            <?php if (!empty($lenderPendingRequests)): ?>
            <button class="filter-chip" data-filter="request">
                Requests
                <span class="fchip-count"><?php echo count($lenderPendingRequests); ?></span>
            </button>
            <?php endif; ?>
            <?php $lenderReviewCount = count(array_filter($lenderActiveRentals, fn($r) => (int)$r['RentalStatusID'] === 7)); if ($lenderReviewCount > 0): ?>
            <button class="filter-chip" data-filter="review">
                Review
                <span class="fchip-count"><?php echo $lenderReviewCount; ?></span>
            </button>
            <?php endif; ?>
        </div>
        <div class="sort-wrap">
            <i class="fas fa-sort-amount-down-alt"></i>
            <select class="sort-select" data-list="lender-list">
                <option value="end-asc">Return date ↑</option>
                <option value="end-desc">Return date ↓</option>
                <option value="start-asc">Start date ↑</option>
                <option value="start-desc">Start date ↓</option>
                <option value="newest">Newest first</option>
            </select>
        </div>
    </div>

    <!-- Flat rentals list -->
    <div class="rentals-list" id="lender-list">

        <!-- ── Active / Pending Review ── -->
        <?php foreach ($lenderActiveRentals as $r):
            $totalDays       = max(1, intval($r['TotalDays']));
            $totalCost       = $r['PricePerDay'] ? $totalDays * floatval($r['PricePerDay']) : null;
            $daysLeft        = intval($r['DaysRemaining']);
            $pillClass       = $daysLeft <= 1 ? 'urgent' : ($daysLeft <= 3 ? 'warn' : 'soon');
            [$borrowerReturned, $lenderReturned] = getReturnState($pdo, $r['RentalID']);
            $returnComplete  = $borrowerReturned && $lenderReturned;
            $alreadyReviewed = !empty($myReviews[(int)$r['RentalID']]);
            $reviewUrl       = 'reviews.php?user_id=' . (int)$r['UserBorrowerID'] . '&rental_id=' . (int)$r['RentalID'];
            $deposit         = getDepositState($pdo, $r['RentalID']);
            $lCardStatus     = ((int)$r['RentalStatusID'] === 7) ? 'review' : 'active';
        ?>
            <div class="rental-card"
                 style="overflow:hidden;"
                 data-status="<?php echo $lCardStatus; ?>"
                 data-end="<?php echo htmlspecialchars($r['EndDate']); ?>"
                 data-start="<?php echo htmlspecialchars($r['StartDate']); ?>"
                 data-added="<?php echo htmlspecialchars($r['UpdatedDate'] ?? $r['StartDate']); ?>">
                <?php if ($borrowerReturned && !$lenderReturned): ?>
                    <div class="your-turn-banner urgent">
                        <i class="fas fa-hand-point-right"></i> Borrower returned the item — your turn to confirm receipt
                    </div>
                <?php endif; ?>
                <?php renderProgressStrip([
                    'has_deposit'     => $deposit['has_deposit'],
                    'deposit_amount'  => $deposit['amount'],
                    'pickup_borrower' => true,
                    'pickup_lender'   => true,
                    'return_borrower' => $borrowerReturned,
                    'return_lender'   => $lenderReturned,
                    'rental_status'   => (int)$r['RentalStatusID'],
                    'is_borrower'     => false,
                    'other_name'      => $r['BorrowerFirstName'],
                    'total_cost'      => $totalCost,
                ]); ?>
                <div class="rental-card-row">
                    <div class="rental-image">
                        <?php if (!empty($r['PrimaryImage'])): ?>
                            <img src="<?php echo htmlspecialchars($r['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($r['Title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="rental-body" style="flex:1;min-width:0;">
                        <div class="rental-title-row">
                            <a href="item_detail.php?id=<?php echo $r['ListingID']; ?>" class="rental-title">
                                <?php echo htmlspecialchars($r['Title']); ?>
                            </a>
                            <span class="rental-person">
                                Rented by: <?php echo htmlspecialchars($r['BorrowerFirstName'] . ' ' . strtoupper(substr($r['BorrowerLastName'], 0, 1)) . '.'); ?>
                            </span>
                            <?php if ((int)$r['RentalStatusID'] === 7): ?>
                                <span class="status-badge badge-review">
                                    <i class="fas fa-star"></i> Review Needed
                                </span>
                            <?php else: ?>
                                <span class="status-badge badge-active">
                                    <i class="fas fa-circle" style="font-size:7px;"></i> Active
                                </span>
                            <?php endif; ?>
                            <span class="days-pill <?php echo $pillClass; ?>">
                                <?php echo $daysLeft; ?> day<?php echo $daysLeft != 1 ? 's' : ''; ?> left
                            </span>
                        </div>
                        <div class="rental-info-row">
                            <div><div class="rental-info-label">Pickup Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['StartDate'])); ?></div></div>
                            <div><div class="rental-info-label">Return Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['EndDate'])); ?></div></div>
                            <div><div class="rental-info-label">Price/Day</div><div class="rental-info-value">$<?php echo number_format(floatval($r['PricePerDay']), 2); ?></div></div>
                            <div><div class="rental-info-label">Total</div><div class="rental-info-value price-total"><?php echo $totalCost !== null ? '$' . number_format($totalCost, 2) : '—'; ?></div></div>
                        </div>
                        <div class="rental-actions">
                            <a href="messages.php" class="btn-outline-action">
                                <i class="fas fa-comment"></i> Message Renter
                            </a>
                            <?php if ($returnComplete): ?>
                                <?php if (!$alreadyReviewed): ?>
                                    <a href="<?php echo htmlspecialchars($reviewUrl); ?>" class="btn-primary-action">
                                        <i class="fas fa-star"></i> Review Renter
                                    </a>
                                <?php else: ?>
                                    <span class="btn-outline-action">
                                        <i class="fas fa-check"></i> Review Submitted
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($lenderReturned && !$borrowerReturned): ?>
                                <button type="button" class="btn-outline-action" disabled>
                                    <i class="fas fa-clock"></i> Waiting on Borrower
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn-primary-action openReturnModal"
                                        data-rental-id="<?php echo (int)$r['RentalID']; ?>">
                                    <i class="fas fa-undo"></i> Confirm Item Return
                                </button>
                            <?php endif; ?>
                        </div>
                    </div><!-- end rental-body -->
                </div><!-- end rental-card-row -->
            </div><!-- end rental-card -->
        <?php endforeach; ?>

        <!-- ── Incoming Requests ── -->
        <?php foreach ($lenderPendingRequests as $r):
            $totalDays = max(1, intval($r['TotalDays']));
            $totalCost = $r['PricePerDay'] ? $totalDays * floatval($r['PricePerDay']) : null;
        ?>
            <div class="rental-card"
                 data-status="request"
                 data-end="<?php echo htmlspecialchars($r['EndDate']); ?>"
                 data-start="<?php echo htmlspecialchars($r['StartDate']); ?>"
                 data-added="<?php echo htmlspecialchars($r['RequestDate']); ?>">
                <div class="rental-card-row">
                    <div class="rental-image">
                        <?php if (!empty($r['PrimaryImage'])): ?><img src="<?php echo htmlspecialchars($r['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($r['Title']); ?>"><?php else: ?><i class="fas fa-image"></i><?php endif; ?>
                    </div>
                    <div class="rental-body">
                        <div class="rental-title-row">
                            <a href="item_detail.php?id=<?php echo $r['ListingID']; ?>" class="rental-title"><?php echo htmlspecialchars($r['Title']); ?></a>
                            <span class="rental-person">From: <?php echo htmlspecialchars($r['BorrowerFirstName'] . ' ' . strtoupper(substr($r['BorrowerLastName'], 0, 1)) . '.'); ?></span>
                            <span class="status-badge badge-pending"><i class="fas fa-hourglass-half"></i> Awaiting Your Response</span>
                        </div>
                        <div class="rental-info-row">
                            <div><div class="rental-info-label">Pickup Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['StartDate'])); ?></div></div>
                            <div><div class="rental-info-label">Return Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['EndDate'])); ?></div></div>
                            <div><div class="rental-info-label">Price/Day</div><div class="rental-info-value">$<?php echo number_format(floatval($r['PricePerDay']), 2); ?></div></div>
                            <div><div class="rental-info-label">Est. Total</div><div class="rental-info-value price-total"><?php echo $totalCost !== null ? '$' . number_format($totalCost, 2) : '—'; ?></div></div>
                        </div>
                        <div style="font-size:12px;color:#9ca3af;margin-bottom:12px;">
                            Requested on <?php echo date('M j, Y g:i A', strtotime($r['RequestDate'])); ?>
                        </div>
                        <div class="rental-actions">
                            <a href="accept_rental.php?request_id=<?php echo $r['RentalRequestID']; ?>" class="btn-green-action"><i class="fas fa-eye"></i> Review &amp; Respond</a>
                            <a href="messages.php" class="btn-outline-action"><i class="fas fa-comment"></i> Message Borrower</a>
                        </div>
                    </div>
                </div><!-- end rental-card-row -->
            </div><!-- end rental-card -->
        <?php endforeach; ?>

        <!-- ── Upcoming Handoffs ── -->
        <?php foreach ($lenderUpcomingRentals as $r):
            $totalDays = max(1, intval($r['TotalDays']));
            $totalCost = $r['PricePerDay'] ? $totalDays * floatval($r['PricePerDay']) : null;
            $daysUntil = intval($r['DaysUntil']);
            [$borrowerConfirmed, $lenderConfirmed] = getPickupState($pdo, $r['RentalID']);
            $pickupComplete = $borrowerConfirmed && $lenderConfirmed;
            $deposit        = getDepositState($pdo, $r['RentalID']);
        ?>
            <div class="rental-card"
                 style="overflow:hidden;"
                 data-status="upcoming"
                 data-end="<?php echo htmlspecialchars($r['EndDate']); ?>"
                 data-start="<?php echo htmlspecialchars($r['StartDate']); ?>"
                 data-added="<?php echo htmlspecialchars($r['UpdatedDate'] ?? $r['StartDate']); ?>">
                <?php if ($borrowerConfirmed && !$lenderConfirmed): ?>
                    <div class="your-turn-banner">
                        <i class="fas fa-hand-point-right"></i> Borrower confirmed pickup — your turn to confirm handoff
                    </div>
                <?php elseif (!$borrowerConfirmed && !$lenderConfirmed): ?>
                    <div class="your-turn-banner" style="background:#6b7280;">
                        <i class="fas fa-calendar"></i> Awaiting pickup day
                    </div>
                <?php endif; ?>
                <?php renderProgressStrip([
                    'has_deposit'     => $deposit['has_deposit'],
                    'deposit_amount'  => $deposit['amount'],
                    'pickup_borrower' => $borrowerConfirmed,
                    'pickup_lender'   => $lenderConfirmed,
                    'return_borrower' => false,
                    'return_lender'   => false,
                    'rental_status'   => 2,
                    'is_borrower'     => false,
                    'other_name'      => $r['BorrowerFirstName'],
                    'total_cost'      => $totalCost,
                ]); ?>
                <div class="rental-card-row">
                    <div class="rental-image">
                        <?php if (!empty($r['PrimaryImage'])): ?>
                            <img src="<?php echo htmlspecialchars($r['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($r['Title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="rental-body" style="flex:1;min-width:0;">
                        <div class="rental-title-row">
                            <a href="item_detail.php?id=<?php echo $r['ListingID']; ?>" class="rental-title">
                                <?php echo htmlspecialchars($r['Title']); ?>
                            </a>
                            <span class="rental-person">
                                Borrower: <?php echo htmlspecialchars($r['BorrowerFirstName'] . ' ' . strtoupper(substr($r['BorrowerLastName'], 0, 1)) . '.'); ?>
                            </span>
                            <span class="status-badge badge-confirmed">
                                <i class="fas fa-check"></i> Confirmed
                            </span>
                            <span class="days-pill soon">
                                In <?php echo $daysUntil; ?> day<?php echo $daysUntil != 1 ? 's' : ''; ?>
                            </span>
                        </div>
                        <div class="rental-info-row">
                            <div><div class="rental-info-label">Pickup Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['StartDate'])); ?></div></div>
                            <div><div class="rental-info-label">Return Date</div><div class="rental-info-value"><?php echo date('M j, Y', strtotime($r['EndDate'])); ?></div></div>
                            <div><div class="rental-info-label">Price/Day</div><div class="rental-info-value">$<?php echo number_format(floatval($r['PricePerDay']), 2); ?></div></div>
                            <div><div class="rental-info-label">Total</div><div class="rental-info-value price-total"><?php echo $totalCost !== null ? '$' . number_format($totalCost, 2) : '—'; ?></div></div>
                        </div>
                        <div class="rental-actions">
                            <?php if ($pickupComplete): ?>
                                <button type="button" class="btn-green-action" style="background:#16a34a;cursor:default;" disabled>
                                    <i class="fas fa-check-circle"></i> Pickup Complete
                                </button>
                            <?php elseif ($lenderConfirmed && !$borrowerConfirmed): ?>
                                <button type="button" class="btn-outline-action" disabled style="cursor:default;opacity:0.9;">
                                    <i class="fas fa-hourglass-half"></i> Waiting on Borrower
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn-green-action openPickupModal"
                                        data-rental-id="<?php echo (int)$r['RentalID']; ?>">
                                    <i class="fas fa-box"></i> Start Pickup
                                </button>
                            <?php endif; ?>
                            <a href="messages.php" class="btn-outline-action">
                                <i class="fas fa-comment"></i> Message Renter
                            </a>
                            <?php if (!$pickupComplete): ?>
                                <button type="button" class="btn-outline-action cancelRentalBtn"
                                        data-rental-id="<?php echo (int)$r['RentalID']; ?>">
                                    <i class="fas fa-ban"></i> Cancel
                                </button>
                            <?php endif; ?>
                        </div>
                    </div><!-- end rental-body -->
                </div><!-- end rental-card-row -->
            </div><!-- end rental-card -->
        <?php endforeach; ?>

        <!-- Empty state -->
        <?php if (empty($lenderActiveRentals) && empty($lenderUpcomingRentals) && empty($lenderPendingRequests)): ?>
            <div class="empty-rental-state">
                <i class="fas fa-tags"></i>
                <h3>No lender activity yet</h3>
                <p>Once someone requests to borrow one of your items, it will appear here.</p>
            </div>
        <?php endif; ?>

    </div><!-- end lender-list -->
            </div><!-- end role-lender -->
        </div>
    </div>
    </main>
     <div id="pickupModal" class="pickup-modal-overlay">
    <div class="pickup-modal-box">
        <button type="button" class="pickup-modal-close" id="closePickupModal">&times;</button>
        <div id="pickupModalContent">
            <div class="pickup-loading">Loading...</div>
        </div>
    </div>
</div>

<div id="extendModal" class="pickup-modal-overlay">
    <div class="pickup-modal-box">
        <button type="button" class="pickup-modal-close" id="closeExtendModal">&times;</button>

        <div class="pickup-card">
            <h3>Request Rental Extension</h3>

            <form id="extendForm">
                <input type="hidden" name="extension_action" value="request_extension">
                <input type="hidden" name="rental_id" id="extendRentalId">

                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;">Current return date</label>
                    <input type="text" id="currentEndDateDisplay" disabled
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;background:#f9fafb;">
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label for="extendEndDate" style="display:block;font-weight:600;margin-bottom:6px;">New requested return date</label>
                    <input type="date" name="new_end_date" id="extendEndDate" required
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">
                </div>

                <button type="submit" class="pickup-btn pickup-btn-primary">Send Extension Request</button>
            </form>
        </div>
    </div>
</div>

<div id="returnModal" class="pickup-modal-overlay">
    <div class="pickup-modal-box">
        <button type="button" id="closeReturnModal" class="pickup-modal-close">&times;</button>
        <div id="returnModalContent">
            <div class="pickup-loading">Loading...</div>
        </div>
    </div>
</div>

   <script>
function toggleUserMenu() {
    document.getElementById('userDropdown').classList.toggle('show');
}

window.onclick = function(event) {
    if (!event.target.matches('.user-avatar')) {
        const d = document.getElementById('userDropdown');
        if (d && d.classList.contains('show')) d.classList.remove('show');
    }
};

function updateUrl(role, tab) {
    const url = new URL(window.location.href);
    if (role) url.searchParams.set('role', role);
    if (tab) url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

function getCurrentRole() {
    const activeRoleBtn = document.querySelector('.role-btn.active');
    if (activeRoleBtn && activeRoleBtn.textContent.toLowerCase().includes('lender')) {
        return 'lender';
    }
    return 'borrower';
}

function getCurrentTab(role) {
    return role === 'lender' ? 'active' : 'current';
}

function switchRole(role, btn) {
    document.querySelectorAll('.role-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('role-' + role).classList.add('active');
    btn.classList.add('active');
    updateUrl(role, role === 'lender' ? 'active' : 'current');
}

// ── Filter + Sort ──────────────────────────────────────────────────────────
function initFilterSort(listId) {
    const list = document.getElementById(listId);
    if (!list) return;

    const bar  = list.closest('.role-panel').querySelector('.rental-filter-bar');
    const chips = bar ? bar.querySelectorAll('.filter-chip') : [];
    const sortSel = bar ? bar.querySelector('.sort-select') : null;

    // Inject "no results" message once
    let emptyMsg = list.querySelector('.filter-empty-msg');
    if (!emptyMsg) {
        emptyMsg = document.createElement('div');
        emptyMsg.className = 'filter-empty-msg';
        emptyMsg.innerHTML = '<i class="fas fa-filter"></i>No rentals match this filter.';
        list.appendChild(emptyMsg);
    }

    let currentFilter = 'all';
    let currentSort   = sortSel ? sortSel.value : 'end-asc';

    function apply() {
        const cards = Array.from(list.querySelectorAll('.rental-card'));

        // Filter
        let visibleCount = 0;
        cards.forEach(card => {
            const show = currentFilter === 'all' || card.dataset.status === currentFilter;
            card.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';

        // Sort visible cards by re-appending in order
        const visible = cards.filter(c => c.style.display !== 'none');
        visible.sort((a, b) => {
            if (currentSort === 'newest') {
                return new Date(b.dataset.added || '1970-01-01') - new Date(a.dataset.added || '1970-01-01');
            }
            const [key, dir] = currentSort.split('-');
            const aDate = new Date(a.dataset[key] || a.dataset.end || '9999-12-31');
            const bDate = new Date(b.dataset[key] || b.dataset.end || '9999-12-31');
            return dir === 'asc' ? aDate - bDate : bDate - aDate;
        });
        visible.forEach(card => list.appendChild(card));
    }

    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            currentFilter = chip.dataset.filter;
            apply();
        });
    });

    if (sortSel) {
        sortSel.addEventListener('change', () => {
            currentSort = sortSel.value;
            apply();
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initFilterSort('borrower-list');
    initFilterSort('lender-list');
});

const pickupModal = document.getElementById('pickupModal');
const pickupModalContent = document.getElementById('pickupModalContent');
const closePickupModalBtn = document.getElementById('closePickupModal');

document.querySelectorAll('.openPickupModal').forEach(button => {
    button.addEventListener('click', function () {
        const rentalId = this.dataset.rentalId;

        pickupModal.classList.add('active');
        pickupModalContent.innerHTML = '<div class="pickup-loading">Loading...</div>';

        fetch('start_pickup.php?rental_id=' + encodeURIComponent(rentalId))
            .then(response => response.text())
            .then(html => {
                pickupModalContent.innerHTML = html;
                // Re-execute <script> tags injected via innerHTML
                pickupModalContent.querySelectorAll('script').forEach(oldScript => {
                    const newScript = document.createElement('script');
                    newScript.textContent = oldScript.textContent;
                    document.body.appendChild(newScript);
                    document.body.removeChild(newScript);
                });
            })
            .catch(() => {
                pickupModalContent.innerHTML = '<div class="pickup-status-msg error">Could not load pickup form.</div>';
            });
    });
});

if (closePickupModalBtn) {
    closePickupModalBtn.addEventListener('click', function () {
        pickupModal.classList.remove('active');
        pickupModalContent.innerHTML = '<div class="pickup-loading">Loading...</div>';
    });
}

if (pickupModal) {
    pickupModal.addEventListener('click', function (e) {
        if (e.target === pickupModal) {
            pickupModal.classList.remove('active');
            pickupModalContent.innerHTML = '<div class="pickup-loading">Loading...</div>';
        }
    });
}

function bindReturnEvents() {
    // Logic is now self-contained in start_return.php's IIFE
}

function bindPickupPartialEvents() {
    // Logic is now self-contained in start_pickup.php's IIFE
}

document.addEventListener('click', function (e) {
    const openBtn = e.target.closest('.openReturnModal');
    if (openBtn) {
        const rentalId = openBtn.dataset.rentalId;
        fetch('start_return.php?rental_id=' + encodeURIComponent(rentalId))
            .then(res => res.text())
            .then(html => {
                const modal = document.getElementById('returnModal');
                const modalContent = document.getElementById('returnModalContent');
                if (!modal || !modalContent) return;
                modalContent.innerHTML = html;
                modal.classList.add('active');
                // Re-execute scripts injected via innerHTML
                modalContent.querySelectorAll('script').forEach(oldScript => {
                    const newScript = document.createElement('script');
                    newScript.textContent = oldScript.textContent;
                    document.body.appendChild(newScript);
                    document.body.removeChild(newScript);
                });
            })
            .catch(err => console.error('Return modal load failed:', err));
        return;
    }

    if (
        e.target.id === 'closeReturnModal' ||
        e.target.id === 'returnModal' ||
        e.target.closest('.return-btn-secondary')
    ) {
        const modal = document.getElementById('returnModal');
        const mc    = document.getElementById('returnModalContent');
        if (modal) modal.classList.remove('active');
        if (mc) mc.innerHTML = '';
    }
});


document.addEventListener('click', function (e) {
    const btn = e.target.closest('.openExtendModal');
    if (!btn) return;

    const rentalId = btn.dataset.rentalId;
    const currentEnd = btn.dataset.currentEnd;

    document.getElementById('extendRentalId').value = rentalId;
    document.getElementById('currentEndDateDisplay').value = currentEnd;

    const dateInput = document.getElementById('extendEndDate');
    dateInput.min = currentEnd;
    dateInput.value = '';

    document.getElementById('extendModal').classList.add('active');
});

document.getElementById('closeExtendModal')?.addEventListener('click', function () {
    document.getElementById('extendModal').classList.remove('active');
});

document.getElementById('extendForm')?.addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch('my_rentals.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            document.getElementById('extendModal').classList.remove('active');
            location.reload();
        }
    })
    .catch(err => {
        console.error(err);
        alert('Something went wrong sending the extension request.');
    });
});

function updateReturnButtonState(rentalId, userRole, message) {
    const btn = document.querySelector('.openReturnModal[data-rental-id="' + rentalId + '"]');
    if (!btn) return;

    const actions = btn.closest('.rental-actions');
    if (!actions) return;

    const waitingText = userRole === 'borrower' ? 'Waiting on Lender' : 'Waiting on Borrower';

    const waitingBtn = document.createElement('button');
    waitingBtn.type = 'button';
    waitingBtn.className = 'btn-outline-action';
    waitingBtn.disabled = true;
    waitingBtn.innerHTML = '<i class="fas fa-clock"></i> ' + waitingText;

    btn.replaceWith(waitingBtn);

    const statusMsg = document.createElement('div');
    statusMsg.className = 'pickup-status-msg success';
    statusMsg.style.marginTop = '10px';
    statusMsg.textContent = message || 'Return files saved successfully.';
    actions.appendChild(statusMsg);

    setTimeout(() => {
        statusMsg.remove();
    }, 2500);
}

document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.cancelRentalBtn');
    if (!btn) return;

    const rentalId = btn.dataset.rentalId;
    if (!rentalId) return;

    const ok = confirm('Are you sure you want to cancel this rental?');
    if (!ok) return;

    const formData = new FormData();
    formData.append('rental_id', rentalId);

    btn.disabled = true;

    try {
        const response = await fetch('cancel_rental.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        alert(data.message || 'Rental updated.');

        if (data.success) {
            window.location.reload();
        } else {
            btn.disabled = false;
        }
    } catch (err) {
        alert('Something went wrong cancelling the rental.');
        btn.disabled = false;
    }
});
</script>

    <script src="https://cdn.jsdelivr.net/npm/driver.js@latest/dist/driver.js.iife.js"></script>
    <script>
    function startRentalsTour() {
        const { driver } = window.driver.js;

        // Build steps dynamically — skip any element not present in the DOM
        const allSteps = [
            {
                element: '.needs-action-header',
                popover: {
                    title: '🔔 Needs Action',
                    description: 'This panel collects everything waiting on you — incoming requests, pickups to confirm, returns, and reviews. Tap it to expand or collapse.',
                    side: 'bottom', align: 'start'
                }
            },
            {
                element: '#actionPanelBody',
                popover: {
                    title: 'Action Items',
                    description: 'Each item here is clickable and will take you straight to the rental that needs attention. The badge next to each one tells you whether you\'re acting as a Renter or Lender.',
                    side: 'bottom', align: 'start'
                }
            },
            {
                element: '.role-switcher',
                popover: {
                    title: '👥 Renter / Lender Toggle',
                    description: 'Switch between your activity as a Renter (borrowing things) and as a Lender (sharing your items). Red badges mean something needs your attention on that side.',
                    side: 'bottom', align: 'start'
                }
            },
            {
                element: '#role-borrower .tab-row',
                popover: {
                    title: '📂 Tabs',
                    description: 'Each tab groups rentals by stage — Current (active), Upcoming (confirmed, not started), and Pending (waiting on lender approval). Colored badges highlight tabs that need action.',
                    side: 'bottom', align: 'start'
                }
            },
            {
                element: '.rental-card',
                popover: {
                    title: '🗂️ Rental Card',
                    description: 'Each card represents one rental. You\'ll see the item, who you\'re renting with, dates, pricing, and any status banners.',
                    side: 'bottom', align: 'start'
                }
            },
            {
                element: '.your-turn-banner',
                popover: {
                    title: '🔴 Your Turn Banner',
                    description: 'When this appears at the top of a card, the rental is waiting on you. Purple means a standard action, red means it\'s urgent (like a return due today).',
                    side: 'bottom', align: 'start'
                }
            },
            {
                element: '.progress-strip',
                popover: {
                    title: '📍 Progress Strip',
                    description: 'This tracks every stage of a rental from request to completion. Green = done, blue = next step, pulsing red = your turn right now.',
                    side: 'top', align: 'start'
                }
            },
            {
                element: '.rental-actions',
                popover: {
                    title: '⚡ Action Buttons',
                    description: 'These buttons do the work — confirm a pickup, log a return, request an extension, or message the other person. Only the relevant buttons show for each rental stage.',
                    side: 'top', align: 'start'
                }
            },
            {
                element: '.user-menu-container',
                popover: {
                    title: '🧾 Transaction History',
                    description: 'All charges and payouts for your rentals are recorded in Transaction History — find it under "My Account." You can view printable invoices for every deposit and balance payment.',
                    side: 'bottom', align: 'end'
                }
            }
        ];

        // Filter to only steps whose element exists in the DOM
        const steps = allSteps.filter(step => {
            try { return !!document.querySelector(step.element); } catch(e) { return false; }
        });

        if (steps.length === 0) return;

        const tour = driver({
            showProgress: true,
            animate: true,
            overlayOpacity: 0.55,
            smoothScroll: true,
            allowClose: true,
            progressText: '{{current}} of {{total}}',
            nextBtnText: 'Next →',
            prevBtnText: '← Back',
            doneBtnText: 'Got it ✓',
            onDestroyStarted: () => {
                localStorage.setItem('my_rentals_tour_done', '1');
                tour.destroy();
            },
            steps: steps
        });

        tour.drive();
    }

    // Auto-start on first visit
    document.addEventListener('DOMContentLoaded', function() {
        if (!localStorage.getItem('my_rentals_tour_done')) {
            setTimeout(startRentalsTour, 700);
        }
    });
    </script>

    <?php require_once 'includes/chatbot_widget.php'; ?>
    <?php include 'includes/header_dropdowns.php'; ?>
</body>
</html>
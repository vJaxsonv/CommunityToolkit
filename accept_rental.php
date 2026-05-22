<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId    = intval($_SESSION['user_id']);
$requestId = intval($_GET['request_id'] ?? $_POST['request_id'] ?? 0);

if (!$requestId) {
    header('Location: my_rentals.php');
    exit;
}

// ── Fetch the rental request with full details ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        rr.RentalRequestID, rr.StartDate, rr.EndDate, rr.RequestDate,
        rr.RequestStatusID, rr.RateTypeID,
        l.ListingID, l.Title, l.PricePerDay, l.PricePerHour, l.UserLenderID,
        (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) AS PrimaryImage,
        rs.Status AS RequestStatus,
        rt.RateType,
        n.NeighborhoodName,
        borrower.UserID     AS BorrowerID,
        borrower.FirstName  AS BorrowerFirst,
        borrower.LastName   AS BorrowerLast,
        borrower.ProfilePictureURL AS BorrowerPhoto,
        (SELECT ROUND(AVG(r2.ReviewRating),1) FROM TReviews r2 WHERE r2.UserRevieweeID = borrower.UserID) AS BorrowerRating,
        (SELECT COUNT(*) FROM TReviews r3 WHERE r3.UserRevieweeID = borrower.UserID) AS BorrowerReviewCount
    FROM TRentalRequests rr
    INNER JOIN TListings l          ON rr.ListingID        = l.ListingID
    INNER JOIN TRequestStatuses rs  ON rr.RequestStatusID  = rs.RequestStatusID
    INNER JOIN TRateTypes rt        ON rr.RateTypeID        = rt.RateTypeID
    INNER JOIN TUsers borrower      ON rr.UserBorrowerID    = borrower.UserID
    LEFT  JOIN TNeighborhoods n     ON l.NeighborhoodID     = n.NeighborhoodID
    WHERE rr.RentalRequestID = ?
");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

// Validate: request must exist and logged-in user must be the lender
if (!$req || intval($req['UserLenderID']) !== $userId) {
    header('Location: my_rentals.php?error=notfound');
    exit;
}

// If already actioned, redirect with message
if ($req['RequestStatusID'] != 1) {
    header('Location: my_rentals.php?already_actioned=1');
    exit;
}

// ── Calculate totals ─────────────────────────────────────────────────────────
$days  = max(1, (int) ceil((strtotime($req['EndDate']) - strtotime($req['StartDate'])) / 86400));
$hours = max(1, (int) ceil((strtotime($req['EndDate']) - strtotime($req['StartDate'])) / 3600));

$rateTypeId    = intval($req['RateTypeID']);
$pricePerDay   = floatval($req['PricePerDay']  ?? 0);
$pricePerHour  = floatval($req['PricePerHour'] ?? 0);
if ($rateTypeId == 1 || $rateTypeId == 3) {
    $estimatedTotal = $days * $pricePerDay;
    $rateLabel      = '$' . number_format($pricePerDay, 2) . '/day × ' . $days . ' day' . ($days != 1 ? 's' : '');
} else {
    $estimatedTotal = $hours * $pricePerHour;
    $rateLabel      = '$' . number_format($pricePerHour, 2) . '/hr × ' . $hours . ' hour' . ($hours != 1 ? 's' : '');
}

$error   = '';
$success = '';

// ── Fetch lender's saved cards ────────────────────────────────────────────────
$lenderCards        = [];
$lenderPrimaryCard  = null;
$lenderSelectedCard = (int)($_POST['lender_card_id'] ?? 0);
try {
    $lcFetch = $pdo->prepare("
        SELECT uc.CardID, uc.LastFourDigits, uc.ExpirationMonth, uc.ExpirationYear,
               uc.PrimaryCard, ct.CardTypeName
        FROM TUserCards uc
        INNER JOIN TCardTypes ct ON uc.CardTypeID = ct.CardTypeID
        WHERE uc.UserID = ?
        ORDER BY uc.PrimaryCard DESC, uc.AddedDate DESC
    ");
    $lcFetch->execute([$userId]);
    $lenderCards = $lcFetch->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lenderCards as $c) {
        if ($c['PrimaryCard']) { $lenderPrimaryCard = $c; break; }
    }
    if (!$lenderPrimaryCard && !empty($lenderCards)) $lenderPrimaryCard = $lenderCards[0];
    if (!$lenderSelectedCard && $lenderPrimaryCard) $lenderSelectedCard = (int)$lenderPrimaryCard['CardID'];
} catch (PDOException $e) { $lenderCards = []; }

// ── Handle POST (accept or decline) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!in_array($action, ['accept', 'decline'])) {
        $error = 'Invalid action.';
    } else {
        // Re-verify still pending
        $checkStmt = $pdo->prepare("SELECT RequestStatusID FROM TRentalRequests WHERE RentalRequestID = ?");
        $checkStmt->execute([$requestId]);
        $currentStatus = intval($checkStmt->fetchColumn());

        if ($currentStatus !== 1) {
            header('Location: my_rentals.php?already_actioned=1');
            exit;
        }

        try {
            if ($action === 'accept') {
                $pdo->beginTransaction();

                // Update request status to Accepted (2)
                $pdo->prepare("UPDATE TRentalRequests SET RequestStatusID = 2 WHERE RentalRequestID = ?")
                    ->execute([$requestId]);

                // Create TRentals record (RentalStatusID 2 = Approved)
                $pdo->prepare("
                    INSERT INTO TRentals
                        (ListingID, UserBorrowerID, UserLenderID, RentalRequestID, RentalStatusID,
                         PickUpPhotoURL, ReturnPhotoURL, UserBorrowerCardID, UserLenderCardID, AddedDate, UpdatedDate)
                    VALUES (?, ?, ?, ?, 2, NULL, NULL, NULL, NULL, NOW(), NOW())
                ")->execute([$req['ListingID'], $req['BorrowerID'], $userId, $requestId]);

                $rentalId = $pdo->lastInsertId();

                // ── Resolve card IDs ─────────────────────────────────────────
                // Borrower: use card they selected when making the request (stored in session)
                $borrowerCardId = (int)($_SESSION['borrower_card_' . $requestId] ?? 0);
                if (!$borrowerCardId) {
                    // Fallback to primary card
                    $bcStmt = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? AND PrimaryCard = 1 LIMIT 1");
                    $bcStmt->execute([$req['BorrowerID']]);
                    $borrowerCardId = (int)($bcStmt->fetchColumn() ?: 0);
                    if (!$borrowerCardId) {
                        $bcAny = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? ORDER BY AddedDate DESC LIMIT 1");
                        $bcAny->execute([$req['BorrowerID']]);
                        $borrowerCardId = (int)($bcAny->fetchColumn() ?: 0);
                    }
                }
                // Clear session key now that we've used it
                unset($_SESSION['borrower_card_' . $requestId]);

                // Lender: use card selected on this page (POST), fallback to primary
                $lenderCardId = (int)($_POST['lender_card_id'] ?? 0);
                // Validate it belongs to this lender
                if ($lenderCardId) {
                    $lcVal = $pdo->prepare("SELECT CardID FROM TUserCards WHERE CardID = ? AND UserID = ? LIMIT 1");
                    $lcVal->execute([$lenderCardId, $userId]);
                    if (!$lcVal->fetchColumn()) $lenderCardId = 0;
                }
                if (!$lenderCardId) {
                    $lcStmt = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? AND PrimaryCard = 1 LIMIT 1");
                    $lcStmt->execute([$userId]);
                    $lenderCardId = (int)($lcStmt->fetchColumn() ?: 0);
                    if (!$lenderCardId) {
                        $lcAny = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? ORDER BY AddedDate DESC LIMIT 1");
                        $lcAny->execute([$userId]);
                        $lenderCardId = (int)($lcAny->fetchColumn() ?: 0);
                    }
                }
                // ─────────────────────────────────────────────────────────────

                // Update TRentals with resolved card IDs
                $pdo->prepare("UPDATE TRentals SET UserBorrowerCardID = ?, UserLenderCardID = ? WHERE RentalID = ?")
                    ->execute([$borrowerCardId, $lenderCardId, $rentalId]);

                // Create conversation
                $pdo->prepare("INSERT INTO TConversations (RentalID, AddedDate, LastMessageDate) VALUES (?, NOW(), NOW())")
                    ->execute([$rentalId]);
                $conversationId = $pdo->lastInsertId();

                // Add both parties to conversation
                $pdo->prepare("INSERT INTO TUserConversations (ConversationID, UserID, LastReadDate) VALUES (?, ?, NOW())")
                    ->execute([$conversationId, $userId]);
                $pdo->prepare("INSERT INTO TUserConversations (ConversationID, UserID, LastReadDate) VALUES (?, ?, '2000-01-01 00:00:00')")
                    ->execute([$conversationId, $req['BorrowerID']]);

                // System message
                $sysMsg = 'Rental request accepted! You can now message each other to arrange pickup.';
                $pdo->prepare("
                    INSERT INTO TMessages (ConversationID, UserSenderID, MessageBody, SystemMessage, SentDate)
                    VALUES (?, ?, ?, 1, NOW())
                ")->execute([$conversationId, $userId, $sysMsg]);

                $msgId = $pdo->lastInsertId();

                // Block the rented dates in TListingAvailability.
                // Real schema: ListingID is direct FK; AvailableDate = start, UnavailableDate = end; BlockReasonID=1 = rented.
                $pdo->prepare("
                    INSERT INTO TListingAvailability (ListingID, AvailableDate, UnavailableDate, BlockReasonID)
                    VALUES (?, ?, ?, 1)
                ")->execute([$req['ListingID'], $req['StartDate'], $req['EndDate']]);

                // Update listing status to Rented (3)
                $pdo->prepare("UPDATE TListings SET ListingStatusID = 3 WHERE ListingID = ?")
                    ->execute([$req['ListingID']]);

                // Notify borrower
                $pdo->prepare("
                    INSERT INTO TNotifications
                        (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                         RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                    VALUES (?, 2, ?, ?, ?, 0, ?, 0, ?, 0, NOW())
                ")->execute([
                    $req['BorrowerID'], $requestId, $conversationId, $msgId,
                    $rentalId, 'Your rental request for "' . $req['Title'] . '" has been accepted!'
                ]);

                $pdo->commit();
                header('Location: my_rentals.php?accepted=1');
                exit;

            } else {
                // Decline
                $pdo->beginTransaction();

                // Update request status to Declined (3)
                $pdo->prepare("UPDATE TRentalRequests SET RequestStatusID = 3 WHERE RentalRequestID = ?")
                    ->execute([$requestId]);

                // Restore listing to Available (1)
                $pdo->prepare("UPDATE TListings SET ListingStatusID = 1 WHERE ListingID = ?")
                    ->execute([$req['ListingID']]);

                // Notify borrower
                $pdo->prepare("
                    INSERT INTO TNotifications
                        (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                         RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                    VALUES (?, 3, ?, 0, 0, 0, 0, 0, ?, 0, NOW())
                ")->execute([
                    $req['BorrowerID'], $requestId,
                    'Your rental request for "' . $req['Title'] . '" was declined.'
                ]);

                $pdo->commit();
                header('Location: my_rentals.php?declined=1');
                exit;
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("accept_rental.php error: " . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rental Request – <?php echo htmlspecialchars($req['Title']); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .accept-wrap {
            max-width: 680px;
            margin: 40px auto 80px;
            padding: 0 20px;
        }
        .accept-wrap h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 6px;
        }
        .accept-wrap .subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 28px;
        }
        .request-card {
            background: white;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .request-card-header {
            display: flex;
            gap: 18px;
            padding: 20px;
            border-bottom: 1px solid #f3f4f6;
            align-items: center;
        }
        .listing-thumb {
            width: 90px;
            height: 90px;
            border-radius: 10px;
            object-fit: cover;
            flex-shrink: 0;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #d1d5db;
            font-size: 28px;
        }
        .listing-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
        }
        .listing-info h2 {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 4px;
        }
        .listing-info .pickup-addr {
            font-size: 13px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .request-details {
            padding: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .detail-block label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .detail-block .detail-value {
            font-size: 15px;
            font-weight: 600;
            color: #1a1a2e;
        }
        .detail-block .detail-sub {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        .total-block {
            grid-column: 1 / -1;
            background: #f0f2ff;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .total-block .total-label {
            font-size: 13px;
            font-weight: 600;
            color: #667eea;
        }
        .total-block .total-amount {
            font-size: 22px;
            font-weight: 800;
            color: #1a1a2e;
        }
        .total-block .rate-label {
            font-size: 12px;
            color: #667eea;
            margin-top: 2px;
        }

        /* Borrower card */
        .borrower-card {
            background: white;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .borrower-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }
        .borrower-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .borrower-info h3 {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 3px;
        }
        .borrower-info .borrower-meta {
            font-size: 13px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .star-rating { color: #f59e0b; font-size: 12px; }
        .requested-on {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }

        /* Action buttons */
        .action-bar {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }
        .btn-accept {
            flex: 1;
            padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: opacity 0.2s;
        }
        .btn-accept:hover { opacity: 0.88; }
        .btn-decline {
            flex: 1;
            padding: 14px;
            background: white;
            color: #dc2626;
            border: 2px solid #fecaca;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-decline:hover { background: #fef2f2; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #667eea;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            margin-bottom: 20px;
        }
        .back-link:hover { text-decoration: underline; }
        .alert {
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .confirm-note {
            font-size: 12px;
            color: #9ca3af;
            text-align: center;
            margin-top: 12px;
        }
        @media (max-width: 500px) {
            .request-details { grid-template-columns: 1fr; }
            .action-bar { flex-direction: column; }
        }
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
                    <a href="home.php" class="nav-link">
                        <i class="fas fa-user-circle"></i><span>My Account</span>
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
                    <a href="my_rentals.php" class="nav-link active">
                        <i class="fas fa-calendar"></i><span>My Rentals</span>
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
        <div class="accept-wrap">

            <a href="my_rentals.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to My Rentals
            </a>

            <h1>Rental Request</h1>
            <p class="subtitle">Review the details below and accept or decline this request.</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php $profileUrl = 'profile.php?user_id=' . $req['BorrowerID'] . '&back=' . urlencode('accept_rental.php?request_id=' . $requestId); ?>

            <!-- Borrower info -->
            <div class="borrower-card">
                <a href="<?php echo $profileUrl; ?>" class="borrower-avatar" style="text-decoration:none;color:inherit;">
                    <?php if (!empty($req['BorrowerPhoto'])): ?>
                        <img src="<?php echo htmlspecialchars($req['BorrowerPhoto']); ?>"
                             alt="<?php echo htmlspecialchars($req['BorrowerFirst']); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($req['BorrowerFirst'], 0, 1)); ?>
                    <?php endif; ?>
                </a>
                <div class="borrower-info">
                    <h3><a href="<?php echo $profileUrl; ?>" style="color:inherit;text-decoration:none;"><?php echo htmlspecialchars($req['BorrowerFirst'] . ' ' . strtoupper(substr($req['BorrowerLast'], 0, 1)) . '.'); ?></a></h3>
                    <div class="borrower-meta">
                        <?php if ($req['BorrowerReviewCount'] > 0): ?>
                            <span class="star-rating">
                                <?php
                                $rating = floatval($req['BorrowerRating'] ?? 0);
                                $full = floor($rating);
                                $half = ($rating - $full) >= 0.5;
                                for ($i = 0; $i < $full; $i++) echo '<i class="fas fa-star"></i>';
                                if ($half) echo '<i class="fas fa-star-half-alt"></i>';
                                $empty = 5 - $full - ($half ? 1 : 0);
                                for ($i = 0; $i < $empty; $i++) echo '<i class="far fa-star"></i>';
                                ?>
                            </span>
                            <span><?php echo number_format(floatval($req['BorrowerRating'] ?? 0), 1); ?>
                                (<?php echo $req['BorrowerReviewCount']; ?> review<?php echo $req['BorrowerReviewCount'] != 1 ? 's' : ''; ?>)
                            </span>
                        <?php else: ?>
                            <span class="star-rating" style="color:#d1d5db;">
                                <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
                            </span>
                            <span style="color:#9ca3af;">No reviews yet</span>
                        <?php endif; ?>
                    </div>
                    <div class="requested-on">
                        Requested on <?php echo date('M j, Y \a\t g:i A', strtotime($req['RequestDate'])); ?>
                    </div>
                </div>
            </div>

            <!-- Listing + request details -->
            <div class="request-card">
                <div class="request-card-header">
                    <div class="listing-thumb">
                        <?php if (!empty($req['PrimaryImage'])): ?>
                            <img src="<?php echo htmlspecialchars($req['PrimaryImage']); ?>"
                                 alt="<?php echo htmlspecialchars($req['Title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="listing-info">
                        <h2><?php echo htmlspecialchars($req['Title']); ?></h2>
                        <div class="pickup-addr">
                            <i class="fas fa-map-marker-alt" style="color:#667eea;"></i>
                            <?php echo htmlspecialchars($req['NeighborhoodName'] ?? 'Cincinnati area'); ?>
                        </div>
                        <a href="item_detail.php?id=<?php echo $req['ListingID']; ?>"
                           style="font-size:12px;color:#667eea;text-decoration:none;margin-top:4px;display:inline-block;">
                            View listing <i class="fas fa-external-link-alt" style="font-size:10px;"></i>
                        </a>
                    </div>
                </div>

                <div class="request-details">
                    <div class="detail-block">
                        <label>Start Date</label>
                        <div class="detail-value"><?php echo date('M j, Y', strtotime($req['StartDate'])); ?></div>
                        <div class="detail-sub"><?php echo date('g:i A', strtotime($req['StartDate'])); ?></div>
                    </div>
                    <div class="detail-block">
                        <label>End Date</label>
                        <div class="detail-value"><?php echo date('M j, Y', strtotime($req['EndDate'])); ?></div>
                        <div class="detail-sub"><?php echo date('g:i A', strtotime($req['EndDate'])); ?></div>
                    </div>
                    <div class="detail-block">
                        <label>Duration</label>
                        <div class="detail-value">
                            <?php if ($rateTypeId == 2 || $rateTypeId == 3): ?>
                                <?php echo $days; ?> day<?php echo $days != 1 ? 's' : ''; ?>
                            <?php else: ?>
                                <?php echo $hours; ?> hour<?php echo $hours != 1 ? 's' : ''; ?>
                            <?php endif; ?>
                        </div>
                        <div class="detail-sub"><?php echo htmlspecialchars($req['RateType']); ?> rate</div>
                    </div>
                    <div class="detail-block">
                        <label>Rate</label>
                        <div class="detail-value">
                            <?php if ($rateTypeId == 1 || $rateTypeId == 3): ?>
                                $<?php echo number_format($pricePerDay, 2); ?>/day
                            <?php else: ?>
                                $<?php echo number_format($pricePerHour, 2); ?>/hr
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="total-block">
                        <div>
                            <div class="total-label">Estimated Total</div>
                            <div class="rate-label"><?php echo $rateLabel; ?></div>
                        </div>
                        <div class="total-amount">$<?php echo number_format($estimatedTotal, 2); ?></div>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <form method="POST">
                <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">

                <!-- Lender card selector -->
                <?php
                $lCardIcons = ['Visa'=>'fa-cc-visa','Mastercard'=>'fa-cc-mastercard','American Express'=>'fa-cc-amex','Discover'=>'fa-cc-discover'];
                ?>
                <div style="background:white;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px;">
                    <h3 style="font-size:15px;font-weight:700;color:#1a1a2e;margin:0 0 6px;">
                        <i class="fas fa-credit-card" style="color:#667eea;margin-right:6px;"></i> Your Payment Card
                    </h3>
                    <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">Select which card to receive payout to when this rental completes.</p>

                    <?php if (!empty($lenderCards)): ?>
                        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:12px;">
                            <?php foreach ($lenderCards as $lc):
                                $lcSelected = (int)$lc['CardID'] === $lenderSelectedCard;
                                $lcIcon = $lCardIcons[$lc['CardTypeName']] ?? 'fa-credit-card';
                            ?>
                                <label style="display:flex;align-items:center;gap:12px;padding:11px 14px;border:<?php echo $lcSelected ? '2px solid #667eea;background:#f5f3ff' : '1.5px solid #e5e7eb;background:white'; ?>;border-radius:10px;cursor:pointer;">
                                    <input type="radio" name="lender_card_id" value="<?php echo $lc['CardID']; ?>"
                                           <?php echo $lcSelected ? 'checked' : ''; ?>
                                           style="accent-color:#667eea;width:16px;height:16px;flex-shrink:0;">
                                    <i class="fab <?php echo $lcIcon; ?>" style="font-size:22px;color:#667eea;flex-shrink:0;"></i>
                                    <div style="flex:1;">
                                        <div style="font-size:13px;font-weight:700;color:#1a1a2e;">
                                            <?php echo htmlspecialchars($lc['CardTypeName']); ?> ····<?php echo htmlspecialchars($lc['LastFourDigits']); ?>
                                            <?php if ($lc['PrimaryCard']): ?>
                                                <span style="font-size:10px;font-weight:700;background:#667eea;color:white;border-radius:10px;padding:1px 7px;margin-left:5px;">PRIMARY</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">Expires <?php echo htmlspecialchars($lc['ExpirationMonth'] . '/' . $lc['ExpirationYear']); ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <a href="account_info.php?return_to=<?php echo urlencode('accept_rental.php?request_id=' . $requestId); ?>#payment-methods" style="font-size:12px;color:#667eea;font-weight:600;text-decoration:none;">
                            <i class="fas fa-plus-circle"></i> Add another card
                        </a>
                    <?php else: ?>
                        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:12px 14px;font-size:13px;color:#b91c1c;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-exclamation-circle"></i>
                            You need a payment card on file to accept rentals.
                            <a href="account_info.php?no_card=1&return_to=<?php echo urlencode('accept_rental.php?request_id=' . $requestId); ?>#payment-methods" style="color:#b91c1c;font-weight:700;text-decoration:underline;">Add one →</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="action-bar">
                    <button type="submit" name="action" value="accept" class="btn-accept"
                            <?php echo empty($lenderCards) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>
                            onclick="return confirm('Accept this rental request from <?php echo htmlspecialchars(addslashes($req['BorrowerFirst'])); ?>?');">
                        <i class="fas fa-check-circle"></i> Accept Request
                    </button>
                    <button type="submit" name="action" value="decline" class="btn-decline"
                            onclick="return confirm('Decline this request? The borrower will be notified.');">
                        <i class="fas fa-times-circle"></i> Decline Request
                    </button>
                </div>
                <p class="confirm-note">
                    Accepting will create a rental and open a message thread with the borrower to arrange transfer details for the item.
                    The dates will be marked unavailable on your listing.
                </p>
            </form>

        </div>
    </main>

    <script>
        function toggleUserMenu() {
            document.getElementById('userDropdown').classList.toggle('show');
        }
        window.onclick = function(e) {
            if (!e.target.matches('.user-avatar')) {
                var d = document.getElementById('userDropdown');
                if (d && d.classList.contains('show')) d.classList.remove('show');
            }
        };
    </script>

    <?php include 'includes/header_dropdowns.php'; ?>
    <?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>

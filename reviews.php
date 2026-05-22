<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
$profileUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : $currentUserId;
$rentalId = isset($_GET['rental_id']) ? (int) $_GET['rental_id'] : 0;

if ($profileUserId <= 0) {
    die('Missing or invalid user_id.');
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection not available.');
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function renderStars($rating)
{
    $fullStars = floor($rating);
    $hasHalf = (($rating - $fullStars) >= 0.5);
    $emptyStars = 5 - $fullStars - ($hasHalf ? 1 : 0);

    $html = '';
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<i class="fas fa-star"></i>';
    }
    if ($hasHalf) {
        $html .= '<i class="fas fa-star-half-alt"></i>';
    }
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<i class="far fa-star"></i>';
    }
    return $html;
}

function getReviewTypeLabel($reviewTypeId)
{
    if ((int)$reviewTypeId === 1) {
        return 'Lender Review';
    }
    if ((int)$reviewTypeId === 2) {
        return 'Borrower Review';
    }
    return 'Review';
}

$errors = [];
$success = '';
$profileUser = null;
$reviewContext = null;
$reviews = [];
$averageRating = 0.0;
$totalReviews = 0;
$canLeaveReview = false;
$existingReview = false;
$autoReviewTypeId = 0;
$autoReviewTypeLabel = '';
$autoRevieweeUserId = 0;

$userInRental = false;
$isReviewingCounterparty = false;
$rentalReviewable = false;
$rentalStatusId = 0;

$sessionFirstName = $_SESSION['firstname'] ?? $_SESSION['FirstName'] ?? 'User';
$sessionLastName  = $_SESSION['lastname'] ?? $_SESSION['LastName'] ?? '';
$sessionEmail     = $_SESSION['email'] ?? $_SESSION['Email'] ?? '';
$sessionInitial   = strtoupper(substr($sessionFirstName, 0, 1));

/*
|--------------------------------------------------------------------------
| Header counts and preview data
|--------------------------------------------------------------------------
*/
$unreadMessageCount = 0;
$unreadNotificationCount = 0;
$messagePreviewItems = [];
$notificationPreviewItems = [];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS Cnt
        FROM TMessages
        WHERE UserReceiverID = :user_id
          AND (IsRead = 0 OR IsRead IS NULL)
    ");
    $stmt->execute([':user_id' => $currentUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unreadMessageCount = (int)($row['Cnt'] ?? 0);
} catch (Throwable $e) {
    $unreadMessageCount = 0;
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS Cnt
        FROM TNotifications
        WHERE UserID = :user_id
          AND (IsRead = 0 OR IsRead IS NULL)
    ");
    $stmt->execute([':user_id' => $currentUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unreadNotificationCount = (int)($row['Cnt'] ?? 0);
} catch (Throwable $e) {
    $unreadNotificationCount = 0;
}

try {
    $stmt = $pdo->prepare("
        SELECT MessageID, MessageText, AddedDate
        FROM TMessages
        WHERE UserReceiverID = :user_id
        ORDER BY AddedDate DESC
        LIMIT 5
    ");
    $stmt->execute([':user_id' => $currentUserId]);
    $messagePreviewItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $messagePreviewItems = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT NotificationID, NotificationText, AddedDate
        FROM TNotifications
        WHERE UserID = :user_id
        ORDER BY AddedDate DESC
        LIMIT 5
    ");
    $stmt->execute([':user_id' => $currentUserId]);
    $notificationPreviewItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $notificationPreviewItems = [];
}

/*
|--------------------------------------------------------------------------
| Load profile user
|--------------------------------------------------------------------------
*/
$userStmt = $pdo->prepare("
    SELECT
        UserID,
        FirstName,
        LastName,
        ProfilePictureURL,
        Email
    FROM TUsers
    WHERE UserID = :user_id
      AND (IsDeleted = 0 OR IsDeleted IS NULL)
    LIMIT 1
");
$userStmt->execute([
    ':user_id' => $profileUserId
]);
$profileUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$profileUser) {
    die('User not found.');
}

/*
|--------------------------------------------------------------------------
| Load rental context
|--------------------------------------------------------------------------
*/
if ($rentalId > 0) {
    $contextStmt = $pdo->prepare("
        SELECT
            r.RentalID,
            r.RentalStatusID,
            r.UserBorrowerID,
            r.UserLenderID,
            r.AddedDate AS RentalAddedDate,
            r.PickUpPhotoURL,
            r.ReturnPhotoURL,
            l.Title,
            lender.FirstName AS LenderFirstName,
            lender.LastName AS LenderLastName,
            borrower.FirstName AS BorrowerFirstName,
            borrower.LastName AS BorrowerLastName
        FROM TRentals r
        INNER JOIN TListings l
            ON r.ListingID = l.ListingID
        INNER JOIN TUsers lender
            ON r.UserLenderID = lender.UserID
        INNER JOIN TUsers borrower
            ON r.UserBorrowerID = borrower.UserID
        WHERE r.RentalID = :rental_id
        LIMIT 1
    ");
    $contextStmt->execute([
        ':rental_id' => $rentalId
    ]);
    $reviewContext = $contextStmt->fetch(PDO::FETCH_ASSOC);

    if ($reviewContext) {
        $lenderId = (int)$reviewContext['UserLenderID'];
        $borrowerId = (int)$reviewContext['UserBorrowerID'];
        $rentalStatusId = (int)$reviewContext['RentalStatusID'];

        $userInRental = ($currentUserId === $lenderId || $currentUserId === $borrowerId);

        $isReviewingCounterparty =
            ($currentUserId === $lenderId && $profileUserId === $borrowerId) ||
            ($currentUserId === $borrowerId && $profileUserId === $lenderId);

        $rentalReviewable = ($rentalStatusId === 7);

        if ($currentUserId === $borrowerId && $profileUserId === $lenderId) {
            $autoReviewTypeId = 1;
            $autoReviewTypeLabel = 'Lender';
            $autoRevieweeUserId = $lenderId;
        } elseif ($currentUserId === $lenderId && $profileUserId === $borrowerId) {
            $autoReviewTypeId = 2;
            $autoReviewTypeLabel = 'Borrower';
            $autoRevieweeUserId = $borrowerId;
        }

        if ($userInRental && $isReviewingCounterparty) {
            $dupeStmt = $pdo->prepare("
                SELECT ReviewID
                FROM TReviews
                WHERE RentalID = :rental_id
                  AND UserReviewerID = :reviewer_id
                  AND UserRevieweeID = :reviewee_id
                LIMIT 1
            ");
            $dupeStmt->execute([
                ':rental_id' => $rentalId,
                ':reviewer_id' => $currentUserId,
                ':reviewee_id' => $profileUserId
            ]);
            $existingReview = (bool)$dupeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingReview && $rentalReviewable) {
                $canLeaveReview = true;
            }

            if ($existingReview) {
                $success = 'You already submitted a review for this rental.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Handle review submission through stored procedure
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = trim($_POST['comment'] ?? '');

    if ($comment === '') {
        $errors[] = 'Please enter a comment.';
    }

    if (mb_strlen($comment) > 1000) {
        $errors[] = 'Comment must be under 1000 characters.';
    }

    if (!$reviewContext) {
        $errors[] = 'Invalid rental context.';
    }

    $actualReviewTypeId = 0;
    $revieweeUserId = 0;

    if ($reviewContext) {
        if (
            $currentUserId === (int)$reviewContext['UserBorrowerID'] &&
            $profileUserId === (int)$reviewContext['UserLenderID']
        ) {
            $actualReviewTypeId = 1;
            $revieweeUserId = (int)$reviewContext['UserLenderID'];
        } elseif (
            $currentUserId === (int)$reviewContext['UserLenderID'] &&
            $profileUserId === (int)$reviewContext['UserBorrowerID']
        ) {
            $actualReviewTypeId = 2;
            $revieweeUserId = (int)$reviewContext['UserBorrowerID'];
        }
    }

    if ($actualReviewTypeId === 0) {
        $errors[] = 'Review type could not be determined from this rental.';
    }

    if ($revieweeUserId !== $profileUserId) {
        $errors[] = 'Reviewed user does not match the rental counterparty.';
    }

    if (empty($errors)) {
        try {
            $submitStmt = $pdo->prepare("
                CALL uspSubmitReview(
                    :rental_id,
                    :user_reviewer_id,
                    :user_reviewee_id,
                    :review_type_id,
                    :rating,
                    :review_text
                )
            ");

            $submitStmt->execute([
                ':rental_id' => $rentalId,
                ':user_reviewer_id' => $currentUserId,
                ':user_reviewee_id' => $revieweeUserId,
                ':review_type_id' => $actualReviewTypeId,
                ':rating' => $rating,
                ':review_text' => $comment
            ]);

            $submitStmt->fetch(PDO::FETCH_ASSOC);
            $submitStmt->closeCursor();

            header("Location: reviews.php?user_id=" . $profileUserId . "&rental_id=" . $rentalId . "&review_submitted=1");
            exit;
        } catch (PDOException $e) {
            $message = $e->getMessage();

            if (stripos($message, 'already submitted') !== false) {
                $errors[] = 'You have already submitted a review for this rental.';
            } elseif (stripos($message, 'Rental not found') !== false) {
                $errors[] = 'Rental not found.';
            } elseif (stripos($message, 'completed rental') !== false || stripos($message, 'pending reviews') !== false) {
                $errors[] = 'Reviews can only be submitted when the rental is in the review stage.';
            } elseif (stripos($message, 'not a party to this rental') !== false) {
                $errors[] = 'You are not a party to this rental.';
            } elseif (stripos($message, 'Invalid review type') !== false) {
                $errors[] = 'Invalid review type.';
            } elseif (stripos($message, 'Rating must be between 1 and 5') !== false) {
                $errors[] = 'Rating must be between 1 and 5.';
            } elseif (stripos($message, 'Reviewee must be the other party') !== false) {
                $errors[] = 'You can only review the other person in this rental.';
            } else {
                $errors[] = 'Could not submit review. ' . $message;
            }
        }
    }
}

if (isset($_GET['review_submitted'])) {
    $success = 'Your review was submitted successfully.';
    $existingReview = true;
    $canLeaveReview = false;
}

/*
|--------------------------------------------------------------------------
| Load all reviews for this profile user
|--------------------------------------------------------------------------
*/
$reviewsStmt = $pdo->prepare("
    SELECT
        r.ReviewID,
        r.RentalID,
        r.ReviewTypeID,
        r.ReviewRating,
        r.ReviewText,
        r.AddedDate,
        reviewer.FirstName AS ReviewerFirstName,
        reviewer.LastName AS ReviewerLastName
    FROM TReviews r
    INNER JOIN TUsers reviewer
        ON r.UserReviewerID = reviewer.UserID
    WHERE r.UserRevieweeID = :reviewee_id
    ORDER BY r.AddedDate DESC
");
$reviewsStmt->execute([
    ':reviewee_id' => $profileUserId
]);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Rating summary
|--------------------------------------------------------------------------
*/
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS TotalReviews,
        COALESCE(AVG(ReviewRating), 0) AS AvgRating
    FROM TReviews
    WHERE UserRevieweeID = :reviewee_id
");
$summaryStmt->execute([
    ':reviewee_id' => $profileUserId
]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalReviews = (int)($summary['TotalReviews'] ?? 0);
$averageRating = $totalReviews > 0 ? round((float)$summary['AvgRating'], 1) : 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reviews-page {
            max-width: 900px;
            margin: 30px auto;
        }

        .reviews-summary,
        .review-form-card,
        .review-card,
        .review-context-card,
        .alert {
            background: white;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 24px;
            margin-bottom: 20px;
        }

        .reviews-summary h1,
        .review-context-card h2,
        .review-form-card h2 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .rating-display {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 600;
            color: #333;
            flex-wrap: wrap;
        }

        .stars {
            color: #f5b301;
        }

        .review-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .reviewer-name {
            font-weight: 600;
            color: #222;
        }

        .review-date {
            color: #777;
            font-size: 14px;
        }

        .review-comment {
            color: #444;
            line-height: 1.6;
            white-space: pre-line;
        }

        .review-type-badge {
            display: inline-block;
            background: #eef2ff;
            color: #4f46e5;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
            text-transform: capitalize;
        }

        .context-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 16px;
        }

        .context-box {
            background: #f8f9fc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
        }

        .context-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .context-value {
            font-size: 16px;
            font-weight: 600;
            color: #222;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group select,
        .form-group textarea,
        .form-readonly {
            width: 100%;
            padding: 12px;
            border: 1px solid #d9d9d9;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
        }

        .form-readonly {
            background: #f8f9fc;
            color: #333;
            font-weight: 600;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .review-help-text {
            font-size: 14px;
            color: #666;
            margin-top: -4px;
            margin-bottom: 16px;
        }

        .submit-review-btn {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .submit-review-btn:hover {
            background: #586de0;
        }

        .alert-success {
            border-left: 4px solid #16a34a;
        }

        .alert-error {
            border-left: 4px solid #dc2626;
        }

        .site-logo img {
            height: 50px;
            width: auto;
            display: block;
        }

        .user-menu-container {
            position: relative;
        }

        .user-avatar {
            cursor: pointer;
        }

        .user-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 240px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
            border: 1px solid #ececf3;
            display: none;
            z-index: 1200;
            overflow: hidden;
        }

        .user-dropdown.show {
            display: block;
        }

        .user-dropdown-header {
            padding: 16px;
            border-bottom: 1px solid #ececf3;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .user-dropdown-header strong {
            color: #111827;
            font-size: 14px;
        }

        .user-dropdown-header small {
            color: #6b7280;
            font-size: 12px;
            word-break: break-word;
        }

        .user-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
        }

        .user-dropdown a:hover {
            background: #f8f9fc;
        }

        .user-dropdown hr {
            margin: 0;
            border: none;
            border-top: 1px solid #ececf3;
        }

        .header-popup-group {
            position: relative;
        }

        .popup-trigger {
            border: none;
            background: transparent;
            cursor: pointer;
            position: relative;
        }

        .header-popup-menu {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 320px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.14);
            overflow: hidden;
            display: none;
            z-index: 1500;
        }

        .header-popup-menu.show {
            display: block;
        }

        .header-popup-title {
            padding: 18px 18px 14px;
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            border-bottom: 1px solid #ececf3;
            background: #fff;
        }

        .header-popup-list {
            max-height: 300px;
            overflow-y: auto;
            background: #fff;
        }

        .header-popup-item {
            padding: 14px 18px;
            border-bottom: 1px solid #f0f1f5;
        }

        .header-popup-item:last-child {
            border-bottom: none;
        }

        .header-popup-item-text {
            font-size: 13px;
            line-height: 1.45;
            color: #374151;
            margin-bottom: 4px;
        }

        .header-popup-item-date {
            font-size: 11px;
            color: #9ca3af;
        }

        .header-popup-empty {
            min-height: 170px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 20px;
            text-align: center;
            background: #fff;
        }

        .header-popup-empty i {
            font-size: 34px;
            color: #d1d5db;
        }

        .header-popup-empty-title {
            font-size: 14px;
            font-weight: 700;
            color: #4b5563;
        }

        .header-popup-empty-subtitle {
            font-size: 12px;
            color: #9ca3af;
        }

        .header-popup-footer {
            display: block;
            padding: 14px 18px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #5b6ee1;
            border-top: 1px solid #ececf3;
            background: #fff;
            text-decoration: none;
        }

        .notification-icon {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: inherit;
            text-decoration: none;
        }

        .notification-icon:hover {
            background: rgba(255,255,255,0.10);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -1px;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        @media (max-width: 700px) {
            .context-grid {
                grid-template-columns: 1fr;
            }

            .header-popup-menu {
                width: min(320px, calc(100vw - 24px));
                right: -40px;
            }
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="container">
        <div class="header-content">
            <a href="index.php" class="site-logo">
                <img src="images/Community.png" alt="Community Toolkit">
            </a>

              <?php include 'includes/search_bar.php'; ?>

            <nav class="main-nav">
                <a href="home.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                <a href="my_items.php" class="nav-link">
                    <i class="fas fa-box"></i>
                    <span>My Items</span>
                </a>
                <a href="create_listing.php" class="nav-link">
                    <i class="fas fa-plus-circle"></i>
                    <span>List Item</span>
                </a>
                <a href="map.php" class="nav-link">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Map</span>
                </a>
                <a href="my_rentals.php" class="nav-link">
                    <i class="fas fa-calendar"></i>
                    <span>My Rentals</span>
                </a>
            </nav>

            <div class="user-section">
                <div class="header-popup-group">
                    <button type="button" class="notification-icon popup-trigger" id="notificationsTrigger" onclick="toggleHeaderPopup('notificationsPopup')">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <span class="notification-badge"><?php echo $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount; ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="header-popup-menu" id="notificationsPopup">
                        <div class="header-popup-title">Notifications</div>

                        <?php if (empty($notificationPreviewItems)): ?>
                            <div class="header-popup-empty">
                                <i class="fas fa-bell-slash"></i>
                                <div class="header-popup-empty-title">No notifications yet</div>
                                <div class="header-popup-empty-subtitle">You'll see activity here.</div>
                            </div>
                        <?php else: ?>
                            <div class="header-popup-list">
                                <?php foreach ($notificationPreviewItems as $item): ?>
                                    <div class="header-popup-item">
                                        <div class="header-popup-item-text">
                                            <?php echo e($item['NotificationText'] ?? 'Notification'); ?>
                                        </div>
                                        <div class="header-popup-item-date">
                                            <?php echo !empty($item['AddedDate']) ? e(date('M j, Y g:i A', strtotime($item['AddedDate']))) : ''; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <a href="notifications.php" class="header-popup-footer">See all notifications <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>

                <div class="header-popup-group">
                    <button type="button" class="notification-icon popup-trigger" id="messagesTrigger" onclick="toggleHeaderPopup('messagesPopup')">
                        <i class="fas fa-comment-dots"></i>
                        <?php if ($unreadMessageCount > 0): ?>
                            <span class="notification-badge"><?php echo $unreadMessageCount > 99 ? '99+' : $unreadMessageCount; ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="header-popup-menu" id="messagesPopup">
                        <div class="header-popup-title">Messages</div>

                        <?php if (empty($messagePreviewItems)): ?>
                            <div class="header-popup-empty">
                                <i class="fas fa-comment-slash"></i>
                                <div class="header-popup-empty-title">No messages yet</div>
                                <div class="header-popup-empty-subtitle">Conversations appear after a rental is accepted.</div>
                            </div>
                        <?php else: ?>
                            <div class="header-popup-list">
                                <?php foreach ($messagePreviewItems as $item): ?>
                                    <div class="header-popup-item">
                                        <div class="header-popup-item-text">
                                            <?php echo e($item['MessageText'] ?? 'Message'); ?>
                                        </div>
                                        <div class="header-popup-item-date">
                                            <?php echo !empty($item['AddedDate']) ? e(date('M j, Y g:i A', strtotime($item['AddedDate']))) : ''; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <a href="messages.php" class="header-popup-footer">See all messages <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>

                <div class="user-menu-container">
                    <div class="user-avatar" onclick="toggleUserMenu()">
                        <?php echo e($sessionInitial); ?>
                    </div>

                    <div class="user-dropdown" id="userDropdown">
                        <div class="user-dropdown-header">
                            <strong><?php echo e(trim($sessionFirstName . ' ' . $sessionLastName)); ?></strong>
                            <small><?php echo e($sessionEmail); ?></small>
                        </div>
                        <a href="profile.php?user_id=<?php echo (int)$currentUserId; ?>">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="account_info.php">
                            <i class="fas fa-cog"></i> Account Info
                        </a>
                        <a href="my_bookmarks.php">
                            <i class="fas fa-bookmark"></i> Bookmarked Items
                        </a>
                        <hr>
                        <a href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="main-content">
    <div class="container">
        <div class="reviews-page">

            <div class="reviews-summary">
                <h1>
                    Reviews for
                    <?php echo e($profileUser['FirstName'] . ' ' . strtoupper(substr($profileUser['LastName'], 0, 1)) . '.'); ?>
                </h1>
                <div class="rating-display">
                    <span class="stars"><?php echo renderStars($averageRating); ?></span>
                    <span><?php echo number_format($averageRating, 1); ?>/5</span>
                    <span>(<?php echo $totalReviews; ?> total reviews)</span>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo e($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($reviewContext): ?>
                <div class="review-context-card">
                    <h2>Transaction Review</h2>
                    <p>This review is tied to a real rental transaction.</p>

                    <div class="context-grid">
                        <div class="context-box">
                            <div class="context-label">Item</div>
                            <div class="context-value"><?php echo e($reviewContext['Title']); ?></div>
                        </div>

                        <div class="context-box">
                            <div class="context-label">Lender</div>
                            <div class="context-value">
                                <?php echo e($reviewContext['LenderFirstName'] . ' ' . strtoupper(substr($reviewContext['LenderLastName'], 0, 1)) . '.'); ?>
                            </div>
                        </div>

                        <div class="context-box">
                            <div class="context-label">Borrower</div>
                            <div class="context-value">
                                <?php echo e($reviewContext['BorrowerFirstName'] . ' ' . strtoupper(substr($reviewContext['BorrowerLastName'], 0, 1)) . '.'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canLeaveReview && $reviewContext): ?>
                <div class="review-form-card">
                    <h2>Write a Review</h2>
                    <form action="" method="POST">
                        <div class="form-group">
                            <label>Reviewing</label>
                            <div class="form-readonly">
                                <?php echo e($autoReviewTypeLabel); ?>
                            </div>
                        </div>

                        <div class="review-help-text">
                            Reviews are tied to this rental and can only be submitted once per side.
                        </div>

                        <div class="form-group">
                            <label for="rating">Rating</label>
                            <select id="rating" name="rating" required>
                                <option value="">Select a rating</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Very Good</option>
                                <option value="3">3 - Good</option>
                                <option value="2">2 - Fair</option>
                                <option value="1">1 - Poor</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="comment">Comment</label>
                            <textarea id="comment" name="comment" maxlength="1000" placeholder="Describe your experience..." required></textarea>
                        </div>

                        <button type="submit" class="submit-review-btn">Submit Review</button>
                    </form>
                </div>
            <?php elseif ($rentalId > 0 && $existingReview): ?>
                <div class="review-card">
                    You already submitted a review for this rental.
                </div>
            <?php elseif ($rentalId > 0 && $reviewContext && !$userInRental): ?>
                <div class="review-card">
                    You are not part of this rental.
                </div>
            <?php elseif ($rentalId > 0 && $reviewContext && !$isReviewingCounterparty): ?>
                <div class="review-card">
                    You can only review the other person in this rental.
                </div>
            <?php elseif ($rentalId > 0 && $reviewContext && !$rentalReviewable): ?>
                <div class="review-card">
                    You can't leave a review yet.
                </div>
            <?php endif; ?>

            <?php if (empty($reviews)): ?>
                <div class="review-card">
                    <div class="review-comment">No reviews yet.</div>
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <div class="review-card">
                        <div class="review-type-badge">
                            <?php echo e(getReviewTypeLabel($review['ReviewTypeID'])); ?>
                        </div>

                        <div class="review-meta">
                            <div class="reviewer-name">
                                <?php echo e($review['ReviewerFirstName'] . ' ' . strtoupper(substr($review['ReviewerLastName'], 0, 1)) . '.'); ?>
                            </div>
                            <div class="review-date">
                                <?php echo e(date('M j, Y', strtotime($review['AddedDate']))); ?>
                            </div>
                        </div>

                        <div class="stars" style="margin-bottom: 10px;">
                            <?php for ($i = 0; $i < (int)$review['ReviewRating']; $i++): ?>
                                <i class="fas fa-star"></i>
                            <?php endfor; ?>
                            <?php for ($i = (int)$review['ReviewRating']; $i < 5; $i++): ?>
                                <i class="far fa-star"></i>
                            <?php endfor; ?>
                        </div>

                        <div class="review-comment"><?php echo e($review['ReviewText']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>
</main>

<script>
function toggleUserMenu() {
    const dropdown = document.getElementById('userDropdown');
    const notificationsPopup = document.getElementById('notificationsPopup');
    const messagesPopup = document.getElementById('messagesPopup');

    if (notificationsPopup) notificationsPopup.classList.remove('show');
    if (messagesPopup) messagesPopup.classList.remove('show');

    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

function toggleHeaderPopup(popupId) {
    const popup = document.getElementById(popupId);
    const userDropdown = document.getElementById('userDropdown');
    const allPopups = document.querySelectorAll('.header-popup-menu');

    allPopups.forEach(function(menu) {
        if (menu.id !== popupId) {
            menu.classList.remove('show');
        }
    });

    if (userDropdown) {
        userDropdown.classList.remove('show');
    }

    if (popup) {
        popup.classList.toggle('show');
    }
}

window.addEventListener('click', function(event) {
    const userMenuContainer = event.target.closest('.user-menu-container');
    const popupGroup = event.target.closest('.header-popup-group');

    if (!userMenuContainer) {
        const dropdown = document.getElementById('userDropdown');
        if (dropdown) {
            dropdown.classList.remove('show');
        }
    }

    if (!popupGroup) {
        document.querySelectorAll('.header-popup-menu').forEach(function(menu) {
            menu.classList.remove('show');
        });
    }
});
</script>

<?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>
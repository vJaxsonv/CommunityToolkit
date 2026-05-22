<?php 
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$profileUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT u.*, g.Gender, n.NeighborhoodName, n.City
    FROM TUsers u
    LEFT JOIN TGenders g ON u.GenderID = g.GenderID
    LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
    WHERE u.UserID = ?
");
$stmt->execute([$profileUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: home.php?error=not_found');
    exit;
}

$age = null;
if ($user['DateOfBirth']) {
    $dob = new DateTime($user['DateOfBirth']);
    $now = new DateTime();
    $age = $now->diff($dob)->y;
}

$stmt = $pdo->prepare("SELECT AVG(CAST(ReviewRating AS DECIMAL(3,1))) as avg_rating, COUNT(*) as review_count FROM TReviews WHERE UserRevieweeID = ?");
$stmt->execute([$profileUserId]);
$ratingData = $stmt->fetch(PDO::FETCH_ASSOC);
$avgRating   = $ratingData['avg_rating'] ? round($ratingData['avg_rating'], 1) : null;
$reviewCount = $ratingData['review_count'];

$isOwnProfile = ($profileUserId == $_SESSION['user_id']);

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM TListings l INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID WHERE l.UserLenderID = ? AND ls.Status IN ('Available','Rented')");
$stmt->execute([$profileUserId]);
$listingsCount = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM TRentalRequests WHERE UserBorrowerID = ?");
$stmt->execute([$profileUserId]);
$rentalsCount = $stmt->fetch()['count'];

// ── Handle direct-message POST (create or find existing conversation) ────────
$dmError = '';
$dmConversationId = 0;
if (!$isOwnProfile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_direct_message'])) {
    try {
        // Check if a direct (non-rental) conversation already exists between these two users.
        // Direct conversations have RentalID = 0.
        $existingStmt = $pdo->prepare("
            SELECT c.ConversationID
            FROM TConversations c
            JOIN TUserConversations uc1 ON uc1.ConversationID = c.ConversationID AND uc1.UserID = ?
            JOIN TUserConversations uc2 ON uc2.ConversationID = c.ConversationID AND uc2.UserID = ?
            WHERE c.RentalID = 0
            LIMIT 1
        ");
        $existingStmt->execute([$_SESSION['user_id'], $profileUserId]);
        $existingConv = $existingStmt->fetchColumn();

        if ($existingConv) {
            // Conversation already exists — just redirect to it
            header('Location: messages.php?conversation=' . intval($existingConv));
            exit;
        }

        // Create a new direct conversation (RentalID = 0 marks it as non-rental)
        $pdo->beginTransaction();

        $pdo->prepare("INSERT INTO TConversations (RentalID, AddedDate, LastMessageDate) VALUES (0, NOW(), NOW())")
            ->execute();
        $dmConversationId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO TUserConversations (ConversationID, UserID, LastReadDate) VALUES (?, ?, NOW())")
            ->execute([$dmConversationId, $_SESSION['user_id']]);
        $pdo->prepare("INSERT INTO TUserConversations (ConversationID, UserID, LastReadDate) VALUES (?, ?, '2000-01-01 00:00:00')")
            ->execute([$dmConversationId, $profileUserId]);

        // Opening system message
        $senderName = htmlspecialchars($_SESSION['firstname'] . ' ' . strtoupper(substr($_SESSION['lastname'], 0, 1)) . '.');
        $pdo->prepare("INSERT INTO TMessages (ConversationID, UserSenderID, MessageBody, SystemMessage, SentDate) VALUES (?, ?, ?, 1, NOW())")
            ->execute([$dmConversationId, $_SESSION['user_id'], $senderName . ' started a conversation with you.']);

        // Notify the recipient
        $pdo->prepare("
            INSERT INTO TNotifications
                (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                 RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
            VALUES (?, 8, 0, ?, 0, 0, 0, 0, ?, 0, NOW())
        ")->execute([
            $profileUserId,
            $dmConversationId,
            $_SESSION['firstname'] . ' sent you a message.'
        ]);

        $pdo->commit();
        header('Location: messages.php?conversation=' . $dmConversationId);
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Direct message creation error: ' . $e->getMessage());
        $dmError = 'Something went wrong starting the conversation. Please try again.';
    }
}

// ── Rental/lending history — only fetched for the logged-in user's own profile ──
$rentedHistory = [];
$lentHistory   = [];
if ($isOwnProfile) {
    // Items this user has RENTED (as borrower)
    $rentedStmt = $pdo->prepare("
        SELECT
            l.ListingID,
            l.Title,
            l.PricePerDay,
            c.CategoryName,
            rr.StartDate,
            rr.EndDate,
            rs.Status AS RentalStatus,
            u.FirstName AS LenderFirstName,
            u.LastName  AS LenderLastName,
            u.UserID    AS LenderUserID,
            (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) AS PrimaryImage
        FROM TRentalRequests rr
        INNER JOIN TListings l  ON rr.ListingID = l.ListingID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TUsers u      ON l.UserLenderID = u.UserID
        LEFT  JOIN TRentals rent ON rent.RentalRequestID = rr.RentalRequestID
        LEFT  JOIN TRentalStatuses rs ON rent.RentalStatusID = rs.RentalStatusID
        WHERE rr.UserBorrowerID = ?
        ORDER BY rr.StartDate DESC
        LIMIT 50
    ");
    $rentedStmt->execute([$_SESSION['user_id']]);
    $rentedHistory = $rentedStmt->fetchAll(PDO::FETCH_ASSOC);

    // Items this user has LENT (as lender) — only completed/active rentals
    $lentStmt = $pdo->prepare("
        SELECT
            l.ListingID,
            l.Title,
            l.PricePerDay,
            c.CategoryName,
            rr.StartDate,
            rr.EndDate,
            rs.Status AS RentalStatus,
            u.FirstName AS BorrowerFirstName,
            u.LastName  AS BorrowerLastName,
            u.UserID    AS BorrowerUserID,
            (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) AS PrimaryImage
        FROM TRentals rent
        INNER JOIN TRentalRequests rr ON rent.RentalRequestID = rr.RentalRequestID
        INNER JOIN TListings l        ON rent.ListingID = l.ListingID
        INNER JOIN TCategories c      ON l.CategoryID = c.CategoryID
        INNER JOIN TUsers u           ON rr.UserBorrowerID = u.UserID
        LEFT  JOIN TRentalStatuses rs ON rent.RentalStatusID = rs.RentalStatusID
        WHERE l.UserLenderID = ?
        ORDER BY rr.StartDate DESC
        LIMIT 50
    ");
    $lentStmt->execute([$_SESSION['user_id']]);
    $lentHistory = $lentStmt->fetchAll(PDO::FETCH_ASSOC);
}

// When viewing someone else's profile, fetch their Available AND Rented listings
if (!$isOwnProfile) {
    $stmt = $pdo->prepare("
        SELECT l.ListingID, l.Title, l.Description, l.PricePerDay,
               c.CategoryName, cond.Condition,
               ls.Status AS ListingStatus,
               (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
        FROM TListings l
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        WHERE l.UserLenderID = ? AND ls.Status IN ('Available', 'Rented')
        ORDER BY ls.Status ASC, l.AddedDate DESC
    ");
    $stmt->execute([$profileUserId]);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$success = $_SESSION['profile_success'] ?? null;
$errors  = $_SESSION['profile_errors'] ?? [];
unset($_SESSION['profile_success'], $_SESSION['profile_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($user['FirstName'] . ' ' . substr($user['LastName'], 0, 1) . '.'); ?> - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── Profile page mobile responsive ───────────────────────────────────────── */
@media (max-width: 768px) {

    /* Both own-profile and other-profile: collapse grid to single column */
    .container [style*="grid-template-columns"] {
        display: flex !important;
        flex-direction: column !important;
        gap: 16px !important;
    }

    /* Profile card: un-stick it, full width */
    .profile-card {
        position: static !important;
        width: 100% !important;
        box-sizing: border-box;
    }

    /* Quick-link buttons inside profile card: keep full width */
    .profile-card a[style*="width:100%"] {
        width: 100% !important;
    }

    /* Container padding so content doesn't hug edges */
    .container {
        padding: 12px !important;
    }

    /* Bio + history cards: full width, no overflow */
    .container > div > div:last-child > div {
        width: 100% !important;
        box-sizing: border-box;
    }

    /* ── History table → mobile card layout ─────────────────────────────── */
    /* Hide table header on mobile — labels move inline */
    .history-table-wrap thead {
        display: none;
    }

    /* Each table row becomes a flex card */
    .history-table-wrap table,
    .history-table-wrap tbody {
        display: block;
        width: 100%;
    }

    .history-table-wrap tr {
        display: flex;
        flex-direction: column;
        background: white;
        border: 1px solid #e5e7eb !important;
        border-radius: 10px;
        margin-bottom: 10px;
        padding: 12px 14px;
        gap: 6px;
    }

    /* Each cell becomes full-width with an inline label */
    .history-table-wrap td {
        display: flex;
        align-items: center;
        padding: 3px 0 !important;
        font-size: 13px;
        white-space: normal !important;
        border: none !important;
    }

    /* Inject labels before each cell using data attributes (set via JS below) */
    .history-table-wrap td[data-label]::before {
        content: attr(data-label);
        font-weight: 700;
        color: #374151;
        min-width: 72px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        flex-shrink: 0;
    }

    /* Item cell: let the image + title stack nicely */
    .history-table-wrap td:first-child {
        padding-bottom: 6px !important;
        border-bottom: 1px solid #f3f4f6 !important;
        margin-bottom: 4px;
    }

    /* Status badge: inline */
    .history-table-wrap td:last-child {
        justify-content: flex-start;
    }

    /* ── Other-profile listings grid: single column on mobile ─────────────── */
    .items-column.grid-2 {
        grid-template-columns: 1fr !important;
    }

    /* Tab buttons: shrink text slightly */
    #histTabRented, #histTabLent {
        padding: 8px 12px !important;
        font-size: 13px !important;
    }
}
</style>
</head>

<body data-logged-in="true">
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height: 50px; width: auto;">
                </a>
                  <?php include 'includes/search_bar.php'; ?>
                <nav class="main-nav">
                    <a href="index.php" class="nav-link">
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
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" style="display:none;"></span>
                    </div>
                    <div class="notification-icon" style="cursor: pointer;" title="Messages">
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
        <?php if ($success && $isOwnProfile): ?>
            <div class="success-message" style="max-width:1200px;margin:20px auto;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors) && $isOwnProfile): ?>
            <div class="error-message" style="max-width:1200px;margin:20px auto;">
                <i class="fas fa-exclamation-circle"></i>
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        $backListing = isset($_GET['back']) ? $_GET['back'] : null;
        if ($backListing) {
            if (strpos($backListing, 'accept_rental.php') !== false) {
                $backLabel = 'Back to Rental Request';
            } elseif (strpos($backListing, 'my_rentals.php') !== false) {
                $backLabel = 'Back to My Rentals';
            } else {
                $backLabel = 'Back to Listing';
            }
        }
        ?>
        <?php if (!$isOwnProfile): ?>
            <div style="max-width:1200px;margin:16px auto 0;padding:0 20px;display:flex;gap:10px;flex-wrap:wrap;">
                <?php if ($backListing): ?>
                <a href="<?php echo htmlspecialchars($backListing); ?>"
                   style="display:inline-flex;align-items:center;gap:6px;color:#667eea;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #c7d2fe;border-radius:8px;background:#f0f2ff;transition:background 0.2s;"
                   onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f2ff'">
                    <i class="fas fa-arrow-left"></i> <?php echo $backLabel; ?>
                </a>
                <?php endif; ?>
                <a href="home.php"
                   style="display:inline-flex;align-items:center;gap:6px;color:#667eea;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #c7d2fe;border-radius:8px;background:#f0f2ff;transition:background 0.2s;"
                   onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f2ff'">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        <?php endif; ?>

        <?php if ($isOwnProfile): ?>
        <!-- ── OWN PROFILE: two-column layout ───────────────────────────── -->
        <div class="container" style="max-width:1200px;margin:0 auto;padding:20px;">
            <div style="display:grid;grid-template-columns:280px 1fr;gap:30px;align-items:start;">

                <!-- ── Left column: sticky profile card ── -->
                <div>
                    <div class="profile-card" style="position:sticky;top:90px;">
                        <div class="profile-avatar-large">
                            <?php if (!empty($user['ProfilePictureURL'])): ?>
                                <img src="<?php echo htmlspecialchars($user['ProfilePictureURL']); ?>"
                                     alt="<?php echo htmlspecialchars($user['FirstName']); ?>"
                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['FirstName'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <h1 class="profile-name">
                            <?php echo htmlspecialchars($user['FirstName'] . ' ' . substr($user['LastName'], 0, 1) . '.'); ?>
                        </h1>

                        <?php if ($age): ?>
                            <div class="profile-info-line">
                                <?php echo $age; ?> years old<?php if ($user['Gender']): ?> &bull; <?php echo htmlspecialchars($user['Gender']); ?><?php endif; ?>
                            </div>
                        <?php elseif ($user['Gender']): ?>
                            <div class="profile-info-line"><?php echo htmlspecialchars($user['Gender']); ?></div>
                        <?php endif; ?>

                        <div class="profile-info-line">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($user['City'] ?? 'Cincinnati area'); ?>
                        </div>

                        <?php if ($avgRating): ?>
                            <div class="profile-rating" style="margin-top:10px;">
                                <?php
                                $fullStars   = floor($avgRating);
                                $hasHalfStar = ($avgRating - $fullStars) >= 0.5;
                                for ($i = 0; $i < $fullStars; $i++) echo '<i class="fas fa-star"></i>';
                                if ($hasHalfStar) echo '<i class="fas fa-star-half-alt"></i>';
                                $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
                                for ($i = 0; $i < $emptyStars; $i++) echo '<i class="far fa-star"></i>';
                                ?>
                            </div>
                            <div style="margin-top:4px;">
                                <a href="user_reviews.php?user=<?php echo $profileUserId; ?>" style="color:#667eea;font-size:13px;text-decoration:none;">
                                    <?php echo $avgRating; ?> &bull; <?php echo $reviewCount; ?> review<?php echo $reviewCount != 1 ? 's' : ''; ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="profile-rating" style="color:#999;margin-top:10px;">
                                <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
                            </div>
                            <div style="margin-top:4px;color:#999;font-size:13px;">No reviews yet</div>
                        <?php endif; ?>

                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $listingsCount; ?></div>
                                <div class="stat-label">Items Listed</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $rentalsCount; ?></div>
                                <div class="stat-label">Rentals</div>
                            </div>
                        </div>

                        <!-- Quick links -->
                        <a href="user_reviews.php?user=<?php echo $profileUserId; ?>"
                           style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;color:#667eea;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #c7d2fe;border-radius:8px;background:#f0f2ff;width:100%;box-sizing:border-box;justify-content:center;transition:background 0.2s;"
                           onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f2ff'">
                            <i class="fas fa-star"></i> View All Reviews
                        </a>

                        <a href="transaction_history.php"
                           style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;color:#667eea;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #c7d2fe;border-radius:8px;background:#f0f2ff;width:100%;box-sizing:border-box;justify-content:center;transition:background 0.2s;"
                           onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f2ff'">
                            <i class="fas fa-receipt"></i> Transaction History
                        </a>

                        <a href="account_info.php"
                           style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;color:#374151;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #e5e7eb;border-radius:8px;background:#f9fafb;width:100%;box-sizing:border-box;justify-content:center;transition:background 0.2s;"
                           onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='#f9fafb'">
                            <i class="fas fa-cog"></i> Account Settings
                        </a>
                    </div>
                </div>

                <!-- ── Right column: bio + history ── -->
                <div>
                    <!-- Bio card -->
                    <div style="background:white;border-radius:12px;padding:20px 24px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:20px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                            <h2 style="margin:0;font-size:17px;color:#333;font-weight:700;"><i class="fas fa-user-circle" style="color:#667eea;margin-right:8px;"></i>About Me</h2>
                            <button type="button" class="btn btn-outline btn-sm" id="editBioBtn" onclick="toggleBioEdit()">
                                <i class="fas fa-edit"></i> Edit Bio
                            </button>
                        </div>
                        <div class="bio-display" id="bioDisplay" style="font-size:14px;line-height:1.7;color:#555;">
                            <?php if (!empty($user['Bio'])): ?>
                                <?php echo nl2br(htmlspecialchars($user['Bio'])); ?>
                            <?php else: ?>
                                <span class="bio-empty">No bio added yet. Click "Edit Bio" to add one!</span>
                            <?php endif; ?>
                        </div>
                        <form action="update_profile_bio.php" method="POST" class="bio-edit-form" id="bioEditForm">
                            <div class="form-group">
                                <textarea name="bio" class="bio-textarea" placeholder="Tell others about yourself..." maxlength="750"><?php echo htmlspecialchars($user['Bio'] ?? ''); ?></textarea>
                                <small style="color:#666;display:block;margin-top:5px;">Maximum 750 characters</small>
                            </div>
                            <div style="display:flex;gap:10px;margin-top:15px;">
                                <button type="submit" class="btn btn-primary">Save Bio</button>
                                <button type="button" class="btn btn-outline" onclick="toggleBioEdit()">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Rental / Lending History card -->
                    <div style="background:white;border-radius:12px;padding:20px 24px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                        <h2 style="margin:0 0 16px 0;font-size:17px;color:#333;font-weight:700;"><i class="fas fa-history" style="color:#667eea;margin-right:8px;"></i>Rental History</h2>

                        <!-- Tab bar -->
                        <div style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:20px;">
                    <button id="histTabRented" onclick="switchHistTab('rented')"
                            style="padding:10px 22px;font-size:14px;font-weight:700;background:none;border:none;border-bottom:3px solid #667eea;color:#667eea;cursor:pointer;margin-bottom:-2px;transition:color 0.2s;">
                        <i class="fas fa-hand-holding"></i> Items I've Rented
                    </button>
                    <button id="histTabLent" onclick="switchHistTab('lent')"
                            style="padding:10px 22px;font-size:14px;font-weight:700;background:none;border:none;border-bottom:3px solid transparent;color:#9ca3af;cursor:pointer;margin-bottom:-2px;transition:color 0.2s;">
                        <i class="fas fa-box-open"></i> Items I've Lent
                    </button>
                </div>

                <!-- Rented history -->
                <div id="histPanelRented">
                    <?php if (empty($rentedHistory)): ?>
                        <div class="empty-state" style="padding:30px;">
                            <i class="fas fa-hand-holding fa-2x"></i>
                            <p style="margin-top:10px;">You haven't rented anything yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="history-table-wrap" style="overflow-x:auto;">
                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <thead>
                                    <tr style="background:#f9fafb;text-align:left;">
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Item</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Category</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Lender</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Dates</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rentedHistory as $h):
                                        $statusColors = ['Completed'=>['#d1fae5','#065f46'],'Active'=>['#dbeafe','#1e40af'],'Cancelled'=>['#fee2e2','#991b1b']];
                                        $sc = $statusColors[$h['RentalStatus'] ?? ''] ?? ['#f3f4f6','#374151'];
                                    ?>
                                    <tr style="border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                                        <td data-label="Item" style="padding:10px 14px;">
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <?php if ($h['PrimaryImage']): ?>
                                                    <img src="<?php echo htmlspecialchars($h['PrimaryImage']); ?>" alt="" style="width:36px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;">
                                                <?php else: ?>
                                                    <div style="width:36px;height:36px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;flex-shrink:0;"><i class="fas fa-image"></i></div>
                                                <?php endif; ?>
                                                <a href="item_detail.php?id=<?php echo $h['ListingID']; ?>" style="color:#667eea;font-weight:600;text-decoration:none;">
                                                    <?php echo htmlspecialchars($h['Title']); ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td data-label="Category" style="padding:10px 14px;color:#6b7280;"><?php echo htmlspecialchars($h['CategoryName']); ?></td>
                                        <td data-label="Lender" style="padding:10px 14px;">
                                            <a href="profile.php?user_id=<?php echo $h['LenderUserID']; ?>" style="color:#374151;text-decoration:none;font-weight:500;">
                                                <?php echo htmlspecialchars($h['LenderFirstName'] . ' ' . strtoupper(substr($h['LenderLastName'], 0, 1)) . '.'); ?>
                                            </a>
                                        </td>
                                        <td data-label="Dates" style="padding:10px 14px;color:#6b7280;white-space:nowrap;">
                                            <?php echo date('M j, Y', strtotime($h['StartDate'])); ?>
                                            <?php if ($h['StartDate'] !== $h['EndDate']): ?>
                                                &ndash; <?php echo date('M j, Y', strtotime($h['EndDate'])); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status" style="padding:10px 14px;">
                                            <?php if ($h['RentalStatus']): ?>
                                                <span style="background:<?php echo $sc[0]; ?>;color:<?php echo $sc[1]; ?>;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;">
                                                    <?php echo htmlspecialchars($h['RentalStatus']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="background:#f3f4f6;color:#9ca3af;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Lent history -->
                <div id="histPanelLent" style="display:none;">
                    <?php if (empty($lentHistory)): ?>
                        <div class="empty-state" style="padding:30px;">
                            <i class="fas fa-box-open fa-2x"></i>
                            <p style="margin-top:10px;">You haven't lent any items yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="history-table-wrap" style="overflow-x:auto;">
                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <thead>
                                    <tr style="background:#f9fafb;text-align:left;">
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Item</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Category</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Borrower</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Dates</th>
                                        <th style="padding:10px 14px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lentHistory as $h):
                                        $statusColors = ['Completed'=>['#d1fae5','#065f46'],'Active'=>['#dbeafe','#1e40af'],'Cancelled'=>['#fee2e2','#991b1b']];
                                        $sc = $statusColors[$h['RentalStatus'] ?? ''] ?? ['#f3f4f6','#374151'];
                                    ?>
                                    <tr style="border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                                        <td data-label="Item" style="padding:10px 14px;">
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <?php if ($h['PrimaryImage']): ?>
                                                    <img src="<?php echo htmlspecialchars($h['PrimaryImage']); ?>" alt="" style="width:36px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;">
                                                <?php else: ?>
                                                    <div style="width:36px;height:36px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;flex-shrink:0;"><i class="fas fa-image"></i></div>
                                                <?php endif; ?>
                                                <a href="item_detail.php?id=<?php echo $h['ListingID']; ?>" style="color:#667eea;font-weight:600;text-decoration:none;">
                                                    <?php echo htmlspecialchars($h['Title']); ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td data-label="Category" style="padding:10px 14px;color:#6b7280;"><?php echo htmlspecialchars($h['CategoryName']); ?></td>
                                        <td data-label="Borrower" style="padding:10px 14px;">
                                            <a href="profile.php?user_id=<?php echo $h['BorrowerUserID']; ?>" style="color:#374151;text-decoration:none;font-weight:500;">
                                                <?php echo htmlspecialchars($h['BorrowerFirstName'] . ' ' . strtoupper(substr($h['BorrowerLastName'], 0, 1)) . '.'); ?>
                                            </a>
                                        </td>
                                        <td data-label="Dates" style="padding:10px 14px;color:#6b7280;white-space:nowrap;">
                                            <?php echo date('M j, Y', strtotime($h['StartDate'])); ?>
                                            <?php if ($h['StartDate'] !== $h['EndDate']): ?>
                                                &ndash; <?php echo date('M j, Y', strtotime($h['EndDate'])); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status" style="padding:10px 14px;">
                                            <?php if ($h['RentalStatus']): ?>
                                                <span style="background:<?php echo $sc[0]; ?>;color:<?php echo $sc[1]; ?>;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;">
                                                    <?php echo htmlspecialchars($h['RentalStatus']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="background:#f3f4f6;color:#9ca3af;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div><!-- end .profile-history-section -->
                    </div><!-- end history card -->
                </div><!-- end right column -->
            </div><!-- end grid -->
        </div><!-- end container -->

        <?php else: ?>
        <!-- ── VIEWING SOMEONE ELSE: two-column layout ───────────────────── -->
        <div class="container" style="max-width:1200px;margin:0 auto;padding:20px;">
            <div style="display:grid;grid-template-columns:280px 1fr;gap:30px;align-items:start;">

                <!-- Left: profile card (sticky) -->
                <div>
                    <div class="profile-card" style="position:sticky;top:80px;">
                        <div class="profile-avatar-large">
                            <?php if (!empty($user['ProfilePictureURL'])): ?>
                                <img src="<?php echo htmlspecialchars($user['ProfilePictureURL']); ?>"
                                     alt="<?php echo htmlspecialchars($user['FirstName']); ?>"
                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['FirstName'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <h1 class="profile-name">
                            <?php echo htmlspecialchars($user['FirstName'] . ' ' . substr($user['LastName'], 0, 1) . '.'); ?>
                        </h1>
                        <?php if ($user['City']): ?>
                            <div class="profile-info-line">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($user['City']); ?>
                            </div>
                        <?php endif; ?>
                       
                        <!-- Stars on own line -->
                        <?php if ($avgRating): ?>
                            <div class="profile-rating" style="margin-top:10px;">
                                <?php
                                $fullStars   = floor($avgRating);
                                $hasHalfStar = ($avgRating - $fullStars) >= 0.5;
                                for ($i = 0; $i < $fullStars; $i++) echo '<i class="fas fa-star"></i>';
                                if ($hasHalfStar) echo '<i class="fas fa-star-half-alt"></i>';
                                $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
                                for ($i = 0; $i < $emptyStars; $i++) echo '<i class="far fa-star"></i>';
                                ?>
                            </div>
                            <div style="margin-top:4px;">
                                <a href="user_reviews.php?user=<?php echo $profileUserId; ?>" style="color:#667eea;font-size:13px;text-decoration:none;">
                                    <?php echo $avgRating; ?> &bull; <?php echo $reviewCount; ?> review<?php echo $reviewCount != 1 ? 's' : ''; ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="profile-rating" style="color:#999;margin-top:10px;">
                                <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
                            </div>
                            <div style="margin-top:4px;color:#999;font-size:13px;">No reviews yet</div>
                        <?php endif; ?>

                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $listingsCount; ?></div>
                                <div class="stat-label">Items Listed</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $rentalsCount; ?></div>
                                <div class="stat-label">Rentals</div>
                            </div>
                        </div>

                        <a href="user_reviews.php?user=<?php echo $profileUserId; ?>"
                           style="display:inline-flex;align-items:center;gap:6px;margin-top:12px;color:#667eea;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #c7d2fe;border-radius:8px;background:#f0f2ff;transition:background 0.2s;"
                           onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f2ff'">
                            <i class="fas fa-star"></i> View All Reviews
                        </a>

                        <!-- Message button — only shown when viewing someone else's profile -->
                        <?php if (!$isOwnProfile): ?>
                        <?php if (!empty($dmError)): ?>
                            <div style="margin-top:8px;padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:12px;color:#dc2626;">
                                <?php echo htmlspecialchars($dmError); ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="profile.php?user_id=<?php echo $profileUserId; ?>" style="margin-top:8px;">
                            <input type="hidden" name="start_direct_message" value="1">
                            <button type="submit"
                                    style="display:inline-flex;align-items:center;gap:6px;color:white;font-size:13px;font-weight:600;padding:7px 14px;border:none;border-radius:8px;background:linear-gradient(135deg,#667eea,#764ba2);cursor:pointer;width:100%;justify-content:center;transition:opacity 0.2s;"
                                    onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
                                <i class="fas fa-comment"></i> Message <?php echo htmlspecialchars($user['FirstName']); ?>
                            </button>
                        </form>
                        <?php endif; ?>

                        <a href="report.php?type=user&user_id=<?php echo $profileUserId; ?>&back_url=<?php echo urlencode('profile.php?user=' . $profileUserId); ?>"
                           style="display:inline-flex;align-items:center;gap:6px;color:#dc2626;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #fecaca;border-radius:8px;background:#fef2f2;margin-top:8px;transition:background 0.2s;"
                           onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                            <i class="fas fa-flag"></i> Report User
                        </a>
                    </div>
                </div>

                <!-- Right: bio + listings -->
                <div>
                    <!-- Bio -->
                    <div style="background:white;border-radius:12px;padding:20px 24px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:20px;">
                        <h2 style="margin:0 0 12px 0;font-size:17px;color:#333;font-weight:700;">About <?php echo htmlspecialchars($user['FirstName']); ?></h2>
                        <div style="font-size:14px;line-height:1.7;color:#555;">
                            <?php if (!empty($user['Bio'])): ?>
                                <?php echo nl2br(htmlspecialchars($user['Bio'])); ?>
                            <?php else: ?>
                                <span style="color:#999;font-style:italic;">No bio available.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Listings -->
                    <div style="background:white;border-radius:12px;padding:20px 24px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                            <h2 style="margin:0;font-size:17px;color:#333;font-weight:700;">
                                <?php echo htmlspecialchars($user['FirstName']); ?>'s Listings
                            </h2>
                            <?php if (!empty($listings)): ?>
                            <div style="display:flex;align-items:center;gap:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:6px 12px;">
                                <i class="fas fa-search" style="color:#999;font-size:13px;"></i>
                                <input type="text" id="listingSearch" placeholder="Search listings..."
                                       style="border:none;outline:none;font-size:13px;width:160px;background:transparent;"
                                       oninput="filterListings(this.value)">
                            </div>
                            <?php endif; ?>
                        </div>
                        <div id="noListingResults" style="display:none;text-align:center;padding:20px;color:#999;">No listings match your search.</div>
                    <?php if (empty($listings)): ?>
                        <div class="empty-state" style="padding:30px;">
                            <i class="fas fa-box-open fa-2x"></i>
                            <p style="margin-top:10px;">No items currently listed.</p>
                        </div>
                    <?php else: ?>
                        <div class="items-column grid-2">
                            <?php foreach ($listings as $item): ?>
                                <?php
                                    $isRented    = ($item['ListingStatus'] === 'Rented');
                                    $statusColor = $isRented ? '#f59e0b' : '#10b981';
                                    $statusLabel = $isRented ? 'Currently Rented' : 'Available';
                                    $statusIcon  = $isRented ? 'fa-clock' : 'fa-check-circle';
                                ?>
                                <div class="item-card" data-title="<?php echo htmlspecialchars(strtolower($item['Title'])); ?>" data-category="<?php echo htmlspecialchars(strtolower($item['CategoryName'])); ?>">
                                    <div class="item-image" style="position:relative;">
                                        <?php
                                            $mediaUrl = $item['PrimaryImage'] ?? '';
                                            $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                                            $videoExts = ['mp4', 'webm', 'ogg', 'mov'];
                                            $isVideo = $mediaUrl && in_array($ext, $videoExts);
                                        ?>
                                    
                                        <?php if ($mediaUrl): ?>
                                            <?php if ($isVideo): ?>
                                                <video autoplay muted loop playsinline preload="metadata">
                                                    <source src="<?php echo htmlspecialchars($mediaUrl); ?>" type="video/<?php echo $ext === 'mov' ? 'mp4' : htmlspecialchars($ext); ?>">
                                                    Your browser does not support the video tag.
                                                </video>
                                            <?php else: ?>
                                                <img src="<?php echo htmlspecialchars($mediaUrl); ?>" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div style="width:100%;height:100%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:36px;">
                                                <i class="fas fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    
                                        <!-- Status badge overlaid on image -->
                                        <span style="position:absolute;top:8px;left:8px;background:<?php echo $statusColor; ?>;color:white;font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px;display:inline-flex;align-items:center;gap:4px;box-shadow:0 1px 4px rgba(0,0,0,0.2);z-index:2;">
                                            <i class="fas <?php echo $statusIcon; ?>"></i> <?php echo $statusLabel; ?>
                                        </span>
                                    </div>
                                    <div class="item-details">
                                        <h3 class="item-title"><?php echo htmlspecialchars($item['Title']); ?></h3>
                                        <p class="item-description"><?php echo htmlspecialchars(substr($item['Description'], 0, 120)); ?>...</p>
                                        <div class="item-meta">
                                            <span class="item-category"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['CategoryName']); ?></span>
                                            <span class="item-condition"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($item['Condition']); ?></span>
                                        </div>
                                        <div class="item-price-section" style="margin-top:auto;">
                                            <div class="price-label">Price per day</div>
                                            <div class="price">$<?php echo number_format($item['PricePerDay'] ?? 0, 2); ?></div>
                                        </div>
                                        <div style="margin-top:10px;">
                                            <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>"
                                               style="display:block;text-align:center;padding:7px;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;background:<?php echo $isRented ? '#f59e0b' : '#667eea'; ?>;color:white;">
                                                View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    </div><!-- end listings card -->
                </div>

            </div>
        </div>
        <?php endif; ?>
    </main>

    <script>
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }
        function openChat() {
            alert('Chat functionality coming soon!');
        }
        function toggleBioEdit() {
            const display = document.getElementById('bioDisplay');
            const form    = document.getElementById('bioEditForm');
            const editBtn = document.getElementById('editBioBtn');
            if (form.classList.contains('active')) {
                form.classList.remove('active');
                display.style.display = 'block';
                editBtn.style.display = 'inline-block';
            } else {
                form.classList.add('active');
                display.style.display = 'none';
                editBtn.style.display = 'none';
            }
        }
        window.onclick = function(event) {
            const dropdown = document.getElementById('userDropdown');
            if (!event.target.matches('.user-avatar')) {
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        }

        function switchHistTab(tab) {
            const rBtn = document.getElementById('histTabRented');
            const lBtn = document.getElementById('histTabLent');
            const rPanel = document.getElementById('histPanelRented');
            const lPanel = document.getElementById('histPanelLent');
            if (!rBtn || !lBtn) return;
            if (tab === 'rented') {
                rPanel.style.display = '';
                lPanel.style.display = 'none';
                rBtn.style.borderBottomColor = '#667eea';
                rBtn.style.color = '#667eea';
                lBtn.style.borderBottomColor = 'transparent';
                lBtn.style.color = '#9ca3af';
            } else {
                lPanel.style.display = '';
                rPanel.style.display = 'none';
                lBtn.style.borderBottomColor = '#667eea';
                lBtn.style.color = '#667eea';
                rBtn.style.borderBottomColor = 'transparent';
                rBtn.style.color = '#9ca3af';
            }
        }

        function filterListings(query) {
            const q = query.toLowerCase().trim();
            const cards = document.querySelectorAll('.items-column .item-card');
            let visible = 0;
            cards.forEach(card => {
                const match = !q || (card.dataset.title || '').includes(q) || (card.dataset.category || '').includes(q);
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            const noResults = document.getElementById('noListingResults');
            if (noResults) noResults.style.display = (visible === 0 && q) ? 'block' : 'none';
        }
    </script>
    <?php include 'includes/header_dropdowns.php'; ?>
<?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>
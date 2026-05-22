<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Accept both POST (arriving from item_detail) and GET (re-render after back button)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_SESSION['confirm_data'])) {
    header('Location: home.php');
    exit;
}

// Store POST data in session on initial arrival from item_detail only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirm_submit'])) {
    $_SESSION['confirm_data'] = $_POST;
}

$d = $_SESSION['confirm_data'];

$userId      = $_SESSION['user_id'];
$listingId   = intval($d['listing_id'] ?? 0);
$rateType    = $d['rate_type']    ?? 'daily';
$startDate   = $d['start_date']   ?? '';
$endDate     = $d['end_date']     ?? '';
$startTime   = $d['start_time']   ?? '';
$hours       = intval($d['hours'] ?? 0);
$totalCost   = floatval($d['total_cost'] ?? 0);
$message     = $d['message']      ?? '';
$listingTitle  = $d['listing_title']  ?? '';
$ownerName     = $d['owner_name']     ?? '';
$pricePerDay   = floatval($d['price_per_day']  ?? 0);
$pricePerHour  = floatval($d['price_per_hour'] ?? 0);
$listingImage  = $d['listing_image'] ?? '';

if ($listingId === 0 || empty($startDate)) {
    header('Location: home.php');
    exit;
}

// Calculate display values
$totalDays = 0;
$endDisplay = '';
if ($rateType === 'daily' && $endDate) {
    $totalDays  = (int)((strtotime($endDate) - strtotime($startDate)) / 86400);
    $endDisplay = date('M j, Y', strtotime($endDate));
}
if ($rateType === 'hourly' && $startTime && $hours > 0) {
    $endTs      = strtotime($startDate . ' ' . $startTime) + ($hours * 3600);
    $endDisplay = date('M j, Y g:i A', $endTs);
}

// Handle final submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_submit'])) {
    try {
        // Final conflict check
        $startStr = $startDate . ($startTime ? ' ' . $startTime : ' 00:00:00');
        if ($rateType === 'daily') {
            $endStr = $endDate . ' 00:00:00';
        } else {
            $endTs2  = strtotime($startStr) + ($hours * 3600);
            $endStr  = date('Y-m-d H:i:s', $endTs2);
        }

        // Block check — schema change: ListingID is now a direct FK on TListingAvailability;
        // UnavailableDate is a single date column (no StartDate/EndDate range).
        $blockedStmt = $pdo->prepare("
            SELECT COUNT(*) FROM TListingAvailability la
            WHERE la.ListingID = ?
            AND la.BlockReasonID IN (1,3)
            AND la.UnavailableDate IS NOT NULL
            AND la.UnavailableDate >= ?
            AND la.UnavailableDate < ?
        ");
        $blockedStmt->execute([$listingId, $startStr, $endStr]);
        if ($blockedStmt->fetchColumn() > 0) {
            $error = 'Sorry, one or more of these dates are no longer available.';
        } else {
            // Conflict check
            $conflictStmt = $pdo->prepare("
                SELECT COUNT(*) FROM TRentalRequests
                WHERE ListingID = ? AND RequestStatusID IN (1,2)
                AND StartDate < ? AND EndDate > ?
            ");
            $conflictStmt->execute([$listingId, $endStr, $startStr]);
            if ($conflictStmt->fetchColumn() > 0) {
                $error = 'These dates were just booked by someone else. Please go back and choose new dates.';
            } else {
                $rateTypeId   = ($rateType === 'hourly') ? 2 : 1;
                $borrowerName = $_SESSION['firstname'] . ' ' . strtoupper(substr($_SESSION['lastname'], 0, 1)) . '.';

                $lenderStmt = $pdo->prepare("SELECT UserLenderID, Title FROM TListings WHERE ListingID = ?");
                $lenderStmt->execute([$listingId]);
                $lender = $lenderStmt->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("
                    INSERT INTO TRentalRequests
                        (ListingID, UserBorrowerID, StartDate, EndDate, RateTypeID, RequestStatusID, RequestDate)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                ")->execute([$listingId, $userId, $startStr, $endStr, $rateTypeId]);
                $requestId = $pdo->lastInsertId();

                $pdo->prepare("UPDATE TListings SET ListingStatusID = 2 WHERE ListingID = ?")
                    ->execute([$listingId]);

                if ($lender) {
                    $pdo->prepare("
                        INSERT INTO TNotifications
                            (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                             RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                        VALUES (?, 1, ?, 0, 0, 0, 0, 0, ?, 0, NOW())
                    ")->execute([
                        $lender['UserLenderID'],
                        $requestId,
                        $borrowerName . ' has requested to rent "' . $lender['Title'] . '".'
                    ]);
                }

                unset($_SESSION['confirm_data']);
                header('Location: my_rentals.php?role=borrower&tab=pending&requested=1');
                exit;
            }
        }
    } catch (PDOException $e) {
        error_log("rental_confirm error: " . $e->getMessage());
        $error = 'Something went wrong. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Rental Request - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/ai_chatbot.css">
    <style>
        .confirm-wrap {
            max-width: 780px;
            margin: 40px auto 80px;
            padding: 0 24px;
        }

        .confirm-wrap h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 6px;
        }

        .confirm-wrap .page-sub {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 28px;
        }

        .confirm-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 24px;
            align-items: start;
        }

        .confirm-section {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            padding: 24px 28px;
            margin-bottom: 20px;
        }

        .confirm-section h3 {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            padding-bottom: 12px;
            border-bottom: 2px solid #667eea;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .confirm-section h3 i { color: #667eea; }

        .confirm-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }

        .confirm-row:last-child { border-bottom: none; }

        .confirm-label { color: #6b7280; font-weight: 500; }
        .confirm-value { color: #1a1a2e; font-weight: 600; text-align: right; }

        .price-total {
            font-size: 22px;
            font-weight: 800;
            color: #667eea;
        }

        /* ── Right summary card ── */
        .summary-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            position: sticky;
            top: 100px;
        }

        .summary-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 36px;
        }

        .summary-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .summary-body { padding: 18px 20px; }

        .summary-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 4px;
        }

        .summary-owner {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 16px;
        }

        .summary-divider { border: none; border-top: 1px solid #f0f0f0; margin: 14px 0; }

        .summary-line {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .summary-line.total {
            font-size: 15px;
            font-weight: 800;
            color: #1a1a2e;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            margin-top: 10px;
        }

        .summary-line.total span:last-child { color: #667eea; }

        /* ── Error box ── */
        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            color: #b91c1c;
            font-size: 14px;
            font-weight: 600;
        }

        /* ── Actions ── */
        .confirm-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            padding: 20px 28px;
            flex-wrap: wrap;
        }

        .btn-back {
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-back:hover { color: #374151; }

        .btn-confirm {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 13px 32px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-confirm:hover { opacity: 0.9; }

        .notice-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            color: #0369a1;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        @media (max-width: 768px) {
            .confirm-grid    { grid-template-columns: 1fr; }
            .summary-card    { position: static; }
            .confirm-actions { flex-direction: column-reverse; gap: 12px; padding: 16px; }
            .btn-confirm     { width: 100%; justify-content: center; }
            .btn-back        { width: 100%; justify-content: center; }
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
                    <a href="https://thecommunitytoolkit.com/" class="nav-link"><i class="fas fa-home"></i><span>Home</span></a>
                    <a href="my_items.php" class="nav-link"><i class="fas fa-box"></i><span>My Items</span></a>
                    <a href="create_listing.php" class="nav-link"><i class="fas fa-plus-circle"></i><span>List Item</span></a>
                    <a href="my_rentals.php" class="nav-link"><i class="fas fa-calendar"></i><span>My Rentals</span></a>
                </nav>
                <div class="user-section">
                    <div class="notification-icon"><i class="fas fa-bell"></i><span class="notification-badge">0</span></div>
                    <div class="notification-icon" style="cursor:pointer;" title="Messages"><i class="fas fa-comment-dots"></i><span class="notification-badge">0</span></div>
                    <div class="user-menu-container">
                        <div class="user-avatar" onclick="toggleUserMenu()">
                            <?php if (!empty($_SESSION['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;">
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
        <div class="confirm-wrap">

            <h1>Confirm your request</h1>
            <p class="page-sub" style="display:flex;align-items:center;gap:12px;">
                Review your rental details before sending the request to the owner.
                <button onclick="startConfirmTour()" style="display:inline-flex;align-items:center;gap:6px;background:none;border:1px solid #d1d5db;border-radius:20px;padding:4px 12px;font-size:12px;color:#6b7280;cursor:pointer;transition:border-color 0.15s,color 0.15s;white-space:nowrap;" onmouseover="this.style.borderColor='#667eea';this.style.color='#667eea'" onmouseout="this.style.borderColor='#d1d5db';this.style.color='#6b7280'">
                    <i class="fas fa-map-signs"></i> Page Tour
                </button>
            </p>

            <?php if (!empty($error)): ?>
                <div class="error-box"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="notice-box">
                <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i>
                <span>This is a <strong>request</strong> — the owner must accept it before the rental is confirmed. You'll be notified once they respond.</span>
            </div>

            <div class="confirm-grid">

                <!-- Left: Details -->
                <div>
                    <!-- Rental details -->
                    <div class="confirm-section">
                        <h3><i class="fas fa-calendar-days"></i> Rental Details</h3>

                        <?php if ($rateType === 'daily'): ?>
                            <div class="confirm-row">
                                <span class="confirm-label">Start Date</span>
                                <span class="confirm-value"><?php echo date('M j, Y', strtotime($startDate)); ?></span>
                            </div>
                            <div class="confirm-row">
                                <span class="confirm-label">End Date</span>
                                <span class="confirm-value"><?php echo date('M j, Y', strtotime($endDate)); ?></span>
                            </div>
                            <div class="confirm-row">
                                <span class="confirm-label">Duration</span>
                                <span class="confirm-value"><?php echo $totalDays; ?> day<?php echo $totalDays != 1 ? 's' : ''; ?></span>
                            </div>
                            <div class="confirm-row">
                                <span class="confirm-label">Rate</span>
                                <span class="confirm-value">$<?php echo number_format($pricePerDay, 2); ?> / day</span>
                            </div>
                        <?php else: ?>
                            <div class="confirm-row">
                                <span class="confirm-label">Date</span>
                                <span class="confirm-value"><?php echo date('M j, Y', strtotime($startDate)); ?></span>
                            </div>
                            <div class="confirm-row">
                                <span class="confirm-label">Start Time</span>
                                <span class="confirm-value"><?php echo date('g:i A', strtotime($startDate . ' ' . $startTime)); ?></span>
                            </div>
                            <div class="confirm-row">
                                <span class="confirm-label">Duration</span>
                                <span class="confirm-value"><?php echo $hours; ?> hour<?php echo $hours != 1 ? 's' : ''; ?></span>
                            </div>
                            <div class="confirm-row">
                                <span class="confirm-label">Return by</span>
                                <span class="confirm-value"><?php echo $endDisplay; ?></span>
                            </div>
                            <div class="confirm-row">
                                <span class="confirm-label">Rate</span>
                                <span class="confirm-value">$<?php echo number_format($pricePerHour, 2); ?> / hour</span>
                            </div>
                        <?php endif; ?>

                        <div class="confirm-row" style="margin-top:8px;">
                            <span class="confirm-label">Estimated Total</span>
                            <span class="confirm-value price-total">$<?php echo number_format($totalCost, 2); ?></span>
                        </div>
                    </div>

                    <!-- Message to owner -->
                    <div class="confirm-section">
                        <h3><i class="fas fa-comment"></i> Message to Owner</h3>
                        <?php if (!empty($message)): ?>
                            <p style="font-size:14px;color:#374151;line-height:1.6;"><?php echo nl2br(htmlspecialchars($message)); ?></p>
                        <?php else: ?>
                            <p style="font-size:14px;color:#9ca3af;font-style:italic;">No message included.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="confirm-actions">
                        <a href="item_detail.php?id=<?php echo $listingId; ?>" class="btn-back" onclick="unsetSession()">
                            <i class="fas fa-arrow-left"></i> Go back and edit
                        </a>
                        <form method="POST" action="rental_confirm.php" style="display:inline;">
                            <input type="hidden" name="confirm_submit" value="1">
                            <button type="submit" class="btn-confirm">
                                <i class="fas fa-paper-plane"></i> Confirm &amp; Send Request
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Right: Summary card -->
                <div>
                    <div class="summary-card">
                        <div class="summary-image">
                            <?php if (!empty($listingImage)): ?>
                                <img src="<?php echo htmlspecialchars($listingImage); ?>" alt="<?php echo htmlspecialchars($listingTitle); ?>">
                            <?php else: ?>
                                <i class="fas fa-image"></i>
                            <?php endif; ?>
                        </div>
                        <div class="summary-body">
                            <div class="summary-title"><?php echo htmlspecialchars($listingTitle); ?></div>
                            <div class="summary-owner">Owner: <?php echo htmlspecialchars($ownerName); ?></div>

                            <hr class="summary-divider">

                            <?php if ($rateType === 'daily'): ?>
                                <div class="summary-line">
                                    <span style="color:#6b7280;"><?php echo $totalDays; ?> night<?php echo $totalDays != 1 ? 's' : ''; ?> × $<?php echo number_format($pricePerDay, 2); ?></span>
                                    <span>$<?php echo number_format($totalDays * $pricePerDay, 2); ?></span>
                                </div>
                            <?php else: ?>
                                <div class="summary-line">
                                    <span style="color:#6b7280;"><?php echo $hours; ?> hour<?php echo $hours != 1 ? 's' : ''; ?> × $<?php echo number_format($pricePerHour, 2); ?></span>
                                    <span>$<?php echo number_format($hours * $pricePerHour, 2); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="summary-line total">
                                <span>Total</span>
                                <span>$<?php echo number_format($totalCost, 2); ?></span>
                            </div>

                            <div style="margin-top:14px;padding:10px 12px;background:#f0f2ff;border-radius:8px;font-size:12px;color:#4f46e5;display:flex;gap:8px;align-items:flex-start;">
                                <i class="fas fa-shield-alt" style="margin-top:1px;flex-shrink:0;"></i>
                                <span>No charge until the owner accepts your request.</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <script>
        function toggleUserMenu() {
            document.getElementById('userDropdown').classList.toggle('show');
        }
        window.onclick = function(e) {
            if (!e.target.matches('.user-avatar')) {
                const d = document.getElementById('userDropdown');
                if (d && d.classList.contains('show')) d.classList.remove('show');
            }
        }
        function unsetSession() {
            // Let the back link navigate normally; session data stays for re-use
        }
    </script>
    <?php require_once 'includes/chatbot_widget.php'; ?>
    <?php include 'includes/header_dropdowns.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@latest/dist/driver.css"/>
    <script src="https://cdn.jsdelivr.net/npm/driver.js@latest/dist/driver.js.iife.js"></script>
    <script>
    function startConfirmTour() {
        const { driver } = window.driver.js;

        const allSteps = [
            {
                element: '.notice-box',
                popover: {
                    title: '📋 Just a Request',
                    description: 'Submitting this form sends a request to the owner — it\'s not a confirmed booking yet. You\'ll be notified once they accept or decline. No charge until they accept.',
                    side: 'bottom', align: 'start'
                }
            },
            {
                element: '.confirm-section',
                popover: {
                    title: '📅 Rental Details',
                    description: 'Review your dates, duration, and rate here. If anything looks wrong, hit "Go back and edit" to return to the listing and adjust.',
                    side: 'right', align: 'start'
                }
            },
            {
                element: '.summary-card',
                popover: {
                    title: '🧾 Cost Summary',
                    description: 'A breakdown of your total cost. A 10% deposit will be charged when the owner accepts — the remainder is settled when the rental completes.',
                    side: 'left', align: 'start'
                }
            },
            {
                element: '.confirm-actions',
                popover: {
                    title: '✅ Ready to Go?',
                    description: 'Click "Confirm & Send Request" to send your request to the owner. Use "Go back and edit" if you need to change dates or add a message first.',
                    side: 'top', align: 'start'
                }
            }
        ];

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
                localStorage.setItem('rental_confirm_tour_done', '1');
                tour.destroy();
            },
            steps: steps
        });

        tour.drive();
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (!localStorage.getItem('rental_confirm_tour_done')) {
            setTimeout(startConfirmTour, 700);
        }
    });
    </script>
</body>
</html>
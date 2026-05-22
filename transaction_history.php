<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$sort   = in_array($_GET['sort'] ?? '', ['asc', 'desc']) ? $_GET['sort'] : 'desc';
$filter = in_array($_GET['filter'] ?? '', ['all', 'renter', 'lender']) ? ($_GET['filter'] ?? 'all') : 'all';

// Fetch all transactions where user is borrower OR lender
// Join to get listing title, rental dates, other party name
$sql = "
    SELECT
        t.TransactionID,
        t.RentalID,
        t.TransactionTypeID,
        t.TransactionStatusID,
        t.Amount,
        t.AddedDate,
        t.UserBorrowerID,
        t.UserLenderID,
        tt.TransactionType,
        ts.Status        AS TransactionStatus,
        COALESCE(l.Title, '[Deleted Listing]') AS ListingTitle,
        rr.StartDate,
        rr.EndDate,
        l.PricePerDay,
        l.PricePerHour,
        l.RateTypeID,
        borrower.FirstName AS BorrowerFirst,
        borrower.LastName  AS BorrowerLast,
        lender.FirstName   AS LenderFirst,
        lender.LastName    AS LenderLast
    FROM TTransactions t
    INNER JOIN TTransactionType      tt  ON t.TransactionTypeID    = tt.TransactionTypeID
    INNER JOIN TTransactionStatuses  ts  ON t.TransactionStatusID  = ts.TransactionStatusID
    INNER JOIN TRentals              r   ON t.RentalID             = r.RentalID
    INNER JOIN TRentalRequests       rr  ON r.RentalRequestID      = rr.RentalRequestID
    LEFT JOIN TListings              l   ON r.ListingID            = l.ListingID
    INNER JOIN TUsers                borrower ON t.UserBorrowerID  = borrower.UserID
    INNER JOIN TUsers                lender   ON t.UserLenderID    = lender.UserID
    WHERE t.UserBorrowerID = ? OR t.UserLenderID = ?
    ORDER BY t.AddedDate " . ($sort === 'asc' ? 'ASC' : 'DESC');

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId, $userId]);
$allTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Apply role filter
$transactions = array_filter($allTransactions, function($t) use ($userId, $filter) {
    if ($filter === 'renter') return (int)$t['UserBorrowerID'] === $userId;
    if ($filter === 'lender') return (int)$t['UserLenderID']   === $userId;
    return true;
});

// Group by RentalID so we can show deposit + balance separately while open,
// and merged when complete
$grouped = [];
foreach ($transactions as $t) {
    $grouped[$t['RentalID']][] = $t;
}

// Helper: rental status
$rentalStatusCache = [];
function getRentalStatus($pdo, $rentalId, &$cache) {
    if (!isset($cache[$rentalId])) {
        $s = $pdo->prepare("SELECT RentalStatusID FROM TRentals WHERE RentalID = ?");
        $s->execute([$rentalId]);
        $cache[$rentalId] = (int)($s->fetchColumn() ?: 0);
    }
    return $cache[$rentalId];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History – Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/ai_chatbot.css">
    <style>
        .th-wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }
        .th-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }
        .th-header h1 { margin: 0; font-size: 22px; }
        .th-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .th-select {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 13px;
            color: #374151;
            background: white;
            cursor: pointer;
        }
        .th-select:focus { outline: none; border-color: #667eea; }

        /* Rental group card */
        .rental-group {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .rental-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            flex-wrap: wrap;
            gap: 8px;
        }
        .rental-group-title {
            font-size: 14px;
            font-weight: 700;
            color: #1a1a2e;
        }
        .rental-group-meta {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        .rental-group-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .badge-renter { background: #e0e7ff; color: #3730a3; }
        .badge-lender { background: #dcfce7; color: #166534; }

        /* Transaction rows */
        .tx-row {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            border-bottom: 1px solid #f3f4f6;
            gap: 14px;
            flex-wrap: wrap;
        }
        .tx-row:last-child { border-bottom: none; }
        .tx-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .tx-icon-deposit  { background: #fef3c7; color: #92400e; }
        .tx-icon-balance  { background: #d1fae5; color: #065f46; }
        .tx-icon-payment  { background: #e0e7ff; color: #3730a3; }
        .tx-icon-refund   { background: #fee2e2; color: #991b1b; }
        .tx-body { flex: 1; min-width: 0; }
        .tx-type { font-size: 13px; font-weight: 700; color: #1a1a2e; }
        .tx-detail { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .tx-amount {
            font-size: 15px;
            font-weight: 700;
            white-space: nowrap;
        }
        .tx-amount-charge { color: #dc2626; }
        .tx-amount-earn   { color: #16a34a; }
        .tx-status {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .status-successful { background: #d1fae5; color: #065f46; }
        .status-pending    { background: #fef3c7; color: #92400e; }
        .status-failed     { background: #fee2e2; color: #991b1b; }
        .status-refunded   { background: #f3f4f6; color: #6b7280; }

        /* Merged total row */
        .tx-merged {
            background: #f0fdf4;
            border-top: 1px solid #bbf7d0;
        }
        .tx-merged .tx-type { color: #15803d; }

        /* Invoice link */
        .tx-invoice-link {
            font-size: 12px;
            color: #667eea;
            text-decoration: none;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .tx-invoice-link:hover { text-decoration: underline; }

        /* Empty state */
        .th-empty {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }
        .th-empty i { font-size: 40px; display: block; margin-bottom: 12px; }

        /* Back link */
        .th-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .th-back:hover { text-decoration: underline; }
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
                    <a href="index.php"          class="nav-link"><i class="fas fa-home"></i><span>Home</span></a>
                    <a href="my_items.php"        class="nav-link"><i class="fas fa-box"></i><span>My Items</span></a>
                    <a href="create_listing.php"  class="nav-link"><i class="fas fa-plus-circle"></i><span>List Item</span></a>
                    <a href="map.php"             class="nav-link"><i class="fas fa-map-marker-alt"></i><span>Map</span></a>
                    <a href="my_rentals.php"      class="nav-link"><i class="fas fa-calendar"></i><span>My Rentals</span></a>
                </nav>
                <div class="user-section">
                    <div class="notification-icon"><i class="fas fa-bell"></i><span class="notification-badge" style="display:none;"></span></div>
                    
                    <div class="notification-icon" onclick="openChat()" style="cursor: pointer;" title="Messages">
                        <i class="fas fa-comment-dots"></i>
                        <span class="notification-badge" style="display:none;"></span>
                    </div>
                    
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
                            <a href="payment_methods.php"><i class="fas fa-credit-card"></i> Payment Methods</a>
                            <a href="my_bookmarks.php"><i class="fas fa-bookmark"></i> Bookmarked Items</a>
                            <a href="transaction_history.php" style="font-weight:700;"><i class="fas fa-receipt"></i> Transaction History</a>
                            <hr>
                            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="th-wrap">

            <a href="profile.php" class="th-back">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>

            <div class="th-header">
                <div>
                    <h1><i class="fas fa-receipt" style="color:#667eea;margin-right:8px;"></i>Transaction History</h1>
                    <p style="color:#6b7280;font-size:13px;margin:4px 0 0;">All charges and payments across your rentals.</p>
                </div>
                <div class="th-controls">
                    <form method="GET" style="display:contents;">
                        <select name="filter" class="th-select" onchange="this.form.submit()">
                            <option value="all"    <?php echo $filter==='all'    ? 'selected' : ''; ?>>All Activity</option>
                            <option value="renter" <?php echo $filter==='renter' ? 'selected' : ''; ?>>As Renter</option>
                            <option value="lender" <?php echo $filter==='lender' ? 'selected' : ''; ?>>As Lender</option>
                        </select>
                        <select name="sort" class="th-select" onchange="this.form.submit()">
                            <option value="desc" <?php echo $sort==='desc' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="asc"  <?php echo $sort==='asc'  ? 'selected' : ''; ?>>Oldest First</option>
                        </select>
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    </form>
                </div>
            </div>

            <?php if (empty($grouped)): ?>
                <div class="th-empty">
                    <i class="fas fa-receipt"></i>
                    <h3 style="color:#374151;margin-bottom:6px;">No transactions yet</h3>
                    <p>Charges and payments will appear here once you complete a rental.</p>
                </div>
            <?php else: ?>

                <?php foreach ($grouped as $rentalId => $txList):
                    $first       = $txList[0];
                    $isRenter    = (int)$first['UserBorrowerID'] === $userId;
                    $rentalStatus = getRentalStatus($pdo, $rentalId, $rentalStatusCache);
                    $isComplete  = in_array($rentalStatus, [5, 6]); // Completed or Cancelled
                    $startFmt    = date('M j, Y', strtotime($first['StartDate']));
                    $endFmt      = date('M j, Y', strtotime($first['EndDate']));

                    // For a completed rental collapse deposit + balance into one total row
                    $deposit     = null;
                    $balance     = null;
                    foreach ($txList as $t) {
                        if ((int)$t['TransactionTypeID'] === 2) $deposit = $t;
                        if ((int)$t['TransactionTypeID'] === 4) $balance = $t;
                    }
                    $mergedTotal = ($isComplete && $deposit && $balance)
                        ? floatval($deposit['Amount']) + floatval($balance['Amount'])
                        : null;
                        
                         $mediaStmt = $pdo->prepare("
                        SELECT 
                            ConfirmationMediaID,
                            RentalID,
                            ConfirmationType,
                            ConfirmationID,
                            UserRole,
                            FileURL,
                            FileType,
                            AddedDate
                        FROM TRentalConfirmationMedia
                        WHERE RentalID = ?
                        ORDER BY ConfirmationType, UserRole, AddedDate ASC
                    ");
                    $mediaStmt->execute([$rentalId]);
                    $confirmationMedia = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                    <div class="rental-group">
                        <div class="rental-group-header">
                            <div>
                                <div class="rental-group-title"><?php echo htmlspecialchars($first['ListingTitle']); ?></div>
                                <div class="rental-group-meta">
                                    Rental #<?php echo $rentalId; ?> &middot;
                                    <?php echo $startFmt; ?> – <?php echo $endFmt; ?> &middot;
                                    <?php if ($isRenter): ?>
                                        Lender: <?php echo htmlspecialchars($first['LenderFirst'] . ' ' . strtoupper(substr($first['LenderLast'], 0, 1)) . '.'); ?>
                                    <?php else: ?>
                                        Renter: <?php echo htmlspecialchars($first['BorrowerFirst'] . ' ' . strtoupper(substr($first['BorrowerLast'], 0, 1)) . '.'); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="rental-group-badge <?php echo $isRenter ? 'badge-renter' : 'badge-lender'; ?>">
                                <?php echo $isRenter ? 'As Renter' : 'As Lender'; ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($confirmationMedia)): ?>
                            <div class="rental-confirmation-media">
                                
                        
                                <div class="confirmation-media-grid">
                                    <?php foreach ($confirmationMedia as $media): ?>
                                        <?php
                                            $fileUrl = $media['FileURL'];
                                            $fileType = strtolower($media['FileType'] ?? '');
                                            $roleLabel = ucfirst($media['UserRole']);
                                            $typeLabel = ucfirst($media['ConfirmationType']);
                                        ?>
                        
                                        <div class="confirmation-media-card">
                                            <div class="confirmation-media-preview">
                                                <?php if ($fileType === 'image' || str_starts_with($fileType, 'image')): ?>
                                                    <img 
                                                        src="<?php echo htmlspecialchars($fileUrl); ?>" 
                                                        alt="Confirmation media"
                                                    >
                                                <?php elseif ($fileType === 'video' || str_starts_with($fileType, 'video')): ?>
                                                    <video controls>
                                                        <source src="<?php echo htmlspecialchars($fileUrl); ?>" type="<?php echo htmlspecialchars($fileType); ?>">
                                                        Your browser does not support the video tag.
                                                    </video>
                                                <?php else: ?>
                                                    <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank">
                                                        View File
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                        
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($mergedTotal !== null): ?>
                            <!-- Completed rental: show merged total -->
                            <div class="tx-row tx-merged">
                                <div class="tx-icon tx-icon-payment">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="tx-body">
                                    <div class="tx-type">Rental Complete</div>
                                    <div class="tx-detail">
                                        <?php if ($isRenter): ?>
                                            Paid to <?php echo htmlspecialchars($first['LenderFirst'] . ' ' . $first['LenderLast']); ?>
                                        <?php else: ?>
                                            Earned from <?php echo htmlspecialchars($first['BorrowerFirst'] . ' ' . $first['BorrowerLast']); ?>
                                        <?php endif; ?>
                                        &middot; <?php echo date('M j, Y', strtotime($balance['AddedDate'])); ?>
                                    </div>
                                </div>
                                <span class="tx-status status-successful">Completed</span>
                                <span class="tx-amount <?php echo $isRenter ? 'tx-amount-charge' : 'tx-amount-earn'; ?>">
                                    <?php echo $isRenter ? '-' : '+'; ?>$<?php echo number_format($mergedTotal, 2); ?>
                                </span>
                                <a href="invoice.php?rental_id=<?php echo $rentalId; ?>" target="_blank" class="tx-invoice-link">
                                    <i class="fas fa-file-invoice"></i> Invoice
                                </a>
                            </div>

                        <?php else: ?>
                            <!-- Active/pending rental: show individual line items -->
                            <?php foreach ($txList as $t):
                                $typeId = (int)$t['TransactionTypeID'];
                                $statusId = (int)$t['TransactionStatusID'];
                                $iconClass = match($typeId) {
                                    2       => 'tx-icon-deposit',
                                    4       => 'tx-icon-balance',
                                    3       => 'tx-icon-refund',
                                    default => 'tx-icon-payment'
                                };
                                $iconSymbol = match($typeId) {
                                    2       => 'fa-coins',
                                    4       => 'fa-dollar-sign',
                                    3       => 'fa-undo',
                                    default => 'fa-credit-card'
                                };
                                $statusClass = match($statusId) {
                                    2       => 'status-successful',
                                    1       => 'status-pending',
                                    3       => 'status-failed',
                                    4       => 'status-refunded',
                                    default => 'status-pending'
                                };
                                $txIsRenter = (int)$t['UserBorrowerID'] === $userId;
                            ?>
                                <div class="tx-row">
                                    <div class="tx-icon <?php echo $iconClass; ?>">
                                        <i class="fas <?php echo $iconSymbol; ?>"></i>
                                    </div>
                                    <div class="tx-body">
                                        <div class="tx-type"><?php echo htmlspecialchars($t['TransactionType']); ?></div>
                                        <div class="tx-detail">
                                            <?php if ($txIsRenter): ?>
                                                Paid to <?php echo htmlspecialchars($t['LenderFirst'] . ' ' . $t['LenderLast']); ?>
                                            <?php else: ?>
                                                Earned from <?php echo htmlspecialchars($t['BorrowerFirst'] . ' ' . $t['BorrowerLast']); ?>
                                            <?php endif; ?>
                                            &middot; <?php echo date('M j, Y g:i A', strtotime($t['AddedDate'])); ?>
                                        </div>
                                    </div>
                                    <span class="tx-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($t['TransactionStatus']); ?></span>
                                    <span class="tx-amount <?php echo $txIsRenter ? 'tx-amount-charge' : 'tx-amount-earn'; ?>">
                                        <?php echo $txIsRenter ? '-' : '+'; ?>$<?php echo number_format(floatval($t['Amount']), 2); ?>
                                    </span>
                                    <a href="invoice.php?rental_id=<?php echo $rentalId; ?>&tx_id=<?php echo $t['TransactionID']; ?>" target="_blank" class="tx-invoice-link">
                                        <i class="fas fa-file-invoice"></i> Invoice
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>

            <?php endif; ?>

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
    };
    </script>
    <?php require_once 'includes/chatbot_widget.php'; ?>
    <?php include 'includes/header_dropdowns.php'; ?>
</body>
</html>

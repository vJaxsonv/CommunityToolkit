<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Handle AJAX: mark one or all notifications read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if ($_POST['ajax_action'] === 'mark_one_read') {
        $nid = intval($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            $pdo->prepare("UPDATE TNotifications SET ReadStatus = 1 WHERE NotificationID = ? AND UserID = ?")
                ->execute([$nid, $userId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_POST['ajax_action'] === 'mark_all_notif_read') {
        $pdo->prepare("UPDATE TNotifications SET ReadStatus = 1 WHERE UserID = ?")
            ->execute([$userId]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

// Handle clear all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_action'])) {
    if ($_POST['clear_action'] === 'clear_notifications') {
        $pdo->prepare("DELETE FROM TNotifications WHERE UserID = ?")->execute([$userId]);
    } elseif ($_POST['clear_action'] === 'mark_notifications_read') {
        $pdo->prepare("UPDATE TNotifications SET ReadStatus = 1 WHERE UserID = ?")->execute([$userId]);
    }
    header('Location: notifications.php');
    exit;
}

// Mark all as read when viewing the full page
$pdo->prepare("UPDATE TNotifications SET ReadStatus = 1 WHERE UserID = ?")
    ->execute([$userId]);

// Fetch all notifications for this user
$stmt = $pdo->prepare("
    SELECT n.*, nt.Name as TypeName
    FROM TNotifications n
    INNER JOIN TNotificationTypes nt ON n.NotificationTypeID = nt.NotificationTypeID
    WHERE n.UserID = ?
    ORDER BY n.AddedDate DESC
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/ai_chatbot.css">
  
</head>
<body>

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
                        <i class="fas fa-box"></i><span>My Items</span>
                    </a>
                    <a href="create_listing.php" class="nav-link">
                        <i class="fas fa-plus-circle"></i><span>List Item</span>
                    </a>
                    <a href="map.php" class="nav-link">
                        <i class="fas fa-map-marker-alt"></i><span>Map</span>
                    </a>
                    <a href="my_rentals.php" class="nav-link">
                        <i class="fas fa-calendar"></i><span>My Rentals</span>
                    </a>
                </nav>

                <div class="user-section">
                    <?php
                    $__nCount = count(array_filter($notifications, fn($n) => !$n['ReadStatus']));
                    $__mStmt = $pdo->prepare("SELECT COUNT(*) FROM TMessages m JOIN TUserConversations uc ON m.ConversationID = uc.ConversationID WHERE uc.UserID = ? AND m.UserSenderID != ? AND m.SentDate > COALESCE(uc.LastReadDate, '2000-01-01')");
                    $__mStmt->execute([$userId, $userId]);
                    $__unreadMsg = intval($__mStmt->fetchColumn());
                    ?>
                    <div class="notification-icon active-icon" onclick="toggleNotifDropdown(event)" style="cursor:pointer;">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" style="<?php echo $__nCount > 0 ? '' : 'display:none;'; ?>"><?php echo min($__nCount, 99); ?></span>
                    </div>
                    <div class="notification-icon" onclick="toggleMsgDropdown(event)" style="cursor:pointer;" title="Messages">
                        <i class="fas fa-comment-dots"></i>
                        <span class="notification-badge" style="<?php echo $__unreadMsg > 0 ? '' : 'display:none;'; ?>"><?php echo min($__unreadMsg, 99); ?></span>
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
        <div class="listing-page-wrap">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
                <h1 style="margin:0;"><i class="fas fa-bell" style="color:#667eea;"></i> Notifications</h1>
                <?php if (!empty($notifications)): ?>
                <form method="POST" style="display:flex;gap:8px;">
                    <button name="clear_action" value="mark_notifications_read"
                            style="padding:7px 14px;background:white;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-weight:600;color:#6b7280;cursor:pointer;transition:all 0.15s;"
                            onmouseover="this.style.borderColor='#667eea';this.style.color='#667eea'" onmouseout="this.style.borderColor='#e5e7eb';this.style.color='#6b7280'">
                        <i class="fas fa-check-double"></i> Mark all read
                    </button>
                    <button name="clear_action" value="clear_notifications"
                            onclick="return confirm('Clear all notifications?');"
                            style="padding:7px 14px;background:white;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-weight:600;color:#dc2626;cursor:pointer;transition:all 0.15s;"
                            onmouseover="this.style.borderColor='#dc2626';this.style.background='#fef2f2'" onmouseout="this.style.borderColor='#e5e7eb';this.style.background='white'">
                        <i class="fas fa-trash"></i> Clear all
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No notifications yet</h3>
                    <p>You'll see rental requests, acceptances, and other updates here.</p>
                </div>
            <?php else: ?>
                <div class="notif-card">
                    <div class="notif-list">
                        <?php foreach ($notifications as $notif):
                            $typeId = intval($notif['NotificationTypeID']);
                            $icons  = [
                                1 => 'fa-paper-plane',
                                2 => 'fa-check',
                                3 => 'fa-times',
                                4 => 'fa-play',
                                5 => 'fa-flag-checkered',
                                6 => 'fa-star',
                                7 => 'fa-clock',
                                8 => 'fa-comment-dots',
                            ];
                            $icon = $icons[$typeId] ?? 'fa-bell';
                            $rid  = intval($notif['RentalRequestID']);
                            $rnid = intval($notif['RentalID']);
                            $notifLink = match($typeId) {
                                1 => $rid  ? 'accept_rental.php?request_id=' . $rid : 'my_rentals.php?role=lender&tab=requests',
                                2 => 'my_rentals.php?role=borrower&tab=upcoming',
                                3 => 'my_rentals.php?role=borrower&tab=pending',
                                4 => 'my_rentals.php?role=borrower&tab=current',
                                5 => 'my_rentals.php?role=borrower',
                                6 => 'profile.php',
                                7 => 'my_rentals.php?role=lender&tab=active',
                                8 => 'messages.php',
                                default => '#'
                            };
                        ?>
                            <a href="<?php echo htmlspecialchars($notifLink); ?>" class="notif-item <?php echo !$notif['ReadStatus'] ? 'unread' : ''; ?>" style="text-decoration:none;color:inherit;">
                                <div class="notif-icon type-<?php echo $typeId; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="notif-body">
                                    <div class="notif-type"><?php echo htmlspecialchars($notif['TypeName']); ?></div>
                                    <div class="notif-message"><?php echo htmlspecialchars($notif['Message']); ?></div>
                                    <div class="notif-date"><?php echo date('M j, Y g:i A', strtotime($notif['AddedDate'])); ?></div>
                                </div>
                                <?php if (!$notif['ReadStatus']): ?>
                                    <div class="unread-dot"></div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
        function toggleUserMenu() {
            document.getElementById('userDropdown').classList.toggle('show');
        }
        function toggleNotifDropdown(e) { e.stopPropagation(); }
        function toggleMsgDropdown(e)   { e.stopPropagation(); window.location.href = 'messages.php'; }
        window.onclick = function(event) {
            if (!event.target.matches('.user-avatar')) {
                const d = document.getElementById('userDropdown');
                if (d && d.classList.contains('show')) d.classList.remove('show');
            }
        }
    </script>
    <script src="js/ai_chatbot.js"></script>
</body>
</html>

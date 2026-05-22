<?php require_once 'admin_auth.php'; ?>
<?php
// Audit log -- all admin access to message viewer is recorded
error_log("[ADMIN MESSAGE ACCESS] Admin UserID=" . $_SESSION['user_id'] . " (" . $_SESSION['firstname'] . " " . $_SESSION['lastname'] . ") accessed messages at " . date('Y-m-d H:i:s'));

$search       = trim($_GET['search'] ?? '');
$activeConvId = intval($_GET['conversation'] ?? 0);

// Fetch conversations
$convSql = "
    SELECT
        c.ConversationID, c.LastMessageDate,
        l.Title AS ListingTitle, l.ListingID,
        borrower.FirstName AS BorrowerFirst, borrower.LastName AS BorrowerLast, borrower.Email AS BorrowerEmail,
        lender.FirstName   AS LenderFirst,   lender.LastName   AS LenderLast,   lender.Email   AS LenderEmail,
        (SELECT COUNT(*)     FROM TMessages WHERE ConversationID = c.ConversationID) AS MessageCount,
        (SELECT MessageBody  FROM TMessages WHERE ConversationID = c.ConversationID ORDER BY SentDate DESC LIMIT 1) AS LastMessage
    FROM TConversations c
    INNER JOIN TRentals  r        ON c.RentalID       = r.RentalID
    INNER JOIN TListings l        ON r.ListingID       = l.ListingID
    INNER JOIN TUsers    borrower ON r.UserBorrowerID  = borrower.UserID
    INNER JOIN TUsers    lender   ON r.UserLenderID    = lender.UserID
    WHERE 1=1
";
$convParams = [];
if ($search !== '') {
    $convSql .= " AND (borrower.FirstName LIKE ? OR borrower.LastName LIKE ? OR borrower.Email LIKE ?
                       OR lender.FirstName LIKE ? OR lender.LastName LIKE ? OR lender.Email LIKE ?
                       OR l.Title LIKE ?)";
    $like = "%$search%";
    $convParams = array_fill(0, 7, $like);
}
$convSql .= " ORDER BY c.LastMessageDate DESC";
$convStmt = $pdo->prepare($convSql);
$convStmt->execute($convParams);
$conversations = $convStmt->fetchAll(PDO::FETCH_ASSOC);

if ($activeConvId === 0 && !empty($conversations)) {
    $activeConvId = $conversations[0]['ConversationID'];
}

// Fetch messages for active conversation
$messages   = [];
$activeConv = null;

if ($activeConvId > 0) {
    foreach ($conversations as $c) {
        if ((int)$c['ConversationID'] === $activeConvId) { $activeConv = $c; break; }
    }
    if (!$activeConv) {
        $acStmt = $pdo->prepare("
            SELECT c.ConversationID, c.LastMessageDate, l.Title AS ListingTitle, l.ListingID,
                   b.FirstName AS BorrowerFirst, b.LastName AS BorrowerLast, b.Email AS BorrowerEmail,
                   ld.FirstName AS LenderFirst, ld.LastName AS LenderLast, ld.Email AS LenderEmail,
                   (SELECT COUNT(*) FROM TMessages WHERE ConversationID = c.ConversationID) AS MessageCount
            FROM TConversations c
            INNER JOIN TRentals r ON c.RentalID = r.RentalID
            INNER JOIN TListings l ON r.ListingID = l.ListingID
            INNER JOIN TUsers b ON r.UserBorrowerID = b.UserID
            INNER JOIN TUsers ld ON r.UserLenderID = ld.UserID
            WHERE c.ConversationID = ?
        ");
        $acStmt->execute([$activeConvId]);
        $activeConv = $acStmt->fetch(PDO::FETCH_ASSOC);
    }
    $msgStmt = $pdo->prepare("
        SELECT m.MessageID, m.MessageBody, m.SentDate, m.SystemMessage,
               u.UserID, u.FirstName, u.LastName, u.Email
        FROM TMessages m
        INNER JOIN TUsers u ON m.UserSenderID = u.UserID
        WHERE m.ConversationID = ?
        ORDER BY m.SentDate ASC
    ");
    $msgStmt->execute([$activeConvId]);
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($activeConv) {
        error_log("[ADMIN MESSAGE VIEW] Admin UserID=" . $_SESSION['user_id'] . " viewed ConversationID=$activeConvId (Listing: " . ($activeConv['ListingTitle'] ?? 'unknown') . ") at " . date('Y-m-d H:i:s'));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - CT Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; }
        .admin-sidebar { width: 240px; background: #1a1a2e; color: white; min-height: 100vh; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-logo { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); font-size: 16px; font-weight: 700; color: #667eea; display: flex; align-items: center; gap: 10px; }
        .sidebar-nav { padding: 16px 0; flex: 1; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 12px 20px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; transition: background 0.2s, color 0.2s; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.07); color: white; }
        .sidebar-nav a.active { background: rgba(102,126,234,0.2); color: #667eea; border-left: 3px solid #667eea; }
        .sidebar-nav a i { width: 18px; text-align: center; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.1); font-size: 13px; color: #64748b; }
        .sidebar-footer a { color: #94a3b8; text-decoration: none; }
        .sidebar-footer a:hover { color: white; }

        .admin-main { flex: 1; display: flex; flex-direction: column; min-height: 100vh; overflow: hidden; }
        .admin-header { padding: 24px 32px 16px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0; }
        .admin-header h1 { font-size: 22px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .audit-notice { font-size: 12px; color: #9ca3af; display: flex; align-items: center; gap: 6px; margin-top: 4px; }

        .search-bar { padding: 12px 32px; background: white; border-bottom: 1px solid #e5e7eb; display: flex; gap: 10px; align-items: center; flex-shrink: 0; }
        .search-bar form { display: flex; gap: 8px; align-items: center; flex: 1; }
        .search-bar input { flex: 1; max-width: 400px; padding: 9px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .search-bar input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        .btn { padding: 9px 18px; border-radius: 8px; border: none; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #667eea; color: white; } .btn-primary:hover { background: #5a6fd6; }
        .btn-neutral { background: #f3f4f6; color: #374151; } .btn-neutral:hover { background: #e5e7eb; }
        .count { font-size: 13px; color: #6b7280; margin-left: auto; white-space: nowrap; }

        .messages-layout { display: flex; flex: 1; overflow: hidden; height: calc(100vh - 145px); }

        /* Conversation list */
        .conv-list { width: 320px; flex-shrink: 0; border-right: 1px solid #e5e7eb; background: white; overflow-y: auto; }
        .conv-item { padding: 13px 16px; border-bottom: 1px solid #f3f4f6; text-decoration: none; display: block; transition: background 0.15s; }
        .conv-item:hover { background: #f9fafb; }
        .conv-item.active { background: #f0f2ff; border-left: 3px solid #667eea; }
        .conv-listing { font-size: 13px; font-weight: 700; color: #1a1a2e; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conv-item.active .conv-listing { color: #667eea; }
        .conv-parties { font-size: 12px; color: #6b7280; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conv-preview { font-size: 12px; color: #9ca3af; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conv-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; }
        .conv-date { font-size: 11px; color: #9ca3af; }
        .conv-badge { font-size: 11px; background: #f3f4f6; color: #6b7280; padding: 2px 7px; border-radius: 999px; font-weight: 600; }
        .conv-empty { padding: 40px 16px; text-align: center; color: #9ca3af; font-size: 14px; }
        .conv-empty i { font-size: 36px; display: block; margin-bottom: 12px; color: #e5e7eb; }

        /* Thread */
        .thread-panel { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #fafafa; }
        .thread-header { padding: 14px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0; }
        .thread-header h2 { font-size: 15px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .thread-meta { font-size: 12px; color: #6b7280; line-height: 1.8; }
        .thread-meta a { color: #667eea; text-decoration: none; }
        .thread-meta a:hover { text-decoration: underline; }
        .thread-messages { flex: 1; overflow-y: auto; padding: 20px 24px; display: flex; flex-direction: column; gap: 14px; }
        .msg-row { display: flex; gap: 10px; align-items: flex-end; }
        .msg-row.system-row { justify-content: center; }
        .msg-avatar { width: 30px; height: 30px; border-radius: 50%; color: white; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .msg-avatar.borrower { background: linear-gradient(135deg, #667eea, #764ba2); }
        .msg-avatar.lender   { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .msg-bubble-wrap { max-width: 68%; }
        .msg-sender { font-size: 11px; color: #9ca3af; margin-bottom: 3px; font-weight: 600; }
        .msg-bubble { padding: 9px 13px; border-radius: 14px; font-size: 14px; line-height: 1.5; color: #1a1a2e; word-wrap: break-word; }
        .msg-bubble.borrower { background: #eff0ff; border-bottom-left-radius: 4px; }
        .msg-bubble.lender   { background: #fde8f5; border-bottom-right-radius: 4px; }
        .msg-time { font-size: 11px; color: #9ca3af; margin-top: 3px; }
        .msg-system { font-size: 12px; color: #9ca3af; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 999px; padding: 4px 14px; }
        .no-selection { flex: 1; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 12px; color: #9ca3af; }
        .no-selection i { font-size: 48px; color: #e5e7eb; }
    </style>
</head>
<body>

<aside class="admin-sidebar">
    <div class="sidebar-logo"><i class="fas fa-tools"></i> CT Admin</div>
    <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        <a href="users.php"><i class="fas fa-users"></i> Users</a>
        <a href="listings.php"><i class="fas fa-box"></i> Listings</a>
        <a href="reviews.php"><i class="fas fa-star"></i> Reviews</a>
        <a href="reports.php"><i class="fas fa-flag"></i> Reports</a>
        <a href="messages.php" class="active"><i class="fas fa-comment-dots"></i> Messages</a>
    </nav>
    <div class="sidebar-footer">
        Logged in as <strong><?php echo htmlspecialchars($_SESSION['firstname']); ?></strong><br>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Log out</a>
    </div>
</aside>

<div class="admin-main">

    <div class="admin-header">
        <h1>Messages</h1>
        <div class="audit-notice">
            <i class="fas fa-shield-alt"></i>
            All access to this page is recorded in the server error log.
        </div>
    </div>

    <div class="search-bar">
        <form method="GET">
            <input type="text" name="search" placeholder="Search by user name, email, or listing title..."
                   value="<?php echo htmlspecialchars($search); ?>">
            <?php if ($activeConvId && !$search): ?>
                <input type="hidden" name="conversation" value="<?php echo $activeConvId; ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <?php if ($search): ?><a href="messages.php" class="btn btn-neutral">Clear</a><?php endif; ?>
        </form>
        <span class="count"><?php echo count($conversations); ?> conversation<?php echo count($conversations) !== 1 ? 's' : ''; ?></span>
    </div>

    <div class="messages-layout">

        <!-- Left: conversation list -->
        <div class="conv-list">
            <?php if (empty($conversations)): ?>
                <div class="conv-empty">
                    <i class="fas fa-comment-slash"></i>
                    No conversations found.
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php
                    $isActive = ((int)$conv['ConversationID'] === $activeConvId);
                    $url = 'messages.php?conversation=' . $conv['ConversationID'] . ($search ? '&search=' . urlencode($search) : '');
                    $ts  = strtotime($conv['LastMessageDate']);
                    $diff = time() - $ts;
                    $ago = $diff < 3600 ? floor($diff/60).'m ago' : ($diff < 86400 ? floor($diff/3600).'h ago' : date('M j, Y', $ts));
                    ?>
                    <a href="<?php echo $url; ?>" class="conv-item<?php echo $isActive ? ' active' : ''; ?>">
                        <div class="conv-listing"><?php echo htmlspecialchars($conv['ListingTitle']); ?></div>
                        <div class="conv-parties">
                            <?php echo htmlspecialchars($conv['BorrowerFirst'] . ' ' . $conv['BorrowerLast']); ?>
                            &rarr;
                            <?php echo htmlspecialchars($conv['LenderFirst'] . ' ' . $conv['LenderLast']); ?>
                        </div>
                        <div class="conv-preview"><?php echo htmlspecialchars(mb_substr($conv['LastMessage'] ?? '', 0, 55)); ?></div>
                        <div class="conv-meta">
                            <span class="conv-date"><?php echo $ago; ?></span>
                            <span class="conv-badge"><?php echo $conv['MessageCount']; ?> msg<?php echo $conv['MessageCount'] != 1 ? 's' : ''; ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Right: thread -->
        <div class="thread-panel">
            <?php if (!$activeConv): ?>
                <div class="no-selection">
                    <i class="fas fa-comments"></i>
                    <span>Select a conversation to read messages</span>
                </div>
            <?php else: ?>
                <div class="thread-header">
                    <h2><?php echo htmlspecialchars($activeConv['ListingTitle']); ?></h2>
                    <div class="thread-meta">
                        <strong>Borrower:</strong>
                        <?php echo htmlspecialchars($activeConv['BorrowerFirst'] . ' ' . $activeConv['BorrowerLast']); ?>
                        (<a href="users.php?search=<?php echo urlencode($activeConv['BorrowerEmail']); ?>"><?php echo htmlspecialchars($activeConv['BorrowerEmail']); ?></a>)
                        &nbsp;&bull;&nbsp;
                        <strong>Lender:</strong>
                        <?php echo htmlspecialchars($activeConv['LenderFirst'] . ' ' . $activeConv['LenderLast']); ?>
                        (<a href="users.php?search=<?php echo urlencode($activeConv['LenderEmail']); ?>"><?php echo htmlspecialchars($activeConv['LenderEmail']); ?></a>)
                        &nbsp;&bull;&nbsp;
                        <a href="../item_detail.php?id=<?php echo $activeConv['ListingID']; ?>" target="_blank">
                            View Listing <i class="fas fa-external-link-alt" style="font-size:10px;"></i>
                        </a>
                        &nbsp;&bull;&nbsp;
                        <?php echo count($messages); ?> message<?php echo count($messages) !== 1 ? 's' : ''; ?>
                    </div>
                </div>

                <div class="thread-messages" id="threadMessages">
                    <?php if (empty($messages)): ?>
                        <div style="text-align:center;color:#9ca3af;padding:40px;font-size:14px;">No messages yet.</div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <?php
                            $isSystem   = !empty($msg['SystemMessage']) && $msg['SystemMessage'] !== '0';
                            $isBorrower = ($msg['Email'] === $activeConv['BorrowerEmail']);
                            $role       = $isBorrower ? 'borrower' : 'lender';
                            $initial    = strtoupper(substr($msg['FirstName'], 0, 1));
                            $senderLabel = htmlspecialchars($msg['FirstName'] . ' ' . $msg['LastName']) . ' (' . ($isBorrower ? 'Borrower' : 'Lender') . ')';
                            ?>
                            <?php if ($isSystem): ?>
                                <div class="msg-row system-row">
                                    <span class="msg-system"><?php echo htmlspecialchars($msg['MessageBody']); ?></span>
                                </div>
                            <?php else: ?>
                                <div class="msg-row">
                                    <div class="msg-avatar <?php echo $role; ?>"><?php echo $initial; ?></div>
                                    <div class="msg-bubble-wrap">
                                        <div class="msg-sender"><?php echo $senderLabel; ?></div>
                                        <div class="msg-bubble <?php echo $role; ?>"><?php echo htmlspecialchars($msg['MessageBody']); ?></div>
                                        <div class="msg-time"><?php echo date('M j, Y g:i A', strtotime($msg['SentDate'])); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
    var t = document.getElementById('threadMessages');
    if (t) t.scrollTop = t.scrollHeight;
</script>

<?php require_once '../includes/chatbot_widget.php'; ?>
</body>
</html>

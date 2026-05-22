<?php
require_once 'config.php';
require_once 'includes/send_notification_email.php';
require_once 'includes/profanity_check.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// ── Handle AJAX: send a message ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {

    header('Content-Type: application/json');

   if ($_POST['ajax_action'] === 'send_message') {
    $conversationId = intval($_POST['conversation_id'] ?? 0);
    $messageBody    = trim($_POST['message_body'] ?? '');
    $gifUrl         = trim($_POST['gif_url'] ?? '');

    $hasFiles = !empty($_FILES['attachments']['name'][0]);

    if ($conversationId <= 0 || ($messageBody === '' && $gifUrl === '' && !$hasFiles)) {
        echo json_encode(['success' => false, 'error' => 'Invalid input.']);
        exit;
    }

    // Only check text if text exists
    if ($messageBody !== '' && !checkContent($messageBody)) {
        echo json_encode(['success' => false, 'error' => 'Your message contains inappropriate content and could not be sent.']);
        exit;
    }

    try {
        // Verify sender is a participant
        $partStmt = $pdo->prepare('SELECT COUNT(*) FROM TUserConversations WHERE ConversationID = ? AND UserID = ?');
        $partStmt->execute([$conversationId, $userId]);
        if (!$partStmt->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Not a participant.']);
            exit;
        }
        $messageBody = trim($_POST['message_body'] ?? '');
        // Insert message
        $pdo->prepare('
            INSERT INTO TMessages (ConversationID, UserSenderID, MessageBody, SystemMessage, SentDate)
            VALUES (?, ?, ?, 0, NOW())
        ')->execute([
            $conversationId,
            $userId,
            $messageBody
          
        ]);

        $messageId = $pdo->lastInsertId();

        // Handle file uploads
        if ($hasFiles) {
            $uploadDir = __DIR__ . '/uploads/chat/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'm4v'];

            $attachStmt = $pdo->prepare('
                INSERT INTO TMessageAttachments (MessageID, FileURL, GifURL, UploadedAt)
                VALUES (?, ?, ?, NOW())
            ');

            foreach ($_FILES['attachments']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $originalName = $_FILES['attachments']['name'][$i];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExtensions, true)) {
                    continue;
                }

                $newName  = uniqid('msg_', true) . '.' . $ext;
                $fullPath = $uploadDir . $newName;
                $dbPath   = 'uploads/chat/' . $newName;

                if (move_uploaded_file($tmpName, $fullPath)) {
                    $attachStmt->execute([$messageId, $dbPath, $originalName]);
                }
            }
        }
        if ($gifUrl !== '') {
    $gifStmt = $pdo->prepare('
        INSERT INTO TMessageAttachments (MessageID, GifURL, UploadedAt)
        VALUES (?, ?, NOW())
    ');
    $gifStmt->execute([$messageId, $gifUrl]);
}
    
        // Update conversation last message date
        $pdo->prepare('UPDATE TConversations SET LastMessageDate = NOW() WHERE ConversationID = ?')
            ->execute([$conversationId]);

        // Find recipient
        $recStmt = $pdo->prepare('SELECT UserID FROM TUserConversations WHERE ConversationID = ? AND UserID != ? LIMIT 1');
        $recStmt->execute([$conversationId, $userId]);
        $recipientId = intval($recStmt->fetchColumn());

        if ($recipientId) {
            try {
                // In-app notification (type 8 = New Message)
                $pdo->prepare('
                    INSERT INTO TNotifications
                        (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                         RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                    VALUES (?, 8, 0, ?, ?, 0, 0, 0, ?, 0, NOW())
                ')->execute([$recipientId, $conversationId, $messageId, 'You have a new message.']);

                sendNotificationEmail($pdo, $recipientId, 8, 'You have a new message on Community Toolkit.');
            } catch (PDOException $ne) {
                error_log('Message notification error: ' . $ne->getMessage());
            }
        }

        echo json_encode(['success' => true, 'messageId' => $messageId, 'message' => 'Sent.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

    if ($_POST['ajax_action'] === 'mark_read') {
        $conversationId = intval($_POST['conversation_id'] ?? 0);

        if ($conversationId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid input.']);
            exit;
        }

        try {
            $pdo->prepare("UPDATE TUserConversations SET LastReadDate = NOW() WHERE ConversationID = ? AND UserID = ?")
                ->execute([$conversationId, $userId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'delete_conversation') {
        $conversationId = intval($_POST['conversation_id'] ?? 0);
        if ($conversationId <= 0) { echo json_encode(['success' => false]); exit; }
        // Verify user is a participant
        $chk = $pdo->prepare('SELECT COUNT(*) FROM TUserConversations WHERE ConversationID = ? AND UserID = ?');
        $chk->execute([$conversationId, $userId]);
        if (!$chk->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'Not a participant.']); exit; }
        // Remove user from conversation (soft delete - other party still sees it)
        $pdo->prepare('DELETE FROM TUserConversations WHERE ConversationID = ? AND UserID = ?')
            ->execute([$conversationId, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['ajax_action'] === 'report_message') {
        $messageId  = intval($_POST['message_id']  ?? 0);
        $reasonId   = intval($_POST['reason_id']   ?? 0);
        $notes      = trim($_POST['notes']         ?? '');

        if ($messageId <= 0 || $reasonId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid input.']);
            exit;
        }

        try {
            // Verify message exists and reporter is a conversation participant
            $chk = $pdo->prepare('
                SELECT m.MessageID, m.UserSenderID, m.ConversationID
                FROM TMessages m
                JOIN TUserConversations uc ON m.ConversationID = uc.ConversationID
                WHERE m.MessageID = ? AND uc.UserID = ?
            ');
            $chk->execute([$messageId, $userId]);
            $msg = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$msg) {
                echo json_encode(['success' => false, 'error' => 'Message not found.']);
                exit;
            }

            // Can't report your own message
            if ($msg['UserSenderID'] == $userId) {
                echo json_encode(['success' => false, 'error' => 'You cannot report your own message.']);
                exit;
            }

            // Check not already reported by this user
            $dup = $pdo->prepare('SELECT COUNT(*) FROM TReports WHERE ReporterUserID = ? AND TargetMessageID = ?');
            $dup->execute([$userId, $messageId]);
            if ($dup->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'You have already reported this message.']);
                exit;
            }

            // Insert report
            $pdo->prepare('
                INSERT INTO TReports (ReporterUserID, ReasonID, AdditionalNotes, TargetUserID, TargetMessageID, ReportStatus, AddedDate)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ')->execute([$userId, $reasonId, $notes ?: null, $msg['UserSenderID'], $messageId]);

            // Increment FlagCount on TMessages
            $pdo->prepare('UPDATE TMessages SET FlagCount = FlagCount + 1 WHERE MessageID = ?')
                ->execute([$messageId]);

            // Auto-hide if FlagCount reaches 3
            $pdo->prepare('UPDATE TMessages SET SystemMessage = 1 WHERE MessageID = ? AND FlagCount >= 3')
                ->execute([$messageId]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'mark_all_read') {
        try {
            // Mark all conversations as read for this user
            $pdo->prepare("UPDATE TUserConversations SET LastReadDate = NOW() WHERE UserID = ?")
                ->execute([$userId]);
            // Also clear message notifications
            $pdo->prepare("UPDATE TNotifications SET ReadStatus = 1 WHERE UserID = ? AND NotificationTypeID = 8")
                ->execute([$userId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'poll_messages') {
        $conversationId = intval($_POST['conversation_id'] ?? 0);
        $lastMessageId  = intval($_POST['last_message_id'] ?? 0);
        if ($conversationId <= 0) { echo json_encode(['success' => false]); exit; }
        $partStmt = $pdo->prepare('SELECT COUNT(*) FROM TUserConversations WHERE ConversationID = ? AND UserID = ?');
        $partStmt->execute([$conversationId, $userId]);
        if (!$partStmt->fetchColumn()) { echo json_encode(['success' => false]); exit; }
        $msgStmt = $pdo->prepare('SELECT MessageID, UserSenderID, MessageBody, SentDate, SystemMessage FROM TMessages WHERE ConversationID = ? AND MessageID > ? ORDER BY SentDate ASC');
        $msgStmt->execute([$conversationId, $lastMessageId]);
        $newMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        $nStmt = $pdo->prepare('SELECT COUNT(*) FROM TNotifications WHERE UserID = ? AND ReadStatus = 0');
        $nStmt->execute([$userId]);
        $unreadNotif = intval($nStmt->fetchColumn());
        $mStmt = $pdo->prepare("SELECT COUNT(*) FROM TMessages m JOIN TUserConversations uc ON m.ConversationID = uc.ConversationID WHERE uc.UserID = ? AND m.UserSenderID != ? AND m.SentDate > COALESCE(uc.LastReadDate, '2000-01-01')");
        $mStmt->execute([$userId, $userId]);
        $unreadMsg = intval($mStmt->fetchColumn());
        echo json_encode(['success' => true, 'messages' => $newMessages, 'unreadNotif' => $unreadNotif, 'unreadMsg' => $unreadMsg]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

// ── Handle direct message start (from item card) ───────────────────────────
// ── Handle direct message start (from item card) ───────────────────────────
if (isset($_GET['user_id']) && !isset($_GET['conversation'])) {

    $otherUserId = intval($_GET['user_id']);

    if ($otherUserId > 0 && $otherUserId != $userId) {

        $stmt = $pdo->prepare("
            SELECT uc1.ConversationID
            FROM TUserConversations uc1
            JOIN TUserConversations uc2
                ON uc1.ConversationID = uc2.ConversationID
            WHERE uc1.UserID = ? AND uc2.UserID = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $otherUserId]);
        $conversationId = $stmt->fetchColumn();

        if (!$conversationId) {
            $pdo->prepare("
                INSERT INTO TConversations (RentalID, LastMessageDate, AddedDate)
                VALUES (0, NOW(), NOW())
            ")->execute();

            $conversationId = $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO TUserConversations (ConversationID, UserID, LastReadDate)
                VALUES (?, ?, NOW()), (?, ?, NOW())
            ")->execute([
                $conversationId, $userId,
                $conversationId, $otherUserId
            ]);
        }

        header("Location: messages.php?conversation=" . $conversationId);
        exit;
    }
}
// ── Which conversation is open? ──────────────────────────────────────────────
$activeConversationId = intval($_GET['conversation'] ?? 0);

// ── Fetch all conversations for this user ───────────────────────────────────
$convStmt = $pdo->prepare("
    SELECT
         uc.ConversationID
        ,c.LastMessageDate
        ,COALESCE(l.Title, 'Direct Message') AS ListingTitle
        ,u.FirstName AS OtherFirstName
        ,u.LastName AS OtherLastName
        ,u.ProfilePictureURL AS OtherProfilePictureURL
        ,(
            SELECT m.MessageBody
            FROM TMessages m
            WHERE m.ConversationID = c.ConversationID
            ORDER BY m.SentDate DESC
            LIMIT 1
         ) AS LastMessageBody
        ,(
            SELECT m.SentDate
            FROM TMessages m
            WHERE m.ConversationID = c.ConversationID
            ORDER BY m.SentDate DESC
            LIMIT 1
         ) AS LastMessageDate2
        ,(
            SELECT COUNT(*)
            FROM TMessages m
            WHERE m.ConversationID = c.ConversationID
              AND m.UserSenderID != ?
              AND m.SentDate > COALESCE(uc.LastReadDate, '2000-01-01')
         ) AS UnreadCount
    FROM TUserConversations uc
    JOIN TConversations c ON uc.ConversationID = c.ConversationID
    LEFT JOIN TRentals r ON c.RentalID = r.RentalID AND c.RentalID != 0
    LEFT JOIN TListings l ON r.ListingID = l.ListingID
    JOIN TUserConversations uc2 ON uc2.ConversationID = c.ConversationID
                               AND uc2.UserID != ?
    JOIN TUsers u ON uc2.UserID = u.UserID
    WHERE uc.UserID = ?
    ORDER BY c.LastMessageDate DESC
");
$convStmt->execute([$userId, $userId, $userId]);
$conversations = $convStmt->fetchAll(PDO::FETCH_ASSOC);

// Default to first conversation if none selected
if ($activeConversationId === 0 && !empty($conversations)) {
    $activeConversationId = $conversations[0]['ConversationID'];
}

// ── Fetch messages for the active conversation ──────────────────────────────
$activeConv = null;
$messages   = [];

if ($activeConversationId > 0) {
    foreach ($conversations as $conv) {
        if ($conv['ConversationID'] == $activeConversationId) {
            $activeConv = $conv;
            break;
        }
    }

    $msgStmt = $pdo->prepare("
        SELECT
             m.MessageID
            ,m.UserSenderID
            ,m.MessageBody
            ,m.SystemMessage
            ,m.SentDate
            ,u.FirstName
            ,u.LastName
            ,u.ProfilePictureURL
        FROM TMessages m
        JOIN TUsers u ON m.UserSenderID = u.UserID
        WHERE m.ConversationID = ?
        ORDER BY m.SentDate ASC
    ");
    $msgStmt->execute([$activeConversationId]);
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $readStmt = $pdo->prepare("CALL uspMarkConversationRead(?, ?)");
        $readStmt->execute([$activeConversationId, $userId]);
        $readStmt->closeCursor();
    } catch (PDOException $e) {
        error_log("uspMarkConversationRead error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages - Community Toolkit</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="css/ai_chatbot.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
                <a href="my_rentals.php" class="nav-link">
                    <i class="fas fa-calendar"></i><span>My Rentals</span>
                </a>
            </nav>

            <div class="user-section">
                <?php
                $__nStmt = $pdo->prepare("SELECT COUNT(*) FROM TNotifications WHERE UserID = ? AND ReadStatus = 0");
                $__nStmt->execute([$userId]);
                $__unreadNotif = intval($__nStmt->fetchColumn());
                $__mStmt = $pdo->prepare("SELECT COUNT(*) FROM TMessages m JOIN TUserConversations uc ON m.ConversationID = uc.ConversationID WHERE uc.UserID = ? AND m.UserSenderID != ? AND m.SentDate > COALESCE(uc.LastReadDate, '2000-01-01')");
                $__mStmt->execute([$userId, $userId]);
                $__unreadMsg = intval($__mStmt->fetchColumn());
                ?>
                <div class="notification-icon" onclick="toggleNotifDropdown(event)" style="cursor:pointer;">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="notifBadge" style="<?php echo $__unreadNotif > 0 ? '' : 'display:none;'; ?>"><?php echo min($__unreadNotif, 99); ?></span>
                </div>
                <div class="notification-icon" onclick="toggleMsgDropdown(event)" style="cursor:pointer;">
                    <i class="fas fa-comment-dots"></i>
                    <span class="notification-badge" id="msgBadge" style="<?php echo $__unreadMsg > 0 ? '' : 'display:none;'; ?>"><?php echo min($__unreadMsg, 99); ?></span>
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
                        <a href="my_bookmarks.php"><i class="fas fa-bookmark"></i> Bookmarked Items</a>
                        <hr>
                        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</header>

<main class="container">
    <div class="messages-page">
        <div class="messages-layout">

            <div class="panel">
                <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <span>Messages</span>
                    <button onclick="markAllMessagesRead()" id="markAllBtn"
                            style="font-size:11px;font-weight:600;color:#667eea;background:none;border:none;cursor:pointer;padding:0;font-family:inherit;"
                            title="Mark all as read">
                        <i class="fas fa-check-double"></i> Mark all read
                    </button>
                </div>
                <div class="conversation-list">
                    <?php if (empty($conversations)): ?>
                        <div class="no-conversations">
                            <i class="fas fa-comment-slash" style="font-size:32px;margin-bottom:8px;"></i><br>
                            No conversations yet.<br>Rental requests and user profiles create conversations automatically.
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conv): ?>
                            <?php
                                $isActive  = ($conv['ConversationID'] == $activeConversationId);
                                $otherName = htmlspecialchars($conv['OtherFirstName'] . ' ' . substr($conv['OtherLastName'], 0, 1) . '.');
                                $initial   = strtoupper(substr($conv['OtherFirstName'], 0, 1));
                                $lastMsg   = htmlspecialchars(mb_strimwidth($conv['LastMessageBody'] ?? '', 0, 50, '…'));
                                $unread    = intval($conv['UnreadCount']);
                            ?>
                            <div class="conversation<?php echo $isActive ? ' active' : ''; ?>" style="position:relative;">
                                <a href="messages.php?conversation=<?php echo $conv['ConversationID']; ?>" style="display:flex;gap:12px;flex:1;text-decoration:none;color:inherit;align-items:center;min-width:0;">
                                    <div class="avatar">
    <?php if (!empty($conv['OtherProfilePictureURL'])): ?>
        <img
            src="<?php echo htmlspecialchars($conv['OtherProfilePictureURL']); ?>"
            alt="<?php echo htmlspecialchars($conv['OtherFirstName']); ?>"
            style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"
        >
    <?php else: ?>
        <?php echo $initial; ?>
    <?php endif; ?>
</div>
                                    <div class="conv-info">
                                        <div class="conv-name"><?php echo $otherName; ?></div>
                                        <div class="conv-item"><?php echo htmlspecialchars($conv['ListingTitle']); ?></div>
                                        <div class="conv-last"><?php echo $lastMsg; ?></div>
                                    </div>
                                    <?php if ($unread > 0): ?>
                                        <div class="unread"><?php echo $unread; ?></div>
                                    <?php endif; ?>
                                </a>
                                <button class="conv-delete" title="Delete conversation"
                                        onclick="deleteConversation(<?php echo $conv['ConversationID']; ?>, this)">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <?php if ($activeConv): ?>

                    <div class="chat-header">
                        <span>
                            <?php echo htmlspecialchars($activeConv['OtherFirstName'] . ' ' . substr($activeConv['OtherLastName'], 0, 1) . '.'); ?>
                            &mdash;
                            <?php echo htmlspecialchars($activeConv['ListingTitle']); ?>
                        </span>
                        <?php
                        $backUrl = urlencode('messages.php?conversation=' . $activeConversationId);
                        $convReportUrl = 'report.php?type=conversation&conversation_id=' . $activeConversationId . '&back_url=' . $backUrl;
                        ?>
                        <a href="<?php echo $convReportUrl; ?>"
                           style="display:inline-flex;align-items:center;gap:5px;color:#dc2626;font-size:12px;font-weight:600;text-decoration:none;padding:5px 11px;border:1.5px solid #fecaca;border-radius:7px;background:#fef2f2;transition:background 0.2s;white-space:nowrap;"
                           onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                            <i class="fas fa-flag"></i> Report
                        </a>
                    </div>

                   <div class="chat-thread" id="chatThread">
                        <?php if (empty($messages)): ?>
                            <div style="text-align:center;color:#9ca3af;margin-top:40px;font-size:14px;">
                                No messages yet. Say hello!
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <?php if ($msg['SystemMessage']): ?>
                                    <div class="message-row system" data-message-id="<?php echo intval($msg['MessageID']); ?>">
                                        <div class="bubble">
                                            <?php echo htmlspecialchars($msg['MessageBody']); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                   <?php $isMe = ($msg['UserSenderID'] == $userId); ?>
<div class="message-row <?php echo $isMe ? 'me' : 'them'; ?>" data-message-id="<?php echo intval($msg['MessageID']); ?>">

    <?php if (!$isMe): ?>
        <div class="message-avatar">
            <?php if (!empty($msg['ProfilePictureURL'])): ?>
                <img
                    src="<?php echo htmlspecialchars($msg['ProfilePictureURL']); ?>"
                    alt="<?php echo htmlspecialchars($msg['FirstName']); ?>"
                    style="width:36px;height:36px;object-fit:cover;border-radius:50%;display:block;"
                >
            <?php else: ?>
                <div style="width:36px;height:36px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:700;">
                    <?php echo strtoupper(substr($msg['FirstName'], 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="bubble">
                                            <?php if (!empty($msg['MessageBody'])): ?>
                                                <div><?php echo htmlspecialchars($msg['MessageBody']); ?></div>
                                            <?php endif; ?>
                    
                                            <?php if (!empty($msg['GifURL'])): ?>
                                                <div class="message-gif">
                                                    <img
                                                        src="<?php echo htmlspecialchars($msg['GifURL']); ?>"
                                                        alt="GIF"
                                                        style="max-width:220px;border-radius:10px;<?php echo !empty($msg['MessageBody']) ? 'margin-top:8px;' : ''; ?>"
                                                    >
                                                </div>
                                            <?php endif; ?>
                                            <?php
                                                $attStmt = $pdo->prepare("SELECT FileURL, GifURL FROM TMessageAttachments WHERE MessageID = ?");
                                                $attStmt->execute([$msg['MessageID']]);
                                                $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);
                                                ?>
                                            <?php foreach ($attachments as $att): ?>
    <?php if (!empty($att['FileURL'])): ?>
        <?php
            $rawUrl = $att['FileURL'];
            $fileUrl = htmlspecialchars($rawUrl);
            $ext = strtolower(pathinfo(parse_url($rawUrl, PHP_URL_PATH), PATHINFO_EXTENSION));

            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $isVideo = in_array($ext, ['mp4', 'webm', 'mov', 'ogg', 'm4v']);
        ?>
        <div class="message-attachment" style="margin-top:8px;">
            <?php if ($isImage): ?>
                <a href="<?php echo $fileUrl; ?>" target="_blank">
                    <img src="<?php echo $fileUrl; ?>" alt="Attachment" style="max-width:220px;border-radius:10px;">
                </a>

            <?php elseif ($isVideo): ?>
                <video
                    src="<?php echo $fileUrl; ?>"
                    controls
                    playsinline
                    style="max-width:220px; border-radius:10px; display:block;"
                ></video>

            <?php else: ?>
                <a href="<?php echo $fileUrl; ?>" target="_blank">
                    <i class="fas fa-paperclip"></i> Open File
                </a>
            <?php endif; ?>
        </div>

    <?php elseif (!empty($att['GifURL']) && filter_var($att['GifURL'], FILTER_VALIDATE_URL)): ?>
        <div class="message-gif" style="margin-top:8px;">
            <img src="<?php echo htmlspecialchars($att['GifURL']); ?>"
                 alt="GIF"
                 style="max-width:220px;border-radius:10px;">
        </div>
    <?php endif; ?>
<?php endforeach; ?>
                                            <div class="msg-time" data-sent="<?php echo htmlspecialchars($msg['SentDate']); ?>"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <div class="chat-input">
                   <div class="chat-form" id="chatForm">
                        <button type="button" class="icon-btn" onclick="document.getElementById('fileInput').click()" title="Upload file">
                            <i class="fas fa-paperclip"></i>
                        </button>
                    
                        <input type="file" id="fileInput" hidden multiple accept="image/*,video/*" onchange="handleFiles(this.files)">
                    
                        <button type="button" class="icon-btn" onclick="toggleGifPicker()" title="GIF">
                            <i class="fas fa-image"></i>
                        </button>
                    
                        <div id="gifPicker" class="gif-picker">
                            <div class="gif-search-row">
                                <input
                                    type="text"
                                    id="gifSearchInput"
                                    placeholder="Search GIFs"
                                    onkeydown="handleGifKey(event)"
                                >
                                <button class="gif-search-btn" type="button" onclick="searchGifs()">Search</button>
                            </div>
                        
                            <div id="gifResults" class="gif-results"></div>
                            <div class="gif-attribution">Powered by GIPHY</div>
                        </div>
                    
                        <input
                            type="text"
                            id="messageInput"
                            placeholder="Type a message..."
                            autocomplete="off"
                            maxlength="2000"
                         
                        >
                     
                        <button type="button" class="send-btn" onclick="sendMessage()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                        
                    </div>
                    <div id="filePreview" class="file-preview"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
function toggleUserMenu() {
    document.getElementById('userDropdown').classList.toggle('show');
}

window.onclick = function(event) {
    if (!event.target.matches('.user-avatar')) {
        const dropdown = document.getElementById('userDropdown');
        if (dropdown && dropdown.classList.contains('show')) {
            dropdown.classList.remove('show');
        }
    }
};

const chatThread = document.getElementById('chatThread');
if (chatThread) {
    chatThread.scrollTop = chatThread.scrollHeight;
}

const activeConversationId = <?php echo intval($activeConversationId); ?>;

// Simple client-side word check
const _badWords = ['fuck','shit','cunt','nigger','faggot','motherfucker'];
function clientProfanityCheck(text) {
    const n = text.toLowerCase().replace(/[^a-z\s]/g, '');
    return !_badWords.some(w => n.includes(w));
}

let selectedFiles = [];
let selectedGifUrl = null;

const GIPHY_API_KEY = "G7CZH6C7AVph0H4tEBWXgqPvRbjLF4FJ";

function handleMessageKey(e) {
    if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function handleGifKey(e) {
    if (e.key === "Enter") {
        e.preventDefault();
        searchGifs();
    }
}

function toggleGifPicker() {
    const picker = document.getElementById("gifPicker");
    picker.classList.toggle("show");
}

function handleFiles(files) {
    selectedFiles = Array.from(files);
    selectedGifUrl = null; // clear old gif when uploading files
    renderPreview();
}

function selectGif(url) {
    selectedGifUrl = url;
    selectedFiles = []; // clear uploaded files when choosing a gif
    const fileInput = document.getElementById("fileInput");
    if (fileInput) fileInput.value = "";
    renderPreview();
    document.getElementById("gifPicker").classList.remove("show");
}

function clearSelectedGif() {
    selectedGifUrl = null;
    renderPreview();
}

function removeSelectedFile(index) {
    selectedFiles.splice(index, 1);
    renderPreview();
}

function renderPreview() {
    const preview = document.getElementById("filePreview");
    if (!preview) return;

    preview.innerHTML = "";

    if (selectedGifUrl) {
        const gifWrap = document.createElement("div");
        gifWrap.className = "gif-selected-preview";
        gifWrap.innerHTML = `
            <img src="${selectedGifUrl}" alt="Selected GIF">
            <button type="button" class="remove-file-btn" onclick="clearSelectedGif()">✕</button>
        `;
        preview.appendChild(gifWrap);
        return;
    }

    selectedFiles.forEach((file, index) => {
        const wrapper = document.createElement("div");
        wrapper.className = "file-preview-item";

        const removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.className = "remove-file-btn";
        removeBtn.innerHTML = "✕";
        removeBtn.onclick = () => removeSelectedFile(index);

        if (file.type.startsWith("image/")) {
            const img = document.createElement("img");
            img.className = "file-preview-thumb";
            img.alt = file.name;

            const reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);

            wrapper.appendChild(img);

        } else if (file.type.startsWith("video/")) {
            const video = document.createElement("video");
            video.className = "file-preview-thumb";
            video.controls = true;
            video.muted = true;
            video.preload = "metadata";

            const reader = new FileReader();
            reader.onload = function(e) {
                video.src = e.target.result;
            };
            reader.readAsDataURL(file);

            wrapper.appendChild(video);

        } else {
            const fileBox = document.createElement("div");
            fileBox.className = "file-preview-doc";
            fileBox.innerHTML = `
                <div class="file-preview-icon"><i class="fas fa-file"></i></div>
                <div class="file-preview-name">${file.name}</div>
            `;
            wrapper.appendChild(fileBox);
        }

        wrapper.appendChild(removeBtn);
        preview.appendChild(wrapper);
    });
}




async function searchGifs() {
    const query = document.getElementById("gifSearchInput").value.trim();
    if (!query) return;

    const resultsBox = document.getElementById("gifResults");
    resultsBox.innerHTML = "Searching...";

    try {
        const url = `https://api.giphy.com/v1/gifs/search?api_key=${encodeURIComponent(GIPHY_API_KEY)}&q=${encodeURIComponent(query)}&limit=12&rating=g&lang=en`;

        const res = await fetch(url);
        const data = await res.json();

        resultsBox.innerHTML = "";

        if (!data.data || data.data.length === 0) {
            resultsBox.innerHTML = "<p>No GIFs found.</p>";
            return;
        }

        data.data.forEach(item => {
            const previewUrl =
                item.images?.fixed_width_small?.url ||
                item.images?.fixed_width?.url ||
                item.images?.original?.url;

            const sendUrl =
                item.images?.original?.url ||
                item.images?.fixed_width?.url;

            if (!previewUrl || !sendUrl) return;

            const img = document.createElement("img");
            img.src = previewUrl;
            img.alt = item.title || "GIF";
            img.onclick = () => selectGif(sendUrl);
            resultsBox.appendChild(img);
        });
    } catch (err) {
        console.error(err);
        resultsBox.innerHTML = "<p>Failed to load GIFs.</p>";
    }
}


function clearSelectedGif() {
    selectedGifUrl = null;

    const preview = document.getElementById("filePreview");
    if (preview) {
        preview.innerHTML = "";
    }
}
async function sendMessage() {
    const input = document.getElementById('messageInput');
    const body = input.value.trim();

    if (!body && selectedFiles.length === 0 && !selectedGifUrl) return;
    if (!activeConversationId) return;

    if (body && !clientProfanityCheck(body)) {
        input.style.borderColor = '#dc2626';
        setTimeout(() => input.style.borderColor = '', 2000);
        return;
    }

    try {
        const formData = new FormData();
        formData.append('ajax_action', 'send_message');
        formData.append('conversation_id', activeConversationId);
        formData.append('message_body', body);

        selectedFiles.forEach(file => {
            formData.append('attachments[]', file);
        });

        if (selectedGifUrl) {
            formData.append('gif_url', selectedGifUrl);
        }

        const response = await fetch('messages.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            input.value = '';
            document.getElementById("filePreview").innerHTML = "";
            document.getElementById("fileInput").value = "";
            selectedFiles = [];
            selectedGifUrl = null;

            // easiest way to show uploaded files immediately
            location.reload();
        } else {
            console.error('Send failed:', data.error || data.message);
            alert(data.error || data.message || 'Failed to send message.');
        }
    } catch (err) {
        console.error('Network error:', err);
        alert('Network error while sending message.');
    }
}

const messageInput = document.getElementById('messageInput');
if (messageInput) {
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
}

// Real-time polling
let lastMessageId = 0;
document.querySelectorAll('[data-message-id]').forEach(function(el) {
    const id = parseInt(el.dataset.messageId);
    if (id > lastMessageId) lastMessageId = id;
});

function formatMsgTime(dateStr) {
    const d = new Date(dateStr.replace(' ', 'T'));
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

document.querySelectorAll('.msg-time[data-sent]').forEach(function(el) {
    el.textContent = formatMsgTime(el.dataset.sent);
});

function appendIncomingBubble(msg) {
    const thread = document.getElementById('chatThread');
    if (!thread) return;

    const row = document.createElement('div');
    row.className = msg.SystemMessage == '1' ? 'message-row system' : 'message-row them';
    row.dataset.messageId = msg.MessageID;

    const bubble = document.createElement('div');
    bubble.className = 'bubble';

    if (msg.MessageBody) {
        const textDiv = document.createElement('div');
        textDiv.textContent = msg.MessageBody;
        bubble.appendChild(textDiv);
    }

    if (msg.GifURL) {
        const gif = document.createElement('img');
        gif.src = msg.GifURL;
        gif.alt = 'GIF';
        gif.style.maxWidth = '220px';
        gif.style.borderRadius = '10px';
        gif.style.display = 'block';
        gif.style.marginTop = msg.MessageBody ? '8px' : '0';
        bubble.appendChild(gif);
    }

    const time = document.createElement('div');
    time.className = 'msg-time';
    time.textContent = formatMsgTime(msg.SentDate);

    bubble.appendChild(time);
    row.appendChild(bubble);
    thread.appendChild(row);
    thread.scrollTop = thread.scrollHeight;
}

function updateBadge(id, count) {
    const badge = document.getElementById(id);
    if (!badge) return;
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.style.display = count > 0 ? '' : 'none';
}

async function pollMessages() {
    if (!activeConversationId) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'poll_messages');
        fd.append('conversation_id', activeConversationId);
        fd.append('last_message_id', lastMessageId);

        const res = await fetch('messages.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;

        data.messages.forEach(function(msg) {
            if (parseInt(msg.UserSenderID) !== <?php echo intval($userId); ?>) {
                appendIncomingBubble(msg);
            }
            if (parseInt(msg.MessageID) > lastMessageId) lastMessageId = parseInt(msg.MessageID);
        });

        updateBadge('notifBadge', data.unreadNotif);
        updateBadge('msgBadge', data.unreadMsg);
    } catch(e) {}
}

if (activeConversationId) {
    setInterval(pollMessages, 3000);
}

async function deleteConversation(conversationId, btn) {
    if (!confirm('Delete this conversation? This cannot be undone.')) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'delete_conversation');
        fd.append('conversation_id', conversationId);

        const res = await fetch('messages.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const row = btn.closest('.conversation');
            row.style.transition = 'opacity 0.25s';
            row.style.opacity = '0';

            setTimeout(() => {
                row.remove();
                if (conversationId == activeConversationId) {
                    window.location.href = 'messages.php';
                }
            }, 250);
        }
    } catch(e) {}
}

async function markAllMessagesRead() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'mark_all_read');
        await fetch('messages.php', { method: 'POST', body: fd });

        updateBadge('msgBadge', 0);
        updateBadge('notifBadge', 0);

        document.getElementById('markAllBtn').textContent = 'All read ✓';
        setTimeout(() => {
            document.getElementById('markAllBtn').innerHTML = '<i class="fas fa-check-double"></i> Mark all read';
        }, 2000);
    } catch(e) {}
}
document.addEventListener("click", function (e) {
    const picker = document.getElementById("gifPicker");
    const gifButton = e.target.closest('[title="GIF"]');
    const insidePicker = e.target.closest("#gifPicker");

    if (!picker) return;

    if (!insidePicker && !gifButton) {
        picker.classList.remove("show");
    }
});
function handleFiles(files) {
    const allFiles = Array.from(files);

    const allowedFiles = allFiles.filter(file =>
        file.type.startsWith("image/") || file.type.startsWith("video/")
    );

    if (allowedFiles.length !== allFiles.length) {
        alert("Only image and video files are allowed.");
    }

    selectedFiles = allowedFiles;
    selectedGifUrl = null;
    renderPreview();
}
</script>

<?php require_once 'includes/chatbot_widget.php'; ?>
<?php include 'includes/header_dropdowns.php'; ?>
</body>
</html>
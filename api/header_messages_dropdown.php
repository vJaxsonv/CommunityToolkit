<?php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    exit('');
}

$_hd_userId = (int)$_SESSION['user_id'];

$_hd_msgStmt = $pdo->prepare("
    SELECT c.ConversationID, c.LastMessageDate,
           l.Title as ListingTitle,
           l.ListingID,
           other.FirstName as OtherFirstName, other.LastName as OtherLastName,
           (SELECT MessageBody 
            FROM TMessages 
            WHERE ConversationID = c.ConversationID 
            ORDER BY SentDate DESC 
            LIMIT 1) as LastMessage,
           (SELECT UserSenderID 
            FROM TMessages 
            WHERE ConversationID = c.ConversationID 
            ORDER BY SentDate DESC 
            LIMIT 1) as LastSenderID,
           (SELECT COUNT(*) 
            FROM TMessages m
            WHERE m.ConversationID = c.ConversationID
              AND m.SentDate > COALESCE(uc.LastReadDate, '2000-01-01')
              AND m.UserSenderID != ?) as UnreadCount
    FROM TConversations c
    INNER JOIN TUserConversations uc 
        ON c.ConversationID = uc.ConversationID 
       AND uc.UserID = ?
    INNER JOIN TRentals r 
        ON c.RentalID = r.RentalID
    INNER JOIN TListings l 
        ON r.ListingID = l.ListingID
    INNER JOIN TUserConversations uc2 
        ON c.ConversationID = uc2.ConversationID 
       AND uc2.UserID != ?
    INNER JOIN TUsers other 
        ON uc2.UserID = other.UserID
    ORDER BY c.LastMessageDate DESC
    LIMIT 3
");
$_hd_msgStmt->execute([$_hd_userId, $_hd_userId, $_hd_userId]);
$_hd_conversations = $_hd_msgStmt->fetchAll(PDO::FETCH_ASSOC);

$_hd_unreadMsgCount = 0;
foreach ($_hd_conversations as $_hd_c) {
    $_hd_unreadMsgCount += (int)$_hd_c['UnreadCount'];
}
?>

<div id="hd-msgDropdownInner">
    <div style="padding:16px 18px 12px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f0f0f0;">
        <span style="font-size:16px;font-weight:800;color:#1a1a2e;">Messages</span>
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="hd-msgUnreadBadge" style="background:#667eea;color:white;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;<?php echo $_hd_unreadMsgCount > 0 ? '' : 'display:none;'; ?>">
                <?php echo $_hd_unreadMsgCount; ?> unread
            </span>

            <a href="messages.php" title="Open all messages"
               style="width:32px;height:32px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#374151;text-decoration:none;">
                <i class="fas fa-expand-alt" style="font-size:13px;"></i>
            </a>
        </div>
    </div>

    <div id="hd-msgDropdownContent">
        <?php if (empty($_hd_conversations)): ?>
            <div style="padding:32px 18px;text-align:center;color:#9ca3af;">
                <i class="fas fa-comment-slash" style="font-size:32px;display:block;margin-bottom:12px;color:#e5e7eb;"></i>
                <div style="font-size:14px;font-weight:600;color:#374151;margin-bottom:4px;">No messages yet</div>
                <div style="font-size:12px;">Conversations appear after a rental is accepted.</div>
            </div>
        <?php else: ?>
            <?php foreach ($_hd_conversations as $_hd_conv):
                $_hd_isUnread = (int)$_hd_conv['UnreadCount'] > 0;
                $_hd_bg = $_hd_isUnread ? '#f5f3ff' : 'white';
                $_hd_initial = strtoupper(substr($_hd_conv['OtherFirstName'], 0, 1));
                $_hd_preview = mb_substr($_hd_conv['LastMessage'] ?? 'No messages yet', 0, 42);
                if (mb_strlen($_hd_conv['LastMessage'] ?? '') > 42) $_hd_preview .= '…';
            ?>
                <a href="messages.php?conversation=<?php echo (int)$_hd_conv['ConversationID']; ?>"
                   style="display:flex;gap:12px;align-items:center;padding:12px 18px;border-bottom:1px solid #f9f9f9;text-decoration:none;background:<?php echo $_hd_bg; ?>;">
                    <div style="position:relative;flex-shrink:0;">
                        <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:16px;font-weight:700;display:flex;align-items:center;justify-content:center;">
                            <?php echo htmlspecialchars($_hd_initial); ?>
                        </div>
                        <?php if ($_hd_isUnread): ?>
                            <div style="position:absolute;bottom:1px;right:1px;width:10px;height:10px;border-radius:50%;background:#667eea;border:2px solid white;"></div>
                        <?php endif; ?>
                    </div>

                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:2px;">
                            <span style="font-size:14px;font-weight:<?php echo $_hd_isUnread ? '800' : '600'; ?>;color:#1a1a2e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;">
                                <?php echo htmlspecialchars($_hd_conv['OtherFirstName'] . ' ' . substr($_hd_conv['OtherLastName'], 0, 1) . '.'); ?>
                            </span>
                            <span style="font-size:11px;color:#9ca3af;flex-shrink:0;margin-left:8px;">
                                <?php
                                $ts = strtotime($_hd_conv['LastMessageDate']);
                                $diff = time() - $ts;
                                if ($diff < 3600) echo floor($diff / 60) . 'm';
                                elseif ($diff < 86400) echo floor($diff / 3600) . 'h';
                                else echo date('M j', $ts);
                                ?>
                            </span>
                        </div>

                        <div style="font-size:12px;color:<?php echo $_hd_isUnread ? '#374151' : '#9ca3af'; ?>;font-weight:<?php echo $_hd_isUnread ? '600' : '400'; ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php if ((int)$_hd_conv['LastSenderID'] === $_hd_userId): ?>
                                <span style="color:#9ca3af;">You: </span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($_hd_preview); ?>
                        </div>

                        <div style="font-size:11px;color:#c4b5fd;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars(mb_substr($_hd_conv['ListingTitle'], 0, 30)); ?>
                        </div>
                    </div>

                    <?php if ($_hd_isUnread): ?>
                        <div style="width:8px;height:8px;border-radius:50%;background:#667eea;flex-shrink:0;"></div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <a href="messages.php" style="display:block;padding:13px 18px;text-align:center;font-size:13px;font-weight:700;color:#667eea;text-decoration:none;background:#fafafa;border-top:1px solid #f0f0f0;">
        See all messages <i class="fas fa-arrow-right" style="font-size:11px;"></i>
    </a>
</div>
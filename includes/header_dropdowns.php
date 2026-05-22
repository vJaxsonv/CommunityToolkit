<?php
if (!isset($_SESSION['user_id'])) return;

$_hd_userId = $_SESSION['user_id'];

// ── 3 most recent notifications ──────────────────────────────
$_hd_notifStmt = $pdo->prepare("
    SELECT n.NotificationID, n.Message, n.ReadStatus, n.AddedDate,
           nt.Name as TypeName, n.NotificationTypeID,
           n.RentalRequestID, n.ConversationID, n.RentalID
    FROM TNotifications n
    INNER JOIN TNotificationTypes nt ON n.NotificationTypeID = nt.NotificationTypeID
    WHERE n.UserID = ?
    ORDER BY n.AddedDate DESC
    LIMIT 3
");
$_hd_notifStmt->execute([$_hd_userId]);
$_hd_notifications = $_hd_notifStmt->fetchAll(PDO::FETCH_ASSOC);

$_hd_unreadNotifStmt = $pdo->prepare("SELECT COUNT(*) FROM TNotifications WHERE UserID = ? AND ReadStatus = 0");
$_hd_unreadNotifStmt->execute([$_hd_userId]);
$_hd_unreadNotifCount = intval($_hd_unreadNotifStmt->fetchColumn());

// ── 3 most recent conversations ──────────────────────────────
$_hd_msgStmt = $pdo->prepare("
    SELECT c.ConversationID, c.LastMessageDate,
           l.Title as ListingTitle,
           l.ListingID,
           other.FirstName as OtherFirstName, other.LastName as OtherLastName,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder ASC LIMIT 1) as ListingPhoto,
           (SELECT MessageBody FROM TMessages WHERE ConversationID = c.ConversationID ORDER BY SentDate DESC LIMIT 1) as LastMessage,
           (SELECT UserSenderID FROM TMessages WHERE ConversationID = c.ConversationID ORDER BY SentDate DESC LIMIT 1) as LastSenderID,
           (SELECT COUNT(*) FROM TMessages m
            WHERE m.ConversationID = c.ConversationID
            AND m.SentDate > COALESCE(uc.LastReadDate, '2000-01-01')
            AND m.UserSenderID != ?) as UnreadCount
    FROM TConversations c
    INNER JOIN TUserConversations uc ON c.ConversationID = uc.ConversationID AND uc.UserID = ?
    INNER JOIN TRentals r ON c.RentalID = r.RentalID
    INNER JOIN TListings l ON r.ListingID = l.ListingID
    INNER JOIN TUserConversations uc2 ON c.ConversationID = uc2.ConversationID AND uc2.UserID != ?
    INNER JOIN TUsers other ON uc2.UserID = other.UserID
    ORDER BY c.LastMessageDate DESC
    LIMIT 3
");
$_hd_msgStmt->execute([$_hd_userId, $_hd_userId, $_hd_userId]);
$_hd_conversations = $_hd_msgStmt->fetchAll(PDO::FETCH_ASSOC);

$_hd_unreadMsgCount = 0;
foreach ($_hd_conversations as $_hd_c) {
    $_hd_unreadMsgCount += intval($_hd_c['UnreadCount']);
}

$_hd_notifIcons = [
    1 => 'fa-paper-plane', 2 => 'fa-check',
    3 => 'fa-times',       4 => 'fa-play',
    5 => 'fa-flag-checkered', 6 => 'fa-star', 7 => 'fa-clock',
    8 => 'fa-comment-dots'
];
$_hd_notifLinks = [
    1 => fn($n) => intval($n['RentalRequestID']) ? 'accept_rental.php?request_id=' . intval($n['RentalRequestID']) : 'my_rentals.php?role=lender&tab=requests',
    2 => fn($n) => 'my_rentals.php?role=borrower&tab=upcoming',
    3 => fn($n) => 'my_rentals.php?role=borrower&tab=pending',
    4 => fn($n) => 'my_rentals.php?role=borrower&tab=current',
    5 => fn($n) => 'my_rentals.php?role=borrower',
    6 => fn($n) => 'profile.php',
    7 => fn($n) => 'my_rentals.php?role=lender&tab=active',
    8 => fn($n) => intval($n['ConversationID']) ? 'messages.php?conversation=' . intval($n['ConversationID']) : 'messages.php',
];
?>

<!-- NOTIFICATION DROPDOWN -->
<div id="hd-notifDropdown" style="
    display:none;
    position:fixed;
    top:68px;
    right:110px;
    width:340px;
    background:white;
    border-radius:14px;
    box-shadow:0 12px 40px rgba(0,0,0,0.18);
    border:1px solid #e5e7eb;
    z-index:99999;
    overflow:hidden;
">
    <!-- Header -->
    <div style="padding:16px 18px 12px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f0f0f0;">
        <span style="font-size:16px;font-weight:800;color:#1a1a2e;">Notifications</span>
        <div style="display:flex;align-items:center;gap:8px;">
            <?php if ($_hd_unreadNotifCount > 0): ?>
                <span style="background:#667eea;color:white;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;">
                    <?php echo $_hd_unreadNotifCount; ?> new
                </span>
                <button onclick="markAllNotifsRead(event)" style="font-size:11px;font-weight:600;color:#667eea;background:none;border:none;cursor:pointer;padding:0;font-family:inherit;white-space:nowrap;">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notification list -->
    <?php if (empty($_hd_notifications)): ?>
        <div style="padding:32px 18px;text-align:center;color:#9ca3af;">
            <i class="fas fa-bell-slash" style="font-size:32px;display:block;margin-bottom:12px;color:#e5e7eb;"></i>
            <div style="font-size:14px;font-weight:600;color:#374151;margin-bottom:4px;">No notifications yet</div>
            <div style="font-size:12px;">You'll see activity here.</div>
        </div>
    <?php else: ?>
        <?php foreach ($_hd_notifications as $_hd_n):
            $_hd_typeId = intval($_hd_n['NotificationTypeID']);
            $_hd_icon   = $_hd_notifIcons[$_hd_typeId] ?? 'fa-bell';
            $_hd_bg     = !$_hd_n['ReadStatus'] ? '#f5f3ff' : 'white';
            $_hd_nid    = intval($_hd_n['NotificationID']);
            $_hd_convId = intval($_hd_n['ConversationID'] ?? 0);
            $_hd_renId  = intval($_hd_n['RentalID'] ?? 0);
            $_hd_reqId  = intval($_hd_n['RentalRequestID'] ?? 0);
            $_hd_base   = isset($_hd_notifLinks[$_hd_typeId]) ? $_hd_notifLinks[$_hd_typeId]($_hd_n) : 'notifications.php';
            // Append mark_notif params so config.php clears them server-side on page load
            $_hd_sep    = strpos($_hd_base, '?') !== false ? '&' : '?';
            $_hd_link   = $_hd_base . $_hd_sep
                        . 'mark_notif=' . $_hd_nid
                        . '&mark_type='    . $_hd_typeId
                        . '&mark_conv='    . $_hd_convId
                        . '&mark_rental='  . $_hd_renId
                        . '&mark_request=' . $_hd_reqId;
        ?>
            <a href="<?php echo htmlspecialchars($_hd_link); ?>"
               onclick="markNotifRead(this)"
               style="display:flex;gap:12px;align-items:flex-start;padding:12px 18px;border-bottom:1px solid #f9f9f9;background:<?php echo $_hd_bg; ?>;text-decoration:none;color:inherit;transition:background 0.15s;"
               onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='<?php echo $_hd_bg; ?>'">
                <div style="width:36px;height:36px;border-radius:50%;background:#ede9fe;color:#7c3aed;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;">
                    <i class="fas <?php echo $_hd_icon; ?>"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;color:#374151;line-height:1.45;margin-bottom:3px;">
                        <?php echo htmlspecialchars($_hd_n['Message']); ?>
                    </div>
                    <div style="font-size:11px;color:#9ca3af;">
                        <?php echo date('M j · g:i A', strtotime($_hd_n['AddedDate'])); ?>
                    </div>
                </div>
                <?php if (!$_hd_n['ReadStatus']): ?>
                    <div class="hd-notif-dot" style="width:8px;height:8px;border-radius:50%;background:#667eea;flex-shrink:0;margin-top:4px;"></div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Footer -->
    <a href="notifications.php" style="display:block;padding:13px 18px;text-align:center;font-size:13px;font-weight:700;color:#667eea;text-decoration:none;background:#fafafa;border-top:1px solid #f0f0f0;">
        See all notifications <i class="fas fa-arrow-right" style="font-size:11px;"></i>
    </a>
</div>


<!-- MESSAGES DROPDOWN -->
<div id="hd-msgDropdown" style="
    display:none;
    position:fixed;
    top:68px;
    right:68px;
    width:360px;
    background:white;
    border-radius:14px;
    box-shadow:0 12px 40px rgba(0,0,0,0.18);
    border:1px solid #e5e7eb;
    z-index:99999;
    overflow:hidden;
">
    <!-- Header — title + "open full messages" icon -->
    <div style="padding:16px 18px 12px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f0f0f0;">
        <span style="font-size:16px;font-weight:800;color:#1a1a2e;">Messages</span>
        <div style="display:flex;align-items:center;gap:10px;">
            <?php if ($_hd_unreadMsgCount > 0): ?>
                <span style="background:#667eea;color:white;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;">
                    <?php echo $_hd_unreadMsgCount; ?> unread
                </span>
                <button onclick="markAllMessagesReadDropdown(event)"
                        style="font-size:11px;font-weight:600;color:#667eea;background:none;border:none;cursor:pointer;padding:0;font-family:inherit;white-space:nowrap;"
                        title="Mark all messages read">
                    Mark all read
                </button>
            <?php endif; ?>
            <!-- Grid icon → opens full messages page -->
            <a href="messages.php" title="Open all messages"
               style="width:32px;height:32px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#374151;text-decoration:none;transition:background 0.15s;"
               onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                <i class="fas fa-expand-alt" style="font-size:13px;"></i>
            </a>
        </div>
    </div>

    <!-- Conversation list -->
    <?php if (empty($_hd_conversations)): ?>
        <div style="padding:32px 18px;text-align:center;color:#9ca3af;">
            <i class="fas fa-comment-slash" style="font-size:32px;display:block;margin-bottom:12px;color:#e5e7eb;"></i>
            <div style="font-size:14px;font-weight:600;color:#374151;margin-bottom:4px;">No messages yet</div>
            <div style="font-size:12px;">Conversations appear after a rental is accepted.</div>
        </div>
    <?php else: ?>
        <?php foreach ($_hd_conversations as $_hd_conv):
            $_hd_isUnread = $_hd_conv['UnreadCount'] > 0;
            $_hd_bg       = $_hd_isUnread ? '#f5f3ff' : 'white';
            $_hd_initial  = strtoupper(substr($_hd_conv['OtherFirstName'], 0, 1));
            $_hd_preview  = mb_substr($_hd_conv['LastMessage'] ?? 'No messages yet', 0, 42);
            if (mb_strlen($_hd_conv['LastMessage'] ?? '') > 42) $_hd_preview .= '…';
        ?>
            <a href="messages.php?conversation=<?php echo $_hd_conv['ConversationID']; ?>"
               style="display:flex;gap:12px;align-items:center;padding:12px 18px;border-bottom:1px solid #f9f9f9;text-decoration:none;background:<?php echo $_hd_bg; ?>;transition:background 0.15s;"
               onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='<?php echo $_hd_bg; ?>'">

                <!-- Avatar -->
                <div style="position:relative;flex-shrink:0;">
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:16px;font-weight:700;display:flex;align-items:center;justify-content:center;">
                        <?php echo $_hd_initial; ?>
                    </div>
                    <?php if ($_hd_isUnread): ?>
                        <div style="position:absolute;bottom:1px;right:1px;width:10px;height:10px;border-radius:50%;background:#667eea;border:2px solid white;"></div>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:2px;">
                        <span style="font-size:14px;font-weight:<?php echo $_hd_isUnread ? '800' : '600'; ?>;color:#1a1a2e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;">
                            <?php echo htmlspecialchars($_hd_conv['OtherFirstName'] . ' ' . substr($_hd_conv['OtherLastName'], 0, 1) . '.'); ?>
                        </span>
                        <span style="font-size:11px;color:#9ca3af;flex-shrink:0;margin-left:8px;">
                            <?php
                            $ts = strtotime($_hd_conv['LastMessageDate']);
                            $diff = time() - $ts;
                            if ($diff < 3600)       echo floor($diff/60) . 'm';
                            elseif ($diff < 86400)  echo floor($diff/3600) . 'h';
                            else                    echo date('M j', $ts);
                            ?>
                        </span>
                    </div>
                    <div style="font-size:12px;color:<?php echo $_hd_isUnread ? '#374151' : '#9ca3af'; ?>;font-weight:<?php echo $_hd_isUnread ? '600' : '400'; ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php if ($_hd_conv['LastSenderID'] == $_hd_userId): ?>
                            <span style="color:#9ca3af;">You: </span>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($_hd_preview); ?>
                    </div>
                    <div style="font-size:11px;color:#c4b5fd;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo htmlspecialchars(mb_substr($_hd_conv['ListingTitle'], 0, 30)); ?>
                    </div>
                </div>

                <!-- Unread dot -->
                <?php if ($_hd_isUnread): ?>
                    <div style="width:8px;height:8px;border-radius:50%;background:#667eea;flex-shrink:0;"></div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Footer -->
    <a href="messages.php" style="display:block;padding:13px 18px;text-align:center;font-size:13px;font-weight:700;color:#667eea;text-decoration:none;background:#fafafa;border-top:1px solid #f0f0f0;">
        See all messages <i class="fas fa-arrow-right" style="font-size:11px;"></i>
    </a>
</div>


<!--BADGE COUNTS + CLICK HANDLERS-->
<script>
document.addEventListener('DOMContentLoaded', function() {

    var unreadNotif   = <?php echo intval($_hd_unreadNotifCount); ?>;
    var unreadMsg     = <?php echo intval($_hd_unreadMsgCount); ?>;
    var notifDropdown = document.getElementById('hd-notifDropdown');
    var msgDropdown   = document.getElementById('hd-msgDropdown');

    if (!notifDropdown || !msgDropdown) return;

    // ── Update badge counts ──────────────────────────────────
    document.querySelectorAll('.notification-icon').forEach(function(icon) {
        var badge = icon.querySelector('.notification-badge');
        var i     = icon.querySelector('i');
        if (!badge || !i) return;
        if (i.classList.contains('fa-bell')) {
            badge.textContent   = unreadNotif > 99 ? '99+' : String(unreadNotif);
            badge.style.display = unreadNotif > 0 ? '' : 'none';
        }
        if (i.classList.contains('fa-comment-dots')) {
            badge.textContent   = unreadMsg > 99 ? '99+' : String(unreadMsg);
            badge.style.display = unreadMsg > 0 ? '' : 'none';
        }
    });

    // ── Close both dropdowns ─────────────────────────────────
    function closeAll() {
        notifDropdown.style.display = 'none';
        msgDropdown.style.display   = 'none';
    }

    // ── Wire up icon clicks ──────────────────────────────────
    document.querySelectorAll('.notification-icon').forEach(function(icon) {
        var i = icon.querySelector('i');
        if (!i) return;

        if (i.classList.contains('fa-bell')) {
            icon.style.cursor = 'pointer';
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = notifDropdown.style.display === 'block';
                closeAll();
                if (!isOpen) notifDropdown.style.display = 'block';
            });
        }

        if (i.classList.contains('fa-comment-dots')) {
            icon.style.cursor = 'pointer';
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = msgDropdown.style.display === 'block';
                closeAll();
                if (!isOpen) msgDropdown.style.display = 'block';
            });
        }
    });

    // ── Close when clicking outside ──────────────────────────
    document.addEventListener('click', function(e) {
        if (!notifDropdown.contains(e.target) && !msgDropdown.contains(e.target)) {
            closeAll();
        }
    });

    // ── Prevent clicks inside dropdown from closing it ───────
    notifDropdown.addEventListener('click', function(e) { e.stopPropagation(); });
    msgDropdown.addEventListener('click',   function(e) { e.stopPropagation(); });

});

// Visual-only update on click -- server-side clearing happens via mark_notif URL params in config.php
function markNotifRead(el) {
    // Clear this item visually
    var dot = el.querySelector('.hd-notif-dot');
    if (dot) dot.remove();
    el.style.background = 'white';
    el.setAttribute('onmouseout', "this.style.background='white'");
    // Decrement bell badge
    document.querySelectorAll('.notification-icon').forEach(function(icon) {
        var i = icon.querySelector('i');
        if (i && i.classList.contains('fa-bell')) {
            var badge = icon.querySelector('.notification-badge');
            if (badge) {
                var count = Math.max(0, parseInt(badge.textContent || '0') - 1);
                badge.textContent = String(count);
                badge.style.display = count > 0 ? '' : 'none';
            }
        }
    });
}

// Mark all notifications read via AJAX
// Defined OUTSIDE DOMContentLoaded so onclick attributes can reach it
function markAllNotifsRead(e) {
    e.stopPropagation();
    var fd = new FormData();
    fd.append('ajax_action', 'mark_all_notif_read');
    fetch('notifications.php', { method: 'POST', body: fd })
        .then(function() {
            document.querySelectorAll('#hd-notifDropdown a').forEach(function(a) {
                a.style.background = 'white';
                var dot = a.querySelector('.hd-notif-dot');
                if (dot) dot.remove();
            });
            document.querySelectorAll('.notification-icon').forEach(function(icon) {
                var i = icon.querySelector('i');
                if (i && i.classList.contains('fa-bell')) {
                    var badge = icon.querySelector('.notification-badge');
                    if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
                }
            });
            var btns = document.querySelectorAll('#hd-notifDropdown div:first-child span, #hd-notifDropdown div:first-child button');
            btns.forEach(function(b) { b.style.display = 'none'; });
        }).catch(function() {});
}
function refreshMessageDropdown() {
    fetch('api/header_messages_dropdown.php')
        .then(function(res) { return res.text(); })
        .then(function(html) {
            var msgDropdown = document.getElementById('hd-msgDropdown');
            if (msgDropdown && html.trim() !== '') {
                msgDropdown.innerHTML = html;
            }
        })
        .catch(function() {});
}

// Mark all messages read from the dropdown
function markAllMessagesReadDropdown(e) {
    if (e) e.stopPropagation();
    var fd = new FormData();
    fd.append('ajax_action', 'mark_all_read');
    fetch('messages.php', { method: 'POST', body: fd })
        .then(function() {
            // Clear message badge immediately
            document.querySelectorAll('.notification-icon').forEach(function(icon) {
                var i = icon.querySelector('i');
                if (i && i.classList.contains('fa-comment-dots')) {
                    var badge = icon.querySelector('.notification-badge');
                    if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
                }
            });
            // Refresh the dropdown to clear unread dots
            refreshMessageDropdown();
        }).catch(function() {});
}

// Poll for fresh badge counts every 3 seconds on all pages except messages.php
(function() {
    if (window.location.pathname.indexOf('messages.php') !== -1) return;

    function pollBadgeCounts() {
        fetch('api/poll_counts.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                document.querySelectorAll('.notification-icon').forEach(function(icon) {
                    var i = icon.querySelector('i');
                    var badge = icon.querySelector('.notification-badge');
                    if (!i || !badge) return;

                    if (i.classList.contains('fa-bell')) {
                        badge.textContent = data.notif > 99 ? '99+' : String(data.notif);
                        badge.style.display = data.notif > 0 ? '' : 'none';
                    }

                    if (i.classList.contains('fa-comment-dots')) {
                        badge.textContent = data.msg > 99 ? '99+' : String(data.msg);
                        badge.style.display = data.msg > 0 ? '' : 'none';
                    }
                });

                refreshMessageDropdown();
            }).catch(function() {});
    }

    pollBadgeCounts();
    setInterval(pollBadgeCounts, 3000);
})();
</script>

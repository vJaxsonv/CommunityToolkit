<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'notif' => 0,
        'msg' => 0
    ]);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// unread notifications
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM TNotifications 
    WHERE UserID = ? AND ReadStatus = 0
");
$stmt->execute([$userId]);
$notifCount = (int)$stmt->fetchColumn();

// unread messages
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(unread_count), 0) AS total_unread
    FROM (
        SELECT COUNT(*) AS unread_count
        FROM TUserConversations uc
        INNER JOIN TMessages m 
            ON m.ConversationID = uc.ConversationID
        WHERE uc.UserID = ?
          AND m.UserSenderID != ?
          AND m.SentDate > COALESCE(uc.LastReadDate, '2000-01-01')
        GROUP BY uc.ConversationID
    ) x
");
$stmt->execute([$userId, $userId]);
$msgCount = (int)$stmt->fetchColumn();

echo json_encode([
    'notif' => $notifCount,
    'msg' => $msgCount
]);
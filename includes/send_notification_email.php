<?php
/**
 * includes/send_notification_email.php
 *
 * Call after every TNotifications INSERT to send an email if the recipient
 * has that notification type enabled in TUserNotificationPrefs.
 *
 * Usage:
 *   require_once 'includes/send_notification_email.php';
 *   sendNotificationEmail($pdo, $recipientUserId, $notificationTypeId, $messageText);
 */

function sendNotificationEmail(PDO $pdo, int $recipientUserId, int $notifTypeId, string $messageText): void {

    // Check preference — default ON if no row exists yet
    try {
        $prefStmt = $pdo->prepare("
            SELECT EmailEnabled FROM TUserNotificationPrefs
            WHERE UserID = ? AND NotificationTypeID = ?
        ");
        $prefStmt->execute([$recipientUserId, $notifTypeId]);
        $row = $prefStmt->fetch(PDO::FETCH_ASSOC);
        $emailEnabled = $row ? intval($row['EmailEnabled']) : 1;
    } catch (PDOException $e) {
        // Table may not exist yet — default to sending
        $emailEnabled = 1;
    }

    if (!$emailEnabled) return;

    // Fetch recipient email and name
    $userStmt = $pdo->prepare("SELECT FirstName, Email FROM TUsers WHERE UserID = ?");
    $userStmt->execute([$recipientUserId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || empty($user['Email'])) return;

    $notifTypeNames = [
        1 => 'New Rental Request',
        2 => 'Rental Request Accepted',
        3 => 'Rental Request Declined',
        4 => 'Rental Started',
        5 => 'Rental Completed',
        6 => 'New Review Received',
        7 => 'Rental Extension Requested',
        8 => 'New Message',
    ];

    $subject   = 'Community Toolkit: ' . ($notifTypeNames[$notifTypeId] ?? 'Notification');
    $firstName = htmlspecialchars($user['FirstName']);
    $safeMsgText = htmlspecialchars($messageText);

    $body = "Hi {$firstName},\n\n"
          . "{$messageText}\n\n"
          . "Log in to Community Toolkit to take action:\n"
          . "https://thecommunitytoolkit.com/\n\n"
          . "---\n"
          . "You're receiving this because you have this notification type enabled.\n"
          . "To manage your email preferences, visit:\n"
          . "https://thecommunitytoolkit.com/account_info.php\n\n"
          . "Community Toolkit\n"
          . "thecommunitytoolkit.com";

    $htmlBody = "<!DOCTYPE html>
<html lang='en'>
<head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:0;'>
  <div style='max-width:560px;margin:40px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);'>
    <div style='background:linear-gradient(135deg,#667eea,#764ba2);padding:28px 32px;'>
      <h1 style='color:white;margin:0;font-size:20px;font-weight:700;'>Community Toolkit</h1>
    </div>
    <div style='padding:32px;'>
      <p style='color:#374151;font-size:16px;margin-top:0;'>Hi {$firstName},</p>
      <p style='color:#374151;font-size:15px;line-height:1.6;'>{$safeMsgText}</p>
      <div style='text-align:center;margin:28px 0;'>
        <a href='https://thecommunitytoolkit.com/'
           style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#667eea,#764ba2);color:white;text-decoration:none;border-radius:8px;font-weight:700;font-size:15px;'>
          Go to Community Toolkit
        </a>
      </div>
      <hr style='border:none;border-top:1px solid #e5e7eb;margin:24px 0;'>
      <p style='color:#9ca3af;font-size:12px;line-height:1.6;'>
        You received this email because you have this notification type enabled.<br>
        <a href='https://thecommunitytoolkit.com/account_info.php' style='color:#667eea;'>Manage your email preferences</a>
      </p>
    </div>
  </div>
</body>
</html>";

    $to      = $user['Email'];
    $from    = 'noreply@thecommunitytoolkit.com';
    $headers = implode("\r\n", [
        'From: Community Toolkit <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/html; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: PHP/' . phpversion(),
    ]);

    // Use @ to suppress warnings if mail server is unavailable
    @mail($to, $subject, $htmlBody, $headers);
}

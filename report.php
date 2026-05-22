<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// ── Get target info from URL ──────────────────────────────────────────────────
$targetType          = $_GET['type']            ?? ''; // 'listing', 'user', 'review', 'conversation'
$targetListingId     = intval($_GET['listing_id']      ?? 0);
$targetUserId        = intval($_GET['user_id']         ?? 0);
$targetReviewId      = intval($_GET['review_id']       ?? 0);
$targetConversationId = intval($_GET['conversation_id'] ?? 0);

// Validate target
if (!in_array($targetType, ['listing', 'user', 'review', 'conversation'])) {
    header('Location: home.php');
    exit;
}

// Prevent reporting yourself
if ($targetType === 'user' && $targetUserId === $userId) {
    header('Location: home.php');
    exit;
}

// Fetch report reasons
$reasons = $pdo->query("SELECT ReasonID, Reason FROM TReportReasons ORDER BY ReasonID")->fetchAll(PDO::FETCH_ASSOC);

// Fetch target label for display
$targetLabel = '';
if ($targetType === 'listing' && $targetListingId) {
    $s = $pdo->prepare("SELECT Title FROM TListings WHERE ListingID = ?");
    $s->execute([$targetListingId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $targetLabel = $row['Title'] ?? 'this listing';
} elseif ($targetType === 'user' && $targetUserId) {
    $s = $pdo->prepare("SELECT FirstName, LastName FROM TUsers WHERE UserID = ?");
    $s->execute([$targetUserId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $targetLabel = $row ? htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) : 'this user';
} elseif ($targetType === 'review' && $targetReviewId) {
    $targetLabel = 'this review';
} elseif ($targetType === 'conversation' && $targetConversationId) {
    // Verify reporter is a participant
    $s = $pdo->prepare('
        SELECT u.FirstName, u.LastName, l.Title
        FROM TUserConversations uc
        JOIN TConversations c ON uc.ConversationID = c.ConversationID
        JOIN TRentals r ON c.RentalID = r.RentalID
        JOIN TListings l ON r.ListingID = l.ListingID
        JOIN TUserConversations uc2 ON c.ConversationID = uc2.ConversationID AND uc2.UserID != ?
        JOIN TUsers u ON uc2.UserID = u.UserID
        WHERE uc.ConversationID = ? AND uc.UserID = ?
    ');
    $s->execute([$userId, $targetConversationId, $userId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $otherName   = htmlspecialchars($row['FirstName'] . ' ' . substr($row['LastName'], 0, 1) . '.');
        $targetLabel = 'conversation with ' . $otherName . ' about ' . htmlspecialchars($row['Title']);
        $targetUserId = 0; // report targets the conversation, not a specific user
    } else {
        header('Location: messages.php');
        exit;
    }
}

$success = false;
$error   = '';

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reasonId = intval($_POST['reason_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');

    if (!$reasonId) {
        $error = 'Please select a reason for your report.';
    } else {
        try {
            // Check if this user already reported this target
            $dupSql = "SELECT ReportID FROM TReports WHERE ReporterUserID = ?";
            $dupParams = [$userId];
            if ($targetType === 'listing')      { $dupSql .= " AND TargetListingID = ?";      $dupParams[] = $targetListingId; }
            if ($targetType === 'user')         { $dupSql .= " AND TargetUserID = ?";         $dupParams[] = $targetUserId; }
            if ($targetType === 'review')       { $dupSql .= " AND TargetReviewID = ?";       $dupParams[] = $targetReviewId; }
            if ($targetType === 'conversation') { $dupSql .= " AND TargetConversationID = ?"; $dupParams[] = $targetConversationId; }
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute($dupParams);
            if ($dupStmt->fetch()) {
                $error = 'You have already submitted a report for this content.';
            } else {
                // Insert the report
                $pdo->prepare("
                    INSERT INTO TReports (ReporterUserID, ReasonID, AdditionalNotes, TargetUserID, TargetListingID, TargetReviewID, TargetConversationID)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $userId,
                    $reasonId,
                    $notes ?: null,
                    $targetType === 'user'    ? $targetUserId    : null,
                    $targetType === 'listing' ? $targetListingId : null,
                    $targetType === 'review'  ? $targetReviewId  : null,
                    $targetType === 'conversation' ? $targetConversationId : null,
                ]);

                // ── Threshold check: count total reports for this target ──
                $THRESHOLD = 3;
                $countSql    = "SELECT COUNT(*) FROM TReports WHERE ReportStatus = 0";
                $countParams = [];
                if ($targetType === 'listing')      { $countSql .= " AND TargetListingID = ?";      $countParams[] = $targetListingId; }
                if ($targetType === 'user')         { $countSql .= " AND TargetUserID = ?";         $countParams[] = $targetUserId; }
                if ($targetType === 'review')       { $countSql .= " AND TargetReviewID = ?";       $countParams[] = $targetReviewId; }
                if ($targetType === 'conversation') { $countSql .= " AND TargetConversationID = ?"; $countParams[] = $targetConversationId; }
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($countParams);
                $flagCount = (int)$countStmt->fetchColumn();

                if ($flagCount >= $THRESHOLD) {
                    // Auto-hide the content
                    if ($targetType === 'listing') {
                        // Set to Under Review (status 4 — add this to TListingStatuses if needed)
                        $pdo->prepare("UPDATE TListings SET ListingStatusID = 4, FlagCount = ? WHERE ListingID = ?")
                            ->execute([$flagCount, $targetListingId]);
                    } elseif ($targetType === 'user') {
                        // Temporarily deactivate the user account
                        $pdo->prepare("UPDATE TUsers SET AccountStatus = 0, FlagCount = ? WHERE UserID = ?")
                            ->execute([$flagCount, $targetUserId]);
                    } elseif ($targetType === 'review') {
                        // Mark review as flagged (FlagCount >= threshold hides it in queries)
                        $pdo->prepare("UPDATE TReviews SET FlagCount = ? WHERE ReviewID = ?")
                            ->execute([$flagCount, $targetReviewId]);
                    }
                } else {
                    // Just update the flag count
                    if ($targetType === 'listing') {
                        $pdo->prepare("UPDATE TListings SET FlagCount = ? WHERE ListingID = ?")->execute([$flagCount, $targetListingId]);
                    } elseif ($targetType === 'user') {
                        $pdo->prepare("UPDATE TUsers SET FlagCount = ? WHERE UserID = ?")->execute([$flagCount, $targetUserId]);
                    } elseif ($targetType === 'review') {
                        $pdo->prepare("UPDATE TReviews SET FlagCount = ? WHERE ReviewID = ?")->execute([$flagCount, $targetReviewId]);
                    }
                }

                $success = true;
            }
        } catch (PDOException $e) {
            error_log("Report error: " . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}

// Back URL
$backUrl = $_POST['back_url'] ?? $_GET['back_url'] ?? null;

if (!$backUrl) {
    if (!empty($reviewUserId)) {
        $backUrl = 'profile.php?user_id=' . (int)$reviewUserId;
    } elseif (!empty($profileUserId)) {
        $backUrl = 'profile.php?user_id=' . (int)$profileUserId;
    } elseif (isset($_GET['user'])) {
        $backUrl = 'profile.php?user_id=' . (int)$_GET['user'];
    } elseif (isset($_GET['user_id'])) {
        $backUrl = 'profile.php?user_id=' . (int)$_GET['user_id'];
    } else {
        $backUrl = 'home.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Content - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .report-container {
            max-width: 560px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .report-card {
            background: white;
            border-radius: 14px;
            padding: 36px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .report-card h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 6px;
        }
        .report-subtitle {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 28px;
        }
        .report-subtitle strong { color: #374151; }
        .reason-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
        .reason-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .reason-option:hover { border-color: #667eea; background: #f5f6ff; }
        .reason-option input[type="radio"] { accent-color: #667eea; width: 18px; height: 18px; flex-shrink: 0; }
        .reason-option.selected { border-color: #667eea; background: #f0f2ff; }
        .reason-label { font-size: 14px; color: #374151; font-weight: 500; }
        .notes-group { margin-bottom: 24px; }
        .notes-group label { display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px; }
        .notes-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 90px;
            box-sizing: border-box;
        }
        .notes-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        .report-actions { display: flex; gap: 12px; }
        .btn-report-submit {
            flex: 1;
            padding: 13px;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-report-submit:hover { background: #b91c1c; }
        .btn-cancel {
            padding: 13px 24px;
            background: #f3f4f6;
            color: #374151;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: background 0.2s;
        }
        .btn-cancel:hover { background: #e5e7eb; }
        .alert { border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: flex-start; gap: 10px; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .success-icon { font-size: 48px; text-align: center; margin-bottom: 16px; }
        .success-title { font-size: 20px; font-weight: 700; color: #1a1a2e; text-align: center; margin-bottom: 8px; }
        .success-text { color: #6b7280; font-size: 14px; text-align: center; line-height: 1.6; margin-bottom: 24px; }
    </style>
</head>
<body>
<div class="report-container">
    <div class="report-card">

        <?php if ($success): ?>
            <div class="success-icon">🚩</div>
            <p class="success-title">Report Submitted</p>
            <p class="success-text">
                Thank you for helping keep Community Toolkit safe.<br>
                Our team will review this content shortly.
            </p>
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-cancel" style="width:100%;justify-content:center;">
                <i class="fas fa-arrow-left"></i>&nbsp; Go Back
            </a>

        <?php else: ?>
            <h1><i class="fas fa-flag" style="color:#dc2626;"></i> Report Content</h1>
            <p class="report-subtitle">
                You are reporting: <strong><?php echo htmlspecialchars($targetLabel); ?></strong>
            </p>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="back_url" value="<?php echo htmlspecialchars($backUrl); ?>">

                <div class="reason-list">
                    <?php foreach ($reasons as $r): ?>
                        <label class="reason-option" id="reason-<?php echo $r['ReasonID']; ?>">
                            <input type="radio" name="reason_id" value="<?php echo $r['ReasonID']; ?>"
                                   onchange="highlightSelected(<?php echo $r['ReasonID']; ?>)">
                            <span class="reason-label"><?php echo htmlspecialchars($r['Reason']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="notes-group">
                    <label>Additional Details (Optional)</label>
                    <textarea name="notes" placeholder="Provide any additional context that may help our review team..." maxlength="500"></textarea>
                </div>

                <div class="report-actions">
                    <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-report-submit">
                        <i class="fas fa-flag"></i> Submit Report
                    </button>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>
<script>
    function highlightSelected(id) {
        document.querySelectorAll('.reason-option').forEach(el => el.classList.remove('selected'));
        document.getElementById('reason-' + id).classList.add('selected');
    }
</script>
</body>
</html>

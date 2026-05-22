<?php require_once 'admin_auth.php'; ?>
<?php
$success = '';
$error   = '';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = intval($_POST['report_id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    if ($reportId > 0) {
        try {
            // Fetch the report to know what to reinstate
            $rpt = $pdo->prepare("SELECT * FROM TReports WHERE ReportID = ?");
            $rpt->execute([$reportId]);
            $report = $rpt->fetch(PDO::FETCH_ASSOC);

            if (!$report) { $error = 'Report not found.'; }
            else {
                // Helper: resolve all related reports
                function resolveReports($pdo, $report, $adminId, $status) {
                    foreach (['TargetListingID','TargetUserID','TargetReviewID',
                              'TargetMessageID','TargetConversationID'] as $col) {
                        if (!empty($report[$col])) {
                            $pdo->prepare("UPDATE TReports SET ReportStatus = ?, ResolvedDate = NOW(), ResolvedByAdminID = ? WHERE $col = ? AND ReportStatus = 0")
                                ->execute([$status, $adminId, $report[$col]]);
                        }
                    }
                }

                // Helper: get the user ID responsible for the reported content
                function getTargetUserId($pdo, $report) {
                    if (!empty($report['TargetUserID'])) return $report['TargetUserID'];
                    if (!empty($report['TargetMessageID'])) {
                        $s = $pdo->prepare('SELECT UserSenderID FROM TMessages WHERE MessageID = ?');
                        $s->execute([$report['TargetMessageID']]);
                        return $s->fetchColumn() ?: null;
                    }
                    if (!empty($report['TargetConversationID'])) {
                        $s = $pdo->prepare('SELECT UserID FROM TUserConversations WHERE ConversationID = ? AND UserID != ? LIMIT 1');
                        $s->execute([$report['TargetConversationID'], $report['ReporterUserID']]);
                        return $s->fetchColumn() ?: null;
                    }
                    if (!empty($report['TargetListingID'])) {
                        $s = $pdo->prepare('SELECT UserLenderID FROM TListings WHERE ListingID = ?');
                        $s->execute([$report['TargetListingID']]);
                        return $s->fetchColumn() ?: null;
                    }
                    if (!empty($report['TargetReviewID'])) {
                        $s = $pdo->prepare('SELECT UserReviewerID FROM TReviews WHERE ReviewID = ?');
                        $s->execute([$report['TargetReviewID']]);
                        return $s->fetchColumn() ?: null;
                    }
                    return null;
                }

                switch ($action) {

                    case 'reinstate':
                        if ($report['TargetListingID']) {
                            $pdo->prepare("UPDATE TListings SET ListingStatusID = 1, FlagCount = 0 WHERE ListingID = ?")
                                ->execute([$report['TargetListingID']]);
                        }
                        if ($report['TargetUserID']) {
                            $pdo->prepare("UPDATE TUsers SET AccountStatus = 1, FlagCount = 0 WHERE UserID = ? AND AccountStatus != 2")
                                ->execute([$report['TargetUserID']]);
                        }
                        if ($report['TargetReviewID']) {
                            $pdo->prepare("UPDATE TReviews SET FlagCount = 0 WHERE ReviewID = ?")
                                ->execute([$report['TargetReviewID']]);
                        }
                        if ($report['TargetMessageID']) {
                            $pdo->prepare("UPDATE TMessages SET SystemMessage = 0 WHERE MessageID = ? AND MessageBody = '[Message removed by admin]'")
                                ->execute([$report['TargetMessageID']]);
                        }
                        resolveReports($pdo, $report, $_SESSION['user_id'], 2);
                        $success = 'Content reinstated. Reports dismissed.';
                        break;

                    case 'warn':
                        $warnUserId = getTargetUserId($pdo, $report);
                        if ($warnUserId) {
                            $pdo->prepare("
                                INSERT INTO TNotifications
                                    (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                                     RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                                VALUES (?, 1, 0, 0, 0, 0, 0, 0, ?, 0, NOW())
                            ")->execute([$warnUserId,
                                'Warning: Your account has received a warning for a policy violation. Further violations may result in suspension.'
                            ]);
                            $pdo->prepare("UPDATE TUsers SET FlagCount = FlagCount + 1 WHERE UserID = ?")
                                ->execute([$warnUserId]);
                        }
                        resolveReports($pdo, $report, $_SESSION['user_id'], 1);
                        $success = 'Warning issued to user. Report resolved.';
                        break;

                    case 'suspend':
                    case 'confirm':
                        if ($report['TargetListingID']) {
                            $pdo->prepare("UPDATE TListings SET ListingStatusID = 4 WHERE ListingID = ?")
                                ->execute([$report['TargetListingID']]);
                        }
                        if ($report['TargetReviewID']) {
                            $pdo->prepare("UPDATE TReviews SET FlagCount = 99 WHERE ReviewID = ?")
                                ->execute([$report['TargetReviewID']]);
                        }
                        if ($report['TargetMessageID']) {
                            $pdo->prepare("UPDATE TMessages SET MessageBody = '[Message removed by admin]', SystemMessage = 1 WHERE MessageID = ?")
                                ->execute([$report['TargetMessageID']]);
                        }
                        $suspendUserId = getTargetUserId($pdo, $report);
                        if ($suspendUserId) {
                            $pdo->prepare("UPDATE TUsers SET AccountStatus = 0 WHERE UserID = ? AND AccountStatus != 2")
                                ->execute([$suspendUserId]);
                            $pdo->prepare("
                                INSERT INTO TNotifications
                                    (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                                     RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                                VALUES (?, 1, 0, 0, 0, 0, 0, 0, ?, 0, NOW())
                            ")->execute([$suspendUserId,
                                'Your account has been suspended due to a policy violation. Please contact support@thecommunitytoolkit.com to appeal.'
                            ]);
                        }
                        resolveReports($pdo, $report, $_SESSION['user_id'], 1);
                        $success = 'User suspended and content hidden.';
                        break;

                    case 'ban':
                        if ($report['TargetListingID']) {
                            $pdo->prepare("UPDATE TListings SET ListingStatusID = 5 WHERE ListingID = ?")
                                ->execute([$report['TargetListingID']]);
                        }
                        if ($report['TargetReviewID']) {
                            $pdo->prepare("UPDATE TReviews SET FlagCount = 99 WHERE ReviewID = ?")
                                ->execute([$report['TargetReviewID']]);
                        }
                        if ($report['TargetMessageID']) {
                            $pdo->prepare("UPDATE TMessages SET MessageBody = '[Message removed by admin]', SystemMessage = 1 WHERE MessageID = ?")
                                ->execute([$report['TargetMessageID']]);
                        }
                        $banUserId = getTargetUserId($pdo, $report);
                        if ($banUserId) {
                            $pdo->prepare("UPDATE TUsers SET AccountStatus = 2 WHERE UserID = ?")
                                ->execute([$banUserId]);
                            $pdo->prepare("UPDATE TListings SET ListingStatusID = 5 WHERE UserLenderID = ?")
                                ->execute([$banUserId]);
                            $pdo->prepare("
                                INSERT INTO TNotifications
                                    (UserID, NotificationTypeID, RentalRequestID, ConversationID, MessageID,
                                     RentalExtensionID, RentalID, ReviewID, Message, ReadStatus, AddedDate)
                                VALUES (?, 1, 0, 0, 0, 0, 0, 0, ?, 0, NOW())
                            ")->execute([$banUserId,
                                'Your account has been permanently banned for repeated or severe policy violations.'
                            ]);
                        }
                        resolveReports($pdo, $report, $_SESSION['user_id'], 1);
                        $success = 'User permanently banned and all content removed.';
                        break;

                    default:
                        $error = 'Unknown action.';
                }
            }
        } catch (PDOException $e) {
            error_log("Admin reports error: " . $e->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}

// ── Fetch grouped reports ─────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'pending';
$statusVal    = match($statusFilter) { 'resolved' => 1, 'dismissed' => 2, default => 0 };

// Fetch one representative report per target (latest one)
$sql = "
    SELECT
        r.ReportID,
        r.ReportStatus,
        r.AddedDate,
        r.AdditionalNotes,
        r.TargetListingID,
        r.TargetUserID,
        r.TargetReviewID,
        r.TargetMessageID,
        r.TargetConversationID,
        rr.Reason,
        reporter.FirstName AS ReporterFirst,
        reporter.LastName  AS ReporterLast,

        -- Listing info
        l.Title            AS ListingTitle,
        l.ListingStatusID,
        ls.Status          AS ListingStatus,
        l.FlagCount        AS ListingFlagCount,

        -- Target user info
        tu.FirstName       AS TargetFirstName,
        tu.LastName        AS TargetLastName,
        tu.Email           AS TargetEmail,
        tu.AccountStatus   AS TargetAccountStatus,
        tu.FlagCount       AS UserFlagCount,

        -- Review info
        rev.ReviewText,
        rev.FlagCount      AS ReviewFlagCount,
        reviewer.FirstName AS ReviewerFirst,
        reviewer.LastName  AS ReviewerLast,

        msg.MessageBody    AS MessageBody,
        msg.SentDate       AS MessageSentDate,
        msgsender.FirstName AS MsgSenderFirst,
        msgsender.LastName  AS MsgSenderLast,
        msgsender.Email     AS MsgSenderEmail,
        msgsender.AccountStatus AS MsgSenderStatus,

        -- Total report count for this target
        (SELECT COUNT(*) FROM TReports r2
         WHERE r2.ReportStatus = 0
           AND (
               (r.TargetListingID      IS NOT NULL AND r2.TargetListingID      = r.TargetListingID)      OR
               (r.TargetUserID         IS NOT NULL AND r2.TargetUserID         = r.TargetUserID)         OR
               (r.TargetReviewID       IS NOT NULL AND r2.TargetReviewID       = r.TargetReviewID)       OR
               (r.TargetMessageID      IS NOT NULL AND r2.TargetMessageID      = r.TargetMessageID)      OR
               (r.TargetConversationID IS NOT NULL AND r2.TargetConversationID = r.TargetConversationID)
           )
        ) AS TotalFlags

    FROM TReports r
    JOIN TReportReasons rr      ON r.ReasonID         = rr.ReasonID
    JOIN TUsers reporter        ON r.ReporterUserID   = reporter.UserID
    LEFT JOIN TListings l       ON r.TargetListingID  = l.ListingID
    LEFT JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
    LEFT JOIN TUsers tu         ON r.TargetUserID     = tu.UserID
    LEFT JOIN TReviews rev      ON r.TargetReviewID   = rev.ReviewID
    LEFT JOIN TUsers reviewer   ON rev.UserReviewerID = reviewer.UserID
    LEFT JOIN TMessages msg     ON r.TargetMessageID  = msg.MessageID
    LEFT JOIN TUsers msgsender  ON msg.UserSenderID   = msgsender.UserID
    LEFT JOIN TConversations cv ON r.TargetConversationID = cv.ConversationID
    WHERE r.ReportStatus = ?
    GROUP BY
        COALESCE(r.TargetListingID, 0),
        COALESCE(r.TargetUserID, 0),
        COALESCE(r.TargetReviewID, 0),
        COALESCE(r.TargetMessageID, 0),
        COALESCE(r.TargetConversationID, 0)
    ORDER BY TotalFlags DESC, r.AddedDate DESC
";

$stmt    = $pdo->prepare($sql);
$stmt->execute([$statusVal]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pending count for badge
$pendingCount = $pdo->query("SELECT COUNT(DISTINCT COALESCE(TargetListingID,0), COALESCE(TargetUserID,0), COALESCE(TargetReviewID,0)) FROM TReports WHERE ReportStatus = 0")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - CT Admin</title>
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
        .nav-badge { background: #dc2626; color: white; font-size: 11px; border-radius: 999px; padding: 1px 7px; margin-left: auto; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.1); font-size: 13px; color: #64748b; }
        .sidebar-footer a { color: #94a3b8; text-decoration: none; }
        .sidebar-footer a:hover { color: white; }

        .admin-main { flex: 1; padding: 32px; overflow-y: auto; }
        .admin-main h1 { font-size: 24px; font-weight: 700; color: #1a1a2e; margin-bottom: 24px; }
        .alert { border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

        .tab-bar { display: flex; gap: 4px; margin-bottom: 24px; background: white; border-radius: 10px; padding: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); width: fit-content; }
        .tab { padding: 8px 20px; border-radius: 7px; font-size: 14px; font-weight: 600; text-decoration: none; color: #6b7280; transition: background 0.2s, color 0.2s; }
        .tab:hover { color: #374151; }
        .tab.active { background: #667eea; color: white; }

        .report-card {
            background: white;
            border-radius: 12px;
            padding: 22px 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            border-left: 4px solid #e5e7eb;
        }
        .report-card.auto-hidden { border-left-color: #dc2626; }
        .report-card.pending     { border-left-color: #f59e0b; }

        .report-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
        .report-type-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
        .badge-listing { background: #eff6ff; color: #1d4ed8; }
        .badge-user    { background: #f0fdf4; color: #166534; }
        .badge-review  { background: #fef9c3; color: #854d0e; }

        .flag-count { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 700; color: #dc2626; }
        .auto-hide-notice { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 3px 10px; }

        .report-target { font-size: 16px; font-weight: 700; color: #1a1a2e; margin-bottom: 6px; }
        .report-meta   { font-size: 13px; color: #6b7280; margin-bottom: 12px; line-height: 1.6; }
        .report-meta strong { color: #374151; }

        .reason-tag { display: inline-block; background: #f3f4f6; color: #374151; border-radius: 6px; padding: 3px 10px; font-size: 12px; font-weight: 600; margin-bottom: 8px; }

        .report-notes { font-size: 13px; color: #6b7280; font-style: italic; background: #f9fafb; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; }

        .action-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { padding: 8px 18px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-reinstate { background: #dcfce7; color: #166534; } .btn-reinstate:hover { background: #bbf7d0; }
        .btn-confirm   { background: #fee2e2; color: #b91c1c; } .btn-confirm:hover   { background: #fecaca; }
        .btn-view      { background: #f3f4f6; color: #374151; } .btn-view:hover      { background: #e5e7eb; }

        .empty-state { text-align: center; padding: 60px 20px; color: #9ca3af; }
        .empty-state i { font-size: 40px; margin-bottom: 12px; display: block; }
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
            <a href="reports.php" class="active">
                <i class="fas fa-flag"></i> Reports
                <?php if ($pendingCount > 0): ?>
                    <span class="nav-badge"><?php echo $pendingCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="messages.php"><i class="fas fa-comment-dots"></i> Messages</a>
        </nav>
        <div class="sidebar-footer">
            Logged in as <strong><?php echo htmlspecialchars($_SESSION['firstname']); ?></strong><br>
            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </aside>

    <main class="admin-main">
        <h1>Reports</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="tab-bar">
            <a href="reports.php?status=pending"   class="tab <?php echo $statusFilter === 'pending'   ? 'active' : ''; ?>">
                Pending <?php if ($pendingCount > 0): ?>(<?php echo $pendingCount; ?>)<?php endif; ?>
            </a>
            <a href="reports.php?status=resolved"  class="tab <?php echo $statusFilter === 'resolved'  ? 'active' : ''; ?>">Resolved</a>
            <a href="reports.php?status=dismissed" class="tab <?php echo $statusFilter === 'dismissed' ? 'active' : ''; ?>">Dismissed</a>
        </div>

        <?php if (empty($reports)): ?>
            <div class="empty-state">
                <i class="fas fa-flag"></i>
                No <?php echo $statusFilter; ?> reports.
            </div>
        <?php else: ?>
            <?php foreach ($reports as $r):
                $isAutoHidden = ($r['TotalFlags'] >= 3);
                $cardClass    = $isAutoHidden ? 'auto-hidden' : 'pending';
            ?>
            <div class="report-card <?php echo $cardClass; ?>">
                <div class="report-header">
                    <div>
                        <?php if ($r['TargetListingID']): ?>
                            <span class="report-type-badge badge-listing"><i class="fas fa-box"></i> Listing</span>
                        <?php elseif ($r['TargetUserID']): ?>
                            <span class="report-type-badge badge-user"><i class="fas fa-user"></i> User Profile</span>
                        <?php elseif ($r['TargetReviewID']): ?>
                            <span class="report-type-badge badge-review"><i class="fas fa-star"></i> Review</span>
                        <?php elseif (!empty($r['TargetMessageID'])): ?>
                            <span class="report-type-badge" style="background:#fdf4ff;color:#7e22ce;"><i class="fas fa-comment"></i> Message</span>
                        <?php elseif (!empty($r['TargetConversationID'])): ?>
                            <span class="report-type-badge" style="background:#fdf4ff;color:#7e22ce;"><i class="fas fa-comments"></i> Conversation</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span class="flag-count"><i class="fas fa-flag"></i> <?php echo $r['TotalFlags']; ?> report<?php echo $r['TotalFlags'] !== 1 ? 's' : ''; ?></span>
                        <?php if ($isAutoHidden && $statusFilter === 'pending'): ?>
                            <span class="auto-hide-notice"><i class="fas fa-eye-slash"></i> Auto-hidden (threshold reached)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Target details -->
                <?php if ($r['TargetListingID']): ?>
                    <div class="report-target"><?php echo htmlspecialchars($r['ListingTitle'] ?? 'Listing #' . $r['TargetListingID']); ?></div>
                    <div class="report-meta">
                        Status: <strong><?php echo htmlspecialchars($r['ListingStatus'] ?? '—'); ?></strong>
                    </div>
                <?php elseif ($r['TargetUserID']): ?>
                    <div class="report-target"><?php echo htmlspecialchars(($r['TargetFirstName'] ?? '') . ' ' . ($r['TargetLastName'] ?? '')); ?></div>
                    <div class="report-meta">
                        Email: <strong><?php echo htmlspecialchars($r['TargetEmail'] ?? '—'); ?></strong> &nbsp;·&nbsp;
                        Account: <strong><?php echo $r['TargetAccountStatus'] ? 'Active' : 'Inactive'; ?></strong>
                    </div>
                <?php elseif ($r['TargetReviewID']): ?>
                    <div class="report-target">Review by <?php echo htmlspecialchars(($r['ReviewerFirst'] ?? '') . ' ' . ($r['ReviewerLast'] ?? '')); ?></div>
                    <div class="report-meta" style="max-width:600px;">
                        "<?php echo htmlspecialchars(mb_strimwidth($r['ReviewText'] ?? '', 0, 200, '…')); ?>"
                    </div>
                <?php elseif (!empty($r['TargetMessageID'])): ?>
                    <span class="report-type-badge" style="background:#fdf4ff;color:#7e22ce;"><i class="fas fa-comment"></i> Message</span>
                    <div class="report-target" style="margin-top:8px;">Message from <?php echo htmlspecialchars(($r['MsgSenderFirst'] ?? '') . ' ' . ($r['MsgSenderLast'] ?? '')); ?></div>
                    <div class="report-notes">"<?php echo htmlspecialchars(mb_strimwidth($r['MessageBody'] ?? '', 0, 300, '...')); ?>"</div>
                    <div class="report-meta">Email: <strong><?php echo htmlspecialchars($r['MsgSenderEmail'] ?? ''); ?></strong> &nbsp;&middot;&nbsp; Account: <strong><?php echo ($r['MsgSenderStatus'] ?? 1) ? 'Active' : 'Suspended'; ?></strong></div>
                <?php elseif (!empty($r['TargetConversationID'])): ?>
                    <span class="report-type-badge" style="background:#fdf4ff;color:#7e22ce;"><i class="fas fa-comments"></i> Conversation</span>
                    <div class="report-target" style="margin-top:8px;">Conversation #<?php echo intval($r['TargetConversationID']); ?></div>
                    <div class="report-meta"><a href="messages.php?conversation=<?php echo intval($r['TargetConversationID']); ?>" target="_blank" style="color:#667eea;">View full conversation &rarr;</a></div>
                <?php endif; ?>

                <!-- Report reason -->
                <span class="reason-tag"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($r['Reason']); ?></span>

                <?php if (!empty($r['AdditionalNotes'])): ?>
                    <div class="report-notes">"<?php echo htmlspecialchars($r['AdditionalNotes']); ?>"</div>
                <?php endif; ?>

                <div class="report-meta">
                    Reported <?php echo date('M j, Y', strtotime($r['AddedDate'])); ?>
                    by <strong><?php echo htmlspecialchars(($r['ReporterFirst'] ?? '') . ' ' . ($r['ReporterLast'] ?? '')); ?></strong>
                </div>

                <!-- Actions -->
                <?php if ($statusFilter === 'pending'): ?>
                    <div class="action-group">
                        <?php if ($r['TargetListingID']): ?>
                            <a href="../item_detail.php?id=<?php echo $r['TargetListingID']; ?>" target="_blank" class="btn btn-view">
                                <i class="fas fa-external-link-alt"></i> View Listing
                            </a>
                        <?php elseif ($r['TargetUserID']): ?>
                            <a href="../profile.php?user=<?php echo $r['TargetUserID']; ?>" target="_blank" class="btn btn-view">
                                <i class="fas fa-external-link-alt"></i> View Profile
                            </a>
                        <?php elseif (!empty($r['TargetConversationID'])): ?>
                            <a href="messages.php?conversation=<?php echo intval($r['TargetConversationID']); ?>" target="_blank" class="btn btn-view">
                                <i class="fas fa-external-link-alt"></i> View Conversation
                            </a>
                        <?php endif; ?>

                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="report_id" value="<?php echo $r['ReportID']; ?>">
                            <input type="hidden" name="action" value="reinstate">
                            <button type="submit" class="btn btn-reinstate"
                                onclick="return confirm('Reinstate this content and dismiss all related reports?')">
                                <i class="fas fa-check"></i> Reinstate — Not a Violation
                            </button>
                        </form>

                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="report_id" value="<?php echo $r['ReportID']; ?>">
                            <input type="hidden" name="action" value="warn">
                            <button type="submit" class="btn" style="background:#fef9c3;color:#854d0e;"
                                onclick="return confirm('Issue a warning to this user? No suspension will occur.')">
                                <i class="fas fa-exclamation-triangle"></i> Warn User
                            </button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="report_id" value="<?php echo $r['ReportID']; ?>">
                            <input type="hidden" name="action" value="suspend">
                            <button type="submit" class="btn btn-confirm"
                                onclick="return confirm('Suspend this user? Their account will be deactivated but can be reinstated.')">
                                <i class="fas fa-ban"></i> Suspend User
                            </button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="report_id" value="<?php echo $r['ReportID']; ?>">
                            <input type="hidden" name="action" value="ban">
                            <button type="submit" class="btn" style="background:#1a1a2e;color:white;"
                                onclick="return confirm('PERMANENTLY BAN this user? This cannot be undone through the admin panel.')">
                                <i class="fas fa-skull"></i> Permanent Ban
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="font-size:13px;color:#9ca3af;">
                        <?php echo $statusFilter === 'resolved' ? 'Violation confirmed' : 'Dismissed as not a violation'; ?>
                        — resolved <?php echo date('M j, Y', strtotime($r['ResolvedDate'] ?? 'now')); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>

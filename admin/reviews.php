<?php require_once 'admin_auth.php'; ?>
<?php
$success = '';
$error   = '';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewId  = intval($_POST['review_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    if ($reviewId > 0) {
        try {
            switch ($action) {
                case 'edit':
                    $newText = trim($_POST['review_text'] ?? '');
                    if (empty($newText)) {
                        $error = 'Review text cannot be empty. Use Delete to remove a review entirely.';
                    } else {
                        $pdo->prepare("UPDATE TReviews SET ReviewText = ? WHERE ReviewID = ?")->execute([$newText, $reviewId]);
                        $success = 'Review updated.';
                    }
                    break;
                case 'delete':
                    $pdo->prepare("DELETE FROM TReviews WHERE ReviewID = ?")->execute([$reviewId]);
                    $success = 'Review deleted.';
                    break;
                default:
                    $error = 'Unknown action.';
            }
        } catch (PDOException $e) {
            error_log("Admin reviews error: " . $e->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}

// ── Fetch reviews ─────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

$sql    = "SELECT r.ReviewID, r.ReviewRating, r.ReviewText, r.AddedDate,
                  reviewer.FirstName AS ReviewerFirst, reviewer.LastName AS ReviewerLast,
                  reviewee.FirstName AS RevieweeFirst, reviewee.LastName AS RevieweeLast,
                  rt.`Review Type` AS ReviewType,
                  l.Title AS ListingTitle
           FROM   TReviews r
           JOIN   TUsers       reviewer ON r.UserReviewerID = reviewer.UserID
           JOIN   TUsers       reviewee ON r.UserRevieweeID = reviewee.UserID
           JOIN   TReviewTypes rt       ON r.ReviewTypeID   = rt.ReviewTypeID
           JOIN   TRentals     ren      ON r.RentalID       = ren.RentalID
           JOIN   TListings    l        ON ren.ListingID     = l.ListingID
           WHERE  1=1";
$params = [];

if ($search !== '') {
    $sql    .= " AND (r.ReviewText LIKE ? OR reviewer.FirstName LIKE ? OR reviewer.LastName LIKE ?
                      OR reviewee.FirstName LIKE ? OR reviewee.LastName LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY r.AddedDate DESC";

$stmt    = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Which review is being edited?
$editingId = intval($_GET['edit'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - CT Admin</title>
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

        .admin-main { flex: 1; padding: 32px; overflow-y: auto; }
        .admin-main h1 { font-size: 24px; font-weight: 700; color: #1a1a2e; margin-bottom: 24px; }

        .alert { border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

        .toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
        .toolbar form { display: flex; gap: 8px; align-items: center; }
        .toolbar input[type="text"] { padding: 9px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; width: 300px; }
        .btn { padding: 9px 18px; border-radius: 8px; border: none; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a6fd6; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-danger  { background: #fee2e2; color: #b91c1c; }
        .btn-danger:hover  { background: #fecaca; }
        .btn-warning { background: #fef9c3; color: #854d0e; }
        .btn-warning:hover { background: #fef08a; }
        .btn-neutral { background: #f3f4f6; color: #374151; }
        .btn-neutral:hover { background: #e5e7eb; }
        .count { font-size: 14px; color: #6b7280; margin-left: auto; }

        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.07); }
        thead { background: #f9fafb; }
        th { padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; }
        td { padding: 14px 16px; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f9fafb; }
        tr.editing-row td { background: #fffbeb; }

        .stars { color: #f59e0b; font-size: 13px; }
        .review-text { max-width: 260px; font-size: 13px; color: #374151; line-height: 1.5; }
        .action-group { display: flex; gap: 6px; flex-wrap: wrap; }

        .edit-form { display: flex; flex-direction: column; gap: 8px; }
        .edit-form textarea {
            width: 100%;
            min-width: 280px;
            padding: 10px 12px;
            border: 1px solid #667eea;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 80px;
        }
        .edit-form textarea:focus { outline: none; box-shadow: 0 0 0 3px rgba(102,126,234,0.15); }
        .edit-actions { display: flex; gap: 8px; }
    </style>
</head>
<body>

    <aside class="admin-sidebar">
        <div class="sidebar-logo"><i class="fas fa-tools"></i> CT Admin</div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <a href="users.php"><i class="fas fa-users"></i> Users</a>
            <a href="listings.php"><i class="fas fa-box"></i> Listings</a>
            <a href="reviews.php" class="active"><i class="fas fa-star"></i> Reviews</a>
            <a href="reports.php"><i class="fas fa-flag"></i> Reports</a>
            <a href="messages.php"><i class="fas fa-comment-dots"></i> Messages</a>
        </nav>
        <div class="sidebar-footer">
            Logged in as <strong><?php echo htmlspecialchars($_SESSION['firstname']); ?></strong><br>
            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </aside>

    <main class="admin-main">
        <h1>Reviews</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="toolbar">
            <form method="GET">
                <input type="text" name="search" placeholder="Search review text or user name..."
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                <?php if ($search): ?>
                    <a href="reviews.php" class="btn btn-neutral">Clear</a>
                <?php endif; ?>
            </form>
            <span class="count"><?php echo count($reviews); ?> review<?php echo count($reviews) !== 1 ? 's' : ''; ?></span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Reviewer</th>
                    <th>Reviewee</th>
                    <th>Listing</th>
                    <th>Type</th>
                    <th>Rating</th>
                    <th>Review Text</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reviews)): ?>
                    <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:40px;">No reviews found.</td></tr>
                <?php else: ?>
                    <?php foreach ($reviews as $r): ?>
                        <?php $isEditing = ($editingId === $r['ReviewID']); ?>
                        <tr <?php echo $isEditing ? 'class="editing-row"' : ''; ?>>
                            <td><?php echo $r['ReviewID']; ?></td>
                            <td><?php echo htmlspecialchars($r['ReviewerFirst'] . ' ' . $r['ReviewerLast']); ?></td>
                            <td><?php echo htmlspecialchars($r['RevieweeFirst'] . ' ' . $r['RevieweeLast']); ?></td>
                            <td><?php echo htmlspecialchars($r['ListingTitle']); ?></td>
                            <td><?php echo htmlspecialchars($r['ReviewType']); ?></td>
                            <td>
                                <span class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fa<?php echo $i <= $r['ReviewRating'] ? 's' : 'r'; ?> fa-star"></i>
                                    <?php endfor; ?>
                                </span>
                                (<?php echo $r['ReviewRating']; ?>)
                            </td>
                            <td>
                                <?php if ($isEditing): ?>
                                    <form method="POST" class="edit-form">
                                        <input type="hidden" name="review_id" value="<?php echo $r['ReviewID']; ?>">
                                        <input type="hidden" name="action" value="edit">
                                        <textarea name="review_text"><?php echo htmlspecialchars($r['ReviewText']); ?></textarea>
                                        <div class="edit-actions">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="fas fa-save"></i> Save
                                            </button>
                                            <a href="reviews.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>"
                                               class="btn btn-sm btn-neutral">Cancel</a>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="review-text"><?php echo htmlspecialchars($r['ReviewText']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('m/d/Y', strtotime($r['AddedDate'])); ?></td>
                            <td>
                                <?php if (!$isEditing): ?>
                                    <div class="action-group">
                                        <a href="reviews.php?edit=<?php echo $r['ReviewID']; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                                           class="btn btn-sm btn-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form method="POST">
                                            <input type="hidden" name="review_id" value="<?php echo $r['ReviewID']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                onclick="return confirm('Permanently delete this review?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </main>

    <?php require_once '../includes/chatbot_widget.php'; ?>
</body>
</html>

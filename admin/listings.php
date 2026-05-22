<?php require_once 'admin_auth.php'; ?>
<?php
$success = '';
$error   = '';

// ── Parse GET params first so they're available inside the POST handler too
$search    = trim($_GET['search'] ?? '');
$filter    = $_GET['filter'] ?? 'all';
$editingId = intval($_GET['edit'] ?? 0);
if (!empty($_GET['saved'])) $success = 'Listing updated successfully.';

// ── Fetch lookup data for edit dropdowns
$categories = $pdo->query("SELECT CategoryID, CategoryName FROM TCategories ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
$conditions = $pdo->query("SELECT ConditionID, `Condition` FROM TConditions ORDER BY ConditionID")->fetchAll(PDO::FETCH_ASSOC);

// ── Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $listingId = intval($_POST['listing_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    if ($listingId > 0) {
        try {
            switch ($action) {
                case 'deactivate':
                    $pdo->prepare("UPDATE TListings SET ListingStatusID = 4 WHERE ListingID = ?")->execute([$listingId]);
                    $success = 'Listing deactivated.';
                    break;
                case 'reactivate':
                    $pdo->prepare("UPDATE TListings SET ListingStatusID = 1 WHERE ListingID = ?")->execute([$listingId]);
                    $success = 'Listing reactivated.';
                    break;
                case 'edit':
                    $title       = trim($_POST['title']        ?? '');
                    $description = trim($_POST['description']  ?? '');
                    $categoryId  = intval($_POST['category_id'] ?? 0);
                    $conditionId = intval($_POST['condition_id'] ?? 0);

                    if (empty($title))       { $error = 'Title is required.'; break; }
                    if (empty($description)) { $error = 'Description is required.'; break; }
                    if (!$categoryId)        { $error = 'Category is required.'; break; }
                    if (!$conditionId)       { $error = 'Condition is required.'; break; }

                    $pdo->prepare("UPDATE TListings SET Title=?, Description=?, CategoryID=?, ConditionID=? WHERE ListingID=?")
                        ->execute([$title, $description, $categoryId, $conditionId, $listingId]);
                    header('Location: listings.php?saved=1' . ($search ? '&search=' . urlencode($search) : '') . ($filter !== 'all' ? '&filter=' . urlencode($filter) : '') . ($sortCol !== 'date' ? '&sort=' . urlencode($sortCol) . '&dir=' . urlencode(strtolower($sortDir)) : ''));
                    exit;
                case 'delete_photo':
                    $photoId = intval($_POST['photo_id'] ?? 0);
                    if ($photoId > 0) {
                        // Get photo URL before deleting to remove the file
                        $photoStmt = $pdo->prepare("SELECT PhotoURL FROM TListingPhotos WHERE ListingPhotoID = ? AND ListingID = ?");
                        $photoStmt->execute([$photoId, $listingId]);
                        $photo = $photoStmt->fetch(PDO::FETCH_ASSOC);
                        if ($photo) {
                            $pdo->prepare("DELETE FROM TListingPhotos WHERE ListingPhotoID = ?")->execute([$photoId]);
                            // Delete physical file if it exists
                            $filePath = __DIR__ . '/../' . ltrim($photo['PhotoURL'], '/');
                            if (file_exists($filePath)) unlink($filePath);
                            $success = 'Photo deleted.';
                        }
                    }
                    break;
                default:
                    $error = 'Unknown action.';
            }
        } catch (PDOException $e) {
            error_log("Admin listings error: " . $e->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}

// ── Build listings query

$sql = "SELECT l.ListingID, l.Title, l.Description, l.PricePerDay, l.PricePerHour,
               l.AddedDate, l.ListingStatusID, l.CategoryID, l.ConditionID,
               ls.Status, c.CategoryName, cond.Condition,
               u.FirstName, u.LastName,
               n.NeighborhoodName, ci.CityName,
               rt.RateType
        FROM TListings l
        JOIN TUsers u           ON l.UserLenderID    = u.UserID
        JOIN TCategories c      ON l.CategoryID      = c.CategoryID
        JOIN TConditions cond   ON l.ConditionID     = cond.ConditionID
        JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        JOIN TRateTypes rt      ON l.RateTypeID      = rt.RateTypeID
        LEFT JOIN TNeighborhoods n ON l.NeighborhoodID = n.NeighborhoodID
        LEFT JOIN TCities ci    ON n.CityID           = ci.CityID
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (l.Title LIKE ? OR u.FirstName LIKE ? OR u.LastName LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
// Correct mapping: 1=Available, 2=Pending, 3=Rented, 4=Inactive, 5=Removed
if ($filter === 'available') { $sql .= " AND l.ListingStatusID = 1"; }
if ($filter === 'pending')   { $sql .= " AND l.ListingStatusID = 2"; }
if ($filter === 'rented')    { $sql .= " AND l.ListingStatusID = 3"; }
if ($filter === 'inactive')  { $sql .= " AND l.ListingStatusID = 4"; }
if ($filter === 'removed')   { $sql .= " AND l.ListingStatusID = 5"; }

// Sorting
$sortCol = $_GET['sort'] ?? 'date';
$sortDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$sortMap = [
    'id'        => 'l.ListingID',
    'title'     => 'l.Title',
    'owner'     => 'u.LastName',
    'category'  => 'c.CategoryName',
    'condition' => 'cond.Condition',
    'status'    => 'l.ListingStatusID',
    'date'      => 'l.AddedDate',
    'price'     => 'l.PricePerDay',
];
$orderBy = $sortMap[$sortCol] ?? 'l.AddedDate';
$sql .= " ORDER BY $orderBy $sortDir";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch photos for the listing being edited
$editingPhotos = [];
if ($editingId > 0) {
    $photoStmt = $pdo->prepare("SELECT ListingPhotoID, PhotoURL, SortOrder FROM TListingPhotos WHERE ListingID = ? ORDER BY SortOrder ASC");
    $photoStmt->execute([$editingId]);
    $editingPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listings - CT Admin</title>
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
        .toolbar input[type="text"] { padding: 9px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; width: 260px; }
        .toolbar select { padding: 9px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .btn { padding: 9px 18px; border-radius: 8px; border: none; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #667eea; color: white; } .btn-primary:hover { background: #5a6fd6; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-danger  { background: #fee2e2; color: #b91c1c; } .btn-danger:hover  { background: #fecaca; }
        .btn-success { background: #dcfce7; color: #166534; } .btn-success:hover { background: #bbf7d0; }
        .btn-warning { background: #fef9c3; color: #854d0e; } .btn-warning:hover { background: #fef08a; }
        .btn-neutral { background: #f3f4f6; color: #374151; } .btn-neutral:hover { background: #e5e7eb; }
        .count { font-size: 14px; color: #6b7280; margin-left: auto; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.07); }
        thead { background: #f9fafb; }
        th { padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; }
        td { padding: 14px 16px; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f9fafb; }
        tr.editing-row td { background: #fffbeb; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge-active   { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #fee2e2; color: #b91c1c; }
        .badge-rented   { background: #fef9c3; color: #854d0e; }
        .badge-pending  { background: #eff6ff; color: #1d4ed8; }
        .badge-removed  { background: #f3f4f6; color: #6b7280; text-decoration: line-through; }
        .action-group { display: flex; gap: 6px; flex-wrap: wrap; }

        /* Inline edit panel */
        .edit-panel { display: flex; flex-direction: column; gap: 10px; min-width: 300px; }
        .edit-panel input[type="text"],
        .edit-panel textarea,
        .edit-panel select {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #667eea;
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
        }
        .edit-panel textarea { min-height: 80px; resize: vertical; }
        .edit-panel input:focus, .edit-panel textarea:focus, .edit-panel select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.15);
        }
        .edit-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .edit-actions { display: flex; gap: 6px; }

        /* Photo grid */
        .photo-grid { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .photo-thumb-admin {
            position: relative;
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            background: #f0f0f0;
            flex-shrink: 0;
        }
        .photo-thumb-admin img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-delete-btn {
            position: absolute;
            top: 3px;
            right: 3px;
            background: rgba(185,28,28,0.85);
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .photo-delete-btn:hover { background: #b91c1c; }
        .no-photos { font-size: 13px; color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <aside class="admin-sidebar">
        <div class="sidebar-logo"><i class="fas fa-tools"></i> CT Admin</div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <a href="users.php"><i class="fas fa-users"></i> Users</a>
            <a href="listings.php" class="active"><i class="fas fa-box"></i> Listings</a>
            <a href="reviews.php"><i class="fas fa-star"></i> Reviews</a>
            <a href="reports.php"><i class="fas fa-flag"></i> Reports</a>
            <a href="messages.php"><i class="fas fa-comment-dots"></i> Messages</a>
        </nav>
        <div class="sidebar-footer">
            Logged in as <strong><?php echo htmlspecialchars($_SESSION['firstname']); ?></strong><br>
            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </aside>

    <main class="admin-main">
        <h1>Listings</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="toolbar">
            <form method="GET">
                <input type="text" name="search" placeholder="Search title or owner name..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="filter">
                    <option value="all"       <?php echo $filter === 'all'       ? 'selected' : ''; ?>>All</option>
                    <option value="available" <?php echo $filter === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="pending"   <?php echo $filter === 'pending'   ? 'selected' : ''; ?>>Pending</option>
                    <option value="rented"    <?php echo $filter === 'rented'    ? 'selected' : ''; ?>>Rented</option>
                    <option value="inactive"  <?php echo $filter === 'inactive'  ? 'selected' : ''; ?>>Inactive</option>
                    <option value="removed"   <?php echo $filter === 'removed'   ? 'selected' : ''; ?>>Removed</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                <?php if ($search || $filter !== 'all'): ?>
                    <a href="listings.php" class="btn btn-neutral">Clear</a>
                <?php endif; ?>
            </form>
            <span class="count"><?php echo count($listings); ?> listing<?php echo count($listings) !== 1 ? 's' : ''; ?></span>
        </div>

        <table>
            <thead>
                <?php
                function sortLink($label, $col, $currentSort, $currentDir, $search, $filter) {
                    $newDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
                    $arrow = $currentSort === $col ? ($currentDir === 'asc' ? ' &#9650;' : ' &#9660;') : ' <span style="opacity:0.3">&#9650;&#9660;</span>';
                    $qs = http_build_query(array_filter(['search' => $search, 'filter' => $filter !== 'all' ? $filter : '', 'sort' => $col, 'dir' => $newDir]));
                    return '<a href="listings.php?' . $qs . '" style="color:inherit;text-decoration:none;white-space:nowrap;">' . $label . $arrow . '</a>';
                }
                ?>
                <tr>
                    <th><?php echo sortLink('ID', 'id', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th><?php echo sortLink('Title', 'title', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th><?php echo sortLink('Owner', 'owner', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th><?php echo sortLink('Category', 'category', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th><?php echo sortLink('Condition', 'condition', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th>Location</th>
                    <th><?php echo sortLink('Price', 'price', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th><?php echo sortLink('Status', 'status', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th><?php echo sortLink('Listed', 'date', $sortCol, $sortDir, $search, $filter); ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($listings)): ?>
                    <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:40px;">No listings found.</td></tr>
                <?php else: ?>
                    <?php foreach ($listings as $l):
                        $isEditing = ($editingId === (int)$l['ListingID']);
                        $qs        = ($search ? 'search=' . urlencode($search) . '&' : '') . ($filter !== 'all' ? 'filter=' . $filter : '');
                        $cancelUrl = 'listings.php' . ($qs ? '?' . rtrim($qs, '&') : '');
                    ?>
                    <tr <?php echo $isEditing ? 'class="editing-row"' : ''; ?>>
                        <td><?php echo $l['ListingID']; ?></td>

                        <!-- Title / inline edit -->
                        <td>
                            <?php if ($isEditing): ?>
                                <form method="POST" class="edit-panel">
                                    <input type="hidden" name="listing_id" value="<?php echo $l['ListingID']; ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="text" name="title" value="<?php echo htmlspecialchars($l['Title']); ?>" placeholder="Title" required>
                                    <textarea name="description" placeholder="Description" required><?php echo htmlspecialchars($l['Description']); ?></textarea>
                                    <div class="edit-row-2">
                                        <select name="category_id" required>
                                            <option value="">— Category —</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo $cat['CategoryID']; ?>" <?php echo ($l['CategoryID'] == $cat['CategoryID']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat['CategoryName']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="condition_id" required>
                                            <option value="">— Condition —</option>
                                            <?php foreach ($conditions as $cond): ?>
                                                <option value="<?php echo $cond['ConditionID']; ?>" <?php echo ($l['ConditionID'] == $cond['ConditionID']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cond['Condition']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="edit-actions">
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save</button>
                                        <a href="<?php echo $cancelUrl; ?>" class="btn btn-sm btn-neutral">Cancel</a>
                                    </div>
                                </form>

                                <!-- Photo management -->
                                <div style="margin-top:16px;">
                                    <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:8px;">Photos</div>
                                    <?php if (empty($editingPhotos)): ?>
                                        <p class="no-photos">No photos for this listing.</p>
                                    <?php else: ?>
                                        <div class="photo-grid">
                                            <?php foreach ($editingPhotos as $photo): ?>
                                                <div class="photo-thumb-admin">
                                                    <img src="<?php echo htmlspecialchars('../' . $photo['PhotoURL']); ?>"
                                                         alt="Listing photo"
                                                         onerror="this.style.display='none'">
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="listing_id" value="<?php echo $l['ListingID']; ?>">
                                                        <input type="hidden" name="action" value="delete_photo">
                                                        <input type="hidden" name="photo_id" value="<?php echo $photo['ListingPhotoID']; ?>">
                                                        <button type="submit" class="photo-delete-btn"
                                                            onclick="return confirm('Delete this photo?')"
                                                            title="Delete photo">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                            <?php else: ?>
                                <?php echo htmlspecialchars($l['Title']); ?>
                                <div style="font-size:12px;color:#9ca3af;margin-top:2px;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo htmlspecialchars($l['Description']); ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td><?php echo htmlspecialchars($l['FirstName'] . ' ' . $l['LastName']); ?></td>
                        <td><?php echo htmlspecialchars($l['CategoryName']); ?></td>
                        <td><?php echo htmlspecialchars($l['Condition']); ?></td>
                        <td><?php echo htmlspecialchars(($l['NeighborhoodName'] ?? '') . ($l['CityName'] ? ', ' . $l['CityName'] : '')); ?></td>
                        <td>
                            <?php if ($l['RateType'] === 'Daily' && $l['PricePerDay']): ?>
                                $<?php echo number_format($l['PricePerDay'], 2); ?>/day
                            <?php elseif ($l['RateType'] === 'Hourly' && $l['PricePerHour']): ?>
                                $<?php echo number_format($l['PricePerHour'], 2); ?>/hr
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $badgeClass = match((int)$l['ListingStatusID']) { 1 => 'badge-active', 2 => 'badge-pending', 3 => 'badge-rented', 4 => 'badge-inactive', 5 => 'badge-removed', default => 'badge-inactive' }; ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($l['Status']); ?></span>
                        </td>
                        <td><?php echo date('m/d/Y', strtotime($l['AddedDate'])); ?></td>

                        <td>
                            <?php if (!$isEditing): ?>
                                <div class="action-group">
                                    <a href="listings.php?edit=<?php echo $l['ListingID']; ?><?php echo $qs ? '&' . $qs : ''; ?>"
                                       class="btn btn-sm btn-warning">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php if (in_array((int)$l['ListingStatusID'], [1, 2])): ?>
                                        <form method="POST">
                                            <input type="hidden" name="listing_id" value="<?php echo $l['ListingID']; ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Deactivate this listing?')">
                                                <i class="fas fa-ban"></i> Deactivate
                                            </button>
                                        </form>
                                    <?php elseif ((int)$l['ListingStatusID'] === 4): ?>
                                        <form method="POST">
                                            <input type="hidden" name="listing_id" value="<?php echo $l['ListingID']; ?>">
                                            <input type="hidden" name="action" value="reactivate">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="fas fa-check"></i> Reactivate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</body>
</html>

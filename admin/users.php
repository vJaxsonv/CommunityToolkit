<?php require_once 'admin_auth.php'; ?>
<?php
$success = '';
$error   = '';

// ── Parse GET params first so they're available inside the POST handler too
$search    = trim($_GET['search'] ?? '');
$filter    = $_GET['filter'] ?? 'all';
$editingId = intval($_GET['edit'] ?? 0);
if (!empty($_GET['saved'])) $success = 'User updated successfully.';

// ── Fetch genders for edit dropdown
$genders = $pdo->query("SELECT GenderID, Gender FROM TGenders ORDER BY GenderID")->fetchAll(PDO::FETCH_ASSOC);

// ── Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetUserId = intval($_POST['target_user_id'] ?? 0);
    $action       = $_POST['action'] ?? '';

    if ($targetUserId === intval($_SESSION['user_id'])) {
        $error = 'You cannot perform this action on your own account.';
    } elseif ($targetUserId > 0) {
        try {
            switch ($action) {
                case 'deactivate':
                    $pdo->prepare("UPDATE TUsers SET AccountStatus = 0 WHERE UserID = ?")->execute([$targetUserId]);
                    $success = 'User account deactivated.';
                    break;
                case 'reactivate':
                    // Check for permanent ban (AccountStatus = 2)
                    $banChk = $pdo->prepare('SELECT AccountStatus FROM TUsers WHERE UserID = ?');
                    $banChk->execute([$targetUserId]);
                    if (intval($banChk->fetchColumn()) === 2) {
                        $error = 'This user is permanently banned and cannot be reactivated.';
                        break;
                    }
                    $pdo->prepare("UPDATE TUsers SET AccountStatus = 1 WHERE UserID = ?")->execute([$targetUserId]);
                    $success = 'User account reactivated.';
                    break;
                case 'clear_bio':
                    $pdo->prepare("UPDATE TUsers SET Bio = NULL WHERE UserID = ?")->execute([$targetUserId]);
                    $success = 'User bio cleared.';
                    break;
                case 'edit':
                    $firstName = trim($_POST['first_name'] ?? '');
                    $lastName  = trim($_POST['last_name']  ?? '');
                    $email     = trim($_POST['email']      ?? '');
                    $phone     = trim($_POST['phone']      ?? '');
                    $genderId  = intval($_POST['gender_id'] ?? 0);
                    $dob       = trim($_POST['dob'] ?? '');

                    if (empty($firstName) || empty($lastName)) { $error = 'First and last name are required.'; break; }
                    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'A valid email address is required.'; break; }

                    $chk = $pdo->prepare("SELECT UserID FROM TUsers WHERE Email = ? AND UserID != ?");
                    $chk->execute([$email, $targetUserId]);
                    if ($chk->fetch()) { $error = 'That email address is already in use by another account.'; break; }

                    if (!empty($phone) && !preg_match('/^[0-9]{10}$/', $phone)) { $error = 'Phone must be exactly 10 digits.'; break; }

                    // Validate DOB if provided
                    $dobValue = null;
                    if (!empty($dob)) {
                        $dobDt = DateTime::createFromFormat('Y-m-d', $dob);
                        if (!$dobDt) { $error = 'Invalid date of birth format.'; break; }
                        $dobValue = $dob;
                    }

                    $pdo->prepare("UPDATE TUsers SET FirstName=?, LastName=?, Email=?, PhoneNumber=?, GenderID=?, DateOfBirth=? WHERE UserID=?")
                        ->execute([$firstName, $lastName, $email, $phone ?: null, $genderId ?: null, $dobValue, $targetUserId]);
                    header('Location: users.php?saved=1' . ($search ? '&search=' . urlencode($search) : '') . ($filter !== 'all' ? '&filter=' . urlencode($filter) : ''));
                    exit;

                default:
                    $error = 'Unknown action.';
            }
        } catch (PDOException $e) {
            error_log("Admin users error: " . $e->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}

// ── Build user query

$sql = "SELECT u.UserID, u.FirstName, u.LastName, u.Email, u.PhoneNumber,
               u.AccountStatus, u.AddedDate, u.Bio, u.IsAdmin, u.GenderID,
               u.DateOfBirth,
               g.Gender, n.NeighborhoodName, c.CityName
        FROM TUsers u
        LEFT JOIN TGenders g ON u.GenderID = g.GenderID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        LEFT JOIN TCities c ON n.CityID = c.CityID
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.FirstName LIKE ? OR u.LastName LIKE ? OR u.Email LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($filter === 'active')   { $sql .= " AND u.AccountStatus = 1"; }
if ($filter === 'inactive') { $sql .= " AND u.AccountStatus = 0"; }
$sql .= " ORDER BY u.AddedDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - CT Admin</title>
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
        .badge-admin    { background: #ede9fe; color: #6d28d9; margin-left: 6px; }
        .bio-cell { max-width: 180px; font-size: 13px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bio-empty { color: #d1d5db; font-style: italic; }
        .action-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .edit-form { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; min-width: 320px; }
        .edit-form input, .edit-form select { padding: 7px 10px; border: 1px solid #667eea; border-radius: 6px; font-size: 13px; font-family: inherit; width: 100%; }
        .edit-form input:focus, .edit-form select:focus { outline: none; box-shadow: 0 0 0 3px rgba(102,126,234,0.15); }
        .edit-span2 { grid-column: span 2; }
        .edit-actions { display: flex; gap: 6px; margin-top: 4px; }
        .edit-hint { font-size: 11px; color: #9ca3af; margin-top: 6px; }
    </style>
</head>
<body>
    <aside class="admin-sidebar">
        <div class="sidebar-logo"><i class="fas fa-tools"></i> CT Admin</div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <a href="users.php" class="active"><i class="fas fa-users"></i> Users</a>
            <a href="listings.php"><i class="fas fa-box"></i> Listings</a>
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
        <h1>Users</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="toolbar">
            <form method="GET">
                <input type="text" name="search" placeholder="Search name or email..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="filter">
                    <option value="all"      <?php echo $filter === 'all'      ? 'selected' : ''; ?>>All Users</option>
                    <option value="active"   <?php echo $filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                <?php if ($search || $filter !== 'all'): ?>
                    <a href="users.php" class="btn btn-neutral">Clear</a>
                <?php endif; ?>
            </form>
            <span class="count"><?php echo count($users); ?> user<?php echo count($users) !== 1 ? 's' : ''; ?></span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name / Edit</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>DOB</th>
                    <th>Location</th>
                    <th>Bio</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:40px;">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u):
                        $isSelf    = ($u['UserID'] == $_SESSION['user_id']);
                        $isEditing = ($editingId === (int)$u['UserID']);
                        $qs        = ($search ? 'search=' . urlencode($search) . '&' : '') . ($filter !== 'all' ? 'filter=' . $filter : '');
                        $cancelUrl = 'users.php' . ($qs ? '?' . rtrim($qs, '&') : '') . '#row-' . $u['UserID'];
                    ?>
                    <tr id="row-<?php echo $u['UserID']; ?>" <?php echo $isEditing ? 'class="editing-row"' : ''; ?>>
                        <td><?php echo $u['UserID']; ?></td>

                        <td>
                            <?php if ($isEditing): ?>
                                <form method="POST" class="edit-form">
                                    <input type="hidden" name="target_user_id" value="<?php echo $u['UserID']; ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="text"  name="first_name" value="<?php echo htmlspecialchars($u['FirstName']); ?>" placeholder="First name" required>
                                    <input type="text"  name="last_name"  value="<?php echo htmlspecialchars($u['LastName']); ?>"  placeholder="Last name"  required>
                                    <input type="email" name="email" class="edit-span2" value="<?php echo htmlspecialchars($u['Email']); ?>" placeholder="Email" required>
                                    <input type="text"  name="phone" value="<?php echo htmlspecialchars($u['PhoneNumber'] ?? ''); ?>" placeholder="10 digits, no spaces">
                                    <select name="gender_id">
                                        <option value="">— Gender —</option>
                                        <?php foreach ($genders as $g): ?>
                                            <option value="<?php echo $g['GenderID']; ?>" <?php echo ($u['GenderID'] == $g['GenderID']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($g['Gender']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="edit-span2" style="display:flex;flex-direction:column;gap:4px;">
                                        <label style="font-size:11px;color:#6b7280;font-weight:600;">Date of Birth</label>
                                        <input type="date" name="dob"
                                               value="<?php echo htmlspecialchars($u['DateOfBirth'] ?? ''); ?>"
                                               max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="edit-actions edit-span2">
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save</button>
                                        <a href="<?php echo $cancelUrl; ?>" class="btn btn-sm btn-neutral">Cancel</a>
                                    </div>
                                    <p class="edit-hint edit-span2">Location and password cannot be changed here.</p>
                                </form>
                            <?php else: ?>
                                <?php echo htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']); ?>
                                <?php if ($u['IsAdmin']): ?><span class="badge badge-admin">Admin</span><?php endif; ?>
                                <?php if ($u['Gender']): ?>
                                    <div style="font-size:12px;color:#9ca3af;margin-top:2px;"><?php echo htmlspecialchars($u['Gender']); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td><?php echo htmlspecialchars($u['Email']); ?></td>
                        <td><?php echo htmlspecialchars($u['PhoneNumber'] ?? '—'); ?></td>
                        <td><?php echo !empty($u['DateOfBirth']) ? date('m/d/Y', strtotime($u['DateOfBirth'])) : '—'; ?></td>
                        <td><?php echo htmlspecialchars(($u['NeighborhoodName'] ?? '') . ($u['CityName'] ? ', ' . $u['CityName'] : '')); ?></td>

                        <td>
                            <?php if (!empty($u['Bio'])): ?>
                                <span class="bio-cell" title="<?php echo htmlspecialchars($u['Bio']); ?>"><?php echo htmlspecialchars($u['Bio']); ?></span>
                            <?php else: ?>
                                <span class="bio-empty">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge <?php echo $u['AccountStatus'] == 1 ? 'badge-active' : 'badge-inactive'; ?>" <?php echo $u['AccountStatus'] == 2 ? 'style="background:#1a1a2e;color:white;"' : ''; ?>>
                                <?php echo $u['AccountStatus'] == 1 ? 'Active' : ($u['AccountStatus'] == 2 ? 'Permanently Banned' : 'Inactive'); ?>
                            </span>
                        </td>
                        <td><?php echo date('m/d/Y', strtotime($u['AddedDate'])); ?></td>

                        <td>
                            <?php if ($isSelf): ?>
                                <span style="color:#9ca3af;font-size:13px;">You</span>
                            <?php elseif (!$isEditing): ?>
                                <div class="action-group">
                                    <a href="users.php?edit=<?php echo $u['UserID']; ?><?php echo $qs ? '&' . $qs : ''; ?>#row-<?php echo $u['UserID']; ?>"
                                       class="btn btn-sm btn-warning">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php if ($u['AccountStatus'] == 1): ?>
                                        <form method="POST">
                                            <input type="hidden" name="target_user_id" value="<?php echo $u['UserID']; ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Deactivate this user?')">
                                                <i class="fas fa-ban"></i> Deactivate
                                            </button>
                                        </form>
                                    <?php elseif ($u['AccountStatus'] == 0): ?>
                                        <form method="POST">
                                            <input type="hidden" name="target_user_id" value="<?php echo $u['UserID']; ?>">
                                            <input type="hidden" name="action" value="reactivate">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="fas fa-check"></i> Reactivate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!empty($u['Bio'])): ?>
                                        <form method="POST">
                                            <input type="hidden" name="target_user_id" value="<?php echo $u['UserID']; ?>">
                                            <input type="hidden" name="action" value="clear_bio">
                                            <button type="submit" class="btn btn-sm btn-neutral" onclick="return confirm('Clear this user\'s bio?')">
                                                <i class="fas fa-eraser"></i> Clear Bio
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

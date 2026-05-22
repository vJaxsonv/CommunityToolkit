<?php require_once 'admin_auth.php'; ?>
<?php
// ── Stats ────────────────────────────────────────────────────────────────────
$totalUsers       = $pdo->query("SELECT COUNT(*) FROM TUsers")->fetchColumn();
$activeUsers      = $pdo->query("SELECT COUNT(*) FROM TUsers WHERE AccountStatus = 1")->fetchColumn();
$totalListings    = $pdo->query("SELECT COUNT(*) FROM TListings")->fetchColumn();
$activeListings   = $pdo->query("SELECT COUNT(*) FROM TListings WHERE ListingStatusID = 1")->fetchColumn();
$totalRentals     = $pdo->query("SELECT COUNT(*) FROM TRentals")->fetchColumn();
$activeRentals    = $pdo->query("SELECT COUNT(*) FROM TRentals WHERE RentalStatusID NOT IN (4,5)")->fetchColumn();
$totalReviews     = $pdo->query("SELECT COUNT(*) FROM TReviews")->fetchColumn();
$newUsersThisMonth = $pdo->query("SELECT COUNT(*) FROM TUsers WHERE AddedDate >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Community Toolkit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
        }

        /* ── Sidebar ── */
        .admin-sidebar {
            width: 240px;
            background: #1a1a2e;
            color: white;
            min-height: 100vh;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-logo {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 16px;
            font-weight: 700;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-nav { padding: 16px 0; flex: 1; }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s;
        }

        .sidebar-nav a:hover { background: rgba(255,255,255,0.07); color: white; }
        .sidebar-nav a.active { background: rgba(102,126,234,0.2); color: #667eea; border-left: 3px solid #667eea; }
        .sidebar-nav a i { width: 18px; text-align: center; }

        .sidebar-footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 13px;
            color: #64748b;
        }

        .sidebar-footer a { color: #94a3b8; text-decoration: none; }
        .sidebar-footer a:hover { color: white; }

        /* ── Main content ── */
        .admin-main { flex: 1; padding: 32px; overflow-y: auto; }

        .admin-main h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .admin-main .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 32px;
        }

        /* ── Stat cards ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 4px;
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .stat-card .stat-label {
            font-size: 13px;
            color: #6b7280;
        }

        .stat-card .stat-sub {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }

        .icon-blue   { background: #eff6ff; color: #3b82f6; }
        .icon-green  { background: #f0fdf4; color: #22c55e; }
        .icon-purple { background: #f5f3ff; color: #8b5cf6; }
        .icon-orange { background: #fff7ed; color: #f97316; }

        /* ── Quick links ── */
        .quick-links { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }

        .quick-link {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-decoration: none;
            color: #374151;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            display: flex;
            align-items: center;
            gap: 14px;
            font-weight: 600;
            font-size: 14px;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .quick-link:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.12); transform: translateY(-2px); }
        .quick-link i { font-size: 20px; color: #667eea; }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <div class="sidebar-logo">
            <i class="fas fa-tools"></i> CT Admin
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="active"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <a href="users.php"><i class="fas fa-users"></i> Users</a>
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

    <!-- Main -->
    <main class="admin-main">
        <h1>Dashboard</h1>
        <p class="subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['firstname']); ?>. Here's what's happening on Community Toolkit.</p>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo number_format($totalUsers); ?></div>
                <div class="stat-label">Total Users</div>
                <div class="stat-sub"><?php echo number_format($activeUsers); ?> active &nbsp;·&nbsp; <?php echo number_format($newUsersThisMonth); ?> this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fas fa-box"></i></div>
                <div class="stat-value"><?php echo number_format($totalListings); ?></div>
                <div class="stat-label">Total Listings</div>
                <div class="stat-sub"><?php echo number_format($activeListings); ?> currently active</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?php echo number_format($totalRentals); ?></div>
                <div class="stat-label">Total Rentals</div>
                <div class="stat-sub"><?php echo number_format($activeRentals); ?> in progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-orange"><i class="fas fa-star"></i></div>
                <div class="stat-value"><?php echo number_format($totalReviews); ?></div>
                <div class="stat-label">Total Reviews</div>
            </div>
        </div>

        <!-- Quick links -->
        <p class="section-title">Quick Actions</p>
        <div class="quick-links">
            <a href="users.php" class="quick-link"><i class="fas fa-users"></i> Manage Users</a>
            <a href="listings.php" class="quick-link"><i class="fas fa-box"></i> Manage Listings</a>
            <a href="reviews.php" class="quick-link"><i class="fas fa-star"></i> Manage Reviews</a>
        </div>
    </main>

</body>
</html>

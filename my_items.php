<?php 
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get current user ID
$userId = $_SESSION['user_id'];

// Build query for ONLY the logged-in user's listings
$sql = "SELECT l.*, 
        u.FirstName, u.LastName, u.UserID as OwnerUserID,
        c.CategoryName,
        cond.ConditionName,
        ls.StatusName as ListingStatus,
        n.NeighborhoodName, n.City
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        WHERE l.UserLenderID = ?
        ORDER BY l.AddedDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Items - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Header/Navigation -->
<header class="main-header">
    <div class="container">
        <div class="header-content">
                <!-- Search Bar -->
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                    <input type="text" placeholder="Search your listings..." class="search-input">
            </div>

            <!-- Navigation -->
            <nav class="main-nav">
                    <a href="home.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                    <a href="my_items.php" class="nav-link active">
                    <i class="fas fa-box"></i>
                    <span>My Items</span>
                </a>
                    <a href="create_listing.php" class="nav-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>List Item</span>
                </a>
                    <a href="my_rentals.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>My Rentals</span>
                </a>
            </nav>

                <!-- User Section -->
            <div class="user-section">
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">0</span>
            </div>
                    <div class="user-menu-container">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
                        </div>
                    </div>
                </div>
        </div>
    </div>
</header>

    <!-- Main Content -->
<main class="main-content">
        <div class="container">
            <h1>My Listed Items</h1>
            
            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <h2>No items listed yet</h2>
                    <p>You haven't posted any items yet. <a href="create_listing.php">Create your first listing!</a></p>
                </div>
            <?php else: ?>
                <div class="items-column">
                    <?php foreach($items as $item): ?>
                    <div class="item-card">
                            <div class="item-header">
                                <div class="user-info">
                                    <div class="avatar avatar-<?php echo ($item['OwnerUserID'] % 2 == 0) ? 'purple' : 'pink'; ?>">
                                        <?php echo strtoupper(substr($item['FirstName'], 0, 1)); ?>
                        </div>

                        <div class="item-details">
                            <h3 class="item-title">DeWalt Power Drill Set</h3>
                            <p class="item-description">Professional grade power drill with multiple bits perfect for home projects and DIY repairs</p>

                            <div class="item-price-section">
                                <div>
                                        <div class="user-name">
                                            <?php echo htmlspecialchars($item['FirstName'] . ' ' . substr($item['LastName'], 0, 1) . '.'); ?>
                                </div>
                                        <div class="user-distance">
                                            <?php echo htmlspecialchars($item['ListingStatus']); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Item Card 2 -->
                    <div class="item-card">
                      

                        <div class="item-image">
                            <img src="https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=600&h=400&fit=crop" alt="Lawn Mower">
                        </div>

                        <div class="item-footer">
                            <div class="item-actions">
                                <button class="action-btn">
                                    <i class="far fa-circle-question"></i>
                                </button>
                                <button class="action-btn">
                                    <i class="fas fa-share-nodes"></i>
                                </button>
                            </div>
                            <button class="favorite-btn">
                                <i class="far fa-star"></i>
                            </button>
                        </div>

                        <div class="item-details">
                            <h3 class="item-title">Lawn Mower</h3>
                            <p class="item-description">Old lawn Mower Not really in use however, need gas to use it</p>

                            <div class="item-price-section">
                                <div>
                                    <div class="price-label">Price per day</div>
                                    <div class="price">$10</div>
                                </div>
                                <button class="Edit-btn">Edit</button>
                            </div>
                        </div>
                    </div>

                    <!-- Item Card 3 -->
                    <div class="item-card">
                       

                        <div class="item-image">
                            </div>
                            <button class="favorite-btn">
                                <i class="far fa-star"></i>
                            </button>
                        </div>

                        <div class="item-details">

                    </div>

                    <!-- Item Card 4 -->
                    <div class="item-card">
                      

                        <div class="item-image">
                            <img src="https://images.unsplash.com/photo-1416339306562-f3d12fefd36f?w=600&h=400&fit=crop" alt="Ladder">
                        </div>

                        <div class="item-footer">
                            <div class="item-actions">
                                <button class="action-btn">
                                    <i class="far fa-circle-question"></i>
                                </button>
                                <button class="action-btn">
                                    <i class="fas fa-share-nodes"></i>
                                </button>
                            </div>
                            <button class="favorite-btn">
                                <i class="far fa-star"></i>
                            </button>
                        </div>

                        <div class="item-details">
                            <h3 class="item-title">Extension Ladder</h3>
                            <p class="item-description">20-foot aluminum ladder, perfect for outdoor projects</p>

                            <div class="item-price-section">
                                <div>
                                    <div class="price-label">Price per day</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>


</body>
</html>
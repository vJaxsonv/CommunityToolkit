<?php 
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$max_price = $_GET['max_price'] ?? '';

// Build query with CORRECT column names
$sql = "SELECT l.*, 
        u.FirstName, u.LastName, u.UserID as OwnerUserID,
        c.CategoryName,
        cond.ConditionName,
        ls.StatusName as ListingStatus,
        n.NeighborhoodName, n.City,
        (SELECT PhotoURL FROM TLisitingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        WHERE ls.StatusName = 'Active'";

$params = [];

// Add search filter
if (!empty($search)) {
    $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Add category filter
if (!empty($category)) {
    $sql .= " AND l.CategoryID = ?";
    $params[] = $category;
}

// Add price filter
if (!empty($max_price)) {
    $sql .= " AND l.PricePerDay <= ?";
    $params[] = $max_price;
}

$sql .= " ORDER BY l.AddedDate DESC LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $pdo->query("SELECT * FROM TCategories WHERE ParentCategoryID IS NULL ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Items - Community Toolkit</title>
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
                    <form action="home.php" method="GET">
                        <input type="text" name="search" placeholder="Search for items near you..." class="search-input" value="<?php echo htmlspecialchars($search); ?>">
                    </form>
                </div>
                
                <!-- Navigation -->
                <nav class="main-nav">
                    <a href="home.php" class="nav-link active">
                        <i class="fas fa-home"></i>
                        <span>Home</span>
                    </a>
                    <a href="my_items.php" class="nav-link">
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
                        <div class="user-avatar" onclick="toggleUserMenu()">
                            <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
                        </div>
                        <div class="user-dropdown" id="userDropdown">
                            <div class="user-dropdown-header">
                                <strong><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></strong>
                                <small><?php echo htmlspecialchars($_SESSION['email']); ?></small>
                            </div>
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <a href="my_items.php"><i class="fas fa-box"></i> My Items</a>
                            <a href="my_rentals.php"><i class="fas fa-calendar"></i> My Rentals</a>
                            <hr>
                            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Filters Section -->
    <section class="filters-section">
        <div class="container">
            <form action="home.php" method="GET" class="filters">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                
                <div class="filter-dropdown">
                    <select name="category" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['CategoryID']; ?>" <?php echo ($category == $cat['CategoryID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <select name="max_price" class="filter-chip" onchange="this.form.submit()">
                    <option value="">Any Price</option>
                    <option value="5" <?php echo ($max_price == '5') ? 'selected' : ''; ?>>Under $5</option>
                    <option value="10" <?php echo ($max_price == '10') ? 'selected' : ''; ?>>Under $10</option>
                    <option value="20" <?php echo ($max_price == '20') ? 'selected' : ''; ?>>Under $20</option>
                    <option value="50" <?php echo ($max_price == '50') ? 'selected' : ''; ?>>Under $50</option>
                </select>
                
                <?php if (!empty($search) || !empty($category) || !empty($max_price)): ?>
                    <a href="home.php" class="btn btn-outline btn-sm">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>
    </section>
    
    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <h1>Available Items Near You</h1>
            
            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="fas fa-search fa-3x"></i>
                    <h2>No items found</h2>
                    <p>Try adjusting your search filters or <a href="create_listing.php">be the first to list an item!</a></p>
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
                                    <div>
                                        <div class="user-name"><?php echo htmlspecialchars($item['FirstName'] . ' ' . substr($item['LastName'], 0, 1) . '.'); ?></div>
                                        <div class="user-distance">
                                            <?php if ($item['City']): ?>
                                                <?php echo htmlspecialchars($item['City']); ?>
                                            <?php else: ?>
                                                Cincinnati area
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <button class="bookmark-btn">
                                    <i class="far fa-bookmark"></i>
                                </button>
                            </div>
                            
                            <div class="item-image">
                                <?php if ($item['PrimaryImage']): ?>
                                    <img src="<?php echo htmlspecialchars($item['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                <?php else: ?>
                                    <img src="https://images.unsplash.com/photo-1504148455328-c376907d081c?w=600&h=400&fit=crop" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                <?php endif; ?>
                            </div>
                            
                            <div class="item-footer">
                                <div class="item-actions">
                                    <button class="action-btn" title="Item Info">
                                        <i class="far fa-circle-question"></i>
                                    </button>
                                    <button class="action-btn" title="Share">
                                        <i class="fas fa-share-nodes"></i>
                                    </button>
                                </div>
                                <button class="favorite-btn">
                                    <i class="far fa-star"></i>
                                </button>
                            </div>
                            
                            <div class="item-details">
                                <h3 class="item-title"><?php echo htmlspecialchars($item['Title']); ?></h3>
                                <p class="item-description"><?php echo htmlspecialchars(substr($item['Description'], 0, 100)); ?>...</p>
                                
                                <div class="item-meta">
                                    <span class="item-category"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['CategoryName']); ?></span>
                                    <span class="item-condition"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($item['ConditionName']); ?></span>
                                </div>
                                
                                <div class="item-price-section">
                                    <div>
                                        <div class="price-label">Price per day</div>
                                        <div class="price">$<?php echo number_format($item['PricePerDay'], 2); ?></div>
                                    </div>
                                    <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>" class="message-btn">View Details</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.user-avatar')) {
                const dropdown = document.getElementById('userDropdown');
                if (dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        }
    </script>
</body>
</html>

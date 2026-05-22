<?php 
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get current user ID
$userId = $_SESSION['user_id'];

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$price_ranges = $_GET['price_ranges'] ?? [];
$min_price = $_GET['min_price'] ?? '';
$max_price_custom = $_GET['max_price'] ?? '';

// Build current URL for back-link (preserves active filters)
$current_url = 'my_bookmarks.php?' . http_build_query(array_filter([
    'search'       => $search,
    'category'     => $category,
    'min_price'    => $min_price,
    'max_price'    => $max_price_custom,
    'price_ranges' => $price_ranges ?: null,
], fn($v) => $v !== null && $v !== '' && $v !== []));
if ($current_url === 'my_bookmarks.php?') $current_url = 'my_bookmarks.php';

// Build query for bookmarked listings
$sql = "SELECT l.*, 
        u.FirstName, u.LastName, u.UserID as OwnerUserID,
        c.CategoryName,
        cond.Condition,
        ls.Status,
        n.NeighborhoodName, n.City, n.CenterLatitude, n.CenterLongitude,
        b.AddedDate as BookmarkedDate,
        (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
        FROM TBookmarks b
        INNER JOIN TListings l ON b.ListingID = l.ListingID
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        WHERE b.UserID = ? AND ls.Status != 'Removed'";

$params = [$userId];

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

// Add price range filters
$priceConditions = [];

// Handle preset ranges
if (!empty($price_ranges) && is_array($price_ranges)) {
    foreach ($price_ranges as $range) {
        switch ($range) {
            case '0-49':
                $priceConditions[] = "(l.PricePerDay >= 0 AND l.PricePerDay <= 49)";
                break;
            case '50-99':
                $priceConditions[] = "(l.PricePerDay >= 50 AND l.PricePerDay <= 99)";
                break;
            case '100-199':
                $priceConditions[] = "(l.PricePerDay >= 100 AND l.PricePerDay <= 199)";
                break;
            case '200-299':
                $priceConditions[] = "(l.PricePerDay >= 200 AND l.PricePerDay <= 299)";
                break;
            case '300-399':
                $priceConditions[] = "(l.PricePerDay >= 300 AND l.PricePerDay <= 399)";
                break;
            case '400-499':
                $priceConditions[] = "(l.PricePerDay >= 400 AND l.PricePerDay <= 499)";
                break;
            case '500-599':
                $priceConditions[] = "(l.PricePerDay >= 500 AND l.PricePerDay <= 599)";
                break;
            case '600-699':
                $priceConditions[] = "(l.PricePerDay >= 600 AND l.PricePerDay <= 699)";
                break;
            case '700-799':
                $priceConditions[] = "(l.PricePerDay >= 700 AND l.PricePerDay <= 799)";
                break;
            case '800-899':
                $priceConditions[] = "(l.PricePerDay >= 800 AND l.PricePerDay <= 899)";
                break;
            case '900-999':
                $priceConditions[] = "(l.PricePerDay >= 900 AND l.PricePerDay <= 999)";
                break;
            case '1000-1499':
                $priceConditions[] = "(l.PricePerDay >= 1000 AND l.PricePerDay <= 1499)";
                break;
            case '1500-1999':
                $priceConditions[] = "(l.PricePerDay >= 1500 AND l.PricePerDay <= 1999)";
                break;
            case '2000-2499':
                $priceConditions[] = "(l.PricePerDay >= 2000 AND l.PricePerDay <= 2499)";
                break;
            case '2500+':
                $priceConditions[] = "(l.PricePerDay >= 2500)";
                break;
        }
    }
}

// Handle custom range
if (!empty($min_price) || !empty($max_price_custom)) {
    $customCondition = [];
    if (!empty($min_price)) {
        $customCondition[] = "l.PricePerDay >= ?";
        $params[] = $min_price;
    }
    if (!empty($max_price_custom)) {
        $customCondition[] = "l.PricePerDay <= ?";
        $params[] = $max_price_custom;
    }
    if (!empty($customCondition)) {
        $priceConditions[] = "(" . implode(" AND ", $customCondition) . ")";
    }
}

// Combine all price conditions with OR
if (!empty($priceConditions)) {
    $sql .= " AND (" . implode(" OR ", $priceConditions) . ")";
}

$sql .= " ORDER BY b.AddedDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $pdo->query("SELECT CategoryID, CategoryName FROM TCategories WHERE ParentCategoryID = 0 ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookmarks - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        a.item-image-link { display: block; text-decoration: none; color: inherit; }
        a.item-image-link:hover .item-image img { opacity: 0.88; transition: opacity 0.15s; }
    </style>
</head>
<body data-logged-in="true">
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <!-- Logo -->
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height: 50px; width: auto;">
                </a>
                
                <!-- Search Bar -->
                  <?php include 'includes/search_bar.php'; ?>
                
                <!-- Navigation -->
                <nav class="main-nav">
                  <a href="index.php" class="nav-link">
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
                    <a href="map.php" class="nav-link">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>Map</span>
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
                        <span class="notification-badge" style="display:none;"></span>
                    </div>
                    <div class="notification-icon" onclick="openChat()" style="cursor: pointer;" title="Messages">
                        <i class="fas fa-comment-dots"></i>
                        <span class="notification-badge" style="display:none;"></span>
                    </div>
                    <div class="user-menu-container">
                        <div class="user-avatar" onclick="toggleUserMenu()">
                            <?php if (!empty($_SESSION['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>"
                                     alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-dropdown" id="userDropdown">
                            <div class="user-dropdown-header">
                                <strong><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></strong>
                                <small><?php echo htmlspecialchars($_SESSION['email']); ?></small>
                            </div>
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <a href="account_info.php"><i class="fas fa-cog"></i> Account Info</a>
                            <a href="my_bookmarks.php"><i class="fas fa-bookmark"></i> Bookmarked Items</a>
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
            <form action="my_bookmarks.php" method="GET" class="filters" id="filterForm">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                
                <div class="filter-dropdown">
                    <select name="category" id="parentCategoryBookmarks" class="filter-select" onchange="loadSubcategoriesBookmarks()">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['CategoryID']; ?>" <?php echo ($category == $cat['CategoryID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Subcategory Dropdown -->
                <div class="filter-dropdown" id="subcategoryContainerBookmarks" style="display: <?php echo !empty($category) ? 'block' : 'none'; ?>;">
                    <select name="subcategory" id="subcategoryBookmarks" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Subcategories</option>
                    </select>
                </div>
                
                <!-- Price Filter -->
                <div class="filter-dropdown price-filter-dropdown">
                    <button type="button" class="filter-select" onclick="togglePriceFilter(event)">
                        <span>Price Range</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="price-filter-menu" id="priceFilterMenu" style="display: none;">
                        <div class="price-filter-section" style="max-height: 300px; overflow-y: auto;">
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="0-49" <?php echo (in_array('0-49', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$0 - $49</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="50-99" <?php echo (in_array('50-99', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$50 - $99</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="100-199" <?php echo (in_array('100-199', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$100 - $199</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="200-299" <?php echo (in_array('200-299', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$200 - $299</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="300-399" <?php echo (in_array('300-399', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$300 - $399</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="400-499" <?php echo (in_array('400-499', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$400 - $499</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="500-599" <?php echo (in_array('500-599', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$500 - $599</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="600-699" <?php echo (in_array('600-699', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$600 - $699</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="700-799" <?php echo (in_array('700-799', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$700 - $799</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="800-899" <?php echo (in_array('800-899', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$800 - $899</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="900-999" <?php echo (in_array('900-999', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$900 - $999</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="1000-1499" <?php echo (in_array('1000-1499', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$1,000 - $1,499</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="1500-1999" <?php echo (in_array('1500-1999', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$1,500 - $1,999</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="2000-2499" <?php echo (in_array('2000-2499', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$2,000 - $2,499</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="2500+" <?php echo (in_array('2500+', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$2,500+</span>
                            </label>
                        </div>
                        
                        <div class="price-filter-divider"></div>
                        
                        <div class="price-filter-section">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Custom Range</label>
                            <div class="custom-price-inputs">
                                <input type="number" name="min_price" placeholder="Min" min="0" value="<?php echo htmlspecialchars($min_price); ?>" class="price-input">
                                <span style="margin: 0 8px;">to</span>
                                <input type="number" name="max_price" placeholder="Max" min="0" value="<?php echo htmlspecialchars($max_price_custom); ?>" class="price-input">
                            </div>
                        </div>
                        
                        <div class="price-filter-actions">
                            <button type="button" onclick="clearPriceFilters()" class="btn btn-outline btn-sm">Clear</button>
                            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        </div>
                    </div>
                </div>
                
                <!-- Grid Layout Dropdown -->
                <div class="filter-dropdown grid-layout-dropdown">
                    <button type="button" class="filter-select" onclick="toggleGridMenu(event)">
                        <span>Grid Layout</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="grid-menu" id="gridMenu" style="display: none;">
                        <button type="button" class="grid-menu-option" data-grid="1">
                            <svg viewBox="0 0 40 32" xmlns="http://www.w3.org/2000/svg" width="40" height="32">
                                <rect class="grid-icon-rect" x="2" y="2" width="36" height="28" rx="2"/>
                            </svg>
                            <span>1 Column</span>
                        </button>
                        <button type="button" class="grid-menu-option active" data-grid="2">
                            <svg viewBox="0 0 40 32" xmlns="http://www.w3.org/2000/svg" width="40" height="32">
                                <rect class="grid-icon-rect" x="2" y="1" width="17" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="21" y="1" width="17" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="2" y="16" width="17" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="21" y="16" width="17" height="13" rx="1.5"/>
                            </svg>
                            <span>2 Columns</span>
                        </button>
                        <button type="button" class="grid-menu-option" data-grid="3">
                            <svg viewBox="0 0 40 32" xmlns="http://www.w3.org/2000/svg" width="40" height="32">
                                <rect class="grid-icon-rect" x="1" y="1" width="11" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="14.5" y="1" width="11" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="28" y="1" width="11" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="1" y="16" width="11" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="14.5" y="16" width="11" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="28" y="16" width="11" height="13" rx="1.5"/>
                            </svg>
                            <span>3 Columns</span>
                        </button>
                        <button type="button" class="grid-menu-option" data-grid="4">
                            <svg viewBox="0 0 40 32" xmlns="http://www.w3.org/2000/svg" width="40" height="32">
                                <rect class="grid-icon-rect" x="2" y="2" width="7.5" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="11.5" y="2" width="7.5" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="21" y="2" width="7.5" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="30.5" y="2" width="7.5" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="2" y="17" width="7.5" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="11.5" y="17" width="7.5" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="21" y="17" width="7.5" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="30.5" y="17" width="7.5" height="13" rx="1.5"/>
                            </svg>
                            <span>4 Columns</span>
                        </button>
                        <button type="button" class="grid-menu-option" data-grid="5">
                            <svg viewBox="0 0 40 32" xmlns="http://www.w3.org/2000/svg" width="40" height="32">
                                <rect class="grid-icon-rect" x="1" y="1" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="8.5" y="1" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="16" y="1" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="23.5" y="1" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="31" y="1" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="1" y="11.5" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="8.5" y="11.5" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="16" y="11.5" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="23.5" y="11.5" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="31" y="11.5" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="1" y="22" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="8.5" y="22" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="16" y="22" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="23.5" y="22" width="6" height="9" rx="1"/>
                                <rect class="grid-icon-rect" x="31" y="22" width="6" height="9" rx="1"/>
                            </svg>
                            <span>5 Columns</span>
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($search) || !empty($category) || !empty($price_ranges) || !empty($min_price) || !empty($max_price_custom)): ?>
                    <a href="my_bookmarks.php" class="btn btn-outline btn-sm">Clear All Filters</a>
                <?php endif; ?>
            </form>
        </div>
    </section>
    
    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <h1>My Bookmarked Items</h1>
            
            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="fas fa-bookmark fa-3x"></i>
                    <h2>No bookmarked items yet</h2>
                    <p>Items you bookmark will appear here. <a href="index.php">Browse available items!</a></p>
                </div>
            <?php else: ?>
                <div class="items-column grid-2">
                    <?php foreach ($items as $item): ?>
                        <div class="item-card">
                            <div class="item-header">
                                <div class="user-info">
                                    <div class="avatar avatar-<?php echo ($item['OwnerUserID'] % 2 == 0) ? 'purple' : 'pink'; ?>">
                                        <?php echo strtoupper(substr($item['FirstName'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="user-name"><?php echo htmlspecialchars($item['FirstName'] . ' ' . substr($item['LastName'], 0, 1) . '.'); ?></div>
                                        <div class="user-distance">
                                            <?php echo htmlspecialchars($item['City'] ?? 'Cincinnati area'); ?>
                                        </div>
                                    </div>
                                </div>
                                <button class="bookmark-btn bookmarked" data-listing-id="<?php echo $item['ListingID']; ?>">
                                    <i class="fas fa-bookmark"></i>
                                </button>
                            </div>
                            
                            <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>&back=<?php echo urlencode($current_url); ?>" class="item-image-link" onclick="sessionStorage.setItem('scrollY', window.scrollY);">
                            <div class="item-image">
                                <?php
                                    $mediaUrl = $item['PrimaryImage'] ?? '';
                                    $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                                    $videoExts = ['mp4', 'webm', 'ogg', 'mov'];
                                    $isVideo = $mediaUrl && in_array($ext, $videoExts);
                                ?>
                        
                                <?php if ($mediaUrl): ?>
                                    <?php if ($isVideo): ?>
                                        <video autoplay muted loop playsinline preload="metadata">
                                            <source src="<?php echo htmlspecialchars($mediaUrl); ?>" type="video/<?php echo $ext === 'mov' ? 'mp4' : htmlspecialchars($ext); ?>">
                                            Your browser does not support the video tag.
                                        </video>
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars($mediaUrl); ?>" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                    <?php endif; ?>
                                <?php else: ?>
                                    <img src="https://images.unsplash.com/photo-1504148455328-c376907d081c?w=600&h=400&fit=crop" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                <?php endif; ?>
                            </div>
                        </a>
                            
                            <div class="item-footer">
                                <div class="item-actions">
                                    <button class="action-btn" title="Item Info">
                                        <i class="far fa-circle-question"></i>
                                    </button>
                                    <button class="action-btn" title="Share">
                                        <i class="fas fa-share-nodes"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="item-details">
                                <h3 class="item-title"><?php echo htmlspecialchars($item['Title']); ?></h3>
                                <p class="item-description"><?php echo htmlspecialchars(substr($item['Description'], 0, 100)); ?>...</p>
                                
                                <div class="item-meta">
                                    <span class="item-category"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['CategoryName']); ?></span>
                                    <span class="item-condition"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($item['Condition']); ?></span>
                                </div>
                                
                                <div class="item-price-section">
                                    <div>
                                        <div class="price-label">Price per day</div>
                                        <div class="price">$<?php echo number_format($item['PricePerDay'], 2); ?></div>
                                    </div>
                                    <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>&back=<?php echo urlencode($current_url); ?>" class="message-btn" onclick="sessionStorage.setItem('scrollY', window.scrollY);">View Details</a>
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
        
        // Price filter dropdown
        function togglePriceFilter(event) {
            event.preventDefault();
            event.stopPropagation();
            const menu = document.getElementById('priceFilterMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        function clearPriceFilters() {
            document.querySelectorAll('input[name="price_ranges[]"]').forEach(cb => cb.checked = false);
            document.querySelector('input[name="min_price"]').value = '';
            document.querySelector('input[name="max_price"]').value = '';
        }
        
        // Grid layout switcher
        function toggleGridMenu(event) {
            event.preventDefault();
            event.stopPropagation();
            const menu = document.getElementById('gridMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        // Load subcategories
        async function loadSubcategoriesBookmarks() {
            const parentId = document.getElementById('parentCategoryBookmarks').value;
            const subcategoryContainer = document.getElementById('subcategoryContainerBookmarks');
            const subcategorySelect = document.getElementById('subcategoryBookmarks');
            
            if (!parentId) {
                subcategoryContainer.style.display = 'none';
                document.getElementById('filterForm').submit();
                return;
            }
            
            try {
                const response = await fetch(`get_subcategories.php?parent=${parentId}`);
                const subcategories = await response.json();
                
                subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
                
                subcategories.forEach(sub => {
                    const option = document.createElement('option');
                    option.value = sub.CategoryID;
                    option.textContent = sub.CategoryName;
                    subcategorySelect.appendChild(option);
                });
                
                if (subcategories.length > 0) {
                    subcategoryContainer.style.display = 'block';
                } else {
                    subcategoryContainer.style.display = 'none';
                    document.getElementById('filterForm').submit();
                }
            } catch (error) {
                console.error('Error loading subcategories:', error);
                document.getElementById('filterForm').submit();
            }
        }
        
        // Grid view functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Restore scroll position if returning from item detail
            const savedScroll = sessionStorage.getItem('scrollY');
            if (savedScroll !== null) {
                setTimeout(() => {
                    window.scrollTo({ top: parseInt(savedScroll), behavior: 'instant' });
                    sessionStorage.removeItem('scrollY');
                }, 100);
            }

            // Auto-load subcategories if category selected
            const parentCategorySelect = document.getElementById('parentCategoryBookmarks');
            if (parentCategorySelect && parentCategorySelect.value) {
                loadSubcategoriesBookmarks();
            }
            
            const savedGrid = localStorage.getItem('gridView') || '2';
            const itemsColumn = document.querySelector('.items-column');
            if (itemsColumn) {
                itemsColumn.className = 'items-column grid-' + savedGrid;
                
                document.querySelectorAll('.grid-menu-option').forEach(opt => {
                    opt.classList.remove('active');
                    if (opt.dataset.grid === savedGrid) {
                        opt.classList.add('active');
                    }
                });
            }
            
            document.querySelectorAll('.grid-menu-option').forEach(option => {
                option.addEventListener('click', function() {
                    const gridSize = this.dataset.grid;
                    const itemsColumn = document.querySelector('.items-column');
                    
                    if (itemsColumn) {
                        itemsColumn.className = 'items-column grid-' + gridSize;
                    }
                    
                    document.querySelectorAll('.grid-menu-option').forEach(opt => {
                        opt.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    localStorage.setItem('gridView', gridSize);
                    document.getElementById('gridMenu').style.display = 'none';
                });
            });
        });
        
        // Bookmark functionality
        async function toggleBookmark(button) {
            const listingId = button.dataset.listingId;
            
            try {
                const response = await fetch('toggle_bookmark.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ listing_id: listingId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (data.bookmarked) {
                        button.classList.add('bookmarked');
                        button.querySelector('i').classList.remove('far');
                        button.querySelector('i').classList.add('fas');
                    } else {
                        // Item was unbookmarked, remove card from page
                        button.closest('.item-card').style.transition = 'opacity 0.3s';
                        button.closest('.item-card').style.opacity = '0';
                        setTimeout(() => {
                            button.closest('.item-card').remove();
                            // Check if no items left
                            if (document.querySelectorAll('.item-card').length === 0) {
                                location.reload();
                            }
                        }, 300);
                    }
                }
            } catch (error) {
                console.error('Error toggling bookmark:', error);
            }
        }
        
        // Add click handlers to bookmark buttons
        document.querySelectorAll('.bookmark-btn').forEach(button => {
            button.addEventListener('click', function() {
                toggleBookmark(this);
            });
        });
        
        // Close dropdown when clicking outside
        window.onclick = function(event) {
            const dropdown = document.getElementById('userDropdown');
            const priceMenu = document.getElementById('priceFilterMenu');
            const gridMenu = document.getElementById('gridMenu');
            
            if (!event.target.matches('.user-avatar')) {
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
            
            if (!event.target.closest('.price-filter-dropdown')) {
                if (priceMenu) {
                    priceMenu.style.display = 'none';
                }
            }
            
            if (!event.target.closest('.grid-layout-dropdown')) {
                if (gridMenu) {
                    gridMenu.style.display = 'none';
                }
            }
        }
    </script>
    <?php include 'includes/header_dropdowns.php'; ?>
    <?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>

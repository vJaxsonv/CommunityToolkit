<?php 
require_once 'config.php';
require_once 'location_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user's location
$userLocation = getUserLocation($pdo);
$user_lat = $userLocation['latitude'] ?? null;
$user_lng = $userLocation['longitude'] ?? null;
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 10;

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$subcategory = $_GET['subcategory'] ?? '';
$price_ranges = $_GET['price_ranges'] ?? [];
$min_price = $_GET['min_price'] ?? '';
$max_price_custom = $_GET['max_price'] ?? '';
// Build current URL for back-link
$current_url = 'home.php?' . http_build_query(array_filter([
    'search'       => $search,
    'category'     => $category,
    'subcategory'  => $subcategory,
    'radius'       => $radius != 10 ? $radius : null,
    'min_price'    => $min_price,
    'max_price'    => $max_price_custom,
    'price_ranges' => $price_ranges ?: null,
], fn($v) => $v !== null && $v !== '' && $v !== []));
if ($current_url === 'home.php?') $current_url = 'home.php';
$items = [];

if ($user_lat !== null && $user_lng !== null) {
    $sql = "
        SELECT *
        FROM (
            SELECT 
                l.*,
                u.FirstName,
                u.LastName,
                u.UserID AS OwnerUserID,
                c.CategoryName,
                cond.Condition,
                ls.Status,
                n.NeighborhoodName,
                n.City,
                n.CenterLatitude,
                n.CenterLongitude,
                (
                    SELECT PhotoURL 
                    FROM TListingPhotos 
                    WHERE ListingID = l.ListingID 
                    ORDER BY SortOrder 
                    LIMIT 1
                ) AS PrimaryImage,
                (
                    3959 * ACOS(
                        LEAST(1, GREATEST(-1,
                            COS(RADIANS(?)) *
                            COS(RADIANS(n.CenterLatitude)) *
                            COS(RADIANS(n.CenterLongitude) - RADIANS(?)) +
                            SIN(RADIANS(?)) *
                            SIN(RADIANS(n.CenterLatitude))
                        ))
                    )
                ) AS distance
            FROM TListings l
            INNER JOIN TUsers u ON l.UserLenderID = u.UserID
            LEFT JOIN TCategories c ON l.CategoryID = c.CategoryID
            LEFT JOIN TConditions cond ON l.ConditionID = cond.ConditionID
            LEFT JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
            LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
            WHERE n.CenterLatitude IS NOT NULL
  AND n.CenterLongitude IS NOT NULL
  AND ls.Status != 'Removed'
  AND l.UserLenderID <> ?
    ";

    $params = [$user_lat, $user_lng, $user_lat];
    $params[] = $_SESSION['user_id'];

    // Search filter
    if (!empty($search)) {
        $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Category filter
    if (!empty($subcategory)) {
        $sql .= " AND l.CategoryID = ?";
        $params[] = $subcategory;
    } elseif (!empty($category)) {
        $sql .= " AND (l.CategoryID = ? OR l.CategoryID IN (SELECT CategoryID FROM TCategories WHERE ParentCategoryID = ?))";
        $params[] = $category;
        $params[] = $category;
    }

    // Price range filters
    $priceConditions = [];

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

    // Custom price range
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

    if (!empty($priceConditions)) {
        $sql .= " AND (" . implode(" OR ", $priceConditions) . ")";
    }

    $sql .= "
        ) AS nearby_items
        WHERE distance <= ?
        ORDER BY distance ASC, AddedDate DESC
        LIMIT 20
    ";

    $params[] = $radius;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get categories for filter - ParentCategoryID = 0 for top level
$categories = $pdo->query("
    SELECT CategoryID, CategoryName 
    FROM TCategories 
    WHERE ParentCategoryID = 0 
    ORDER BY CategoryName
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all photos for carousel
$listingIds = array_column($items, 'ListingID');
$listingPhotos = [];
if (!empty($listingIds)) {
    $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
    $photoStmt = $pdo->prepare("
        SELECT ListingID, PhotoURL
        FROM TListingPhotos
        WHERE ListingID IN ($placeholders)
        ORDER BY ListingID, SortOrder ASC
    ");
    $photoStmt->execute($listingIds);
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $listingPhotos[$row['ListingID']][] = $row['PhotoURL'];
    }
}

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
<style>
.action-buttons {
    display: flex;
    align-items: center;
    gap: 8px; 
}
    .message-icon-btn {
      background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    color: #999;
    font-size: 18px;


    color: #666; /* gray icon */


    text-decoration: none;

    transition: all 0.2s ease;
}

.message-icon-btn:hover {
  color: #333;
}

</style>
<body data-logged-in="<?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>" data-has-account-location="<?php echo ($userLocation['source'] === 'account_neighborhood' || $userLocation['source'] === 'account_zipcode') ? 'true' : 'false'; ?>">
    <!-- Header/Navigation -->
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
                        <span class="notification-badge">0</span>
                    </div>
                    <div class="notification-icon" style="cursor: pointer;" title="Messages">
                        <i class="fas fa-comment-dots"></i>
                        <span class="notification-badge">0</span>
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
            <form action="home.php" method="GET" class="filters" id="filterForm">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                
                <div class="filter-dropdown">
                    <select name="category" id="parentCategoryHome" class="filter-select" onchange="loadSubcategoriesHome()">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['CategoryID']; ?>" <?php echo ($category == $cat['CategoryID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Subcategory Dropdown -->
                <div class="filter-dropdown" id="subcategoryContainerHome" style="display: <?php echo !empty($category) ? 'block' : 'none'; ?>;">
                    <select name="subcategory" id="subcategoryHome" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Subcategories</option>
                    </select>
                </div>
                
                <!-- Price Filter with Checkboxes -->
                <div class="filter-dropdown price-filter-dropdown">
                    <button type="button" class="filter-select" onclick="togglePriceFilter(event)">
                        <span>Price Range</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="price-filter-menu" id="priceFilterMenu" style="display: none;">
                        <div class="price-filter-section" style="max-height: 300px; overflow-y: auto;">
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="0-49" 
                                    <?php echo (in_array('0-49', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$0 - $49</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="50-99" 
                                    <?php echo (in_array('50-99', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$50 - $99</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="100-199" 
                                    <?php echo (in_array('100-199', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$100 - $199</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="200-299" 
                                    <?php echo (in_array('200-299', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$200 - $299</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="300-399" 
                                    <?php echo (in_array('300-399', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$300 - $399</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="400-499" 
                                    <?php echo (in_array('400-499', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$400 - $499</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="500-599" 
                                    <?php echo (in_array('500-599', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$500 - $599</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="600-699" 
                                    <?php echo (in_array('600-699', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$600 - $699</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="700-799" 
                                    <?php echo (in_array('700-799', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$700 - $799</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="800-899" 
                                    <?php echo (in_array('800-899', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$800 - $899</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="900-999" 
                                    <?php echo (in_array('900-999', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$900 - $999</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="1000-1499" 
                                    <?php echo (in_array('1000-1499', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$1,000 - $1,499</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="1500-1999" 
                                    <?php echo (in_array('1500-1999', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$1,500 - $1,999</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="2000-2499" 
                                    <?php echo (in_array('2000-2499', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$2,000 - $2,499</span>
                            </label>
                            <label class="filter-checkbox">
                                <input type="checkbox" name="price_ranges[]" value="2500+" 
                                    <?php echo (in_array('2500+', $price_ranges)) ? 'checked' : ''; ?>>
                                <span>$2,500+</span>
                            </label>
                        </div>
                        
                        <div class="price-filter-divider"></div>
                        
                        <div class="price-filter-section">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Custom Range</label>
                            <div class="custom-price-inputs">
                                <input type="number" name="min_price" placeholder="Min" min="0" 
                                    value="<?php echo htmlspecialchars($min_price); ?>" class="price-input">
                                <span style="margin: 0 8px;">to</span>
                                <input type="number" name="max_price" placeholder="Max" min="0" 
                                    value="<?php echo htmlspecialchars($max_price_custom); ?>" class="price-input">
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
                        
                        <!-- 1 Column -->
                        <button type="button" class="grid-menu-option" data-grid="1">
                            <svg viewBox="0 0 40 32" xmlns="http://www.w3.org/2000/svg" width="40" height="32">
                                <rect class="grid-icon-rect" x="2" y="2" width="36" height="28" rx="2"/>
                            </svg>
                            <span>1 Column</span>
                        </button>
                        
                        <!-- 2 Columns -->
                        <button type="button" class="grid-menu-option active" data-grid="2">
                            <svg viewBox="0 0 40 32" xmlns="http://www.w3.org/2000/svg" width="40" height="32">
                                <rect class="grid-icon-rect" x="2" y="1" width="17" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="21" y="1" width="17" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="2" y="16" width="17" height="13" rx="1.5"/>
                                <rect class="grid-icon-rect" x="21" y="16" width="17" height="13" rx="1.5"/>
                            </svg>
                            <span>2 Columns</span>
                        </button>
                        
                        <!-- 3 Columns -->
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
                        
                        <!-- 4 Columns -->
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
                        
                        <!-- 5 Columns -->
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
                    
                <?php if (!empty($search) || !empty($category) || !empty($subcategory) || !empty($price_ranges) || !empty($min_price) || !empty($max_price_custom)): ?>
                    <a href="home.php" class="btn btn-outline btn-sm">Clear All Filters</a>
                <?php endif; ?>
                
                   <div class="distance-filter">

            <div class="distance-header">
                <i class="fas fa-location-dot"></i>
                <span>Distance</span>
            </div>

            <div class="distance-slider">
                <input 
                    type="range" 
                    id="radius"
                    name="radius"
                    min="1"
                    max="50"
                    value="<?= htmlspecialchars($_GET['radius'] ?? 10) ?>"
                    oninput="document.getElementById('radiusValue').textContent = this.value"
                >

                <div class="distance-value">
                    <span id="radiusValue"><?= htmlspecialchars($_GET['radius'] ?? 10) ?></span> miles
                </div>
            </div>

            </div><!-- /.distance-filter -->

                <button type="submit" class="apply-filters-btn">
                    <i class="fas fa-check"></i> Apply Filter(s)
                </button>
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
                <div class="items-column grid-2">
                    <?php foreach($items as $item): ?>
                        <div class="item-card">
                            <div class="item-header">
                                <div class="user-info">
                                     <a href="profile.php?user_id=<?php echo $item['OwnerUserID']; ?>" class="avatar-link">
                                        <span class="avatar avatar-<?php echo ($item['OwnerUserID'] % 2 == 0) ? 'purple' : 'pink'; ?>">
                                            <?php echo strtoupper(substr($item['FirstName'], 0, 1)); ?>
                                        </span>
                                    </a>
                                    <div>
                                        <div class="user-name"><?php echo htmlspecialchars($item['FirstName'] . ' ' . substr($item['LastName'], 0, 1) . '.'); ?>
                                    </div>
                                        <div class="user-distance">
                                            <?php if (isset($item['distance']) && $item['distance'] !== null): ?>
                                                <i class="fas fa-location-dot"></i> <?php echo number_format($item['distance'], 1); ?> mi away
                                            <?php elseif ($item['City']): ?>
                                                <?php echo htmlspecialchars($item['City']); ?>
                                            <?php else: ?>
                                                Cincinnati area
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                              <div class="action-buttons">
                                    <a href="messages.php?profile_id=<?php echo $item['OwnerUserID']; ?>" class="message-icon-btn">
                                        <i class="far fa-comment-dots"></i>
                                    </a>
                                
                                    <button class="bookmark-btn" data-listing-id="<?php echo $item['ListingID']; ?>">
                                        <i class="far fa-bookmark"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="item-image" id="carousel-<?php echo $item['ListingID']; ?>" style="position:relative;overflow:hidden;">
                                <?php
                                $photos = $listingPhotos[$item['ListingID']] ?? [];
                                if (empty($photos) && !empty($item['PrimaryImage'])) $photos = [$item['PrimaryImage']];
                                if (empty($photos)) $photos = ['https://images.unsplash.com/photo-1504148455328-c376907d081c?w=600&h=400&fit=crop'];
                                $total = count($photos);
                                ?>
                                <div data-total="<?php echo $total; ?>" data-current="0">
                                <?php foreach ($photos as $i => $photoUrl): ?>
                                    <div style="<?php echo $i===0 ? '' : 'display:none;'; ?>width:100%;position:<?php echo $i===0 ? 'relative' : 'absolute'; ?>;top:0;left:0;">
                                        <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>&back=<?php echo urlencode($current_url); ?>" style="display:block;width:100%;height:100%;" onclick="sessionStorage.setItem('scrollY', window.scrollY);">
                                            <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                <?php if ($total > 1): ?>
                                <button class="ci-prev" onclick="ciMove(<?php echo $item['ListingID']; ?>,-1,event)" aria-label="Previous" style="visibility:hidden;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                                </button>
                                <button class="ci-next" onclick="ciMove(<?php echo $item['ListingID']; ?>,1,event)" aria-label="Next">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <div class="ci-dots">
                                    <?php for ($d=0;$d<$total;$d++): ?>
                                        <span class="ci-dot<?php echo $d===0 ? ' ci-dot-on' : ''; ?>"></span>
                                    <?php endfor; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="item-footer">
                                <div class="item-actions">
                                    <button class="action-btn share-btn"
                                            data-id="<?php echo (int)$item['ListingID']; ?>"
                                            title="Share">
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
        
        // Chat placeholder - to be implemented

        
        // Price filter dropdown
        function togglePriceFilter(event) {
            event.preventDefault();
            event.stopPropagation();
            const menu = document.getElementById('priceFilterMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        function clearPriceFilters() {
            // Uncheck all checkboxes
            document.querySelectorAll('input[name="price_ranges[]"]').forEach(cb => cb.checked = false);
            // Clear custom inputs
            document.querySelector('input[name="min_price"]').value = '';
            document.querySelector('input[name="max_price"]').value = '';
        }
        
        // Load subcategories based on parent selection
        async function loadSubcategoriesHome(autoSubmit = true) {
            const parentId = document.getElementById('parentCategoryHome').value;
            const subcategoryContainer = document.getElementById('subcategoryContainerHome');
            const subcategorySelect = document.getElementById('subcategoryHome');

            subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';

            if (!parentId) {
                subcategoryContainer.style.display = 'none';
                if (autoSubmit) document.getElementById('filterForm').submit();
                return;
            }

            try {
                const response = await fetch(`get_subcategories.php?parent=${parentId}`);
                const subcategories = await response.json();
                const selectedSub = '<?php echo htmlspecialchars($subcategory ?? ""); ?>';

                if (subcategories.length > 0) {
                    subcategories.forEach(sub => {
                        const option = document.createElement('option');
                        option.value = sub.CategoryID;
                        option.textContent = sub.CategoryName;
                        if (String(sub.CategoryID) === selectedSub) option.selected = true;
                        subcategorySelect.appendChild(option);
                    });
                    subcategoryContainer.style.display = 'block';
                } else {
                    subcategoryContainer.style.display = 'none';
                }
                if (autoSubmit) document.getElementById('filterForm').submit();
            } catch (error) {
                console.error('Error loading subcategories:', error);
                if (autoSubmit) document.getElementById('filterForm').submit();
            }
        }
        
        // Grid layout switcher
        function toggleGridMenu(event) {
            event.preventDefault();
            event.stopPropagation();
            const menu = document.getElementById('gridMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Restore scroll position if returning from item detail
            const savedScroll = sessionStorage.getItem('scrollY');
            if (savedScroll !== null) {
                setTimeout(() => {
                    window.scrollTo({ top: parseInt(savedScroll), behavior: 'instant' });
                    sessionStorage.removeItem('scrollY');
                }, 100);
            }

            // Auto-load subcategories if parent category is selected (no submit on page load)
            const parentCategorySelect = document.getElementById('parentCategoryHome');
            if (parentCategorySelect && parentCategorySelect.value) {
                loadSubcategoriesHome(false);
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
            
            // Check if user is logged in
            const isLoggedIn = document.body.dataset.loggedIn === 'true';
            if (!isLoggedIn) {
                alert('Please log in to bookmark items');
                window.location.href = 'login.php';
                return;
            }
            
            try {
                const response = await fetch('toggle_bookmark.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ listing_id: listingId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const icon = button.querySelector('i');
                    if (data.bookmarked) {
                        button.classList.add('bookmarked');
                        icon.classList.remove('far');
                        icon.classList.add('fas');
                    } else {
                        button.classList.remove('bookmarked');
                        icon.classList.remove('fas');
                        icon.classList.add('far');
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

            // Close user dropdown
            if (dropdown && !event.target.matches('.user-avatar')) {
                if (dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }

            // Close price filter menu
            if (priceMenu && !event.target.closest('.price-filter-dropdown')) {
                priceMenu.style.display = 'none';
            }

            // Close grid menu
            if (gridMenu && !event.target.closest('.grid-layout-dropdown')) {
                gridMenu.style.display = 'none';
            }
        }
    </script>
    
    <style>
        .price-filter-dropdown {
            position: relative;
        }
        
        .price-filter-dropdown .filter-select {
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        
        .price-filter-menu {
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 8px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 16px;
            min-width: 280px;
            z-index: 1000;
        }
        
        .price-filter-section {
            margin-bottom: 12px;
        }
        
        .filter-checkbox {
            display: flex;
            align-items: center;
            padding: 8px 0;
            cursor: pointer;
            user-select: none;
        }
        
        .filter-checkbox input[type="checkbox"] {
            margin-right: 10px;
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        
        .filter-checkbox span {
            font-size: 14px;
            color: #333;
        }
        
        .filter-checkbox:hover {
            background-color: #f5f5f5;
            margin: 0 -8px;
            padding-left: 8px;
            padding-right: 8px;
            border-radius: 4px;
        }
        
        .price-filter-divider {
            height: 1px;
            background: #e0e0e0;
            margin: 12px 0;
        }
        
        .custom-price-inputs {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .price-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .price-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .price-filter-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
        }
        
        .price-filter-actions .btn {
            flex: 1;
        }
        .filters-form{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px;
}

.filters-left{
    display:flex;
    gap:15px;
    align-items:center;
}

/* Distance filter card */

.distance-filter{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:12px 16px;
    display:flex;
    align-items:center;
    gap:15px;
    box-shadow:0 3px 10px rgba(0,0,0,0.06);
}

/* Header */

.distance-header{
    display:flex;
    align-items:center;
    gap:6px;
    font-weight:600;
    color:#374151;
    font-size:14px;
}

/* Slider */

.distance-slider{
    display:flex;
    align-items:center;
    gap:10px;
}

.distance-slider input[type=range]{
    width:130px;
    accent-color:#7C3AED; /* purple */
    cursor:pointer;
}

/* Distance number */

.distance-value{
    font-weight:600;
    font-size:13px;
    color:#111827;
}
.distance-header i{
    color:#7C3AED;
}
/* Button */

.distance-btn{
    background:#7C3AED;
    color:white;
    border:none;
    padding:6px 14px;
    border-radius:6px;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    transition:all 0.2s ease;
}

.distance-btn:hover{
    background:#6D28D9;
}

.apply-filters-btn {
    background: #667eea;
    color: white;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    flex-shrink: 0;
}
.apply-filters-btn:hover {
    background: #5a6fd6;
}
.share-modal{
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.45);
    justify-content:center;
    align-items:center;
    z-index:1000;
}

.share-content{
    background:white;
    padding:25px;
    border-radius:12px;
    width:300px;
    text-align:center;
    box-shadow:0 8px 25px rgba(0,0,0,0.2);
}

.share-content h3{
    margin-bottom:15px;
}

.share-options{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.share-options button,
.share-options a{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:10px;
    border:none;
    background:#f4f4f4;
    border-radius:8px;
    text-decoration:none;
    cursor:pointer;
    font-size:14px;
}

.share-options button:hover,
.share-options a:hover{
    background:#e8e8e8;
}

.close-share{
    margin-top:15px;
    background:#7c3aed;
    color:white;
    border:none;
    padding:8px 16px;
    border-radius:6px;
    cursor:pointer;
}

.avatar-link {
    display: inline-block;
    position: relative;
    z-index: 10;
    pointer-events: auto;
    text-decoration: none;
    cursor: pointer;
}

.avatar-link .avatar {
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-info {
    position: relative;
    z-index: 10;
}



    </style>
    
    <script src="location_manager.js"></script>
    
    
    <div id="shareModal" class="share-modal">
    <div class="share-content">
        <h3>Share this item</h3>

        <div class="share-options">
            <button id="copyLink"><i class="fas fa-link"></i> Copy Link</button>

            <a id="shareFacebook" target="_blank">
                <i class="fab fa-facebook"></i> Facebook
            </a>

            <a id="shareTwitter" target="_blank">
                <i class="fab fa-twitter"></i> Twitter
            </a>

            <a id="shareEmail">
                <i class="fas fa-envelope"></i> Email
            </a>
        </div>

        <button class="close-share">Close</button>
    </div>
</div>
    <?php include 'includes/header_dropdowns.php'; ?>
    <?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>
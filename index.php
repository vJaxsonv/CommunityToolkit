<?php 
require_once 'config.php';
require_once 'location_helper.php';

$isLoggedIn = isset($_SESSION['user_id']);
$currentUserId = $isLoggedIn ? (int)$_SESSION['user_id'] : null;

// Get user's location only if logged in
$user_lat = null;
$user_lng = null;

if ($isLoggedIn) {
    $userLocation = getUserLocation($pdo);
    $user_lat = $userLocation['latitude'] ?? null;
    $user_lng = $userLocation['longitude'] ?? null;
}

$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 10;

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$price_ranges = $_GET['price_ranges'] ?? [];
$min_price = $_GET['min_price'] ?? '';
$max_price_custom = $_GET['max_price'] ?? '';
$subcategory = $_GET['subcategory'] ?? '';

// Get top-level categories for filter dropdown
$categories = $pdo->query("
    SELECT CategoryID, CategoryName
    FROM TCategories
    WHERE ParentCategoryID = 0
    ORDER BY CategoryName
")->fetchAll(PDO::FETCH_ASSOC);

// Build current URL for back-link (preserves all active filters)
$current_url = 'index.php?' . http_build_query(array_filter([
    'search'       => $search,
    'category'     => $category,
    'subcategory'  => $subcategory,
    'radius'       => $radius != 10 ? $radius : null,
    'min_price'    => $min_price,
    'max_price'    => $max_price_custom,
    'price_ranges' => $price_ranges ?: null,
], fn($v) => $v !== null && $v !== '' && $v !== []));
if ($current_url === 'index.php?') $current_url = 'index.php';

$items = [];
if ($user_lat !== null && $user_lng !== null) {
    $sql = "
        SELECT *
        FROM (
            SELECT 
    l.*,
    u.FirstName,
    u.LastName,
    u.ProfilePictureURL AS OwnerProfilePicture,
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
  AND ls.Status NOT IN ('Removed', 'Inactive')
    ";

    $params = [$user_lat, $user_lng, $user_lat];

    if ($isLoggedIn) {
        $sql .= " AND l.UserLenderID <> ?";
        $params[] = $currentUserId;
    }

    if (!empty($search)) {
        $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($subcategory)) {
        $sql .= " AND l.CategoryID = ?";
        $params[] = $subcategory;
    } elseif (!empty($category)) {
        $sql .= " AND (l.CategoryID = ? OR l.CategoryID IN (SELECT CategoryID FROM TCategories WHERE ParentCategoryID = ?))";
        $params[] = $category;
        $params[] = $category;
    }

    $priceConditions = [];

    if (!empty($price_ranges) && is_array($price_ranges)) {
        foreach ($price_ranges as $range) {
            switch ($range) {
                case '0-49': $priceConditions[] = "(l.PricePerDay >= 0 AND l.PricePerDay <= 49)"; break;
                case '50-99': $priceConditions[] = "(l.PricePerDay >= 50 AND l.PricePerDay <= 99)"; break;
                case '100-199': $priceConditions[] = "(l.PricePerDay >= 100 AND l.PricePerDay <= 199)"; break;
                case '200-299': $priceConditions[] = "(l.PricePerDay >= 200 AND l.PricePerDay <= 299)"; break;
                case '300-399': $priceConditions[] = "(l.PricePerDay >= 300 AND l.PricePerDay <= 399)"; break;
                case '400-499': $priceConditions[] = "(l.PricePerDay >= 400 AND l.PricePerDay <= 499)"; break;
                case '500-599': $priceConditions[] = "(l.PricePerDay >= 500 AND l.PricePerDay <= 599)"; break;
                case '600-699': $priceConditions[] = "(l.PricePerDay >= 600 AND l.PricePerDay <= 699)"; break;
                case '700-799': $priceConditions[] = "(l.PricePerDay >= 700 AND l.PricePerDay <= 799)"; break;
                case '800-899': $priceConditions[] = "(l.PricePerDay >= 800 AND l.PricePerDay <= 899)"; break;
                case '900-999': $priceConditions[] = "(l.PricePerDay >= 900 AND l.PricePerDay <= 999)"; break;
                case '1000-1499': $priceConditions[] = "(l.PricePerDay >= 1000 AND l.PricePerDay <= 1499)"; break;
                case '1500-1999': $priceConditions[] = "(l.PricePerDay >= 1500 AND l.PricePerDay <= 1999)"; break;
                case '2000-2499': $priceConditions[] = "(l.PricePerDay >= 2000 AND l.PricePerDay <= 2499)"; break;
                case '2500+': $priceConditions[] = "(l.PricePerDay >= 2500)"; break;
            }
        }
    }

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
        LIMIT 12
    ";

    $params[] = $radius;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    $sql = "
       SELECT 
    l.*,
    u.FirstName,
    u.LastName,
    u.ProfilePictureURL AS OwnerProfilePicture,
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
            NULL AS distance
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        LEFT JOIN TCategories c ON l.CategoryID = c.CategoryID
        LEFT JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        LEFT JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
       WHERE ls.Status NOT IN ('Removed', 'Inactive')
    ";

    $params = [];

    if ($isLoggedIn) {
        $sql .= " AND l.UserLenderID <> ?";
        $params[] = $currentUserId;
    }

    if (!empty($search)) {
        $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($subcategory)) {
        $sql .= " AND l.CategoryID = ?";
        $params[] = $subcategory;
    } elseif (!empty($category)) {
        $sql .= " AND (l.CategoryID = ? OR l.CategoryID IN (SELECT CategoryID FROM TCategories WHERE ParentCategoryID = ?))";
        $params[] = $category;
        $params[] = $category;
    }

    $priceConditions = [];

    if (!empty($price_ranges) && is_array($price_ranges)) {
        foreach ($price_ranges as $range) {
            switch ($range) {
                case '0-49': $priceConditions[] = "(l.PricePerDay >= 0 AND l.PricePerDay <= 49)"; break;
                case '50-99': $priceConditions[] = "(l.PricePerDay >= 50 AND l.PricePerDay <= 99)"; break;
                case '100-199': $priceConditions[] = "(l.PricePerDay >= 100 AND l.PricePerDay <= 199)"; break;
                case '200-299': $priceConditions[] = "(l.PricePerDay >= 200 AND l.PricePerDay <= 299)"; break;
                case '300-399': $priceConditions[] = "(l.PricePerDay >= 300 AND l.PricePerDay <= 399)"; break;
                case '400-499': $priceConditions[] = "(l.PricePerDay >= 400 AND l.PricePerDay <= 499)"; break;
                case '500-599': $priceConditions[] = "(l.PricePerDay >= 500 AND l.PricePerDay <= 599)"; break;
                case '600-699': $priceConditions[] = "(l.PricePerDay >= 600 AND l.PricePerDay <= 699)"; break;
                case '700-799': $priceConditions[] = "(l.PricePerDay >= 700 AND l.PricePerDay <= 799)"; break;
                case '800-899': $priceConditions[] = "(l.PricePerDay >= 800 AND l.PricePerDay <= 899)"; break;
                case '900-999': $priceConditions[] = "(l.PricePerDay >= 900 AND l.PricePerDay <= 999)"; break;
                case '1000-1499': $priceConditions[] = "(l.PricePerDay >= 1000 AND l.PricePerDay <= 1499)"; break;
                case '1500-1999': $priceConditions[] = "(l.PricePerDay >= 1500 AND l.PricePerDay <= 1999)"; break;
                case '2000-2499': $priceConditions[] = "(l.PricePerDay >= 2000 AND l.PricePerDay <= 2499)"; break;
                case '2500+': $priceConditions[] = "(l.PricePerDay >= 2500)"; break;
            }
        }
    }

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

    $sql .= " ORDER BY l.AddedDate DESC LIMIT 12";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
    <title>Community Toolkit - Rent & Share Tools Locally</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body data-logged-in="<?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>" data-has-account-location="false">
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
                    <?php if (isset($_SESSION['user_id'])): ?>
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
                    <?php endif; ?>
                </nav>

                <!-- User Section -->
                <div class="user-section">
                    <?php if (isset($_SESSION['user_id'])): ?>
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
                    <?php else: ?>
                        <a href="login.php" class="btn btn-outline">Login</a>
                        <a href="register.php" class="btn btn-primary">Sign Up</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Filters Section -->
    <section class="filters-section">
        <div class="container">
            <form action="index.php" method="GET" class="filters" id="filterForm">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                
                <div class="filter-dropdown">
                    <select name="category" id="parentCategoryIndex" class="filter-select" onchange="loadSubcategoriesIndex()">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['CategoryID']; ?>" <?php echo ($category == $cat['CategoryID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Subcategory Dropdown -->
                <div class="filter-dropdown" id="subcategoryContainerIndex" style="display: <?php echo !empty($category) ? 'block' : 'none'; ?>;">
                    <select name="subcategory" id="subcategoryIndex" class="filter-select" onchange="this.form.submit()">
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

                <?php if (!empty($search) || !empty($category) || !empty($subcategory) || !empty($price_ranges) || !empty($min_price) || !empty($max_price_custom)): ?>
                    <a href="index.php" class="btn btn-outline btn-sm">Clear All Filters</a>
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
                    <p>Try adjusting your search filters or check back later for new listings!</p>
                </div>
            <?php else: ?>
                <div class="items-column grid-2">
                    <?php foreach($items as $item): ?>
                        <div class="item-card">
                            <div class="item-header">
                                <div class="user-info">
                                    <a href="profile.php?user_id=<?php echo (int)$item['OwnerUserID']; ?>" style="text-decoration:none;display:contents;">
                                    <div class="avatar avatar-<?php echo ($item['OwnerUserID'] % 2 == 0) ? 'purple' : 'pink'; ?>">
                                        <?php if (!empty($item['OwnerProfilePicture'])): ?>
                                            <img
                                                src="<?php echo htmlspecialchars($item['OwnerProfilePicture']); ?>"
                                                alt="<?php echo htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']); ?>"
                                                style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($item['FirstName'], 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="user-name"><?php echo htmlspecialchars($item['FirstName'] . ' ' . substr($item['LastName'], 0, 1) . '.'); ?></div>
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
                                    </a>
                                </div>
                                <button class="bookmark-btn" data-listing-id="<?php echo $item['ListingID']; ?>">
                                    <i class="far fa-bookmark"></i>
                                </button>
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
                                    <?php
                                        $ext = strtolower(pathinfo(parse_url($photoUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                                        $isVideo = in_array($ext, ['mp4', 'webm', 'ogg', 'mov']);
                                    ?>
                                    <div style="<?php echo $i===0 ? '' : 'display:none;'; ?>width:100%;position:<?php echo $i===0 ? 'relative' : 'absolute'; ?>;top:0;left:0;">
                                        <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>&back=<?php echo urlencode($current_url); ?>" style="display:block;width:100%;height:100%;" onclick="sessionStorage.setItem('scrollY', window.scrollY);">
                                            <?php if ($isVideo): ?>
                                                <video 
                                                playsinline 
                                                muted  
                                                autoplay
                                                loop preload="metadata" style="width:100%;height:100%;object-fit:cover;display:block;">
                                                    <source src="<?php echo htmlspecialchars($photoUrl); ?>" type="video/mp4">
                                                </video>
                                            <?php else: ?>
                                                <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                            <?php endif; ?>
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
        <?php
$shareUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['PHP_SELF']), '/\\')
    . '/item_detail.php?id=' . (int)$item['ListingID'];
?>

<button class="action-btn"
        type="button"
        title="Share"
        onclick="shareListing('<?php echo htmlspecialchars($shareUrl, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['Title'], ENT_QUOTES); ?>')">
    <i class="fas fa-share-nodes"></i>
</button>

        <?php if (isset($_SESSION['user_id'])): ?>
         <a href="messages.php?user_id=<?php echo $item['OwnerUserID']; ?>"
   class="action-btn"
   title="Message Owner">
    <i class="fas fa-comment-dots"></i>
</a>
        <?php else: ?>
            <a href="login.php" class="action-btn" title="Login to message">
                <i class="fas fa-comment-dots"></i>
            </a>
        <?php endif; ?>
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
        <?php if (isset($item['PricePerHour']) && $item['PricePerHour'] !== null && (float)$item['PricePerHour'] > 0): ?>
            <div class="price-label">Price per hour</div>
            <div class="price">$<?php echo number_format((float)$item['PricePerHour'], 2); ?></div>

            <?php if (isset($item['PricePerDay']) && $item['PricePerDay'] !== null && (float)$item['PricePerDay'] > 0): ?>
                <div class="price-subtext">or $<?php echo number_format((float)$item['PricePerDay'], 2); ?>/day</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="price-label">Price per day</div>
            <div class="price">$<?php echo number_format((float)$item['PricePerDay'], 2); ?></div>
        <?php endif; ?>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>&back=<?php echo urlencode($current_url); ?>" class="message-btn" onclick="sessionStorage.setItem('scrollY', window.scrollY);">View Details</a>
    <?php else: ?>
        <a href="login.php" class="message-btn">Login to View</a>
    <?php endif; ?>
</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Infinite scroll sentinel -->
                <div id="scrollSentinel" style="height:40px;width:100%;display:none;"></div>
                <!-- Loading spinner (hidden until needed) -->
                <div id="loadingSpinner" style="display:none;text-align:center;padding:24px 0;color:#667eea;">
                    <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i>
                    <div style="margin-top:8px;font-size:13px;font-weight:600;">Loading more items...</div>
                </div>

            <?php endif; ?>
        </div>
    </main>
    
    <script>
        // ── Infinite scroll ───────────────────────────────────────────────────
        (function() {
            let currentOffset   = <?php echo count($items); ?>;
            let isLoading       = false;
            let hasMore         = <?php echo count($items) >= 12 ? 'true' : 'false'; ?>;
            const grid          = document.querySelector('.items-column');
            const sentinel      = document.getElementById('scrollSentinel');
            const spinner       = document.getElementById('loadingSpinner');

            if (!grid || !sentinel) return;

            // Build query string from current page filters
            function getFilterParams() {
                const params = new URLSearchParams(window.location.search);
                params.set('offset', currentOffset);
                return params.toString();
            }

            async function loadMore() {
                if (isLoading || !hasMore) return;
                isLoading = true;
                spinner.style.display = 'block';
                sentinel.style.display = 'none';

                try {
                    const url = 'api/get_listings.php?' + getFilterParams();
                    console.log('[InfiniteScroll] Fetching:', url);
                    const res  = await fetch(url);
                    const data = await res.json();
                    console.log('[InfiniteScroll] Response:', data);

                    if (data.items && data.items.length > 0) {
                        data.items.forEach(item => {
                            const card = buildCard(item);
                            grid.insertAdjacentHTML('beforeend', card);
                        });
                        currentOffset = data.nextOffset;
                        hasMore       = data.hasMore;
                    } else {
                        hasMore = false;
                    }
                } catch (e) {
                    console.error('Infinite scroll error:', e);
                }

                isLoading = false;
                spinner.style.display = 'none';
                if (hasMore) sentinel.style.display = 'block';
            }

            function buildCard(item) {
                const id        = item.ListingID;
                const photos    = item.photos && item.photos.length ? item.photos : (item.PrimaryImage ? [item.PrimaryImage] : ['https://images.unsplash.com/photo-1504148455328-c376907d081c?w=600&h=400&fit=crop']);
                const total     = photos.length;
                const firstName = (item.FirstName || '').replace(/</g,'&lt;');
                const lastName  = item.LastName || '';
                const lastInit  = lastName ? lastName[0] + '.' : '';
                const avatarClass = parseInt(item.OwnerUserID) % 2 === 0 ? 'avatar-purple' : 'avatar-pink';
                const avatarInner = item.OwnerProfilePicture
                    ? `<img src="${item.OwnerProfilePicture}" alt="${firstName}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`
                    : firstName[0]?.toUpperCase() || '?';
                const dist = (item.distance !== null && item.distance !== undefined)
                    ? `<i class="fas fa-location-dot"></i> ${parseFloat(item.distance).toFixed(1)} mi away`
                    : (item.City ? item.City : 'Cincinnati area');
                const title   = (item.Title || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                const desc    = (item.Description || '').replace(/</g,'&lt;').replace(/>/g,'&gt;').substring(0, 100);
                const cat     = (item.CategoryName || '').replace(/</g,'&lt;');
                const cond    = (item.Condition || '').replace(/</g,'&lt;');
                const backUrl = encodeURIComponent(window.location.href);
                const shareUrl = window.location.origin + '/item_detail.php?id=' + id;
                const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;

                // Price display — mirrors PHP template
                let priceHtml = '';
                const pph = parseFloat(item.PricePerHour);
                const ppd = parseFloat(item.PricePerDay);
                if (pph > 0) {
                    priceHtml = `<div class="price-label">Price per hour</div><div class="price">$${pph.toFixed(2)}</div>`;
                    if (ppd > 0) priceHtml += `<div class="price-subtext">or $${ppd.toFixed(2)}/day</div>`;
                } else {
                    priceHtml = `<div class="price-label">Price per day</div><div class="price">$${ppd.toFixed(2)}</div>`;
                }

                // Photo carousel slides
                let slides = '';
                photos.forEach((url, i) => {
                    const ext = url.split('.').pop().toLowerCase();
                    const isVid = ['mp4','webm','ogg','mov'].includes(ext);
                    const pos = i === 0 ? 'relative' : 'absolute';
                    const vis = i === 0 ? '' : 'display:none;';
                    const mediaEl = isVid
                        ? `<video playsinline muted autoplay loop preload="metadata" style="width:100%;height:100%;object-fit:cover;display:block;"><source src="${url}" type="video/mp4"></video>`
                        : `<img src="${url}" alt="${title}" loading="lazy">`;
                    slides += `<div style="${vis}width:100%;position:${pos};top:0;left:0;"><a href="item_detail.php?id=${id}&back=${backUrl}" style="display:block;width:100%;height:100%;" onclick="sessionStorage.setItem('scrollY',window.scrollY)">${mediaEl}</a></div>`;
                });

                // Prev/next/dots
                let navHtml = '';
                if (total > 1) {
                    const dots = Array.from({length: total}, (_,i) => `<span class="ci-dot${i===0?' ci-dot-on':''}"></span>`).join('');
                    navHtml = `
                        <button class="ci-prev" onclick="ciMove(${id},-1,event)" aria-label="Previous" style="visibility:hidden;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <button class="ci-next" onclick="ciMove(${id},1,event)" aria-label="Next">
                            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                        <div class="ci-dots">${dots}</div>`;
                }

                const msgBtn = isLoggedIn
                    ? `<a href="messages.php?user_id=${item.OwnerUserID}" class="action-btn" title="Message Owner"><i class="fas fa-comment-dots"></i></a>`
                    : `<a href="login.php" class="action-btn" title="Login to message"><i class="fas fa-comment-dots"></i></a>`;

                const viewBtn = isLoggedIn
                    ? `<a href="item_detail.php?id=${id}&back=${backUrl}" class="message-btn" onclick="sessionStorage.setItem('scrollY',window.scrollY)">View Details</a>`
                    : `<a href="login.php" class="message-btn">Login to View</a>`;

                return `
                <div class="item-card">
                    <div class="item-header">
                        <div class="user-info">
                            <a href="profile.php?user_id=${item.OwnerUserID}" style="text-decoration:none;display:contents;">
                                <div class="avatar ${avatarClass}">${avatarInner}</div>
                                <div>
                                    <div class="user-name">${firstName} ${lastInit}</div>
                                    <div class="user-distance">${dist}</div>
                                </div>
                            </a>
                        </div>
                        <button class="bookmark-btn" data-listing-id="${id}"><i class="far fa-bookmark"></i></button>
                    </div>
                    <div class="item-image" id="carousel-${id}" style="position:relative;overflow:hidden;">
                        <div data-total="${total}" data-current="0">${slides}</div>
                        ${navHtml}
                    </div>
                    <div class="item-footer">
                        <div class="item-actions">
                            <button class="action-btn" type="button" title="Share" onclick="shareListing('${shareUrl}','${title.replace(/'/g,"\'")}')" ><i class="fas fa-share-nodes"></i></button>
                            ${msgBtn}
                        </div>
                    </div>
                    <div class="item-details">
                        <h3 class="item-title">${title}</h3>
                        <p class="item-description">${desc}...</p>
                        <div class="item-meta">
                            <span class="item-category"><i class="fas fa-tag"></i> ${cat}</span>
                            <span class="item-condition"><i class="fas fa-check-circle"></i> ${cond}</span>
                        </div>
                        <div class="item-price-section">
                            <div>${priceHtml}</div>
                            ${viewBtn}
                        </div>
                    </div>
                </div>`;
            }

            // Observe the sentinel
            const observer = new IntersectionObserver(function(entries) {
                if (entries[0].isIntersecting) loadMore();
            }, { rootMargin: '200px' });

            if (hasMore) {
                sentinel.style.display = 'block';
                observer.observe(sentinel);
            }
        })();

        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        }
        
        // Chat placeholder - to be implemented
        function openChat() {
            // TODO: Open chat window/panel
            alert('Chat functionality coming soon!');
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
        
        // Load subcategories based on parent selection
        async function loadSubcategoriesIndex(autoSubmit = true) {
            const parentId = document.getElementById('parentCategoryIndex').value;
            const subcategoryContainer = document.getElementById('subcategoryContainerIndex');
            const subcategorySelect = document.getElementById('subcategoryIndex');

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
        
        // Grid view switcher
        document.addEventListener('DOMContentLoaded', function() {
            // Restore scroll position if returning from item detail
            const savedScroll = sessionStorage.getItem('scrollY');
            if (savedScroll !== null) {
                setTimeout(() => {
                    window.scrollTo({ top: parseInt(savedScroll), behavior: 'instant' });
                    sessionStorage.removeItem('scrollY');
                }, 100);
            }

            // Auto-load subcategories if parent category is selected
            const parentCategorySelect = document.getElementById('parentCategoryIndex');
            if (parentCategorySelect && parentCategorySelect.value) {
                loadSubcategoriesIndex(false);
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
    </script>
    
    <script src="location_manager.js"></script>
    <script>
        function ciMove(id, dir, e) {
            e.preventDefault(); e.stopPropagation();
            var el    = document.getElementById('carousel-' + id);
            var track = el.querySelector('[data-total]');
            var total = parseInt(track.dataset.total);
            var cur   = parseInt(track.dataset.current);
            var slides = track.children;
            slides[cur].style.display = 'none';
            cur = (cur + dir + total) % total;
            track.dataset.current = cur;
            slides[cur].style.display = '';
            var dots = el.querySelectorAll('.ci-dot');
            dots.forEach(function(d,i){ d.classList.toggle('ci-dot-on', i===cur); });
            var pp=el.querySelector('.ci-prev'), np=el.querySelector('.ci-next');
            var pm=el.querySelector('.ci-mob-prev'), nm=el.querySelector('.ci-mob-next');
            if(pp) pp.style.visibility = cur===0?'hidden':'visible';
            if(np) np.style.visibility = cur===total-1?'hidden':'visible';
            if(pm) pm.style.visibility = cur===0?'hidden':'visible';
            if(nm) nm.style.visibility = cur===total-1?'hidden':'visible';
        }

        // Touch swipe for carousels on mobile
        document.querySelectorAll('[id^="carousel-"]').forEach(function(el) {
            var startX = 0;
            var id = parseInt(el.id.replace('carousel-', ''));
            el.addEventListener('touchstart', function(e) {
                startX = e.touches[0].clientX;
            }, { passive: true });
            el.addEventListener('touchend', function(e) {
                var diff = startX - e.changedTouches[0].clientX;
                if (Math.abs(diff) > 40) {
                    var track = el.querySelector('[data-total]');
                    if (!track) return;
                    var fakeEvent = { preventDefault: function(){}, stopPropagation: function(){} };
                    ciMove(id, diff > 0 ? 1 : -1, fakeEvent);
                }
            }, { passive: true });
        });
    </script>
    <?php include 'includes/header_dropdowns.php'; ?>
<?php require_once 'includes/chatbot_widget.php'; ?>
<script>
async function shareListing(url, title) {
    try {
        if (navigator.share) {
            await navigator.share({
                title: title || 'Check out this item',
                text: 'Check out this item:',
                url: url
            });
            return;
        }

        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(url);
            alert('Link copied to clipboard!');
            return;
        }

        prompt('Copy this link:', url);
    } catch (err) {
        console.error(err);
        prompt('Copy this link:', url);
    }
}
</script>
</body>
</html>
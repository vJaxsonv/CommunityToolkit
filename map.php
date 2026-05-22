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
$subcategory = $_GET['subcategory'] ?? '';
$price_ranges = $_GET['price_ranges'] ?? [];
$min_price = $_GET['min_price'] ?? '';
$max_price_custom = $_GET['max_price'] ?? '';

// Privacy radius for neighborhood vicinity shown on map
$privacyRadiusMiles = 0.75;

/**
 * Create a stable obfuscated point near the neighborhood center.
 * Stable per listing so it does not jump around every refresh.
 */
function getObfuscatedCoordinates(float $lat, float $lng, int $listingId, float $radiusMiles): array
{
    $seed = crc32('listing-' . $listingId);

    // Stable angle between 0 and 2π
    $angle = (($seed % 3600) / 3600) * 2 * M_PI;

    // Stable distance between 55% and 100% of radius
    $distanceMiles = $radiusMiles * (0.55 + ((($seed >> 8) % 450) / 1000));

    $deltaLat = $distanceMiles / 69.172;
    $deltaLng = $distanceMiles / (69.172 * max(cos(deg2rad($lat)), 0.00001));

    $obfLat = $lat + sin($angle) * $deltaLat;
    $obfLng = $lng + cos($angle) * $deltaLng;

    return [$obfLat, $obfLng];
}

// Build query
$sql = "SELECT l.ListingID,
        l.UserLenderID,
        l.NeighborhoodID,
        l.CategoryID,
        l.ListingStatusID,
        l.Title,
        l.Description,
        l.ConditionID,
        l.RateTypeID,
        l.PricePerDay,
        l.PricePerHour,
        l.AddedDate,
        u.FirstName,
        u.LastName,
        u.UserID AS OwnerUserID,
        c.CategoryName,
        c.ParentCategoryID,
        cond.`Condition`,
        ls.Status,
        n.NeighborhoodName,
        n.City,
        n.CenterLatitude,
        n.CenterLongitude,
        (SELECT PhotoURL
         FROM TListingPhotos
         WHERE ListingID = l.ListingID
         ORDER BY SortOrder
         LIMIT 1) AS PrimaryImage
        FROM TListings l
        LEFT JOIN TUsers u ON l.UserLenderID = u.UserID
        LEFT JOIN TCategories c ON l.CategoryID = c.CategoryID
        LEFT JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        LEFT JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON l.NeighborhoodID = n.NeighborhoodID
        WHERE l.ListingStatusID IN (1, 2, 3)
        AND l.UserLenderID != ?";

$params = [];
$params[] = $_SESSION['user_id'];

// Search filter
if (!empty($search)) {
    $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Category / subcategory filter
if (!empty($subcategory)) {
    $sql .= " AND l.CategoryID = ?";
    $params[] = $subcategory;
} elseif (!empty($category)) {
    $sql .= " AND (l.CategoryID = ? OR c.ParentCategoryID = ?)";
    $params[] = $category;
    $params[] = $category;
}

// Price range filters
$priceConditions = [];

if (!empty($price_ranges) && is_array($price_ranges)) {
    foreach ($price_ranges as $range) {
        switch ($range) {
            case '0-49':
                $priceConditions[] = "((l.PricePerDay >= 0 AND l.PricePerDay <= 49) OR (l.PricePerHour >= 0 AND l.PricePerHour <= 49))";
                break;
            case '50-99':
                $priceConditions[] = "((l.PricePerDay >= 50 AND l.PricePerDay <= 99) OR (l.PricePerHour >= 50 AND l.PricePerHour <= 99))";
                break;
            case '100-199':
                $priceConditions[] = "((l.PricePerDay >= 100 AND l.PricePerDay <= 199) OR (l.PricePerHour >= 100 AND l.PricePerHour <= 199))";
                break;
            case '200-299':
                $priceConditions[] = "((l.PricePerDay >= 200 AND l.PricePerDay <= 299) OR (l.PricePerHour >= 200 AND l.PricePerHour <= 299))";
                break;
            case '300-399':
                $priceConditions[] = "((l.PricePerDay >= 300 AND l.PricePerDay <= 399) OR (l.PricePerHour >= 300 AND l.PricePerHour <= 399))";
                break;
            case '400-499':
                $priceConditions[] = "((l.PricePerDay >= 400 AND l.PricePerDay <= 499) OR (l.PricePerHour >= 400 AND l.PricePerHour <= 499))";
                break;
            case '500-599':
                $priceConditions[] = "((l.PricePerDay >= 500 AND l.PricePerDay <= 599) OR (l.PricePerHour >= 500 AND l.PricePerHour <= 599))";
                break;
            case '600-699':
                $priceConditions[] = "((l.PricePerDay >= 600 AND l.PricePerDay <= 699) OR (l.PricePerHour >= 600 AND l.PricePerHour <= 699))";
                break;
            case '700-799':
                $priceConditions[] = "((l.PricePerDay >= 700 AND l.PricePerDay <= 799) OR (l.PricePerHour >= 700 AND l.PricePerHour <= 799))";
                break;
            case '800-899':
                $priceConditions[] = "((l.PricePerDay >= 800 AND l.PricePerDay <= 899) OR (l.PricePerHour >= 800 AND l.PricePerHour <= 899))";
                break;
            case '900-999':
                $priceConditions[] = "((l.PricePerDay >= 900 AND l.PricePerDay <= 999) OR (l.PricePerHour >= 900 AND l.PricePerHour <= 999))";
                break;
            case '1000-1499':
                $priceConditions[] = "((l.PricePerDay >= 1000 AND l.PricePerDay <= 1499) OR (l.PricePerHour >= 1000 AND l.PricePerHour <= 1499))";
                break;
            case '1500-1999':
                $priceConditions[] = "((l.PricePerDay >= 1500 AND l.PricePerDay <= 1999) OR (l.PricePerHour >= 1500 AND l.PricePerHour <= 1999))";
                break;
            case '2000-2499':
                $priceConditions[] = "((l.PricePerDay >= 2000 AND l.PricePerDay <= 2499) OR (l.PricePerHour >= 2000 AND l.PricePerHour <= 2499))";
                break;
            case '2500+':
                $priceConditions[] = "(l.PricePerDay >= 2500 OR l.PricePerHour >= 2500)";
                break;
        }
    }
}

if ($min_price !== '' || $max_price_custom !== '') {
    $customCondition = [];

    if ($min_price !== '') {
        $customCondition[] = "((l.PricePerDay IS NOT NULL AND l.PricePerDay >= ?) OR (l.PricePerHour IS NOT NULL AND l.PricePerHour >= ?))";
        $params[] = $min_price;
        $params[] = $min_price;
    }

    if ($max_price_custom !== '') {
        $customCondition[] = "((l.PricePerDay IS NOT NULL AND l.PricePerDay <= ?) OR (l.PricePerHour IS NOT NULL AND l.PricePerHour <= ?))";
        $params[] = $max_price_custom;
        $params[] = $max_price_custom;
    }

    if (!empty($customCondition)) {
        $priceConditions[] = "(" . implode(" AND ", $customCondition) . ")";
    }
}

if (!empty($priceConditions)) {
    $sql .= " AND (" . implode(" OR ", $priceConditions) . ")";
}

$sql .= " ORDER BY l.AddedDate DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parent categories
$categories = $pdo->query("
    SELECT CategoryID, CategoryName
    FROM TCategories
    WHERE ParentCategoryID = 0
    ORDER BY CategoryName
")->fetchAll(PDO::FETCH_ASSOC);

// Preload subcategories in PHP so the dropdown does not flicker on page reload
$subcategories = [];

if (!empty($category)) {
    $subStmt = $pdo->prepare("
        SELECT CategoryID, CategoryName
        FROM TCategories
        WHERE ParentCategoryID = ?
        ORDER BY CategoryName
    ");
    $subStmt->execute([$category]);
    $subcategories = $subStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Convert listings for map JS
$mapListings = [];
$coordCounts = [];

foreach ($items as $item) {
    $lat = $item['CenterLatitude'] ?? null;
    $lng = $item['CenterLongitude'] ?? null;
    $hasExactLocation = true;

    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        continue;
    }

    $lat = (float)$lat;
    $lng = (float)$lng;

    $price = 0;
    $rateLabel = '';

    if (!empty($item['PricePerDay'])) {
        $price = (float)$item['PricePerDay'];
        $rateLabel = '/day';
    } elseif (!empty($item['PricePerHour'])) {
        $price = (float)$item['PricePerHour'];
        $rateLabel = '/hour';
    }

    $displayLat = $lat;
    $displayLng = $lng;
    $privacyMode = false;

    if ($hasExactLocation) {
        [$displayLat, $displayLng] = getObfuscatedCoordinates(
            $lat,
            $lng,
            (int)$item['ListingID'],
            $privacyRadiusMiles
        );
        $privacyMode = true;
    }

    $mapListings[] = [
        'id' => (int)$item['ListingID'],
        'title' => $item['Title'],
        'category' => $item['CategoryName'] ?? 'Uncategorized',
        'price' => $price,
        'rateLabel' => $rateLabel,
        'neighborhood' => $item['NeighborhoodName'] ?? '',
        'city' => $item['City'] ?? '',
        'hasExactLocation' => $hasExactLocation,
        'privacyMode' => $privacyMode,
        'privacyRadiusMiles' => $privacyMode ? $privacyRadiusMiles : 0,
        'locationLabel' => trim(($item['NeighborhoodName'] ?? '') . (!empty($item['City']) ? ', ' . $item['City'] : '')),
        'coords' => [$displayLng, $displayLat],
        'centerCoords' => [$lng, $lat],
        'image' => !empty($item['PrimaryImage']) ? $item['PrimaryImage'] : 'images/default-listing.jpg',
        'url' => 'item_detail.php?id=' . (int)$item['ListingID'] . '&back=' . urlencode($_SERVER['REQUEST_URI'])
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Map - Community Toolkit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1,maximum-scale=1,user-scalable=no">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.19.1/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.19.1/mapbox-gl.js"></script>
    <script id="search-js" defer src="https://api.mapbox.com/search-js/v1.5.0/web.js"></script>
    <link href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.min.js"></script>

   
</head>

<body>
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height: 50px; width: auto;">
                </a>

                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <form action="map.php" method="GET">
                        <input type="text" name="search" placeholder="Search for items near you..." class="search-input" value="<?php echo htmlspecialchars($search); ?>">
                    </form>
                </div>

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
                    <a href="my_rentals.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>My Rentals</span>
                    </a>
                </nav>

                <div class="user-section">
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">0</span>
                    </div>
                    <div class="notification-icon" onclick="openChat()" style="cursor: pointer;" title="Messages">
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

    <section class="filters-section">
        <div class="container">
            <form action="map.php" method="GET" class="filters" id="filterForm">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">

                <div class="filter-dropdown">
                    <select name="category" id="parentCategoryHome" class="filter-select" onchange="onParentCategoryChange()">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['CategoryID']; ?>" <?php echo ($category == $cat['CategoryID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-dropdown" id="subcategoryContainerHome" style="<?php echo (!empty($subcategories)) ? 'display: block;' : 'display: none;'; ?>">
                    <select name="subcategory" id="subcategoryHome" class="filter-select" onchange="requestAnimationFrame(() => document.getElementById('filterForm').submit())">
                        <option value="">All Subcategories</option>
                        <?php foreach ($subcategories as $sub): ?>
                            <option value="<?php echo $sub['CategoryID']; ?>" <?php echo ($subcategory == $sub['CategoryID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sub['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-dropdown price-filter-dropdown">
                    <button type="button" class="filter-select" onclick="togglePriceFilter(event)">
                        <span>Price Range</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="price-filter-menu" id="priceFilterMenu" style="display: none;">
                        <div class="price-filter-section" style="max-height: 300px; overflow-y: auto;">
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="0-49" <?php echo in_array('0-49', $price_ranges) ? 'checked' : ''; ?>><span>$0 - $49</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="50-99" <?php echo in_array('50-99', $price_ranges) ? 'checked' : ''; ?>><span>$50 - $99</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="100-199" <?php echo in_array('100-199', $price_ranges) ? 'checked' : ''; ?>><span>$100 - $199</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="200-299" <?php echo in_array('200-299', $price_ranges) ? 'checked' : ''; ?>><span>$200 - $299</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="300-399" <?php echo in_array('300-399', $price_ranges) ? 'checked' : ''; ?>><span>$300 - $399</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="400-499" <?php echo in_array('400-499', $price_ranges) ? 'checked' : ''; ?>><span>$400 - $499</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="500-599" <?php echo in_array('500-599', $price_ranges) ? 'checked' : ''; ?>><span>$500 - $599</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="600-699" <?php echo in_array('600-699', $price_ranges) ? 'checked' : ''; ?>><span>$600 - $699</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="700-799" <?php echo in_array('700-799', $price_ranges) ? 'checked' : ''; ?>><span>$700 - $799</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="800-899" <?php echo in_array('800-899', $price_ranges) ? 'checked' : ''; ?>><span>$800 - $899</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="900-999" <?php echo in_array('900-999', $price_ranges) ? 'checked' : ''; ?>><span>$900 - $999</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="1000-1499" <?php echo in_array('1000-1499', $price_ranges) ? 'checked' : ''; ?>><span>$1,000 - $1,499</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="1500-1999" <?php echo in_array('1500-1999', $price_ranges) ? 'checked' : ''; ?>><span>$1,500 - $1,999</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="2000-2499" <?php echo in_array('2000-2499', $price_ranges) ? 'checked' : ''; ?>><span>$2,000 - $2,499</span></label>
                            <label class="filter-checkbox"><input type="checkbox" name="price_ranges[]" value="2500+" <?php echo in_array('2500+', $price_ranges) ? 'checked' : ''; ?>><span>$2,500+</span></label>
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

                <div class="filter-dropdown">
                    <select id="radiusMiles" class="filter-select">
                        <option value="">No Radius</option>
                        <option value="5">5 miles</option>
                        <option value="10">10 miles</option>
                        <option value="25">25 miles</option>
                        <option value="50">50 miles</option>
                    </select>
                </div>

                <?php if (!empty($search) || !empty($category) || !empty($subcategory) || !empty($price_ranges) || $min_price !== '' || $max_price_custom !== ''): ?>
                    <a href="map.php" class="btn btn-outline btn-sm">Clear All Filters</a>
                <?php endif; ?>
                
            
            </form>
        </div>
    </section>

    <div class="map-layout" id="mapLayout">
        <button type="button" class="mobile-listings-toggle" id="mobileListingsToggle" aria-label="Show listings" aria-expanded="false">
            <i class="fas fa-list"></i>
            <span>Browse Listings</span>
        </button>

        <div class="mobile-sheet-backdrop" id="mobileSheetBackdrop"></div>

        <div class="mobile-detail-sheet" id="mobileDetailSheet" aria-live="polite">
            <button type="button" class="mobile-sheet-close" id="mobileDetailClose" aria-label="Close details">
                <i class="fas fa-times"></i>
            </button>

            <div class="mobile-detail-image-wrap" id="mobileDetailImageWrap">
                <img src="" alt="" id="mobileDetailImage" class="mobile-detail-image">
            </div>

            <div class="mobile-detail-content">
                <h3 id="mobileDetailTitle"></h3>
                <p class="mobile-detail-price" id="mobileDetailPrice"></p>
                <p class="mobile-detail-meta" id="mobileDetailMeta"></p>
                <a href="#" class="mobile-detail-btn" id="mobileDetailLink">View Listing</a>
            </div>
        </div>

        <aside class="map-sidebar" id="mapSidebar" aria-hidden="true">
            <div class="map-sidebar-header" id="mapSidebarHeader">
                <div>
                    <h2>Nearby Listings</h2>
                    <div class="radius-status" id="radiusStatus"></div>
                </div>
                <button type="button" class="map-sidebar-close" id="mapSidebarClose" aria-label="Close listings">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="listingSidebar"></div>
        </aside>

        <div id="map"></div>
    </div>

    <script> 
      

        const listings = <?php echo json_encode($mapListings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        const mapLayout = document.getElementById('mapLayout');
        const sidebar = document.getElementById('listingSidebar');
        const mobileListingsToggle = document.getElementById('mobileListingsToggle');
        const mapSidebar = document.getElementById('mapSidebar');
        const mapSidebarHeader = document.getElementById('mapSidebarHeader');
        const mapSidebarClose = document.getElementById('mapSidebarClose');

        const mobileSheetBackdrop = document.getElementById('mobileSheetBackdrop');
        const mobileDetailSheet = document.getElementById('mobileDetailSheet');
        const mobileDetailClose = document.getElementById('mobileDetailClose');
        const mobileDetailTitle = document.getElementById('mobileDetailTitle');
        const mobileDetailPrice = document.getElementById('mobileDetailPrice');
        const mobileDetailMeta = document.getElementById('mobileDetailMeta');
        const mobileDetailLink = document.getElementById('mobileDetailLink');
        const mobileDetailImage = document.getElementById('mobileDetailImage');
        const mobileDetailImageWrap = document.getElementById('mobileDetailImageWrap');
        const radiusMilesSelect = document.getElementById('radiusMiles');
        const radiusStatus = document.getElementById('radiusStatus');

        const markerRefs = {};
        const markerObjects = {};
        const popupRefs = {};

        let suppressNextMapClick = false;
        let radiusCenter = null;
        let activeRadiusMiles = 0;
        let selectedListingId = null;
        let searchAreaActive = false;

        const map = new mapboxgl.Map({
            container: 'map',
            style: 'mapbox://styles/mapbox/standard',
            projection: 'globe',
            zoom: 11,
            center: [-84.5120, 39.1031]
        });

        map.addControl(new mapboxgl.NavigationControl());

        map.on('style.load', () => {
            map.setFog({});
        });

        function isMobileView() {
            return window.innerWidth <= 768;
        }

        function formatPrice(listing) {
            const priceValue = Number(listing.price || 0);
            if (priceValue <= 0) return 'See listing';
            return `$${priceValue}${listing.rateLabel || ''}`;
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function setMobileMapHeight() {
            if (!mapLayout) return;

            if (!isMobileView()) {
                mapLayout.style.height = 'calc(100vh - 70px)';
                map.resize();
                return;
            }

            const header = document.querySelector('.main-header');
            const filters = document.querySelector('.filters-section');

            const headerHeight = header ? header.offsetHeight : 0;
            const filtersHeight = filters ? filters.offsetHeight : 0;
            const availableHeight = window.innerHeight - headerHeight - filtersHeight;

            mapLayout.style.height = Math.max(availableHeight, 260) + 'px';
            map.resize();
        }

        function isMobileSidebarOpen() {
            return isMobileView() && mapSidebar && mapSidebar.classList.contains('open');
        }

        function showBackdrop() {
            if (mobileSheetBackdrop && isMobileView()) {
                mobileSheetBackdrop.classList.add('show');
            }
        }

        function hideBackdrop() {
            if (mobileSheetBackdrop) {
                mobileSheetBackdrop.classList.remove('show');
            }
        }

        function clearActiveStates() {
            document.querySelectorAll('.listing-preview').forEach(card => {
                card.classList.remove('active');
            });

            selectedListingId = null;
            updateVicinityBubbles();
        }

        function hideMobileDetailSheet() {
            if (mobileDetailSheet) {
                mobileDetailSheet.classList.remove('show');
            }
        }

        function buildLocationText(listing) {
            return [listing.neighborhood, listing.city].filter(Boolean).join(', ');
        }

        function showMobileDetailSheet(listing) {
            if (!mobileDetailSheet || !listing) return;

            mobileDetailTitle.textContent = listing.title || '';
            mobileDetailPrice.textContent = formatPrice(listing);
            mobileDetailMeta.textContent = [
                listing.category,
                buildLocationText(listing)
            ].filter(Boolean).join(' • ');

            mobileDetailLink.href = listing.url || '#';

            if (listing.image) {
                mobileDetailImage.src = listing.image;
                mobileDetailImage.alt = listing.title || 'Listing image';
                mobileDetailImageWrap.style.display = '';
            } else {
                mobileDetailImageWrap.style.display = 'none';
            }

            mobileDetailSheet.classList.add('show');
            showBackdrop();
            syncMobileToggle();
        }

        function hideAllMobilePanels() {
            hideMobileDetailSheet();

            if (mapSidebar) {
                mapSidebar.classList.remove('open');
                mapSidebar.classList.remove('dragging');
                mapSidebar.style.transform = '';
                mapSidebar.setAttribute('aria-hidden', 'true');
            }

            if (mapLayout) {
                mapLayout.classList.remove('mobile-list-open');
            }

            hideBackdrop();
            map.resize();
            syncMobileToggle();
        }

        function closeAllPopups() {
            Object.values(popupRefs).forEach(popup => {
                popup.remove();
            });
        }

        function syncMobileToggle() {
            if (!mobileListingsToggle || !mapSidebar) return;

            if (!isMobileView()) {
                mobileListingsToggle.classList.add('hidden');
                mobileListingsToggle.setAttribute('aria-expanded', 'false');
                return;
            }

            const isDrawerOpen = isMobileSidebarOpen();
            mobileListingsToggle.setAttribute('aria-expanded', isDrawerOpen ? 'true' : 'false');

            if (isDrawerOpen) {
                mobileListingsToggle.classList.add('hidden');
            } else {
                mobileListingsToggle.classList.remove('hidden');
                mobileListingsToggle.innerHTML = '<i class="fas fa-list"></i><span>Browse Listings</span>';
            }
        }

        function closeMobileSidebar() {
            if (isMobileView() && mapSidebar) {
                mapSidebar.classList.remove('open');
                mapSidebar.classList.remove('dragging');
                mapSidebar.style.transform = '';
                mapSidebar.setAttribute('aria-hidden', 'true');
                mapLayout.classList.remove('mobile-list-open');

                if (!mobileDetailSheet.classList.contains('show')) {
                    hideBackdrop();
                }

                map.resize();
                syncMobileToggle();
            }
        }

        function openMobileSidebar() {
            if (isMobileView() && mapSidebar) {
                hideMobileDetailSheet();
                mapSidebar.classList.remove('dragging');
                mapSidebar.style.transform = '';
                mapSidebar.classList.add('open');
                mapSidebar.setAttribute('aria-hidden', 'false');
                mapLayout.classList.add('mobile-list-open');
                showBackdrop();
                syncMobileToggle();
            }
        }

        function toggleMobileSidebar(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            if (!isMobileView() || !mapSidebar) return;

            if (mapSidebar.classList.contains('open')) {
                closeMobileUI();
            } else {
                openMobileSidebar();
            }
        }

        function milesBetween(lat1, lon1, lat2, lon2) {
            const toRad = (deg) => deg * Math.PI / 180;
            const R = 3958.8;

            const dLat = toRad(lat2 - lat1);
            const dLon = toRad(lon2 - lon1);

            const a =
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);

            return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        function createGeoJSONCircle(center, radiusMiles, points = 64) {
            const lng = center[0];
            const lat = center[1];
            const coords = [];
            const distanceX = radiusMiles / (69.172 * Math.cos(lat * Math.PI / 180));
            const distanceY = radiusMiles / 69.172;

            for (let i = 0; i < points; i++) {
                const theta = (i / points) * Math.PI * 2;
                const circleLng = lng + (distanceX * Math.cos(theta));
                const circleLat = lat + (distanceY * Math.sin(theta));
                coords.push([circleLng, circleLat]);
            }

            coords.push(coords[0]);

            return {
                type: 'FeatureCollection',
                features: [{
                    type: 'Feature',
                    geometry: {
                        type: 'Polygon',
                        coordinates: [coords]
                    }
                }]
            };
        }

        function createCircleFeature(center, radiusMiles, points = 48) {
            const lng = center[0];
            const lat = center[1];
            const coords = [];
            const distanceX = radiusMiles / (69.172 * Math.cos(lat * Math.PI / 180));
            const distanceY = radiusMiles / 69.172;

            for (let i = 0; i < points; i++) {
                const theta = (i / points) * Math.PI * 2;
                const circleLng = lng + (distanceX * Math.cos(theta));
                const circleLat = lat + (distanceY * Math.sin(theta));
                coords.push([circleLng, circleLat]);
            }

            coords.push(coords[0]);

            return {
                type: 'Feature',
                properties: {},
                geometry: {
                    type: 'Polygon',
                    coordinates: [coords]
                }
            };
        }

        function ensureRadiusLayers() {
            if (!map.getSource('radius-source')) {
                map.addSource('radius-source', {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: []
                    }
                });
            }

            if (!map.getLayer('radius-fill')) {
                map.addLayer({
                    id: 'radius-fill',
                    type: 'fill',
                    source: 'radius-source',
                    paint: {
                        'fill-color': '#667eea',
                        'fill-opacity': 0.12
                    }
                });
            }

            if (!map.getLayer('radius-outline')) {
                map.addLayer({
                    id: 'radius-outline',
                    type: 'line',
                    source: 'radius-source',
                    paint: {
                        'line-color': '#667eea',
                        'line-width': 2
                    }
                });
            }
        }

        function ensureVicinityLayers() {
            if (!map.getSource('listing-vicinity-source')) {
                map.addSource('listing-vicinity-source', {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: []
                    }
                });
            }

            if (!map.getLayer('listing-vicinity-fill')) {
                map.addLayer({
                    id: 'listing-vicinity-fill',
                    type: 'fill',
                    source: 'listing-vicinity-source',
                    paint: {
                        'fill-color': '#7c4dff',
                        'fill-opacity': 0.22
                    }
                });
            }

            if (!map.getLayer('listing-vicinity-outline')) {
                map.addLayer({
                    id: 'listing-vicinity-outline',
                    type: 'line',
                    source: 'listing-vicinity-source',
                    paint: {
                        'line-color': '#7c4dff',
                        'line-width': 3,
                        'line-opacity': 0.95
                    }
                });
            }
        }

        function getVisibleListings() {
            if (radiusCenter && activeRadiusMiles) {
                return listings.filter(listing => {
                    const coords = listing.centerCoords || listing.coords;
                    const distance = milesBetween(
                        radiusCenter[1],
                        radiusCenter[0],
                        coords[1],
                        coords[0]
                    );
                    return distance <= activeRadiusMiles;
                });
            }

            if (searchAreaActive && map && map.isStyleLoaded()) {
                const bounds = map.getBounds();

                return listings.filter(listing => {
                    const coords = listing.centerCoords || listing.coords;
                    return bounds.contains(coords);
                });
            }

            return listings;
        }

        function updateRadiusStatus() {
            if (!radiusStatus) return;

            if (!radiusCenter || !activeRadiusMiles) {
                radiusStatus.textContent = '';
                return;
            }

            const count = getVisibleListings().length;
            radiusStatus.textContent = `${count} listing${count === 1 ? '' : 's'} within ${activeRadiusMiles} mile${activeRadiusMiles === 1 ? '' : 's'}`;
        }

        function updateRadiusBubble() {
            activeRadiusMiles = Number(radiusMilesSelect?.value || 0);

            if (!map.isStyleLoaded()) return;

            ensureRadiusLayers();

            if (!radiusCenter || !activeRadiusMiles) {
                map.getSource('radius-source').setData({
                    type: 'FeatureCollection',
                    features: []
                });
                updateRadiusStatus();
                buildMarkers();
                return;
            }

            const circleGeoJSON = createGeoJSONCircle(radiusCenter, activeRadiusMiles);
            map.getSource('radius-source').setData(circleGeoJSON);

            updateRadiusStatus();
            buildMarkers();
        }

        function updateVicinityBubbles() {
            if (!map.isStyleLoaded()) return;

            ensureVicinityLayers();

            const visibleListings = getVisibleListings();

            const features = visibleListings
                .filter(listing =>
                    Number(listing.id) === Number(selectedListingId) &&
                    Number(listing.privacyRadiusMiles) > 0
                )
                .map(listing => createCircleFeature(
                    listing.centerCoords || listing.coords,
                    Number(listing.privacyRadiusMiles)
                ));

            map.getSource('listing-vicinity-source').setData({
                type: 'FeatureCollection',
                features
            });
        }

        function focusListing(listingId) {
            const visibleListings = getVisibleListings();
            const listing = visibleListings.find(item => Number(item.id) === Number(listingId)) ||
                            listings.find(item => Number(item.id) === Number(listingId));

            if (!listing) return;

            selectedListingId = listing.id;
            updateVicinityBubbles();

            clearActiveStates();
            selectedListingId = listing.id;
            updateVicinityBubbles();

            const card = document.querySelector(`.listing-preview[data-id="${listingId}"]`);
            if (card) {
                card.classList.add('active');

                if (!isMobileView()) {
                    const sidebarRect = sidebar.getBoundingClientRect();
                    const cardRect = card.getBoundingClientRect();

                    const cardAbove = cardRect.top < sidebarRect.top;
                    const cardBelow = cardRect.bottom > sidebarRect.bottom;

                    if (cardAbove || cardBelow) {
                        card.scrollIntoView({ behavior: 'auto', block: 'nearest' });
                    }
                }
            }

            if (isMobileView()) {
                if (isMobileSidebarOpen()) {
                    closeMobileSidebar();
                }

                showMobileDetailSheet(listing);

              map.easeTo({
    center: listing.centerCoords || listing.coords,
    duration: 400
});

                return;
            }

            closeAllPopups();

            if (popupRefs[listingId]) {
                popupRefs[listingId].addTo(map);
            }

          map.easeTo({
    center: listing.centerCoords || listing.coords,
    duration: 400
});
        }

        function buildMarkers() {
            sidebar.innerHTML = '';

            Object.values(markerObjects).forEach(marker => marker.remove());

            for (const key in markerRefs) delete markerRefs[key];
            for (const key in markerObjects) delete markerObjects[key];
            for (const key in popupRefs) delete popupRefs[key];

            const visibleListings = getVisibleListings();

            if (visibleListings.length === 0) {
                sidebar.innerHTML = '<p>No listings found in this area.</p>';
                return;
            }

            visibleListings.forEach(listing => {
                const safeTitle = escapeHtml(listing.title);
                const safeCategory = escapeHtml(listing.category);
                const safeNeighborhood = escapeHtml(listing.neighborhood);
                const safeCity = escapeHtml(listing.city);
                const safeImage = escapeHtml(listing.image);
                const safeUrl = escapeHtml(listing.url);
                const displayPrice = formatPrice(listing);
                const locationText = buildLocationText(listing);

                const card = document.createElement('div');
                card.className = 'listing-preview';
                card.setAttribute('data-id', listing.id);
               card.innerHTML = `
    <img src="${safeImage}" alt="${safeTitle}" onerror="this.src='images/default-listing.jpg'">
    <div class="listing-preview-content">
        <h3>${safeTitle}</h3>
        <p>${safeCategory}</p>
        <p>${escapeHtml(locationText)}</p>
        <p style="font-size:12px; color:#666; margin-top:2px;">
            Approximate location of item
        </p>
        <p class="listing-price">${escapeHtml(displayPrice)}</p>
        <a href="${safeUrl}" class="popup-btn" style="margin-top:10px; display:inline-block;">
            View Listing
        </a>
    </div>
`;
card.querySelector('a').addEventListener('click', (e) => {
    e.stopPropagation();
});

card.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    focusListing(listing.id);
});
                card.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    focusListing(listing.id);
                });
                sidebar.appendChild(card);

                const popupLocation = `${safeNeighborhood}${safeNeighborhood && safeCity ? ', ' : ''}${safeCity}`;

                const popupHTML = `
                    <div class="map-popup">
                        <div class="popup-image-wrap">
                            <img src="${safeImage}" alt="${safeTitle}" class="popup-image"
                                 onerror="this.closest('.popup-image-wrap').style.display='none'">
                        </div>
                        <div class="popup-content">
                            <h3>${safeTitle}</h3>
                            <p><strong>Category:</strong> ${safeCategory}</p>
                            <p><strong>Price:</strong> ${escapeHtml(displayPrice)}</p>
                            <p><strong>Location:</strong> ${popupLocation}</p>
                            <a href="${safeUrl}" class="popup-btn">View Listing</a>
                        </div>
                    </div>
                `;

                popupRefs[listing.id] = new mapboxgl.Popup({
                    offset: 25,
                    closeOnClick: true,
                    maxWidth: '260px'
                }).setHTML(popupHTML);
            });

            if (!searchAreaActive || activeRadiusMiles) {
                const bounds = new mapboxgl.LngLatBounds();
                visibleListings.forEach(listing => bounds.extend(listing.coords));

                if (radiusCenter && activeRadiusMiles) {
                    bounds.extend(radiusCenter);
                }

                if (!bounds.isEmpty()) {
                    map.fitBounds(bounds, {
                        padding: 50,
                        maxZoom: 13,
                        duration: 0
                    });
                }
            }

            updateVicinityBubbles();
        }

        function setupSidebarSwipeToClose() {
            if (!mapSidebar || !mapSidebarHeader) return;

            let startY = 0;
            let startX = 0;
            let currentY = 0;
            let dragging = false;
            let startedFromHeader = false;

            function onTouchStart(e) {
                if (!isMobileView()) return;
                if (!mapSidebar.classList.contains('open')) return;

                const touch = e.touches[0];
                const headerRect = mapSidebarHeader.getBoundingClientRect();

                startedFromHeader =
                    touch.clientY >= headerRect.top &&
                    touch.clientY <= headerRect.bottom;

                if (!startedFromHeader) return;

                startY = touch.clientY;
                startX = touch.clientX;
                currentY = startY;
                dragging = true;
                mapSidebar.classList.add('dragging');
            }

            function onTouchMove(e) {
                if (!dragging || !startedFromHeader) return;

                const touch = e.touches[0];
                currentY = touch.clientY;

                const deltaY = currentY - startY;
                const deltaX = touch.clientX - startX;

                if (Math.abs(deltaY) < Math.abs(deltaX)) return;

                if (deltaY > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    mapSidebar.style.transform = `translateY(${deltaY}px)`;
                } else {
                    mapSidebar.style.transform = 'translateY(0)';
                }
            }

            function onTouchEnd() {
                if (!dragging) return;

                const deltaY = currentY - startY;
                dragging = false;
                startedFromHeader = false;
                mapSidebar.classList.remove('dragging');

                if (deltaY > 80) {
                    clearActiveStates();
                    hideAllMobilePanels();
                } else {
                    mapSidebar.style.transform = '';
                }
            }

            mapSidebarHeader.addEventListener('touchstart', onTouchStart, { passive: true });
            mapSidebarHeader.addEventListener('touchmove', onTouchMove, { passive: false });
            mapSidebarHeader.addEventListener('touchend', onTouchEnd, { passive: true });
            mapSidebarHeader.addEventListener('touchcancel', onTouchEnd, { passive: true });
        }

        function closeMobileUI(event = null) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            clearActiveStates();
            hideAllMobilePanels();
        }

        map.on('click', (e) => {
            const target = e.originalEvent && e.originalEvent.target ? e.originalEvent.target : null;

            if (
                target &&
                (
                    target.closest('.price-marker') ||
                    target.closest('.mobile-detail-sheet') ||
                    target.closest('.map-sidebar') ||
                    target.closest('.mobile-listings-toggle') ||
                    target.closest('.mapboxgl-popup')
                )
            ) {
                return;
            }

            if (isMobileView()) {
                if (suppressNextMapClick) {
                    suppressNextMapClick = false;
                    return;
                }

                clearActiveStates();
                hideAllMobilePanels();
                return;
            }

            clearActiveStates();
            closeAllPopups();
        });

        map.on('dragstart', () => {
            if (isMobileView()) {
                hideAllMobilePanels();
            }
        });

        const searchBox = document.querySelector('mapbox-search-box');
        if (searchBox) {
            searchBox.accessToken = mapboxgl.accessToken;
            searchBox.mapboxgl = mapboxgl;

            searchBox.addEventListener('retrieve', (e) => {
                const result = e.detail && e.detail.features ? e.detail.features[0] : null;
                if (!result || !result.geometry || !result.geometry.coordinates) return;

                const coords = result.geometry.coordinates;
                radiusCenter = coords;
                searchAreaActive = true;

                if (isMobileView()) {
                    clearActiveStates();
                    hideAllMobilePanels();
                }

                const refreshListings = () => {
                    if (activeRadiusMiles) {
                        updateRadiusBubble();
                    } else {
                        updateRadiusStatus();
                        buildMarkers();
                    }
                };

                const bbox = result.bbox;

                if (Array.isArray(bbox) && bbox.length === 4) {
                    map.once('moveend', refreshListings);
                    map.fitBounds(
                        [
                            [bbox[0], bbox[1]],
                            [bbox[2], bbox[3]]
                        ],
                        {
                            padding: 50,
                            duration: 500
                        }
                    );
                } else {
                    map.once('moveend', refreshListings);
                    map.easeTo({
                        center: coords,
                        zoom: 10,
                        duration: 500
                    });
                }
            });
        }

        if (radiusMilesSelect) {
            radiusMilesSelect.addEventListener('change', () => {
                if (!radiusCenter) {
                    const center = map.getCenter();
                    radiusCenter = [center.lng, center.lat];
                }
                updateRadiusBubble();
            });
        }

        if (mobileListingsToggle && mapSidebar) {
            mobileListingsToggle.addEventListener('click', toggleMobileSidebar);
        }

        if (mapSidebarClose) {
            mapSidebarClose.addEventListener('click', closeMobileUI);
        }

        if (mobileDetailClose) {
            mobileDetailClose.addEventListener('click', closeMobileUI);
        }

        if (mobileSheetBackdrop) {
            mobileSheetBackdrop.addEventListener('click', closeMobileUI);
        }

        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

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
            document.getElementById('filterForm').submit();
        }

        async function populateSubcategories(parentId, selectedSubcategory = '') {
            const subcategoryContainer = document.getElementById('subcategoryContainerHome');
            const subcategorySelect = document.getElementById('subcategoryHome');

            subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';

            if (!parentId) {
                subcategoryContainer.style.display = 'none';
                return;
            }

            try {
                const response = await fetch(`get_subcategories.php?parent=${parentId}`);
                const subcategories = await response.json();

                subcategories.forEach(sub => {
                    const option = document.createElement('option');
                    option.value = sub.CategoryID;
                    option.textContent = sub.CategoryName;
                    subcategorySelect.appendChild(option);
                });

                if (subcategories.length > 0) {
                    subcategoryContainer.style.display = 'block';
                    if (selectedSubcategory) {
                        subcategorySelect.value = selectedSubcategory;
                    }
                } else {
                    subcategoryContainer.style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading subcategories:', error);
                subcategoryContainer.style.display = 'none';
            }
        }

        async function onParentCategoryChange() {
            const parentId = document.getElementById('parentCategoryHome').value;
            const subcategorySelect = document.getElementById('subcategoryHome');

            if (!parentId) {
                subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
                document.getElementById('filterForm').submit();
                return;
            }

            await populateSubcategories(parentId, '');
            subcategorySelect.value = '';

            requestAnimationFrame(() => {
                document.getElementById('filterForm').submit();
            });
        }

        window.onclick = function(event) {
            const dropdown = document.getElementById('userDropdown');
            const priceMenu = document.getElementById('priceFilterMenu');

            if (!event.target.closest('.user-menu-container')) {
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }

            if (!event.target.closest('.price-filter-dropdown')) {
                if (priceMenu) {
                    priceMenu.style.display = 'none';
                }
            }
        };

        let resizeTimer;

        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                setMobileMapHeight();
                syncMobileToggle();
                map.resize();
            }, 150);
        });

        document.addEventListener('DOMContentLoaded', () => {
            setMobileMapHeight();
            setupSidebarSwipeToClose();
            syncMobileToggle();

            setTimeout(() => {
                setMobileMapHeight();
            }, 100);
        });

        map.on('load', () => {
            ensureRadiusLayers();
            ensureVicinityLayers();

            const center = map.getCenter();
            radiusCenter = [center.lng, center.lat];

            buildMarkers();
            updateRadiusStatus();
            setMobileMapHeight();

            if (activeRadiusMiles) {
                updateRadiusBubble();
            }
        });

        window.addEventListener('load', () => {
            setMobileMapHeight();
            map.resize();
        });
    </script>

    <?php include 'includes/header_dropdowns.php'; ?>
    <?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>
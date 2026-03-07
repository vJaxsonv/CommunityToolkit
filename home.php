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
$price_ranges = $_GET['price_ranges'] ?? [];
$min_price = $_GET['min_price'] ?? '';
$max_price_custom = $_GET['max_price'] ?? '';

// Build query - USING ACTUAL DATABASE COLUMN NAMES
$sql = "SELECT l.*, 
        u.FirstName, u.LastName, u.UserID as OwnerUserID,
        c.CategoryName,
        cond.Condition,
        ls.Status,
        n.NeighborhoodName, n.City,
        (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        WHERE ls.Status = 'Active'";

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

$sql .= " ORDER BY l.AddedDate DESC LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter - ParentCategoryID = 0 for top level
$categories = $pdo->query("SELECT CategoryID, CategoryName FROM TCategories WHERE ParentCategoryID = 0 ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
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
                    <a href="index.php" class="nav-link active">
                        <i class="fas fa-home"></i>
                        <span>Home</span>
                    </a>
                    <a href="myitems.php" class="nav-link">
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
                <div class="filter-dropdown" id="subcategoryContainerHome" style="display: none;">
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
                
                <?php if (!empty($search) || !empty($category) || !empty($price_ranges) || !empty($min_price) || !empty($max_price_custom)): ?>
                    <a href="home.php" class="btn btn-outline btn-sm">Clear All Filters</a>
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
                                    <span class="item-condition"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($item['Condition']); ?></span>
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
        async function loadSubcategoriesHome() {
            const parentId = document.getElementById('parentCategoryHome').value;
            const subcategoryContainer = document.getElementById('subcategoryContainerHome');
            const subcategorySelect = document.getElementById('subcategoryHome');
            
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
        
        // Close dropdown when clicking outside
        window.onclick = function(event) {
            const dropdown = document.getElementById('userDropdown');
            const priceMenu = document.getElementById('priceFilterMenu');
            
            // Close user dropdown
            if (!event.target.matches('.user-avatar')) {
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
            
            // Close price filter menu
            if (!event.target.closest('.price-filter-dropdown')) {
                if (priceMenu) {
                    priceMenu.style.display = 'none';
                }
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
    </style>
</body>
</html>

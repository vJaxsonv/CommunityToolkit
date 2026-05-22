<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get current user ID
$userId = $_SESSION['user_id'];
$search = trim($_GET['search'] ?? '');

// Build query for ONLY the logged-in user's listings
$sql = "SELECT l.*, 
        u.FirstName, u.LastName, u.UserID as OwnerUserID,
        u.ProfilePictureURL,
        c.CategoryName,
        cond.Condition,
        ls.Status as ListingStatus,
        n.NeighborhoodName, n.City
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        WHERE l.UserLenderID = ? AND l.ListingStatusID NOT IN (4, 5)";

$params = [$userId];

if ($search !== '') {
    $sql .= " AND (
        l.Title LIKE ?
        OR l.Description LIKE ?
        OR c.CategoryName LIKE ?
        OR cond.Condition LIKE ?
        OR ls.Status LIKE ?
        OR n.NeighborhoodName LIKE ?
        OR n.City LIKE ?
    )";

    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY l.AddedDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <!-- Logo -->
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height: 50px; width: auto;">
                </a>
                <!-- Search Bar -->
                <?php include 'includes/search_bar.php'; ?>
               

                <nav class="main-nav">
                   <a href="index.php" class="nav-link">
    <i class="fas fa-home"></i>
    <span>Home</span>
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

    <main class="main-content">
        <div class="container">
        <form method="GET" action="my_items.php" class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input
                type="text"
                name="search"
                placeholder="Search your listings..."
                class="search-input"
                id="myItemsSearch"
                value="<?php echo htmlspecialchars($search); ?>"
                oninput="clearTimeout(window.myItemsSearchTimer); window.myItemsSearchTimer = setTimeout(() => this.form.submit(), 300);">
        </form>
            <h1>My Listed Items</h1>

            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <h2>No items listed yet</h2>
                    <p>You haven't posted any items yet. <a href="create_listing.php">Create your first listing!</a></p>
                </div>
            <?php else: ?>
                <div class="items-column">
                    <?php foreach ($items as $item): ?>
                        <div class="item-card" data-title="<?php echo htmlspecialchars(strtolower($item['Title'])); ?>" data-category="<?php echo htmlspecialchars(strtolower($item['CategoryName'])); ?>" data-condition="<?php echo htmlspecialchars(strtolower($item['Condition'])); ?>">
                            <div class="item-header">
                                <div class="user-info">
                                    <div style="width:44px;height:44px;min-width:44px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#e5e7eb;flex-shrink:0;">
                                        <?php if (!empty($item['ProfilePictureURL'])): ?>
                                            <img src="<?php echo htmlspecialchars($item['ProfilePictureURL']); ?>"
                                                 alt="Profile Picture"
                                                 style="width:44px;height:44px;object-fit:cover;display:block;border-radius:50%;">
                                        <?php else: ?>
                                            <span style="font-weight:600;font-size:16px;color:#374151;line-height:1;">
                                                <?php echo strtoupper(substr($item['FirstName'], 0, 1)); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="user-name">
                                            <?php echo htmlspecialchars($item['FirstName'] . ' ' . substr($item['LastName'], 0, 1) . '.'); ?>
                                        </div>
                                        <div class="user-distance">
                                            <?php echo htmlspecialchars($item['ListingStatus']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="item-image">
                                <?php
                                $photoStmt = $pdo->prepare("SELECT PhotoURL FROM TListingPhotos WHERE ListingID = ? ORDER BY SortOrder LIMIT 1");
                                $photoStmt->execute([$item['ListingID']]);
                                $firstPhoto = $photoStmt->fetchColumn();
                            
                                $isVideo = false;
                                if ($firstPhoto) {
                                    $ext = strtolower(pathinfo(parse_url($firstPhoto, PHP_URL_PATH), PATHINFO_EXTENSION));
                                    $isVideo = in_array($ext, ['mp4', 'webm', 'ogg', 'mov']);
                                }
                                ?>
                                
                                <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>&back=my_items.php"
                                   style="display:block;width:100%;height:100%;"
                                   onclick="sessionStorage.setItem('myItemsScrollY', window.scrollY)">
                                   
                                    <?php if ($firstPhoto): ?>
                                        
                                        <?php if ($isVideo): ?>
                                            <video
                                                muted
                                                playsinline
                                                autoplay
                                                loop
                                                preload="metadata"
                                                style="width:100%;height:100%;object-fit:cover;display:block;">
                                                <source src="<?php echo htmlspecialchars($firstPhoto); ?>" type="video/mp4">
                                            </video>
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($firstPhoto); ?>"
                                                 alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                        <?php endif; ?>
                            
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:36px;">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            </div>

                            <div class="item-details">
                                <h3 class="item-title"><a href="item_detail.php?id=<?php echo $item['ListingID']; ?>&back=my_items.php" style="color:inherit;text-decoration:none;" onclick="sessionStorage.setItem('myItemsScrollY', window.scrollY)"><?php echo htmlspecialchars($item['Title']); ?></a></h3>
                                <p class="item-description">
                                    <?php echo htmlspecialchars(substr($item['Description'], 0, 150)); ?>...
                                </p>

                                <div class="item-meta">
                                    <span class="item-category">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['CategoryName']); ?>
                                    </span>
                                    <span class="item-condition">
                                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($item['Condition']); ?>
                                    </span>
                                </div>

                                <div class="item-price-section">
                                    <div class="price-label">Price per day</div>
                                    <div class="price">$<?php echo number_format($item['PricePerDay'] ?? 0, 2); ?></div>
                                </div>
                                <div style="display:flex; gap:6px; margin-top:10px;">
                                    <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>" style="flex:1;text-align:center;padding:6px 4px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;background:#f0f2ff;color:#667eea;border:1.5px solid #c7d2fe;display:inline-flex;align-items:center;justify-content:center;gap:4px;"><i class="fas fa-eye"></i> View</a>
                                    <a href="edit_listing.php?id=<?php echo $item['ListingID']; ?>" style="flex:1;text-align:center;padding:6px 4px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;background:#667eea;color:white;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-edit"></i> Edit</a>
                                    <a href="delete_listing.php?id=<?php echo $item['ListingID']; ?>" style="flex:1;text-align:center;padding:6px 4px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;background:#fee2e2;color:#dc2626;border:1.5px solid #fecaca;display:inline-flex;align-items:center;justify-content:center;" onclick="return confirm('Are you sure you want to delete this listing?');"><i class="fas fa-trash"></i> Delete</a>
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
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}
function prevPhoto() {
    let newIndex = currentPhotoIndex - 1;
    if (newIndex < 0) newIndex = galleryMedia.length - 1;
    renderMainMedia(newIndex);
}
window.addEventListener('click', function(event) {
    const avatar = event.target.closest('.user-avatar');
    const menuContainer = event.target.closest('.user-menu-container');
    const dropdown = document.getElementById('userDropdown');

    if (avatar) {
        return;
    }

    if (!menuContainer && dropdown && dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
    }
});
</script>
    </script>
    <?php include 'includes/header_dropdowns.php'; ?>
    <?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>

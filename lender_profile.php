<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$lenderUserId = intval($_GET['id'] ?? 0);
if (!$lenderUserId) {
    header('Location: home.php');
    exit;
}

// Fetch lender's public data (no phone, no email, no DOB)
$stmt = $pdo->prepare("
    SELECT u.UserID, u.FirstName, u.LastName, u.ProfilePictureURL, u.Bio,
           n.NeighborhoodName, n.City
    FROM TUsers u
    LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
    WHERE u.UserID = ?
");
$stmt->execute([$lenderUserId]);
$lender = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lender) {
    header('Location: home.php?error=not_found');
    exit;
}

// Rating & review count
$stmt = $pdo->prepare("SELECT AVG(CAST(ReviewRating AS DECIMAL(3,1))) as avg_rating, COUNT(*) as review_count FROM TReviews WHERE UserRevieweeID = ?");
$stmt->execute([$lenderUserId]);
$ratingData = $stmt->fetch(PDO::FETCH_ASSOC);
$avgRating   = $ratingData['avg_rating'] ? round($ratingData['avg_rating'], 1) : null;
$reviewCount = $ratingData['review_count'];

// Active listings count
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM TListings l INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID WHERE l.UserLenderID = ? AND ls.Status = 'Available'");
$stmt->execute([$lenderUserId]);
$listingsCount = $stmt->fetch()['count'];

// Active listings with photos
$stmt = $pdo->prepare("
    SELECT l.ListingID, l.Title, l.Description, l.PricePerDay, l.PricePerHour,
           c.CategoryName, cond.Condition,
           (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
    FROM TListings l
    INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
    INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
    INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
    WHERE l.UserLenderID = ? AND ls.Status = 'Available'
    ORDER BY l.AddedDate DESC
");
$stmt->execute([$lenderUserId]);
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Last 5 reviews
$stmt = $pdo->prepare("
    SELECT r.ReviewRating, r.ReviewText, r.AddedDate,
           u.FirstName, u.LastName
    FROM TReviews r
    INNER JOIN TUsers u ON r.UserReviewerID = u.UserID
    WHERE r.UserRevieweeID = ?
    ORDER BY r.AddedDate DESC
    LIMIT 5
");
$stmt->execute([$lenderUserId]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isOwnProfile = ($lenderUserId == $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($lender['FirstName'] . ' ' . substr($lender['LastName'], 0, 1) . '.'); ?> - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body data-logged-in="true">
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height: 50px; width: auto;">
                </a>
                 <?php include 'includes/search_bar.php'; ?>
                <nav class="main-nav">
                    <a href="home.php" class="nav-link">
                        <i class="fas fa-user-circle"></i>
                        <span>My Account</span>
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

    <main class="main-content">
        <div class="container">
            <a href="javascript:history.back()" style="color:#667eea;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;">
                <i class="fas fa-arrow-left"></i> Back
            </a>

            <div style="display:grid;grid-template-columns:280px 1fr;gap:30px;align-items:start;">

                <!-- Left: Lender Card -->
                <div>
                    <div class="profile-card" style="position:sticky;top:20px;">
                        <div class="profile-avatar-large">
                            <?php if (!empty($lender['ProfilePictureURL'])): ?>
                                <img src="<?php echo htmlspecialchars($lender['ProfilePictureURL']); ?>"
                                     alt="<?php echo htmlspecialchars($lender['FirstName']); ?>"
                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($lender['FirstName'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>

                        <h1 class="profile-name">
                            <?php echo htmlspecialchars($lender['FirstName'] . ' ' . substr($lender['LastName'], 0, 1) . '.'); ?>
                        </h1>

                        <?php if ($lender['City']): ?>
                            <div class="profile-info-line">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($lender['City']); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Rating -->
                        <div class="profile-rating" style="margin:10px 0;">
                            <?php if ($avgRating): ?>
                                <?php
                                $fullStars = floor($avgRating);
                                $hasHalf   = ($avgRating - $fullStars) >= 0.5;
                                for ($i = 0; $i < $fullStars; $i++) echo '<i class="fas fa-star"></i>';
                                if ($hasHalf) echo '<i class="fas fa-star-half-alt"></i>';
                                $empty = 5 - $fullStars - ($hasHalf ? 1 : 0);
                                for ($i = 0; $i < $empty; $i++) echo '<i class="far fa-star"></i>';
                                ?>
                                <span style="color:#666;margin-left:6px;"><?php echo $avgRating; ?> (<?php echo $reviewCount; ?> review<?php echo $reviewCount != 1 ? 's' : ''; ?>)</span>
                            <?php else: ?>
                                <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
                                <span style="color:#999;margin-left:6px;">No reviews yet</span>
                            <?php endif; ?>
                        </div>

                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $listingsCount; ?></div>
                                <div class="stat-label">Items Listed</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $reviewCount; ?></div>
                                <div class="stat-label">Reviews</div>
                            </div>
                        </div>

                        <!-- Link to full profile/bio -->
                        <a href="profile.php?user=<?php echo $lenderUserId; ?>"
                           style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;color:#667eea;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #c7d2fe;border-radius:8px;background:#f0f2ff;transition:background 0.2s;"
                           onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f2ff'">
                            <i class="fas fa-user-circle"></i> View Full Profile
                        </a>

                        <?php if (!$isOwnProfile): ?>
                            <a href="report.php?type=user&user_id=<?php echo $lenderUserId; ?>&back_url=<?php echo urlencode('lender_profile.php?id=' . $lenderUserId); ?>"
                               style="display:inline-flex;align-items:center;gap:6px;color:#dc2626;font-size:13px;font-weight:600;text-decoration:none;padding:7px 14px;border:1.5px solid #fecaca;border-radius:8px;background:#fef2f2;margin-top:8px;transition:background 0.2s;"
                               onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                                <i class="fas fa-flag"></i> Report User
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Listings + Reviews -->
                <div>
                    <!-- Available Listings -->
                    <h2 style="margin-bottom:16px;color:#333;">
                        <i class="fas fa-box" style="color:#667eea;"></i>
                        Items Available to Rent
                    </h2>

                    <?php if (empty($listings)): ?>
                        <div class="empty-state" style="padding:30px;">
                            <i class="fas fa-box-open fa-2x"></i>
                            <p style="margin-top:10px;">No items currently available.</p>
                        </div>
                    <?php else: ?>
                        <div class="items-column grid-2" style="margin-bottom:40px;">
                            <?php foreach ($listings as $item): ?>
                                <div class="item-card">
                                    <div class="item-image">
                                        <?php if ($item['PrimaryImage']): ?>
                                            <img src="<?php echo htmlspecialchars($item['PrimaryImage']); ?>" alt="<?php echo htmlspecialchars($item['Title']); ?>">
                                        <?php else: ?>
                                            <div style="width:100%;height:100%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:36px;">
                                                <i class="fas fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-details">
                                        <h3 class="item-title"><?php echo htmlspecialchars($item['Title']); ?></h3>
                                        <p class="item-description"><?php echo htmlspecialchars(substr($item['Description'], 0, 120)); ?>...</p>
                                        <div class="item-meta">
                                            <span class="item-category"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['CategoryName']); ?></span>
                                            <span class="item-condition"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($item['Condition']); ?></span>
                                        </div>
                                        <div class="item-price-section" style="margin-top:auto;">
                                            <div class="price-label">Price per day</div>
                                            <div class="price">$<?php echo number_format($item['PricePerDay'] ?? 0, 2); ?></div>
                                        </div>
                                        <div style="margin-top:10px;">
                                            <a href="item_detail.php?id=<?php echo $item['ListingID']; ?>"
                                               style="display:block;text-align:center;padding:7px;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;background:#667eea;color:white;">
                                                View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Reviews -->
                    <h2 style="margin-bottom:16px;color:#333;">
                        <i class="fas fa-star" style="color:#f39c12;"></i>
                        Reviews
                    </h2>

                    <?php if (empty($reviews)): ?>
                        <div class="empty-state" style="padding:30px;">
                            <i class="far fa-star fa-2x"></i>
                            <p style="margin-top:10px;">No reviews yet.</p>
                        </div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:16px;">
                            <?php foreach ($reviews as $review): ?>
                                <div style="background:white;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div class="avatar avatar-purple" style="width:36px;height:36px;font-size:14px;">
                                                <?php echo strtoupper(substr($review['FirstName'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight:600;font-size:14px;">
                                                    <?php echo htmlspecialchars($review['FirstName'] . ' ' . substr($review['LastName'], 0, 1) . '.'); ?>
                                                </div>
                                                <div style="font-size:12px;color:#999;">
                                                    <?php echo date('M j, Y', strtotime($review['AddedDate'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="color:#f39c12;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="<?php echo $i <= $review['ReviewRating'] ? 'fas' : 'far'; ?> fa-star" style="font-size:13px;"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($review['ReviewText'])): ?>
                                        <p style="color:#555;font-size:14px;margin:0;line-height:1.5;">
                                            <?php echo nl2br(htmlspecialchars($review['ReviewText'])); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }
        function openChat() {
            alert('Chat functionality coming soon!');
        }
        window.onclick = function(event) {
            if (!event.target.matches('.user-avatar')) {
                const dropdown = document.getElementById('userDropdown');
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        }
    </script>
    <?php require_once 'includes/chatbot_widget.php'; ?>
</body>
</html>

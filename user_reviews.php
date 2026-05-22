<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$reviewUserId = intval($_GET['user'] ?? 0);
if (!$reviewUserId) {
    header('Location: home.php');
    exit;
}

$stmt = $pdo->prepare("SELECT UserID, FirstName, LastName, ProfilePictureURL FROM TUsers WHERE UserID = ?");
$stmt->execute([$reviewUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: home.php?error=not_found');
    exit;
}

// Lender ratings — reviews left by renters (people who rented FROM this user)
// ReviewTypeID = 1 means reviewer is the renter, reviewee is the lender
$stmt = $pdo->prepare("
   SELECT r.ReviewRating, r.ReviewText, r.AddedDate,
       u.UserID, u.FirstName, u.LastName, u.ProfilePictureURL
    FROM TReviews r
    INNER JOIN TUsers u ON r.UserReviewerID = u.UserID
    WHERE r.UserRevieweeID = ? AND r.ReviewTypeID = 1
    ORDER BY r.AddedDate DESC
");
$stmt->execute([$reviewUserId]);
$lenderReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Renter ratings — reviews left by lenders (people who lent TO this user)
// ReviewTypeID = 2 means reviewer is the lender, reviewee is the renter
$stmt = $pdo->prepare("
    SELECT r.ReviewRating, r.ReviewText, r.AddedDate,
           u.UserID, u.FirstName, u.LastName, u.ProfilePictureURL
    FROM TReviews r
    INNER JOIN TUsers u ON r.UserReviewerID = u.UserID
    WHERE r.UserRevieweeID = ? AND r.ReviewTypeID = 2
    ORDER BY r.AddedDate DESC
");
$stmt->execute([$reviewUserId]);
$renterReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Overall average (all reviews combined)
$stmt = $pdo->prepare("SELECT AVG(CAST(ReviewRating AS DECIMAL(3,1))) as avg_rating, COUNT(*) as review_count FROM TReviews WHERE UserRevieweeID = ?");
$stmt->execute([$reviewUserId]);
$ratingData  = $stmt->fetch(PDO::FETCH_ASSOC);
$avgRating   = $ratingData['avg_rating'] ? round($ratingData['avg_rating'], 1) : null;
$reviewCount = $ratingData['review_count'];

$activeTab = $_GET['tab'] ?? 'lender';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews for <?php echo htmlspecialchars($user['FirstName'] . ' ' . substr($user['LastName'], 0, 1) . '.'); ?> - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
   
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

    <main class="main-content">
        <div class="container" style="max-width:800px;">
            <a href="profile.php?user_id=<?php echo $reviewUserId; ?>" style="color:#667eea;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>

            <!-- Header card -->
            <div class="reviews-header">
                <div class="reviews-avatar">
                    <?php if (!empty($user['ProfilePictureURL'])): ?>
                        <img src="<?php echo htmlspecialchars($user['ProfilePictureURL']); ?>"
                             style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user['FirstName'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h1 style="margin:0 0 4px 0;font-size:22px;">
                        <?php echo htmlspecialchars($user['FirstName'] . ' ' . substr($user['LastName'], 0, 1) . '.'); ?>
                    </h1>
                    <?php if ($avgRating): ?>
                        <div class="star-display" style="margin-bottom:2px;">
                            <?php
                            $full = floor($avgRating);
                            $half = ($avgRating - $full) >= 0.5;
                            for ($i = 0; $i < $full; $i++) echo '<i class="fas fa-star"></i>';
                            if ($half) echo '<i class="fas fa-star-half-alt"></i>';
                            $empty = 5 - $full - ($half ? 1 : 0);
                            for ($i = 0; $i < $empty; $i++) echo '<i class="far fa-star"></i>';
                            ?>
                        </div>
                        <div style="color:#666;font-size:14px;"><?php echo $avgRating; ?> overall &bull; <?php echo $reviewCount; ?> review<?php echo $reviewCount != 1 ? 's' : ''; ?></div>
                    <?php else: ?>
                        <div class="star-display" style="color:#ddd;">
                            <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
                        </div>
                        <div style="color:#999;font-size:14px;">No reviews yet</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabs -->
            <div class="reviews-tab-bar">
                <button class="reviews-tab <?php echo $activeTab === 'lender' ? 'active' : ''; ?>"
                        onclick="switchTab('lender')">
                    <i class="fas fa-box"></i> Lender Ratings
                    <span style="background:#f0f2ff;color:#667eea;border-radius:12px;padding:2px 8px;font-size:12px;margin-left:6px;">
                        <?php echo count($lenderReviews); ?>
                    </span>
                </button>
                <button class="reviews-tab <?php echo $activeTab === 'renter' ? 'active' : ''; ?>"
                        onclick="switchTab('renter')">
                    <i class="fas fa-key"></i> Renter Ratings
                    <span style="background:#f0f2ff;color:#667eea;border-radius:12px;padding:2px 8px;font-size:12px;margin-left:6px;">
                        <?php echo count($renterReviews); ?>
                    </span>
                </button>
            </div>

            <!-- Lender Reviews Panel -->
            <div class="tab-panel <?php echo $activeTab === 'lender' ? 'active' : ''; ?>" id="panel-lender">
                <p style="color:#666;font-size:13px;margin-bottom:16px;">Reviews left by people who rented items from <?php echo htmlspecialchars($user['FirstName']); ?>.</p>
                <?php if (empty($lenderReviews)): ?>
                    <div class="empty-reviews">
                        <i class="fas fa-box fa-2x" style="margin-bottom:10px;"></i>
                        <p>No lender ratings yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($lenderReviews as $review): ?>
                        <?php // include_once 'includes/review_card.php'; ?>
                       <div class="review-card">
                            <div class="review-card-header">
                                <div class="reviewer-info">
                                    <a href="profile.php?user_id=<?php echo $review['UserID']; ?>">
                                        <div class="reviewer-avatar">
                                            <?php if (!empty($review['ProfilePictureURL'])): ?>
                                                <img src="<?php echo htmlspecialchars($review['ProfilePictureURL']); ?>"
                                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($review['FirstName'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <div>
                                    <div style="font-weight:600;font-size:14px;">
                                        <?php echo htmlspecialchars($review['FirstName'] . ' ' . substr($review['LastName'], 0, 1) . '.'); ?>
                                    </div>
                                    <div style="font-size:12px;color:#999;">
                                        <?php echo date('M j, Y', strtotime($review['AddedDate'])); ?>
                                    </div>
                                </div>
                            </div>
                    
                            <div class="star-display">
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
<?php endif; ?>
</div>
            <!-- Renter Reviews Panel -->
            <div class="tab-panel <?php echo $activeTab === 'renter' ? 'active' : ''; ?>" id="panel-renter">
                <p style="color:#666;font-size:13px;margin-bottom:16px;">Reviews left by people who lent items to <?php echo htmlspecialchars($user['FirstName']); ?>.</p>
                <?php if (empty($renterReviews)): ?>
                    <div class="empty-reviews">
                        <i class="fas fa-key fa-2x" style="margin-bottom:10px;"></i>
                        <p>No renter ratings yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($renterReviews as $review): ?>
                        <div class="review-card">
                            <div class="review-card-header">
                                <div class="reviewer-info">
                                   <a href="profile.php?user_id=<?php echo $review['UserID']; ?>">
                                        <div class="reviewer-avatar">
                                            <?php if (!empty($review['ProfilePictureURL'])): ?>
                                                <img src="<?php echo htmlspecialchars($review['ProfilePictureURL']); ?>"
                                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($review['FirstName'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <div>
                                        <div style="font-weight:600;font-size:14px;">
                                            <?php echo htmlspecialchars($review['FirstName'] . ' ' . substr($review['LastName'], 0, 1) . '.'); ?>
                                        </div>
                                        <div style="font-size:12px;color:#999;">
                                            <?php echo date('M j, Y', strtotime($review['AddedDate'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="star-display">
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
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.reviews-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
            document.getElementById(`panel-${tab}`).classList.add('active');
        }
 
        function openChat() {
            alert('Chat functionality coming soon!');
        }
   
        
            function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }
        
    </script>
    <?php require_once 'includes/chatbot_widget.php'; ?>
        <?php include 'includes/header_dropdowns.php'; ?>

</body>
</html>
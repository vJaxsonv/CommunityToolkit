<?php 
require_once 'config.php';
date_default_timezone_set('America/New_York');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get listing ID from URL
$listingId = intval($_GET['id'] ?? 0);
// $backUrl = where 'Back to Listings' goes (filtered home URL)
$backUrl  = !empty($_GET['back']) ? $_GET['back'] : 'home.php';
// $selfUrl = this page's full URL including back param, for passing through to profile
$selfUrl  = 'item_detail.php?id=' . $listingId . (!empty($_GET['back']) ? '&back=' . urlencode($_GET['back']) : '');

if ($listingId == 0) {
    header('Location: home.php');
    exit;
}
$privacyRadiusMiles = 0.75;

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
// Fetch listing details with all related info
$sql = "SELECT l.*, 
        u.UserID as OwnerID, u.FirstName as OwnerFirstName, u.LastName as OwnerLastName, 
        u.ProfilePictureURL, u.PhoneNumber as OwnerPhone,
        c.CategoryName,
        cond.Condition,
        ls.Status as ListingStatus,
        n.NeighborhoodName, n.City, n.StateID,
        n.CenterLatitude AS NeighborhoodCenterLatitude,
        n.CenterLongitude AS NeighborhoodCenterLongitude,
        s.StateName,
        rt.RateType
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON l.NeighborhoodID = n.NeighborhoodID
        LEFT JOIN TStates s ON n.StateID = s.StateID
        LEFT JOIN TRateTypes rt ON l.RateTypeID = rt.RateTypeID
        WHERE l.ListingID = ? 
          AND (ls.Status = 'Available' OR ls.Status = 'Pending' OR ls.Status = 'Rented' OR l.UserLenderID = ?)";

$stmt = $pdo->prepare($sql);
$stmt->execute([$listingId, $_SESSION['user_id']]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$listing) {
    header('Location: home.php?error=not_found');
    exit;
}

// Fetch photos for this listing
$photoSql = "SELECT PhotoURL, SortOrder FROM TListingPhotos WHERE ListingID = ? ORDER BY SortOrder";
$photoStmt = $pdo->prepare($photoSql);
$photoStmt->execute([$listingId]);
$photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if user is the owner
$isOwner = ($listing['OwnerID'] == $_SESSION['user_id']);

// Determine available rate types
$hasDaily = !empty($listing['PricePerDay']) && $listing['PricePerDay'] > 0;
$hasHourly = !empty($listing['PricePerHour']) && $listing['PricePerHour'] > 0;

// Get owner's average rating
$ratingSql = "SELECT AVG(CAST(ReviewRating AS DECIMAL(3,1))) as avg_rating, COUNT(*) as review_count
              FROM TReviews WHERE UserRevieweeID = ?";
$ratingStmt = $pdo->prepare($ratingSql);
$ratingStmt->execute([$listing['OwnerID']]);
$ownerRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);

// Fetch unavailable dates — only block dates for genuinely active/pending situations
// Conditions:
//   1. Pending requests (status 1) where end date hasn't passed yet
//   2. Accepted requests (status 2) where the rental is still Approved (2) or Active (4)
//      i.e. not yet completed (5), cancelled (6), or pending reviews (7)
$unavailableSql = "
    SELECT rr.StartDate, rr.EndDate
    FROM TRentalRequests rr
    WHERE rr.ListingID = ?
      AND rr.RequestStatusID = 1
      AND rr.EndDate > CURDATE()
    UNION
    SELECT rr.StartDate, rr.EndDate
    FROM TRentalRequests rr
    INNER JOIN TRentals r ON r.RentalRequestID = rr.RentalRequestID
    WHERE rr.ListingID = ?
      AND rr.RequestStatusID = 2
      AND r.RentalStatusID IN (2, 4)
      AND rr.EndDate > CURDATE()
    ORDER BY StartDate";

$unavailableStmt = $pdo->prepare($unavailableSql);
$unavailableStmt->execute([$listingId, $listingId]);
$unavailableDates = $unavailableStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch manually blocked dates from TListingAvailability
// Only show blocks that correspond to an active/approved rental — ignore orphaned blocks
$manualBlockStmt = $pdo->prepare("
    SELECT DATE(la.UnavailableDate) as BlockedDate
    FROM TListingAvailability la
    WHERE la.ListingID = ?
      AND la.UnavailableDate IS NOT NULL
      AND la.BlockReasonID = 1
      AND EXISTS (
          SELECT 1 FROM TRentals r
          INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
          WHERE r.ListingID = la.ListingID
            AND r.RentalStatusID IN (2, 4)
            AND rr.StartDate <= la.UnavailableDate
            AND rr.EndDate >= la.UnavailableDate
      )
    ORDER BY la.UnavailableDate
");
$manualBlockStmt->execute([$listingId]);
$manualBlocked = $manualBlockStmt->fetchAll(PDO::FETCH_ASSOC);

// Convert to JavaScript-friendly format
$bookedRanges = [];
foreach ($unavailableDates as $booking) {
    $bookedRanges[] = [
        'start' => date('Y-m-d', strtotime($booking['StartDate'])),
        'end'   => date('Y-m-d', strtotime($booking['EndDate'])),
        'type'  => 'booked'
    ];
}
// Add individual blocked dates
foreach ($manualBlocked as $blocked) {
    $bookedRanges[] = [
        'start' => $blocked['BlockedDate'],
        'end'   => $blocked['BlockedDate'],
        'type'  => 'blocked'
    ];
}
$bookedRangesJson = json_encode($bookedRanges);
// Check if item is bookmarked by current user
$isBookmarked = false;
$bookmarkStmt = $pdo->prepare("SELECT COUNT(*) FROM TBookmarks WHERE UserID = ? AND ListingID = ?");
$bookmarkStmt->execute([$_SESSION['user_id'], $listingId]);
$isBookmarked = (bool)$bookmarkStmt->fetchColumn();


// Convert listings for map JS
$mapListings = [];



$lat = $listing['NeighborhoodCenterLatitude'] ?? null;
$lng = $listing['NeighborhoodCenterLongitude'] ?? null;
if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
    $lat = (float)$lat;
    $lng = (float)$lng;

    $price = 0;
    $rateLabel = '';

    if (!empty($listing['PricePerDay'])) {
        $price = (float)$listing['PricePerDay'];
        $rateLabel = '/day';
    } elseif (!empty($listing['PricePerHour'])) {
        $price = (float)$listing['PricePerHour'];
        $rateLabel = '/hour';
    }

    [$displayLat, $displayLng] = getObfuscatedCoordinates(
        $lat,
        $lng,
        (int)$listing['ListingID'],
        $privacyRadiusMiles
    );

    $mapListings[] = [
        'id' => (int)$listing['ListingID'],
        'title' => $listing['Title'],
        'category' => $listing['CategoryName'] ?? 'Uncategorized',
        'price' => $price,
        'rateLabel' => $rateLabel,
        'neighborhood' => $listing['NeighborhoodName'] ?? '',
        'city' => $listing['City'] ?? '',
        'hasExactLocation' => true,
        'privacyMode' => true,
        'privacyRadiusMiles' => $privacyRadiusMiles,
        'locationLabel' => trim(($listing['NeighborhoodName'] ?? '') . (!empty($listing['City']) ? ', ' . $listing['City'] : '')),
        'coords' => [$displayLng, $displayLat],
        'centerCoords' => [$lng, $lat],
        'image' => !empty($listing['PrimaryImage']) ? $listing['PrimaryImage'] : 'images/default-listing.jpg',
        'url' => 'item_detail.php?id=' . (int)$listing['ListingID'] . '&back=' . urlencode($_SERVER['REQUEST_URI'])
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($listing['Title']); ?> - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
       <link href="https://api.mapbox.com/mapbox-gl-js/v3.19.1/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.19.1/mapbox-gl.js"></script>
    <script id="search-js" defer src="https://api.mapbox.com/search-js/v1.5.0/web.js"></script>
    <link href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.min.js"></script>
 
</head>
<body data-logged-in="true">
    <!-- Header -->
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height:50px;width:auto;">
                </a>
                  <?php include 'includes/search_bar.php'; ?>
                <nav class="main-nav">
                    <a href="https://thecommunitytoolkit.com/" class="nav-link"><i class="fas fa-home"></i><span>Home</span></a>
                    <a href="my_items.php" class="nav-link"><i class="fas fa-box"></i><span>My Items</span></a>
                    <a href="create_listing.php" class="nav-link"><i class="fas fa-plus-circle"></i><span>List Item</span></a>
                    <a href="map.php" class="nav-link"><i class="fas fa-map-marker-alt"></i><span>Map</span></a>
                    <a href="my_rentals.php" class="nav-link"><i class="fas fa-calendar"></i><span>My Rentals</span></a>
                </nav>
                <div class="user-section">
                    <div class="notification-icon"><i class="fas fa-bell"></i><span class="notification-badge">0</span></div>
                    <div class="notification-icon" style="cursor:pointer;" title="Messages"><i class="fas fa-comment-dots"></i><span class="notification-badge">0</span></div>
                    <div class="user-menu-container">
                        <div class="user-avatar" onclick="toggleUserMenu()">
                            <?php if (!empty($_SESSION['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;">
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

    <div class="item-detail-container">
        <?php
        $backParam = $_GET['back'] ?? '';
        if (strpos($backParam, 'my_items') !== false) {
            $backLabel = 'Back to My Items';
        } elseif (strpos($backParam, 'home') !== false || strpos($backParam, 'index') !== false) {
            $backLabel = 'Back to Listings';
        } elseif (strpos($backParam, 'map') !== false) {
            $backLabel = 'Back to Map';
        } else {
            $backLabel = 'Back to Listings';
        }
        ?>
        <div class="detail-grid">
            <!-- Left Column: Photos & Description -->
            <div>
                <!-- Left column header: Back left, Bookmark right -->
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <a href="<?php echo htmlspecialchars($backUrl); ?>" style="display:inline-flex;align-items:center;gap:8px;color:#667eea;text-decoration:none;font-size:15px;font-weight:600;padding:10px 18px;border:2px solid #c7d2fe;border-radius:9px;background:#f0f2ff;transition:background 0.2s;" onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f2ff'"><i class="fas fa-arrow-left"></i> <?php echo $backLabel; ?></a>
                    <?php if (!$isOwner): ?>
                    <button class="bookmark-btn<?php echo $isBookmarked ? ' bookmarked' : ''; ?>" data-listing-id="<?php echo $listingId; ?>" onclick="toggleBookmark(this)" title="<?php echo $isBookmarked ? 'Bookmarked' : 'Bookmark'; ?>" style="width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;border:2px solid <?php echo $isBookmarked ? '#667eea' : '#c7d2fe'; ?>;border-radius:9px;background:<?php echo $isBookmarked ? '#667eea' : '#f0f2ff'; ?>;color:<?php echo $isBookmarked ? 'white' : '#667eea'; ?>;cursor:pointer;font-size:17px;transition:all 0.2s;"><i class="<?php echo $isBookmarked ? 'fas' : 'far'; ?> fa-bookmark"></i></button>
                    <?php endif; ?>
                </div>
                <div class="photo-gallery">
                    <?php if (count($photos) > 0): ?>
                        <div class="photo-main-area">
                        <div style="position:relative;">
                            <div class="zoom-wrapper">
                                <?php
                                    $firstUrl = $photos[0]['PhotoURL'];
                                    $firstExt = strtolower(pathinfo(parse_url($firstUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                                    $firstIsVideo = in_array($firstExt, ['mp4', 'webm', 'ogg', 'mov']);
                                ?>
                
                                <?php if ($firstIsVideo): ?>
                                    <video class="main-photo" id="mainVideo" controls playsinline preload="metadata">
                                        <source src="<?php echo htmlspecialchars($firstUrl); ?>" type="video/mp4">
                                        Your browser does not support the video tag.
                                    </video>
                                <?php else: ?>
                                    <img src="<?php echo htmlspecialchars($firstUrl); ?>"
                                         alt="<?php echo htmlspecialchars($listing['Title']); ?>"
                                         class="main-photo" id="mainPhoto"
                                         onclick="handlePhotoClick(event)">
                                    <div class="zoom-lens" id="zoomLens"></div>
                                    <div class="zoom-result" id="zoomResult"></div>
                                <?php endif; ?>
                            </div>
                
                            <?php if (count($photos) > 1): ?>
                                <button onclick="prevPhoto()" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.5);color:white;border:none;border-radius:50%;width:38px;height:38px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;">&#8249;</button>
                                <button onclick="nextPhoto()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.5);color:white;border:none;border-radius:50%;width:38px;height:38px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;">&#8250;</button>
                            <?php endif; ?>
                        </div>
                
                        </div><!-- end photo-main-area -->
                        <div class="photo-thumb-area">
                            <?php foreach($photos as $index => $photo): ?>
                                <?php
                                    $thumbUrl = $photo['PhotoURL'];
                                    $thumbExt = strtolower(pathinfo(parse_url($thumbUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                                    $thumbIsVideo = in_array($thumbExt, ['mp4', 'webm', 'ogg', 'mov']);
                                ?>
                                <?php if ($thumbIsVideo): ?>
                                    <video class="thumbnail <?php echo $index == 0 ? 'active' : ''; ?>"
                                           onclick="goToPhoto(<?php echo $index; ?>)"
                                           muted playsinline preload="metadata">
                                        <source src="<?php echo htmlspecialchars($thumbUrl); ?>" type="video/mp4">
                                    </video>
                                <?php else: ?>
                                    <img src="<?php echo htmlspecialchars($thumbUrl); ?>"
                                         alt="Photo <?php echo $index + 1; ?>"
                                         class="thumbnail <?php echo $index == 0 ? 'active' : ''; ?>"
                                         onclick="goToPhoto(<?php echo $index; ?>)">
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-photos">
                            <i class="fas fa-image" style="font-size: 48px;"></i>
                            <p style="margin-top: 15px;">No photos available</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Lightbox -->
                <div id="lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:9999;align-items:center;justify-content:center;flex-direction:column;">
                    <button onclick="closeLightbox()" style="position:absolute;top:16px;right:20px;background:none;border:none;color:white;font-size:32px;cursor:pointer;z-index:10001;">&times;</button>
                    <button onclick="lbPrev()" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.15);color:white;border:none;border-radius:50%;width:48px;height:48px;font-size:26px;cursor:pointer;">&#8249;</button>
                    <img id="lightboxImg" src="" style="max-width:90vw;max-height:88vh;border-radius:8px;object-fit:contain;">
                    <div id="lightboxCounter" style="color:white;margin-top:12px;font-size:14px;"></div>
                    <button onclick="lbNext()" style="position:absolute;right:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.15);color:white;border:none;border-radius:50%;width:48px;height:48px;font-size:26px;cursor:pointer;">&#8250;</button>
                </div>
                
                <div class="item-map" style="margin-top: 20px;">
                     <div id="map" style="width:100%; height:400px;" ></div>
                </div>
          
                <div class="item-info" style="margin-top: 20px;">
                    <h1 class="item-title"><?php echo htmlspecialchars($listing['Title']); ?></h1>
                    
                    <div class="item-meta">
                        <span class="meta-badge">
                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($listing['CategoryName']); ?>
                        </span>
                        <span class="meta-badge">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($listing['Condition']); ?>
                        </span>
                        <span class="meta-badge">
                            <i class="fas fa-map-marker-alt"></i> 
                            <?php 
                            if ($listing['City']) {
                                echo htmlspecialchars($listing['NeighborhoodName'] . ', ' . $listing['City']);
                            } else {
                                echo htmlspecialchars($listing['NeighborhoodName']);
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div class="price-section">
                        <h3 style="margin-top: 0; color: #333;">Rental Rates</h3>
                        <?php if ($hasHourly): ?>
                            <div class="price-option">
                                <i class="fas fa-clock" style="color: #667eea;"></i>
                                <span class="price-amount">$<?php echo number_format($listing['PricePerHour'], 2); ?></span>
                                <span style="color: #666;">/hour</span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($hasDaily): ?>
                            <div class="price-option">
                                <i class="fas fa-calendar-day" style="color: #667eea;"></i>
                                <span class="price-amount">$<?php echo number_format($listing['PricePerDay'], 2); ?></span>
                                <span style="color: #666;">/day</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="description-section">
                        <h3>Description</h3>
                        <p style="color: #666; line-height: 1.6;">
                            <?php echo nl2br(htmlspecialchars($listing['Description'])); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Owner Info & Rental Form -->
            <div>
                <!-- Right column header: Report left, Tour right -->
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <?php if (!$isOwner): ?>
                    <a href="report.php?type=listing&listing_id=<?php echo $listingId; ?>&back_url=<?php echo urlencode('item_detail.php?id=' . $listingId); ?>" title="Report Listing" style="width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;color:#dc2626;text-decoration:none;border:2px solid #fecaca;border-radius:9px;background:#fef2f2;font-size:17px;transition:background 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"><i class="fas fa-flag"></i></a>
                    <button onclick="startItemTour()" style="display:inline-flex;align-items:center;gap:6px;background:none;border:1px solid #d1d5db;border-radius:20px;padding:4px 12px;font-size:12px;color:#6b7280;cursor:pointer;transition:border-color 0.15s,color 0.15s;" onmouseover="this.style.borderColor='#667eea';this.style.color='#667eea'" onmouseout="this.style.borderColor='#d1d5db';this.style.color='#6b7280'"><i class="fas fa-compass"></i> Take the Tour</button>
                    <?php endif; ?>
                </div>
                <div class="owner-card">
                    <div class="owner-header">
                        <a href="profile.php?user_id=<?php echo $listing['OwnerID']; ?>&back=<?php echo urlencode($selfUrl); ?>" style="text-decoration:none;color:inherit;">
                        <div class="owner-avatar">
                            <?php if (!empty($listing['ProfilePictureURL'])): ?>
                                <img src="<?php echo htmlspecialchars($listing['ProfilePictureURL']); ?>"
                                     alt="<?php echo htmlspecialchars($listing['OwnerFirstName']); ?>"
                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($listing['OwnerFirstName'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        </a>
                        <div class="owner-info">
                            <h3><a href="profile.php?user_id=<?php echo $listing['OwnerID']; ?>&back=<?php echo urlencode($selfUrl); ?>" style="color:inherit;text-decoration:none;"><?php echo htmlspecialchars($listing['OwnerFirstName'] . ' ' . substr($listing['OwnerLastName'], 0, 1) . '.'); ?></a></h3>
                            <div class="owner-rating">
                                <?php if ($ownerRating['review_count'] > 0):
                                    $full = floor($ownerRating['avg_rating']);
                                    $half = ($ownerRating['avg_rating'] - $full) >= 0.5;
                                    for ($i = 0; $i < $full; $i++) echo '<i class="fas fa-star"></i>';
                                    if ($half) echo '<i class="fas fa-star-half-alt"></i>';
                                    $empty = 5 - $full - ($half ? 1 : 0);
                                    for ($i = 0; $i < $empty; $i++) echo '<i class="far fa-star"></i>';
                                else:
                                    for ($i = 0; $i < 5; $i++) echo '<i class="far fa-star"></i>';
                                endif; ?>
                                <span style="color:#999;font-size:12px;margin-left:4px;">
                                    <?php if ($ownerRating['review_count'] > 0): ?>
                                        <?php echo number_format($ownerRating['avg_rating'], 1); ?> (<?php echo $ownerRating['review_count']; ?> review<?php echo $ownerRating['review_count'] != 1 ? 's' : ''; ?>)
                                    <?php else: ?>
                                        No reviews yet
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    

                </div>
                
                <?php if ($isOwner): ?>
                    <div class="owner-badge">
                        <i class="fas fa-info-circle"></i> This is your listing
                    </div>
                <?php else: ?>
                    <?php if (count($unavailableDates) > 0): ?>
                        <div style="background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
                            <h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px;">
                                <i class="fas fa-calendar-check" style="color: #667eea;"></i> Upcoming Bookings
                            </h4>
                            <div style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($unavailableDates as $booking): ?>
                                    <?php if (strtotime($booking['EndDate']) > strtotime('today')): ?>
                                    <div style="padding: 6px 0; color: #666; font-size: 13px; border-bottom: 1px solid #f0f0f0;">
                                        <i class="fas fa-circle" style="font-size: 6px; color: #f39c12;"></i>
                                        <?php 
                                        echo date('M j, Y', strtotime($booking['StartDate']));
                                        if (date('Y-m-d', strtotime($booking['StartDate'])) != date('Y-m-d', strtotime($booking['EndDate']))) {
                                            echo ' - ' . date('M j, Y', strtotime($booking['EndDate']));
                                        }
                                        ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="rental-form">
                        <h3>Request to Rent</h3>

                        <?php if ($hasHourly && $hasDaily): ?>
                        <div class="rate-type-selector" style="margin-bottom:16px;">
                            <label class="rate-option" id="rateOptHourly">
                                <input type="radio" name="rate_type_ui" value="hourly"  onchange="switchRateMode('hourly')">
                                <span class="rate-label">Hourly</span>
                                <span class="rate-price">$<?php echo number_format($listing['PricePerHour'], 2); ?>/hr</span>
                            </label>
                            <label class="rate-option" id="rateOptDaily">
                                <input type="radio" name="rate_type_ui" value="daily" checked onchange="switchRateMode('daily')">
                                <span class="rate-label">Daily</span>
                                <span class="rate-price">$<?php echo number_format($listing['PricePerDay'], 2); ?>/day</span>
                            </label>
                        </div>
                        <?php endif; ?>

                        <!-- ── DAILY: Airbnb-style calendar ── -->
                        <div id="dailySection" style="display:<?php echo ($hasDaily && !$hasHourly) || $hasDaily ? 'block' : 'none'; ?>;">
                            <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;">Select dates</div>

                            <!-- Pick Up / Return display -->
                            <div style="display:grid;grid-template-columns:1fr 1fr;border:1.5px solid #d1d5db;border-radius:10px;overflow:hidden;margin-bottom:16px;">
                                <div id="pickUpBox" style="padding:10px 14px;border-right:1px solid #d1d5db;cursor:pointer;" onclick="focusCalendar('pickup')">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#9ca3af;">Start Date</div>
                                    <div id="pickUpDisplay" style="font-size:14px;font-weight:600;color:#374151;">Add date</div>
                                </div>
                                <div id="returnBox" style="padding:10px 14px;cursor:pointer;" onclick="focusCalendar('returndate')">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#9ca3af;">End Date</div>
                                    <div id="returnDisplay" style="font-size:14px;font-weight:600;color:#374151;">Add date</div>
                                </div>
                            </div>

                            <!-- Calendar -->
                            <div id="calendarWrap" style="border:1.5px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px;background:#fff;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                                    <button type="button" onclick="calPrev()" style="background:none;border:1px solid #e5e7eb;border-radius:50%;width:30px;height:30px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;">‹</button>
                                    <span id="calMonthLabel" style="font-size:14px;font-weight:700;color:#1a1a2e;"></span>
                                    <button type="button" onclick="calNext()" style="background:none;border:1px solid #e5e7eb;border-radius:50%;width:30px;height:30px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;">›</button>
                                </div>
                                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:6px;">
                                    <?php foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d): ?>
                                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;"><?php echo $d; ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <div id="calGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;"></div>
                            </div>

                            <!-- Legend -->
                            <div style="display:flex;gap:16px;font-size:12px;color:#6b7280;margin-bottom:16px;flex-wrap:wrap;">
                                <span><span style="display:inline-block;width:12px;height:12px;background:#667eea;border-radius:50%;margin-right:5px;vertical-align:middle;"></span>Selected</span>
                                <span><span style="display:inline-block;width:12px;height:12px;background:#bfdbfe;border-radius:2px;margin-right:5px;vertical-align:middle;"></span>In range</span>
                                <span><span style="display:inline-block;width:12px;height:12px;background:#f3f4f6;border-radius:2px;margin-right:5px;vertical-align:middle;text-decoration:line-through;"></span>Unavailable</span>
                            </div>
                        </div>

                        <!-- ── HOURLY: Date + time picker ── -->
                        <div id="hourlySection" style="display:<?php echo $hasHourly && !$hasDaily ? 'block' : 'none'; ?>;">
                          
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="form-group">
                                    <label>Start Time *</label>
                                    <select id="startTime" name="start_time" onchange="calcHourly()" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;background:#fff;">
                                        <option value="">Select start time</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>End Time *</label>
                                    <select id="endTime" name="end_time" onchange="calcHourly()" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;background:#fff;">
                                        <option value="">Select end time</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Total cost display -->
                        <div class="total-cost" id="totalCostBox" style="display:none;">
                            <div class="total-label">Estimated Total</div>
                            <div class="total-amount" id="totalCost">$0.00</div>
                            <div class="suggestion-text" id="suggestion"></div>
                        </div>

                        <!-- Message -->
                        <div class="form-group" style="margin-top:12px;position:relative;">
                            <label>Message to Owner *</label>
                            <div id="msgTooltip" class="validation-tooltip">
                                <span class="tooltip-icon">!</span>Please fill out this field.
                            </div>
                            <textarea id="rentalMessage" rows="2" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;resize:vertical;font-size:14px;" placeholder="Let the owner know when you'll pick up the item..." oninput="hideMsgTooltip()"></textarea>
                        </div>

                        <!-- Request Now button — goes to confirmation page -->
                        <button type="button" class="btn-request" id="btnRequestNow" onclick="openDisclaimer()" disabled>
    <i class="fas fa-calendar-check"></i> Request Now
</button>

                        <!-- Hidden form that submits to confirm page -->
                        <form action="rental_confirm.php" method="POST" id="confirmForm">
                            <input type="hidden" name="listing_id"   value="<?php echo $listingId; ?>">
                            <input type="hidden" name="rate_type"    id="hfRateType"   value="<?php echo $hasDaily ? 'daily' : 'hourly'; ?>">
                            <input type="hidden" name="start_date"   id="hfStartDate"  value="">
                            <input type="hidden" name="end_date"     id="hfEndDate"    value="">
                            <input type="hidden" name="start_time"   id="hfStartTime"  value="">
                            <input type="hidden" name="hours"        id="hfHours"      value="">
                            <input type="hidden" name="total_cost"   id="hfTotalCost"  value="">
                            <input type="hidden" name="message"      id="hfMessage"    value="">
                            <input type="hidden" name="listing_title"    value="<?php echo htmlspecialchars($listing['Title']); ?>">
                            <input type="hidden" name="owner_name"       value="<?php echo htmlspecialchars($listing['OwnerFirstName'] . ' ' . $listing['OwnerLastName']); ?>">
                            <input type="hidden" name="price_per_day"    value="<?php echo floatval($listing['PricePerDay'] ?? 0); ?>">
                            <input type="hidden" name="price_per_hour"   value="<?php echo floatval($listing['PricePerHour'] ?? 0); ?>">
                            <input type="hidden" name="listing_image"    value="<?php echo !empty($photos) ? htmlspecialchars($photos[0]['PhotoURL']) : ''; ?>">
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

     
    </div>

    <script>
    
    const galleryMedia = <?php
    $media = array_map(function($photo) {
        $url = $photo['PhotoURL'];
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $isVideo = in_array($ext, ['mp4', 'webm', 'ogg', 'mov']);
        return [
            'url' => $url,
            'isVideo' => $isVideo
        ];
    }, $photos);

    echo json_encode($media);
?>;

let currentPhotoIndex = 0;
    function openDisclaimer() {
    // Run your existing validation FIRST
    const msg = document.getElementById('rentalMessage')?.value || '';
    if (!msg.trim()) {
        showMsgTooltip();
        return;
    }

    document.getElementById('disclaimerModal').style.display = 'flex';
}

function closeDisclaimer() {
    document.getElementById('disclaimerModal').style.display = 'none';
    document.getElementById('agreeCheckbox').checked = false;
    toggleDisclaimerBtn();
}

function toggleDisclaimerBtn() {
    const checked = document.getElementById('agreeCheckbox').checked;
    const btn = document.getElementById('confirmDisclaimerBtn');

    btn.disabled = !checked;
    btn.style.opacity = checked ? '1' : '0.5';
}

function submitAfterDisclaimer() {
    closeDisclaimer();
    goToConfirm(); // your ORIGINAL function
}
    // Booked date ranges from database
    const bookedRanges = <?php echo $bookedRangesJson; ?>;
    
    function toggleUserMenu() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }
    
    window.onclick = function(event) {
        if (!event.target.matches('.user-avatar')) {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown && dropdown.classList.contains('show')) {
                dropdown.classList.remove('show');
            }
        }
    }
    function showDaily() {
    document.getElementById("dailySection").style.display = "block";
    document.getElementById("hourlySection").style.display = "none";
}

function showHourly() {
    document.getElementById("dailySection").style.display = "none";
    document.getElementById("hourlySection").style.display = "block";
}
    // Photo URLs array from PHP
   function goToPhoto(index) {
    currentPhotoIndex = index;

    const media = galleryMedia[index];
    const wrapper = document.querySelector('.zoom-wrapper');
    const title = <?php echo json_encode($listing['Title']); ?>;

    if (!wrapper || !media) return;

    if (media.isVideo) {
        wrapper.innerHTML = `
            <video class="main-photo" id="mainVideo" controls playsinline preload="metadata">
                <source src="${media.url}" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        `;
    } else {
        wrapper.innerHTML = `
            <img src="${media.url}"
                 alt="${title}"
                 class="main-photo"
                 id="mainPhoto"
                 onclick="handlePhotoClick(event)">
            <div class="zoom-lens" id="zoomLens"></div>
            <div class="zoom-result" id="zoomResult"></div>
        `;
        // Re-attach zoom listeners to the newly created #mainPhoto
        setupZoom();
    }

    document.querySelectorAll('.thumbnail').forEach((t, i) => {
        t.classList.toggle('active', i === index);
    });
}

function prevPhoto() {
    goToPhoto((currentPhotoIndex - 1 + galleryMedia.length) % galleryMedia.length);
}

function nextPhoto() {
    goToPhoto((currentPhotoIndex + 1) % galleryMedia.length);
}

    // Lightbox
    function openLightbox(index) {
        currentPhotoIndex = index;
        document.getElementById('lightbox').style.display = 'flex';
        updateLightbox();
    }

    // ── Magnifying glass zoom (desktop only) ─────────────────────
    // setupZoom is called after every goToPhoto so it always attaches to the
    // freshly-created #mainPhoto element (innerHTML swap destroys old listeners)
    function setupZoom() {
        const LENS   = 100;
        const ZOOM   = 3;
        const img    = document.getElementById('mainPhoto');
        const lens   = document.getElementById('zoomLens');
        const result = document.getElementById('zoomResult');
        if (!img || !lens || !result) return;

        function update(e) {
            if (window.matchMedia('(max-width:900px)').matches) return;
            const r  = img.getBoundingClientRect();
            const mx = e.clientX - r.left;
            const my = e.clientY - r.top;
            const lx = Math.max(0, Math.min(mx - LENS/2, r.width  - LENS));
            const ly = Math.max(0, Math.min(my - LENS/2, r.height - LENS));
            lens.style.left = lx + 'px';
            lens.style.top  = ly + 'px';
            result.style.backgroundSize     = `${r.width*ZOOM}px ${r.height*ZOOM}px`;
            result.style.backgroundPosition = `-${lx*ZOOM}px -${ly*ZOOM}px`;
        }

        function show() {
            if (window.matchMedia('(max-width:900px)').matches) return;
            result.style.backgroundImage = `url('${img.src}')`;
            lens.style.display   = 'block';
            result.style.display = 'block';
        }

        function hide() {
            lens.style.display   = 'none';
            result.style.display = 'none';
        }

        img.addEventListener('mouseenter', show);
        img.addEventListener('mouseleave', hide);
        img.addEventListener('mousemove',  update);
    }

    document.addEventListener('DOMContentLoaded', setupZoom);

    // ── Touch swipe for main photo (mobile) ──────────────────────
    (function() {
        let startX = null;
        let startY = null;
        const THRESHOLD = 40; // min px to count as swipe

        function getWrapper() { return document.querySelector('.zoom-wrapper'); }

        document.addEventListener('touchstart', function(e) {
            const wrapper = getWrapper();
            if (!wrapper || !wrapper.contains(e.target)) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });

        document.addEventListener('touchend', function(e) {
            if (startX === null) return;
            const wrapper = getWrapper();
            if (!wrapper || !wrapper.contains(e.target)) { startX = null; return; }
            const dx = e.changedTouches[0].clientX - startX;
            const dy = e.changedTouches[0].clientY - startY;
            // Only trigger if horizontal swipe is dominant
            if (Math.abs(dx) > THRESHOLD && Math.abs(dx) > Math.abs(dy) * 1.5) {
                if (dx < 0) nextPhoto(); else prevPhoto();
            }
            startX = null;
            startY = null;
        }, { passive: true });
    })();

    // ── Touch swipe for lightbox (mobile) ────────────────────────
    (function() {
        let startX = null;
        let startY = null;
        const THRESHOLD = 40;

        document.addEventListener('touchstart', function(e) {
            const lb = document.getElementById('lightbox');
            if (!lb || lb.style.display !== 'flex') return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });

        document.addEventListener('touchend', function(e) {
            if (startX === null) return;
            const lb = document.getElementById('lightbox');
            if (!lb || lb.style.display !== 'flex') { startX = null; return; }
            const dx = e.changedTouches[0].clientX - startX;
            const dy = e.changedTouches[0].clientY - startY;
            if (Math.abs(dx) > THRESHOLD && Math.abs(dx) > Math.abs(dy) * 1.5) {
                if (dx < 0) lbNext(); else lbPrev();
            }
            startX = null;
            startY = null;
        }, { passive: true });
    })();

    function handlePhotoClick(e) {
        if (window.matchMedia('(max-width:900px)').matches) openLightbox(currentPhotoIndex);
    }

    function closeLightbox() {
        document.getElementById('lightbox').style.display = 'none';
    }

    // updateLightbox uses galleryMedia directly — photoUrls was never defined
    function updateLightbox() {
        const media = galleryMedia[currentPhotoIndex];
        if (!media || media.isVideo) return;
        document.getElementById('lightboxImg').src = media.url;
        const photoCount = galleryMedia.filter(m => !m.isVideo).length;
        const photoPos   = galleryMedia.slice(0, currentPhotoIndex + 1).filter(m => !m.isVideo).length;
        document.getElementById('lightboxCounter').textContent = photoPos + ' / ' + photoCount;
    }

    function lbPrev() {
        let idx = currentPhotoIndex;
        do { idx = (idx - 1 + galleryMedia.length) % galleryMedia.length; }
        while (galleryMedia[idx].isVideo && idx !== currentPhotoIndex);
        currentPhotoIndex = idx;
        updateLightbox();
        goToPhoto(currentPhotoIndex);
    }

    function lbNext() {
        let idx = currentPhotoIndex;
        do { idx = (idx + 1) % galleryMedia.length; }
        while (galleryMedia[idx].isVideo && idx !== currentPhotoIndex);
        currentPhotoIndex = idx;
        updateLightbox();
        goToPhoto(currentPhotoIndex);
    }

    // Keyboard nav for lightbox
    document.addEventListener('keydown', function(e) {
        const lb = document.getElementById('lightbox');
        if (lb.style.display === 'flex') {
            if (e.key === 'ArrowLeft') lbPrev();
            if (e.key === 'ArrowRight') lbNext();
            if (e.key === 'Escape') closeLightbox();
        }
    });

    // Close lightbox on backdrop click
    const lightboxEl = document.getElementById('lightbox');
    if (lightboxEl) lightboxEl.addEventListener('click', function(e) {
        if (e.target === this) closeLightbox();
    });
    
    // Check if a date is within any booked range
    function isDateBooked(dateStr) {
        const checkDate = new Date(dateStr);
        
        for (let range of bookedRanges) {
            const start = new Date(range.start);
            const end = new Date(range.end);
            
            if (checkDate >= start && checkDate <= end) {
                return true;
            }
        }
        return false;
    }
    
    // Check if a date range overlaps with any booked range
    function isRangeAvailable(startDate, endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        for (let range of bookedRanges) {
            const bookedStart = new Date(range.start);
            const bookedEnd = new Date(range.end);
            
            // Check for any overlap
            if (start <= bookedEnd && end >= bookedStart) {
                return false;
            }
        }
        return true;
    }
    
    const PRICE_PER_DAY  = <?php echo floatval($listing['PricePerDay']  ?? 0); ?>;
    const PRICE_PER_HOUR = <?php echo floatval($listing['PricePerHour'] ?? 0); ?>;
    const HAS_DAILY      = <?php echo $hasDaily  ? 'true' : 'false'; ?>;
    const HAS_HOURLY     = <?php echo $hasHourly ? 'true' : 'false'; ?>;

    // ── Calendar state ─────────────────────────────────────────
    const _now = new Date();
    let calYear  = _now.getFullYear();
    let calMonth = _now.getMonth();
    let calPickup = null, calReturn = null, calFocus = 'pickup';

    const MONTHS = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];

    function toYMD(d) {
        return d.getFullYear() + '-' +
               String(d.getMonth()+1).padStart(2,'0') + '-' +
               String(d.getDate()).padStart(2,'0');
    }

    function isUnavailable(ymd) {
        return bookedRanges.some(r => ymd >= r.start && ymd <= r.end);
    }

    function isPast(ymd) {
        return ymd < '<?php echo date('Y-m-d'); ?>';
    }

    function renderCal() {
        const label = document.getElementById('calMonthLabel');
        const grid  = document.getElementById('calGrid');
        if (!label || !grid) return;

        label.textContent = MONTHS[calMonth] + ' ' + calYear;
        grid.innerHTML = '';

        const first = new Date(calYear, calMonth, 1).getDay();
        const days  = new Date(calYear, calMonth + 1, 0).getDate();

        for (let i = 0; i < first; i++) {
            const empty = document.createElement('div');
            grid.appendChild(empty);
        }

        for (let d = 1; d <= days; d++) {
            const ymd     = calYear + '-' + String(calMonth+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
            const past    = isPast(ymd);
            const unavail = isUnavailable(ymd);
            const isStart = calPickup  && ymd === calPickup;
            const isEnd   = calReturn && ymd === calReturn;
            const inRange = calPickup && calReturn && ymd > calPickup && ymd < calReturn;

            let bg = 'transparent';
            let color = (past || unavail) ? '#c8c8c8' : '#1a1a2e';
            let br = '50%', fw = '400', textDecor = '';

            if (unavail) { bg = '#f3f4f6'; textDecor = 'line-through'; }
            if (inRange) { bg = '#bfdbfe'; br = '0'; }
            if (isStart || isEnd) { bg = '#667eea'; color = 'white'; fw = '700'; }

            const cell = document.createElement('div');
            cell.textContent = d;
            cell.style.cssText = 'text-align:center;padding:7px 2px;border-radius:' + br +
                ';background:' + bg + ';color:' + color + ';font-size:13px;font-weight:' + fw +
                ';cursor:' + (past || unavail ? 'not-allowed' : 'pointer') +
                ';text-decoration:' + textDecor + ';transition:background 0.15s;';

            if (!past && !unavail) {
                const capturedYmd = ymd;
                cell.addEventListener('click',       function() { calClick(capturedYmd); });
                cell.addEventListener('mouseover',   function() { calHover(this, capturedYmd); });
                cell.addEventListener('mouseout',    function() { calUnhover(this, capturedYmd); });
            }

            grid.appendChild(cell);
        }
    }

    function calHover(el, ymd) {
        if (calPickup && !calReturn && ymd > calPickup && !isUnavailable(ymd)) {
            el.style.background = '#dbeafe';
            el.style.borderRadius = '0';
        } else if (!calPickup) {
            el.style.background = '#e0e7ff';
            el.style.borderRadius = '50%';
        }
    }

    function calUnhover(el, ymd) {
        const isStart = calPickup  && ymd === calPickup;
        const isEnd   = calReturn && ymd === calReturn;
        const inRange = calPickup && calReturn && ymd > calPickup && ymd < calReturn;
        if (isStart || isEnd) { el.style.background='#667eea'; }
        else if (inRange)     { el.style.background='#bfdbfe'; el.style.borderRadius='0'; }
        else                  { el.style.background='transparent'; el.style.borderRadius='50%'; }
    }

    function calClick(ymd) {
    const mode = document.getElementById('hfRateType')?.value || 'daily';

    // HOURLY: one date only
    if (mode === 'hourly') {
        calPickup = ymd;
        calReturn = ymd;
        calFocus = 'pickup';

        updateDateDisplays();
        renderCal();
        calcHourly();
        updateRequestBtn();
        return;
    }

    // DAILY: first click = pickup, second click = return
    if (!calPickup || (calPickup && calReturn)) {
        // start new selection
        calPickup = ymd;
        calReturn = null;
        calFocus = 'returndate';
        highlightBox('returndate');
    } 
    else if (ymd > calPickup) {
        // check for blocked dates in between
        let d = new Date(calPickup);
        d.setDate(d.getDate() + 1);
        const end = new Date(ymd);

        let blocked = false;
        while (d < end) {
            if (isUnavailable(toYMD(d))) {
                blocked = true;
                break;
            }
            d.setDate(d.getDate() + 1);
        }

        if (blocked) {
            alert('Some dates in this range are unavailable. Please choose different dates.');
            return;
        }

        calReturn = ymd;
        calFocus = 'pickup';
        highlightBox('pickup');
    } 
    else if (ymd === calPickup) {
        // optional: same click just keeps waiting for return date
        calReturn = null;
        calFocus = 'returndate';
        highlightBox('returndate');
    } 
    else {
        // clicked an earlier date, restart selection
        calPickup = ymd;
        calReturn = null;
        calFocus = 'returndate';
        highlightBox('returndate');
    }

    updateDateDisplays();
    renderCal();
    updateRequestBtn();
}

    function calPrev() {
        calMonth--;
        if (calMonth < 0) { calMonth = 11; calYear--; }
        renderCal();
    }

    function calNext() {
        calMonth++;
        if (calMonth > 11) { calMonth = 0; calYear++; }
        renderCal();
    }

    function focusCalendar(which) {
        calFocus = which;
        highlightBox(which);
    }

    function highlightBox(which) {
        const ci = document.getElementById('pickUpBox');
        const co = document.getElementById('returnBox');
        if (!ci || !co) return;
        ci.style.background = which === 'pickup'  ? '#f0f2ff' : 'white';
        co.style.background = which === 'returndate' ? '#f0f2ff' : 'white';
    }

  function updateDateDisplays() {
    const ci = document.getElementById('pickUpDisplay');
    const co = document.getElementById('returnDisplay');
    const mode = document.getElementById('hfRateType')?.value || 'daily';

    if (ci) ci.textContent = calPickup ? formatDate(calPickup) : 'Add date';

    if (co) {
        if (mode === 'hourly') {
            co.textContent = calPickup ? formatDate(calPickup) : 'Add date';
        } else {
            co.textContent = calReturn ? formatDate(calReturn) : 'Add date';
        }
    }

    const tcBox = document.getElementById('totalCostBox');
    const tc = document.getElementById('totalCost');

    if (mode === 'daily' && calPickup && calReturn) {
        const days = Math.round((new Date(calReturn) - new Date(calPickup)) / 86400000);
        const total = days * PRICE_PER_DAY;

        if (tcBox) tcBox.style.display = 'block';
        if (tc) tc.textContent = '$' + total.toFixed(2);
    } else if (mode === 'daily') {
        if (tcBox) tcBox.style.display = 'none';
    }
}

    function formatDate(ymd) {
        const [y,m,d] = ymd.split('-');
        return MONTHS[parseInt(m)-1].slice(0,3) + ' ' + parseInt(d) + ', ' + y;
    }

    function switchRateMode(mode) {
    const dailySection = document.getElementById('dailySection');
    const hourlySection = document.getElementById('hourlySection');
    const hiddenRateType = document.getElementById('hfRateType');

    if (dailySection) dailySection.style.display = 'block'; // always show shared calendar
    if (hourlySection) hourlySection.style.display = mode === 'hourly' ? 'block' : 'none';
    if (hiddenRateType) hiddenRateType.value = mode;

    updateDateDisplays();
    renderCal();
    updateRequestBtn();
}

    // ── Hourly calculations ────────────────────────────────────
function calcHourly() {
    const date      = calPickup;
    const startVal  = document.getElementById('startTime')?.value;
    const endVal    = document.getElementById('endTime')?.value;
    const tcBox     = document.getElementById('totalCostBox');
    const tc        = document.getElementById('totalCost');

    if (date && startVal && endVal && endVal > startVal) {
        const startDt = new Date(date + 'T' + startVal);
        const endDt   = new Date(date + 'T' + endVal);
        const diffMs  = endDt - startDt;
        const hours   = diffMs / 3600000;

        if (tcBox) tcBox.style.display = 'block';
        if (tc)    tc.textContent      = '$' + (hours * PRICE_PER_HOUR).toFixed(2);
    } else {
        if (tcBox) tcBox.style.display = 'none';
    }

    updateRequestBtn();
}

    // ── Enable/disable Request Now button ─────────────────────
    function updateRequestBtn() {
        const btn = document.getElementById('btnRequestNow');
        if (!btn) return;
        const mode = document.getElementById('hfRateType')?.value || (HAS_DAILY ? 'daily' : 'hourly');
        let ready = false;
        if (mode === 'daily')  ready = !!(calPickup && calReturn);
        if (mode === 'hourly') {
        const startTime = document.getElementById('startTime')?.value;
        const endTimeVal = document.getElementById('endTime')?.value;
        ready = !!(calPickup && startTime && endTimeVal && endTimeVal > startTime);
    }
        btn.disabled = !ready;
        btn.style.opacity = ready ? '1' : '0.5';
    }

    // ── Go to confirmation page ────────────────────────────────
    function goToConfirm() {
        const mode = document.getElementById('hfRateType')?.value || (HAS_DAILY ? 'daily' : 'hourly');
        const msg  = document.getElementById('rentalMessage')?.value || '';

        if (mode === 'daily') {
            if (!calPickup || !calReturn) return;
            document.getElementById('hfStartDate').value = calPickup;
            document.getElementById('hfEndDate').value   = calReturn;
            const days  = Math.round((new Date(calReturn) - new Date(calPickup)) / 86400000);
            document.getElementById('hfTotalCost').value = (days * PRICE_PER_DAY).toFixed(2);
        } else {
                const selectedDate = calPickup;
                const startTime   = document.getElementById('startTime')?.value;
                const endTimeVal  = document.getElementById('endTime')?.value;

                if (!selectedDate || !startTime || !endTimeVal || endTimeVal <= startTime) return;

                const startDt  = new Date(selectedDate + 'T' + startTime);
                const endDt    = new Date(selectedDate + 'T' + endTimeVal);
                const hours    = (endDt - startDt) / 3600000;

                document.getElementById('hfStartDate').value = selectedDate;
                document.getElementById('hfStartTime').value = startTime;
                document.getElementById('hfHours').value     = hours.toFixed(2);
                document.getElementById('hfTotalCost').value = (hours * PRICE_PER_HOUR).toFixed(2);
            }

        // Message is now required
        if (!msg.trim()) {
            showMsgTooltip();
            return;
        }

        document.getElementById('hfMessage').value = msg;
        document.getElementById('confirmForm').submit();
    }

    // ── Populate time dropdowns (12:00 AM – 11:30 PM in 30-min steps) ────
    function populateTimePickers() {
        const startSel = document.getElementById('startTime');
        const endSel   = document.getElementById('endTime');
        if (!startSel || !endSel) return;

        const times = [];
        for (let h = 0; h < 24; h++) {
            for (let m = 0; m < 60; m += 30) {
                const hh     = String(h).padStart(2, '0');
                const mm     = String(m).padStart(2, '0');
                const val    = hh + ':' + mm;
                const hour12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
                const ampm   = h < 12 ? 'AM' : 'PM';
                const label  = hour12 + ':' + mm + ' ' + ampm;
                times.push({ val, label });
            }
        }

        times.forEach(t => {
            const o1 = new Option(t.label, t.val);
            const o2 = new Option(t.label, t.val);
            startSel.appendChild(o1);
            endSel.appendChild(o2);
        });

        // When start changes, update end dropdown to only show later times
        startSel.addEventListener('change', function() {
            const startVal = this.value;
            Array.from(endSel.options).forEach(opt => {
                if (opt.value === '') return;
                opt.disabled = opt.value <= startVal;
                opt.style.color = opt.value <= startVal ? '#ccc' : '';
            });
            // Reset end if now invalid
            if (endSel.value && endSel.value <= startVal) endSel.value = '';
            calcHourly();
        });
    }

    // ── Custom validation tooltip for message field ───────────
    function showMsgTooltip() {
        const textarea = document.getElementById('rentalMessage');
        const tooltip  = document.getElementById('msgTooltip');
        if (!tooltip || !textarea) return;
        tooltip.style.display = 'block';
        textarea.style.borderColor = '#f59e0b';
        textarea.focus();
        // Auto-hide after 3 seconds
        clearTimeout(window._msgTooltipTimer);
        window._msgTooltipTimer = setTimeout(hideMsgTooltip, 3000);
    }

    function hideMsgTooltip() {
        const textarea = document.getElementById('rentalMessage');
        const tooltip  = document.getElementById('msgTooltip');
        if (tooltip) tooltip.style.display = 'none';
        if (textarea) textarea.style.borderColor = '#ddd';
    }

    // ── Init on load ───────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        populateTimePickers();
        renderCal();
        highlightBox('pickup');
        // Set correct mode on load
        if (HAS_HOURLY && !HAS_DAILY) switchRateMode('hourly');
        else if (HAS_DAILY && !HAS_HOURLY) switchRateMode('daily');
        else switchRateMode('daily'); // default to daily if both
    });
    </script>
    <!-- Page Tour -->
    <script src="https://cdn.jsdelivr.net/npm/driver.js@latest/dist/driver.js.iife.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@latest/dist/driver.css">
    <script>
    function startItemTour() {
        const { driver } = window.driver.js;

        const allSteps = [
            {
                element: '.photo-gallery',
                popover: {
                    title: '📸 Photos',
                    description: 'Browse all photos of this item. On desktop, hover to zoom in. On mobile, tap to open the full-screen lightbox and swipe left or right between photos.',
                    side: 'right', align: 'start'
                }
            },
            {
                element: '.item-title',
                popover: {
                    title: '📋 Item Details',
                    description: 'The item name, category, condition, and full description are listed here so you know exactly what you are renting.',
                    side: 'right', align: 'start'
                }
            },
            {
                element: '.owner-card',
                popover: {
                    title: '👤 About the Lender',
                    description: 'See the lender name, rating, and number of reviews. Click their name or photo to view their full profile.',
                    side: 'left', align: 'start'
                }
            },
            {
                element: '.rental-form',
                popover: {
                    title: '📅 Request to Rent',
                    description: 'Select your rental dates here. If the item offers both hourly and daily rates you can toggle between them. The total cost is calculated automatically.',
                    side: 'left', align: 'start'
                }
            },
            {
                element: '#btnRequestNow',
                popover: {
                    title: '🚀 Send Your Request',
                    description: 'Once you have selected your dates, click here to review and confirm your booking. You will need a payment method on file before submitting.',
                    side: 'top', align: 'start'
                }
            },
            {
                element: '.calendar-section',
                popover: {
                    title: '📆 Availability Calendar',
                    description: 'Booked and unavailable dates are shown in red. Only open dates are available for rental.',
                    side: 'left', align: 'start'
                }
            },
            {
                element: '.ct-chat-launcher',
                popover: {
                    title: '🤖 Toolbot',
                    description: 'Have questions about this item or want to find similar options? Ask Toolbot! It knows all available inventory and can help you compare items, find accessories, or locate a contractor.',
                    side: 'top', align: 'end'
                }
            }
        ];

        const steps = allSteps.filter(step => {
            try { return !!document.querySelector(step.element); } catch(e) { return false; }
        });

        if (steps.length === 0) return;

        const tour = driver({
            showProgress: true,
            animate: true,
            overlayOpacity: 0.55,
            smoothScroll: true,
            allowClose: true,
            progressText: '{{current}} of {{total}}',
            nextBtnText: 'Next →',
            prevBtnText: '← Back',
            doneBtnText: 'Got it ✓',
            onDestroyStarted: () => {
                localStorage.setItem('item_detail_tour_done', '1');
                tour.destroy();
            },
            steps: steps
        });

        tour.drive();
    }

    // Auto-start on first visit
    document.addEventListener('DOMContentLoaded', function() {
        if (!localStorage.getItem('item_detail_tour_done')) {
            setTimeout(startItemTour, 700);
        }
    });
    </script>

    <?php require_once 'includes/chatbot_widget.php'; ?>
    <?php include 'includes/header_dropdowns.php'; ?>

<script>
async function toggleBookmark(button) {
    const listingId = button.dataset.listingId;
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
            const label = document.getElementById('bookmarkLabel');
            if (data.bookmarked) {
                button.classList.add('bookmarked');
                icon.classList.remove('far'); icon.classList.add('fas');
                button.style.borderColor = '#667eea';
                button.style.background  = '#667eea';
                button.style.color       = 'white';
                if (label) label.textContent = 'Bookmarked';
            } else {
                button.classList.remove('bookmarked');
                icon.classList.remove('fas'); icon.classList.add('far');
                button.style.borderColor = '#c7d2fe';
                button.style.background  = '#f0f2ff';
                button.style.color       = '#667eea';
                if (label) label.textContent = 'Bookmark';
            }
        }
    } catch (error) {
        console.error('Error toggling bookmark:', error);
    }
}







mapboxgl.accessToken = <?php echo json_encode(MAPBOX_ACCESS_TOKEN); ?>;
const listings = <?php echo json_encode($mapListings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

console.log('listings from php:', listings);

const defaultCenter = [-84.5120, 39.1031];
const itemCenter = listings[0]?.centerCoords || listings[0]?.coords || defaultCenter;

const map = new mapboxgl.Map({
    container: 'map',
    style: 'mapbox://styles/mapbox/streets-v12',
    center: itemCenter,
    zoom: 13
});

map.addControl(new mapboxgl.NavigationControl());

function createCircleFeature(center, radiusMiles, points = 64) {
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

map.on('load', () => {
    map.addSource('listing-vicinity-source', {
        type: 'geojson',
        data: {
            type: 'FeatureCollection',
            features: []
        }
    });

    map.addLayer({
    id: 'listing-vicinity-fill',
    type: 'fill',
    source: 'listing-vicinity-source',
    paint: {
        'fill-color': '#7c4dff',
        'fill-opacity': 0.22
    }
});

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

    if (!listings[0] || Number(listings[0].privacyRadiusMiles) <= 0) {
        console.log('No valid listing or radius');
        return;
    }

    const feature = createCircleFeature(
        listings[0].centerCoords,
        Number(listings[0].privacyRadiusMiles)
    );

    console.log('feature:', feature);

    map.getSource('listing-vicinity-source').setData({
        type: 'FeatureCollection',
        features: [feature]
    });

    const lng = listings[0].centerCoords[0];
    const lat = listings[0].centerCoords[1];
    const r = Number(listings[0].privacyRadiusMiles);

    const deltaLat = r / 69.172;
    const deltaLng = r / (69.172 * Math.cos(lat * Math.PI / 180));

    map.fitBounds([
        [lng - deltaLng, lat - deltaLat],
        [lng + deltaLng, lat + deltaLat]
    ], {
        padding: 40,
        duration: 0
    });
});
</script>
    
    <!-- Disclaimer Modal -->
<div id="disclaimerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;padding:24px;border-radius:12px;max-width:420px;width:90%;box-shadow:0 10px 30px rgba(0,0,0,0.2);">
    
    <h3 style="margin-top:0;">Before You Continue</h3>

    <p style="font-size:14px;color:#555;line-height:1.5;">
      By submitting this request, you acknowledge that:
    </p>

    <ul style="font-size:13px;color:#555;padding-left:18px;margin-top:10px;">
      <li>You are responsible for returning the item in agreed condition.</li>
      <li>You may be liable for damage, loss, or late return.</li>
      <li>This request does not guarantee approval.</li>
    </ul>

    <label style="display:flex;align-items:center;margin-top:15px;font-size:14px;">
      <input type="checkbox" id="agreeCheckbox" onchange="toggleDisclaimerBtn()" style="margin-right:8px;">
      I agree to the terms above
    </label>

    <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
      <button onclick="closeDisclaimer()" style="padding:8px 14px;border:none;background:#eee;border-radius:6px;cursor:pointer;">
        Cancel
      </button>

      <button id="confirmDisclaimerBtn" onclick="submitAfterDisclaimer()" disabled
              style="padding:8px 14px;border:none;background:#667eea;color:white;border-radius:6px;cursor:pointer;opacity:0.5;">
        Confirm Request
      </button>
    </div>

  </div>
</div>
</body>
</html>
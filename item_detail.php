<?php 
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get listing ID from URL
$listingId = intval($_GET['id'] ?? 0);

if ($listingId == 0) {
    header('Location: home.php');
    exit;
}

// Fetch listing details with all related info
$sql = "SELECT l.*, 
        u.UserID as OwnerID, u.FirstName as OwnerFirstName, u.LastName as OwnerLastName, 
        u.ProfilePictureURL, u.PhoneNumber as OwnerPhone,
        c.CategoryName,
        cond.Condition,
        ls.Status as ListingStatus,
        n.NeighborhoodName, n.City, n.StateID,
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
        WHERE l.ListingID = ? AND ls.Status = 'Active'";

$stmt = $pdo->prepare($sql);
$stmt->execute([$listingId]);
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
$ratingSql = "SELECT AVG(ReviewRating) as avg_rating, COUNT(*) as review_count
              FROM TReviews WHERE UserRevieweeID = ?";
$ratingStmt = $pdo->prepare($ratingSql);
$ratingStmt->execute([$listing['OwnerID']]);
$ownerRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);

// Fetch unavailable dates (pending/approved requests and active rentals)
$unavailableSql = "
    SELECT StartDate, EndDate 
    FROM TRentalRequests 
    WHERE ListingID = ? 
    AND RequestStatusID IN (1, 2)
    UNION
    SELECT rr.StartDate, rr.EndDate
    FROM TRentals r
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    WHERE r.ListingID = ?
    AND r.RentalStatusID = 4
    ORDER BY StartDate";

$unavailableStmt = $pdo->prepare($unavailableSql);
$unavailableStmt->execute([$listingId, $listingId]);
$unavailableDates = $unavailableStmt->fetchAll(PDO::FETCH_ASSOC);

// Convert to JavaScript-friendly format
$bookedRanges = [];
foreach ($unavailableDates as $booking) {
    $bookedRanges[] = [
        'start' => date('Y-m-d', strtotime($booking['StartDate'])),
        'end' => date('Y-m-d', strtotime($booking['EndDate']))
    ];
}
$bookedRangesJson = json_encode($bookedRanges);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($listing['Title']); ?> - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .item-detail-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        
        .photo-gallery {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .main-photo {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
            cursor: pointer;
        }
        
        .thumbnail-strip {
            display: flex;
            gap: 10px;
            overflow-x: auto;
        }
        
        .thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.2s;
        }
        
        .thumbnail:hover, .thumbnail.active {
            border-color: #667eea;
        }
        
        .no-photos {
            width: 100%;
            height: 400px;
            background: #f0f0f0;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 18px;
        }
        
        .item-info {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .item-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        
        .item-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .meta-badge {
            background: #f0f0f0;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            color: #666;
        }
        
        .price-section {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .price-option {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
        }
        
        .price-amount {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        
        .description-section {
            margin: 20px 0;
        }
        
        .description-section h3 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .owner-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .owner-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .owner-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
        }
        
        .owner-info h3 {
            margin: 0;
            color: #333;
        }
        
        .owner-rating {
            color: #f39c12;
            font-size: 14px;
        }
        
        .rental-form {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .rental-form h3 {
            color: #333;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .rate-type-selector {
            display: flex;
            gap: 15px;
            margin: 15px 0;
        }
        
        .rate-option {
            flex: 1;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        
        .rate-option:has(input:checked) {
            border-color: #667eea;
            background: #f8f9ff;
        }
        
        .rate-option:hover {
            border-color: #667eea;
        }
        
        .rate-option input[type="radio"] {
            display: none;
        }
        
        .rate-label {
            display: block;
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .rate-option:has(input:checked) .rate-label {
            color: #667eea;
            font-weight: bold;
        }
        
        .rate-price {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        
        .rate-option:has(input:checked) .rate-price {
            color: #667eea;
        }
        
        .total-cost {
            background: #f8f9ff;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }
        
        .total-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .total-amount {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        
        .suggestion-text {
            font-size: 12px;
            color: #f39c12;
            margin-top: 5px;
        }
        
        .btn-request {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn-request:hover {
            transform: translateY(-2px);
        }
        
        .btn-request:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .owner-badge {
            background: #f39c12;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            text-align: center;
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <form action="home.php" method="GET">
                        <input type="text" name="search" placeholder="Search for items near you..." class="search-input">
                    </form>
                </div>
                
                <nav class="main-nav">
                    <a href="home.php" class="nav-link">
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
                            <a href="myitems.php"><i class="fas fa-box"></i> My Items</a>
                            <a href="my_rentals.php"><i class="fas fa-calendar"></i> My Rentals</a>
                            <hr>
                            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="item-detail-container">
        <a href="home.php" style="color: #667eea; text-decoration: none; margin-bottom: 20px; display: inline-block;">
            <i class="fas fa-arrow-left"></i> Back to Listings
        </a>
        
        <div class="detail-grid">
            <!-- Left Column: Photos & Description -->
            <div>
                <div class="photo-gallery">
                    <?php if (count($photos) > 0): ?>
                        <img src="<?php echo htmlspecialchars($photos[0]['PhotoURL']); ?>" 
                             alt="<?php echo htmlspecialchars($listing['Title']); ?>" 
                             class="main-photo" 
                             id="mainPhoto">
                        
                        <?php if (count($photos) > 1): ?>
                            <div class="thumbnail-strip">
                                <?php foreach($photos as $index => $photo): ?>
                                    <img src="<?php echo htmlspecialchars($photo['PhotoURL']); ?>" 
                                         alt="Photo <?php echo $index + 1; ?>" 
                                         class="thumbnail <?php echo $index == 0 ? 'active' : ''; ?>"
                                         onclick="changeMainPhoto('<?php echo htmlspecialchars($photo['PhotoURL']); ?>', this)">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-photos">
                            <i class="fas fa-image" style="font-size: 48px;"></i>
                            <p style="margin-top: 15px;">No photos available</p>
                        </div>
                    <?php endif; ?>
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
                <div class="owner-card">
                    <div class="owner-header">
                        <div class="owner-avatar">
                            <?php echo strtoupper(substr($listing['OwnerFirstName'], 0, 1)); ?>
                        </div>
                        <div class="owner-info">
                            <h3><?php echo htmlspecialchars($listing['OwnerFirstName'] . ' ' . $listing['OwnerLastName']); ?></h3>
                            <?php if ($ownerRating['review_count'] > 0): ?>
                                <div class="owner-rating">
                                    <i class="fas fa-star"></i>
                                    <?php echo number_format($ownerRating['avg_rating'], 1); ?>
                                    (<?php echo $ownerRating['review_count']; ?> reviews)
                                </div>
                            <?php else: ?>
                                <div class="owner-rating" style="color: #999;">
                                    New lender
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($listing['OwnerPhone']): ?>
                        <p style="color: #666; margin: 10px 0;">
                            <i class="fas fa-phone"></i> 
                            <?php echo htmlspecialchars($listing['OwnerPhone']); ?>
                        </p>
                    <?php endif; ?>
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
                                    <div style="padding: 6px 0; color: #666; font-size: 13px; border-bottom: 1px solid #f0f0f0;">
                                        <i class="fas fa-circle" style="font-size: 6px; color: #f39c12;"></i>
                                        <?php 
                                        echo date('M j, Y', strtotime($booking['StartDate']));
                                        if (date('Y-m-d', strtotime($booking['StartDate'])) != date('Y-m-d', strtotime($booking['EndDate']))) {
                                            echo ' - ' . date('M j, Y', strtotime($booking['EndDate']));
                                        }
                                        ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="rental-form">
                        <h3>Request to Rent</h3>
                        
                        <form action="request_rental_process.php" method="POST" id="rentalForm">
                            <input type="hidden" name="listing_id" value="<?php echo $listingId; ?>">
                            
                            <?php if ($hasHourly && $hasDaily): ?>
                                <div class="rate-type-selector">
                                    <label class="rate-option">
                                        <input type="radio" name="rate_type" value="hourly" checked onchange="updateRateType()">
                                        <span class="rate-label">Hourly</span>
                                        <span class="rate-price">$<?php echo number_format($listing['PricePerHour'], 2); ?>/hr</span>
                                    </label>
                                    <label class="rate-option">
                                        <input type="radio" name="rate_type" value="daily" onchange="updateRateType()">
                                        <span class="rate-label">Daily</span>
                                        <span class="rate-price">$<?php echo number_format($listing['PricePerDay'], 2); ?>/day</span>
                                    </label>
                                </div>
                            <?php elseif ($hasHourly): ?>
                                <input type="hidden" name="rate_type" value="hourly">
                            <?php else: ?>
                                <input type="hidden" name="rate_type" value="daily">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label>Start Date *</label>
                                <input type="date" name="start_date" id="startDate" required 
                                       min="<?php echo date('Y-m-d'); ?>"
                                       onchange="calculateTotal()">
                            </div>
                            
                            <div class="form-group" id="startTimeGroup">
                                <label>Start Time *</label>
                                <input type="time" name="start_time" id="startTime" required onchange="calculateTotal()">
                            </div>
                            
                            <div class="form-group" id="hoursGroup" style="display: <?php echo $hasHourly ? 'block' : 'none'; ?>;">
                                <label>Number of Hours *</label>
                                <input type="number" name="hours" id="hours" min="1" max="168" 
                                       placeholder="1-168 hours" onchange="calculateTotal()">
                            </div>
                            
                            <div class="form-group" id="daysGroup" style="display: <?php echo (!$hasHourly && $hasDaily) ? 'block' : 'none'; ?>;">
                                <label>Number of Days *</label>
                                <input type="number" name="days" id="days" min="1" max="30" 
                                       placeholder="1-30 days" onchange="calculateTotal()">
                            </div>
                            
                            <div class="total-cost">
                                <div class="total-label">Estimated Total</div>
                                <div class="total-amount" id="totalCost">$0.00</div>
                                <div class="suggestion-text" id="suggestion"></div>
                            </div>
                            
                            <div class="form-group">
                                <label>Message to Owner (Optional)</label>
                                <textarea name="message" rows="3" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; resize: vertical;" placeholder="Let the owner know when you'll pick up the item..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn-request">
                                <i class="fas fa-paper-plane"></i> Request to Rent
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
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
    
    function changeMainPhoto(url, thumbnail) {
        document.getElementById('mainPhoto').src = url;
        document.querySelectorAll('.thumbnail').forEach(t => t.classList.remove('active'));
        thumbnail.classList.add('active');
    }
    
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
    
    const pricePerHour = <?php echo $listing['PricePerHour'] ?? 0; ?>;
    const pricePerDay = <?php echo $listing['PricePerDay'] ?? 0; ?>;
    const hasHourly = <?php echo $hasHourly ? 'true' : 'false'; ?>;
    const hasDaily = <?php echo $hasDaily ? 'true' : 'false'; ?>;
    
    function updateRateType() {
        const rateType = document.querySelector('input[name="rate_type"]:checked').value;
        const hoursGroup = document.getElementById('hoursGroup');
        const daysGroup = document.getElementById('daysGroup');
        const startTimeGroup = document.getElementById('startTimeGroup');
        const hours = document.getElementById('hours');
        const days = document.getElementById('days');
        const startTime = document.getElementById('startTime');
        
        if (rateType === 'hourly') {
            hoursGroup.style.display = 'block';
            daysGroup.style.display = 'none';
            startTimeGroup.style.display = 'block';
            hours.required = true;
            days.required = false;
            startTime.required = true;
            days.value = '';
        } else {
            hoursGroup.style.display = 'none';
            daysGroup.style.display = 'block';
            startTimeGroup.style.display = 'none';
            hours.required = false;
            days.required = true;
            startTime.required = false;
            hours.value = '';
        }
        
        calculateTotal();
    }
    
    function calculateTotal() {
        const rateTypeInput = document.querySelector('input[name="rate_type"]:checked');
        if (!rateTypeInput) return;
        
        const rateType = rateTypeInput.value;
        const hours = parseFloat(document.getElementById('hours')?.value || 0);
        const days = parseFloat(document.getElementById('days')?.value || 0);
        const startDate = document.getElementById('startDate').value;
        const totalCostEl = document.getElementById('totalCost');
        const suggestionEl = document.getElementById('suggestion');
        
        let total = 0;
        let suggestion = '';
        
        // Validate availability
        if (startDate) {
            const start = new Date(startDate);
            let endDate;
            
            if (rateType === 'hourly' && hours > 0) {
                const startTime = document.getElementById('startTime').value;
                if (startTime) {
                    endDate = new Date(startDate + 'T' + startTime);
                    endDate.setHours(endDate.getHours() + hours);
                    
                    if (!isRangeAvailable(startDate, endDate.toISOString().split('T')[0])) {
                        suggestion = '⚠️ This time slot is unavailable';
                        totalCostEl.textContent = '$0.00';
                        return;
                    }
                }
                
                total = hours * pricePerHour;
                
                // Suggest daily if it's cheaper
                if (hasDaily && hasHourly) {
                    const equivalentDays = Math.ceil(hours / 24);
                    const dailyTotal = equivalentDays * pricePerDay;
                    
                    if (dailyTotal < total) {
                        suggestion = `💡 Daily rate (${equivalentDays} day${equivalentDays > 1 ? 's' : ''}) would be $${dailyTotal.toFixed(2)} - cheaper!`;
                    }
                }
            } else if (rateType === 'daily' && days > 0) {
                endDate = new Date(start);
                endDate.setDate(endDate.getDate() + days);
                
                if (!isRangeAvailable(startDate, endDate.toISOString().split('T')[0])) {
                    suggestion = '⚠️ Some or all of these dates are unavailable';
                    totalCostEl.textContent = '$0.00';
                    return;
                }
                
                total = days * pricePerDay;
            }
        }
        
        totalCostEl.textContent = '$' + total.toFixed(2);
        suggestionEl.textContent = suggestion;
    }
    
    // Initialize form and set up date validation
    document.addEventListener('DOMContentLoaded', function() {
        const startDateInput = document.getElementById('startDate');
        const rentalForm = document.getElementById('rentalForm');
        
        // Disable booked dates on date input (visual feedback)
        if (startDateInput) {
            startDateInput.addEventListener('input', function() {
                if (isDateBooked(this.value)) {
                    this.setCustomValidity('This date is already booked');
                } else {
                    this.setCustomValidity('');
                }
            });
        }
        
        // Validate on form submit
        rentalForm.addEventListener('submit', function(e) {
            const rateType = document.querySelector('input[name="rate_type"]:checked').value;
            const startDate = document.getElementById('startDate').value;
            const hours = parseFloat(document.getElementById('hours')?.value || 0);
            const days = parseFloat(document.getElementById('days')?.value || 0);
            
            let endDate;
            
            if (rateType === 'hourly' && hours > 0) {
                const startTime = document.getElementById('startTime').value;
                endDate = new Date(startDate + 'T' + startTime);
                endDate.setHours(endDate.getHours() + hours);
            } else if (rateType === 'daily' && days > 0) {
                endDate = new Date(startDate);
                endDate.setDate(endDate.getDate() + days);
            }
            
            if (endDate && !isRangeAvailable(startDate, endDate.toISOString().split('T')[0])) {
                e.preventDefault();
                alert('Sorry, this item is not available for the selected dates. Please choose different dates.');
                return false;
            }
        });
        
        <?php if ($hasHourly && $hasDaily): ?>
            updateRateType();
        <?php elseif ($hasHourly): ?>
            document.getElementById('startTimeGroup').style.display = 'block';
            document.getElementById('hoursGroup').style.display = 'block';
            document.getElementById('hours').required = true;
        <?php else: ?>
            document.getElementById('daysGroup').style.display = 'block';
            document.getElementById('days').required = true;
        <?php endif; ?>
    });
    </script>
</body>
</html>

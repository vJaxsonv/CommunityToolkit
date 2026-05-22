<?php
require_once 'config.php';
ob_start();
error_reporting(0);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId    = $_SESSION['user_id'];
$listingId = intval($_GET['id'] ?? 0);

if ($listingId === 0) {
    header('Location: my_items.php');
    exit;
}

// Fetch listing — must belong to this user
$listingStmt = $pdo->prepare("
    SELECT l.*, n.CityID, n.StateID
    FROM TListings l
    LEFT JOIN TNeighborhoods n ON l.NeighborhoodID = n.NeighborhoodID
    WHERE l.ListingID = ? AND l.UserLenderID = ?
");
$listingStmt->execute([$listingId, $userId]);
$listing = $listingStmt->fetch(PDO::FETCH_ASSOC);

if (!$listing) {
    header('Location: my_items.php');
    exit;
}





// Fetch existing photos
$photoStmt = $pdo->prepare("SELECT ListingPhotoID, PhotoURL, SortOrder FROM TListingPhotos WHERE ListingID = ? ORDER BY SortOrder ASC");
$photoStmt->execute([$listingId]);
$existingPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch dropdown data
$categories = $pdo->query("SELECT CategoryID, CategoryName FROM TCategories WHERE ParentCategoryID = 0 ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
$conditions = $pdo->query("SELECT ConditionID, `Condition` FROM TConditions ORDER BY ConditionID")->fetchAll(PDO::FETCH_ASSOC);
$rateTypes  = $pdo->query("SELECT RateTypeID, RateType FROM TRateTypes ORDER BY RateTypeID")->fetchAll(PDO::FETCH_ASSOC);
$states     = $pdo->query("SELECT StateID, StateName FROM TStates ORDER BY StateName")->fetchAll(PDO::FETCH_ASSOC);
$statuses = $pdo->query("SELECT ListingStatusID, Status FROM TListingStatuses ORDER BY ListingStatusID")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];

// Pre-populate from existing listing
$title             = $listing['Title'];
$description       = $listing['Description'];
$category_id       = $listing['CategoryID'];
$all_parent_ids_flat = $pdo->query("SELECT CategoryID FROM TCategories WHERE ParentCategoryID = 0")->fetchAll(PDO::FETCH_COLUMN);
try {
    $existing_cats = $pdo->prepare("SELECT CategoryID FROM TListingCategories WHERE ListingID = ?");
    $existing_cats->execute([$listingId]);
    $existing_cat_rows = $existing_cats->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $existing_cat_rows = []; }
if (empty($existing_cat_rows)) {
    if (in_array($category_id, $all_parent_ids_flat)) {
        $existing_cat_rows = [$category_id];
    } else {
        $parentRow = $pdo->prepare("SELECT ParentCategoryID FROM TCategories WHERE CategoryID = ?");
        $parentRow->execute([$category_id]);
        $parentId = intval($parentRow->fetchColumn());
        $existing_cat_rows = $parentId > 0 ? [$parentId, $category_id] : [$category_id];
    }
}
// Ensure parents of any stored subcategories are included so buttons show selected
$sub_ids_in_rows = array_diff($existing_cat_rows, $all_parent_ids_flat);
if (!empty($sub_ids_in_rows)) {
    $placeholders = implode(',', array_fill(0, count($sub_ids_in_rows), '?'));
    $parentStmt = $pdo->prepare("SELECT DISTINCT ParentCategoryID FROM TCategories WHERE CategoryID IN ($placeholders) AND ParentCategoryID != 0");
    $parentStmt->execute(array_values($sub_ids_in_rows));
    $impliedParents = $parentStmt->fetchAll(PDO::FETCH_COLUMN);
    $existing_cat_rows = array_unique(array_merge($existing_cat_rows, $impliedParents));
}
$selected_parent_ids = array_values(array_intersect($existing_cat_rows, $all_parent_ids_flat));
$selected_sub_ids    = array_values(array_diff($existing_cat_rows, $all_parent_ids_flat));
$condition_id      = $listing['ConditionID'];
$rate_type_id      = $listing['RateTypeID'];
$neighborhood_id   = $listing['NeighborhoodID'];
$listing_status_id = $listing['ListingStatusID'];
$always_available = intval($listing['AlwaysAvailable'] ?? 0);
// Fetch existing owner-blocked dates for this listing
// Schema change: ListingID is now a direct FK on TListingAvailability; UnavailableDate replaces StartDate.
$existingAvailableStmt = $pdo->prepare("
    SELECT DATE(AvailableDate) AS d
    FROM TListingAvailability
    WHERE ListingID = ?
      AND BlockReasonID = 0
      AND AvailableDate IS NOT NULL
");
$existingAvailableStmt->execute([$listingId]);
$existingAvailable = array_column($existingAvailableStmt->fetchAll(PDO::FETCH_ASSOC), 'd');

$existingAvailableJson = json_encode($existingAvailable);

// Fetch booked ranges (accepted requests / active rentals)
$bookedRangesStmt = $pdo->prepare("
    SELECT DATE(StartDate) as start, DATE(EndDate) as end FROM TRentalRequests
    WHERE ListingID = ? AND RequestStatusID IN (1,2)
    UNION
    SELECT DATE(rr.StartDate), DATE(rr.EndDate) FROM TRentals r
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    WHERE r.ListingID = ? AND r.RentalStatusID = 4
");
$bookedRangesStmt->execute([$listingId, $listingId]);
$bookedRangesRaw = $bookedRangesStmt->fetchAll(PDO::FETCH_ASSOC);

$price_per_day     = $listing['PricePerDay']  ?? '';
$price_per_hour    = $listing['PricePerHour'] ?? '';
$state_id          = $listing['StateID']      ?? 0;
$city_id           = $listing['CityID']       ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title             = trim($_POST['title']              ?? '');
    $description       = trim($_POST['description']        ?? '');
    $selected_parent_ids = array_map('intval', $_POST['category_ids'] ?? []);
    $selected_sub_ids    = array_map('intval', $_POST['subcategory_ids'] ?? []);
    $category_id    = !empty($selected_parent_ids) ? $selected_parent_ids[0] : 0;
    $subcategory_id = !empty($selected_sub_ids)    ? $selected_sub_ids[0]    : 0;
    $condition_id      = intval($_POST['condition_id']     ?? 0);
    $rate_type_id      = intval($_POST['rate_type_id']     ?? 0);
    $neighborhood_id   = intval($_POST['neighborhood_id']  ?? 0);
    $listing_status_id = intval($_POST['listing_status_id'] ?? 1);
    $state_id          = intval($_POST['state']            ?? 0);
    $city_id           = intval($_POST['city']             ?? 0);
    $price_per_day     = trim($_POST['price_per_day']      ?? '');
    $price_per_hour    = trim($_POST['price_per_hour']     ?? '');
    $deletePhotoIds    = $_POST['delete_photos']           ?? [];
    $always_available = isset($_POST['always_available']) ? 1 : 0;

    $finalCategoryId = ($subcategory_id > 0) ? $subcategory_id : $category_id;
    // Also store parents of any selected subcategories so pre-fill works on next load
    $allCategoryIds = array_unique(array_filter(array_merge($selected_sub_ids, $selected_parent_ids)));
    if (!empty($selected_sub_ids)) {
        $ph = implode(',', array_fill(0, count($selected_sub_ids), '?'));
        $pStmt = $pdo->prepare("SELECT DISTINCT ParentCategoryID FROM TCategories WHERE CategoryID IN ($ph) AND ParentCategoryID != 0");
        $pStmt->execute(array_values($selected_sub_ids));
        $impliedParents = $pStmt->fetchAll(PDO::FETCH_COLUMN);
        $allCategoryIds = array_unique(array_merge($allCategoryIds, $impliedParents));
    }

    // Validate required fields
    if (empty($title))           $errors[] = 'Item title is required.';
    if (empty($description))     $errors[] = 'Description is required.';
    if (empty($selected_parent_ids)) $errors[] = 'Please select at least one category.';
    if ($condition_id === 0)     $errors[] = 'Please select the item condition.';
    if ($rate_type_id === 0)     $errors[] = 'Please select a rate type.';
    if ($neighborhood_id === 0)  $errors[] = 'Please select a neighborhood.';

    // Validate price based on rate type (1=Daily, 2=Hourly, 3=Both)
    $priceDay  = ($price_per_day  !== '') ? floatval($price_per_day)  : null;
    $priceHour = ($price_per_hour !== '') ? floatval($price_per_hour) : null;

    if ($rate_type_id === 1 && ($priceDay === null || $priceDay <= 0))
        $errors[] = 'Please enter a valid price per day.';
    if ($rate_type_id === 2 && ($priceHour === null || $priceHour <= 0))
        $errors[] = 'Please enter a valid price per hour.';
    if ($rate_type_id === 3) {
        if ($priceDay  === null || $priceDay  <= 0) $errors[] = 'Please enter a valid price per day.';
        if ($priceHour === null || $priceHour <= 0) $errors[] = 'Please enter a valid price per hour.';
    }
    
    $availableDates = json_decode($_POST['available_dates_json'] ?? '[]', true);
$availableDates = is_array($availableDates) ? $availableDates : [];
$availableDates = array_values(array_unique(array_filter(array_map('trim', $availableDates))));

if (!$always_available && empty($availableDates)) {
    $errors[] = 'Please select at least one available date.';
}
    // Handle new photo uploads
    $uploadedPhotos = [];
    if (!empty($_FILES['photos']['name'][0])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm'];
        $uploadDir    = 'uploads/listings/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
            if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;

            $mimeType = mime_content_type($tmpName);
            if (!in_array($mimeType, $allowedTypes)) {
                $errors[] = 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, MOV, WEBM) are allowed.';
                break;
            }

            $ext      = pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION);
            $filename = uniqid('listing_', true) . '.' . $ext;
            $destPath = $uploadDir . $filename;

            if (move_uploaded_file($tmpName, $destPath)) {
                $uploadedPhotos[] = $destPath;
            }
        }
    }
  
    if (empty($errors)) {
    try {
        // Reactivate inactive listing so uspUpdateListing will allow the edit
        $pdo->prepare("
            UPDATE TListings
            SET ListingStatusID = 1
            WHERE ListingID = ? AND UserLenderID = ? AND ListingStatusID = 4
        ")->execute([$listingId, $userId]);

        // ── Call uspUpdateListing ──────────────────────────────
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $stmt = $pdo->prepare("CALL uspUpdateListing(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $listingId,
            $userId,
            $neighborhood_id,
            $finalCategoryId,
            $title,
            $description,
            $condition_id,
            $rate_type_id,
            $priceDay,
            $priceHour
        ]);

        do {
            $stmt->fetchAll();
        } while ($stmt->nextRowset());

        $stmt->closeCursor();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->prepare("
                UPDATE TListings
                SET AlwaysAvailable = ?
                WHERE ListingID = ? AND UserLenderID = ?
            ")->execute([
                $always_available,
                $listingId,
                $userId
            ]);
            // ── Update listing status ──
            // Use checkbox: checked = Active(1), unchecked = Inactive(4)
     
          
           

            // ── Save availability date-range (BlockReasonID = 0) ──
            // Delete old date-range rows, then re-insert one row per day if a range was specified.
          
            
            // remove old owner-controlled availability rows
           $pdo->prepare("
    DELETE FROM TListingAvailability
    WHERE ListingID = ? AND BlockReasonID = 0
")->execute([$listingId]);

if (!$always_available && !empty($availableDates)) {
    $availableStmt = $pdo->prepare("
        INSERT INTO TListingAvailability (
            ListingID,
            AvailableDate,
            UnavailableDate,
            BlockReasonID
        )
        VALUES (?, ?, NULL, 0)
    ");

    $seenDates = [];

    foreach ($availableDates as $availableDate) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $availableDate)) {
            continue;
        }

        if (isset($seenDates[$availableDate])) {
            continue;
        }
        $seenDates[$availableDate] = true;

        $availableStmt->execute([
            $listingId,
            $availableDate
        ]);
    }
}
            // ── Delete photos via uspRemoveListingPhoto ────────────
            if (!empty($deletePhotoIds)) {
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                $delStmt = $pdo->prepare("CALL uspRemoveListingPhoto(?, ?)");
                foreach ($deletePhotoIds as $photoId) {
                    $delStmt->execute([intval($photoId), $userId]);
                    $delStmt->closeCursor();
                }
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            }

            // ── Add new photos via uspAddListingPhoto ──────────────
            if (!empty($uploadedPhotos)) {
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                $photoStmtNew = $pdo->prepare("CALL uspAddListingPhoto(?, ?, ?, ?)");
                foreach ($uploadedPhotos as $photoPath) {
                    $photoStmtNew->execute([
                        $listingId,
                        $userId,
                        $photoPath,
                        null   // NULL = auto-assign sort order
                    ]);
                    $photoStmtNew->closeCursor();
                }
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            }

            // Update TListingCategories (table may not exist yet if migration hasn't run)
            try {
                $pdo->prepare("DELETE FROM TListingCategories WHERE ListingID = ?")->execute([$listingId]);
                $lcStmt = $pdo->prepare("INSERT IGNORE INTO TListingCategories (ListingID, CategoryID, IsPrimary) VALUES (?, ?, ?)");
                foreach ($allCategoryIds as $catId) {
                    if ($catId > 0) $lcStmt->execute([$listingId, $catId, ($catId === $finalCategoryId) ? 1 : 0]);
                }
            } catch (PDOException $e) { /* TListingCategories not yet created -- run add_listing_categories.sql */ }
            // Update photo sort order from drag-reorder
            $photoOrder = array_map('intval', array_filter($_POST['photo_order'] ?? []));
            if (!empty($photoOrder)) {
                $sortStmt = $pdo->prepare("UPDATE TListingPhotos SET SortOrder = ? WHERE ListingPhotoID = ? AND ListingID = ?");
                foreach ($photoOrder as $sortPos => $photoId) {
                    $sortStmt->execute([$sortPos + 1, $photoId, $listingId]);
                }
            }
            header('Location: my_items.php?updated=1');
            exit;

        } catch (PDOException $e) {
            error_log("edit_listing error: " . $e->getMessage());
            $errors[] = 'Something went wrong saving your changes. Please try again.';
        }
    }

    // On validation failure, keep selected location values
    $state_id = $state_id;
    $city_id  = $city_id;

    // Re-fetch existing photos in case deletions were partially processed
    $photoStmt->execute([$listingId]);
    $existingPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Listing - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/ai_chatbot.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
</head>
<body>

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
                    <a href="https://thecommunitytoolkit.com/" class="nav-link">
                        <i class="fas fa-home"></i>
                        <span>Home</span>
                    </a>
                    <a href="my_items.php" class="nav-link active">
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

    <!-- Main Content -->
    <main class="main-content">
        <div class="listing-page-wrap">

            <h1>Edit Listing</h1>

            <?php if (!empty($errors)): ?>
                <div class="listing-errors">
                    <p><i class="fas fa-exclamation-circle"></i> Please fix the following:</p>
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="edit_listing.php?id=<?php echo $listingId; ?>" enctype="multipart/form-data" id="editListingForm">

                <!-- Section 1: Photos & Videos -->
                <div class="listing-section">
                    <div class="listing-section-title">
                        <i class="fas fa-camera"></i> Photos &amp; Videos
                    </div>

                    <!-- Existing photos -->
                    <?php if (!empty($existingPhotos)): ?>
                        <p style="font-size:14px; font-weight:600; color:#374151; margin-bottom:12px;">
                            Current Photos — click <i class="fas fa-times" style="color:#ef4444;"></i> to mark for removal
                        </p>
                        <div class="existing-photos-grid" id="existingPhotosGrid">
                            <?php foreach ($existingPhotos as $idx => $photo): ?>
                                <div class="existing-photo-thumb" id="thumb-<?php echo $photo['ListingPhotoID']; ?>"
                                     data-photoid="<?php echo $photo['ListingPhotoID']; ?>">
                                    <?php if ($idx === 0): ?><span class="photo-primary-badge">Cover</span><?php endif; ?>
                                    <img src="<?php echo htmlspecialchars($photo['PhotoURL']); ?>" alt="Listing photo">
                                    <span class="drag-hint"><i class="fas fa-grip-vertical"></i></span>
                                    <button type="button" class="delete-overlay"
                                            onclick="toggleDeletePhoto(<?php echo $photo['ListingPhotoID']; ?>)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="delete-label">Remove</div>
                                    <input type="checkbox" name="delete_photos[]"
                                           value="<?php echo $photo['ListingPhotoID']; ?>"
                                           id="del-<?php echo $photo['ListingPhotoID']; ?>"
                                           style="display:none;">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="photoOrderContainer"></div>
                    <?php else: ?>
                        <p style="color:#9ca3af; font-size:14px; font-style:italic; margin-bottom:16px;">No photos currently attached to this listing.</p>
                    <?php endif; ?>

                    <!-- Upload new photos -->
                    <p style="font-size:14px; font-weight:600; color:#374151; margin-bottom:12px;">Add New Photos</p>

                    <div class="upload-zone" id="uploadZone">
                        <input type="file" name="photos[]" id="photoInput"
                               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm"
                               multiple>
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p><strong>Drag &amp; drop photos or videos here</strong><br>
                        or click to browse your files</p>
                        <p style="margin-top:8px; font-size:12px;">JPG, PNG, GIF, WEBP · MP4, MOV, WEBM · Up to 10 files</p>
                    </div>

                    <div class="photo-preview-grid" id="previewGrid"></div>
                </div>

                <!-- Section 2: Basic Information -->
                <div class="listing-section">
                    <div class="listing-section-title">
                        <i class="fas fa-tag"></i> Basic Information
                    </div>

                    <div class="form-group">
                        <label>Item Title *</label>
                        <input type="text" name="title" maxlength="255"
                               value="<?php echo htmlspecialchars($title); ?>"
                               placeholder="e.g. DeWalt 20V Power Drill Set" required>
                    </div>

                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" id="descriptionField" maxlength="2000"
                                  placeholder="Describe your item — condition, features, what it's best used for..."
                                  required><?php echo htmlspecialchars($description); ?></textarea>
                        <small>Maximum 2000 characters</small>
                        <button type="button" class="ai-assist-btn" id="aiDescBtn">
                            <i class="fas fa-magic"></i> Write with AI
                        </button>
                        <div class="ai-result-box" id="aiDescResult">
                            <span id="aiDescText"></span>
                            <div class="ai-result-actions">
                                <button type="button" class="ai-use-btn" id="aiDescUse">Use This</button>
                                <button type="button" class="ai-dismiss-btn" id="aiDescDismiss">Dismiss</button>
                            </div>
                        </div>
                    </div>

                    <?php
                    $allSubcats = $pdo->query("SELECT CategoryID, CategoryName, ParentCategoryID FROM TCategories WHERE ParentCategoryID != 0 ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
                    $subcatsByParent = [];
                    foreach ($allSubcats as $sc) { $subcatsByParent[$sc['ParentCategoryID']][] = $sc; }
                    ?>
                    <div class="form-group">
                        <label>Category * <span style="font-weight:400;font-size:12px;color:#6b7280;">(select all that apply)</span></label>
                        <div class="cat-tag-picker" id="multiCatPicker">
                            <?php foreach ($categories as $cat): ?>
                            <?php $cid = $cat['CategoryID']; $chk = in_array($cid, $selected_parent_ids); ?>
                            <div class="cat-tag-row">
                                <input type="checkbox" name="category_ids[]" value="<?php echo $cid; ?>" id="cat-<?php echo $cid; ?>" <?php echo $chk ? 'checked' : ''; ?> style="display:none;">
                                <button type="button" class="cat-tag<?php echo $chk ? ' selected' : ''; ?>" onclick="toggleCatTag(<?php echo $cid; ?>, this)">
                                    <?php echo htmlspecialchars($cat['CategoryName']); ?>
                                </button>
                                <?php if (!empty($subcatsByParent[$cid])): ?>
                                <div class="cat-sub-tags" id="subs-<?php echo $cid; ?>" <?php echo $chk ? '' : 'style="display:none;"'; ?>>
                                    <?php foreach ($subcatsByParent[$cid] as $sub): ?>
                                    <?php $schk = in_array($sub['CategoryID'], $selected_sub_ids); ?>
                                    <input type="checkbox" name="subcategory_ids[]" value="<?php echo $sub['CategoryID']; ?>" id="sub-<?php echo $sub['CategoryID']; ?>" <?php echo $schk ? 'checked' : ''; ?> style="display:none;">
                                    <button type="button" class="cat-sub-tag<?php echo $schk ? ' selected' : ''; ?>" onclick="toggleSubTag(<?php echo $sub['CategoryID']; ?>, this)">
                                        <?php echo htmlspecialchars($sub['CategoryName']); ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Condition *</label>
                        <select name="condition_id" required>
                            <option value="">Select condition...</option>
                            <?php foreach ($conditions as $cond): ?>
                                <option value="<?php echo $cond['ConditionID']; ?>"
                                    <?php echo ($condition_id == $cond['ConditionID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cond['Condition']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
               <div class="availability-section">
    <div class="form-group" style="margin-bottom:16px;">
        <label for="alwaysAvailableCheckbox" style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" name="always_available" id="alwaysAvailableCheckbox" value="1"
                <?php echo $always_available ? 'checked' : ''; ?>
                style="margin:0;width:16px;height:16px;flex:0 0 auto;">
            <span>This item is always available</span>
        </label>
    </div>

    <div id="availabilityDateSection">
        <h3>Item Availability</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr 140px 140px;gap:12px;margin-bottom:16px;align-items:end;">
            <div class="date-group">
                <label for="rangeStartDate">Available From</label>
                <input type="date" id="rangeStartDate">
            </div>

            <div class="date-group">
                <label for="rangeEndDate">Available Until</label>
                <input type="date" id="rangeEndDate">
            </div>

            <button type="button" id="addAvailableRangeBtn" style="
                height:42px;
                border:none;
                border-radius:8px;
                background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color:#fff;
                cursor:pointer;
                font-size:14px;
                font-weight:600;
                white-space:nowrap;
            ">
                <i class="fas fa-calendar-plus"></i> Add Range
            </button>

            <button type="button" id="clearAllDatesBtn" style="
                height:42px;
                border:1px solid #d1d5db;
                border-radius:8px;
                background:#fff;
                color:#374151;
                font-weight:600;
                cursor:pointer;
            ">
                Clear All
            </button>
        </div>

        <div id="specificDaysWrapper" class="listing-section" style="margin-top:16px;">
            <div class="listing-section-title">
                <i class="fas fa-calendar-alt"></i> Specific Available Days
            </div>

            <p id="specificDaysMessage" style="font-size:13px;color:#6b7280;margin-bottom:16px;">
                Click dates below to mark specific available days.
            </p>

            <div style="border:1.5px solid #e5e7eb;border-radius:12px;padding:16px;background:#fafafa;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <button type="button" onclick="avPrev()" id="avPrevBtn" style="background:none;border:1px solid #e5e7eb;border-radius:50%;width:32px;height:32px;cursor:pointer;">‹</button>
                    <span id="avMonthLabel" style="font-size:15px;font-weight:700;color:#1a1a2e;"></span>
                    <button type="button" onclick="avNext()" id="avNextBtn" style="background:none;border:1px solid #e5e7eb;border-radius:50%;width:32px;height:32px;cursor:pointer;">›</button>
                </div>

                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:8px;">
                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;">Su</div>
                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;">Mo</div>
                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;">Tu</div>
                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;">We</div>
                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;">Th</div>
                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;">Fr</div>
                    <div style="text-align:center;font-size:11px;font-weight:600;color:#9ca3af;padding:4px 0;">Sa</div>
                </div>

                <div id="avGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;"></div>
            </div>

            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:12px;">
                <span style="display:inline-flex;align-items:center;gap:6px;">
                    <span style="width:14px;height:14px;border-radius:4px;background:#d1fae5;border:1px solid #6ee7b7;display:inline-block;"></span>
                    Available
                </span>

                <span style="display:inline-flex;align-items:center;gap:6px;">
                    <span style="width:14px;height:14px;border-radius:4px;background:#ffffff;border:1px solid #e5e7eb;display:inline-block;"></span>
                    Not selected
                </span>
            </div>

            <input type="hidden" name="available_dates_json" id="availableDatesJson">
        </div>
    </div>
</div>

                <!-- Section 3: Location -->
                <div class="listing-section">
                    <div class="listing-section-title">
                        <i class="fas fa-map-marker-alt"></i> Location
                    </div>

                    <small style="display:block; margin-bottom:16px; color:#6b7280;">
                        <i class="fas fa-info-circle"></i>
                        Current location pre-filled. Change if the item is located elsewhere.
                    </small>

                    <div class="form-group">
                        <label>State *</label>
                        <select name="state" id="stateSelect" required onchange="loadCities()">
                            <option value="">Select State</option>
                            <?php foreach ($states as $state): ?>
                                <option value="<?php echo $state['StateID']; ?>"
                                    <?php echo ($state_id == $state['StateID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($state['StateName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="cityContainer">
                        <label>City *</label>
                        <select name="city" id="citySelect" onchange="loadNeighborhoods()">
                            <option value="">Select City</option>
                        </select>
                    </div>

                    <div class="form-group" id="neighborhoodContainer">
                        <label>Neighborhood *</label>
                        <select name="neighborhood_id" id="neighborhoodSelect">
                            <option value="">Select Neighborhood</option>
                        </select>
                        <small>Where borrowers will pick up the item.</small>
                    </div>

                    <input type="hidden" id="prefilledCityId"         value="<?php echo intval($city_id); ?>">
                    <input type="hidden" id="prefilledNeighborhoodId" value="<?php echo intval($neighborhood_id); ?>">
                </div>

                <!-- Section 4: Pricing -->
                <div class="listing-section">
                    <div class="listing-section-title">
                        <i class="fas fa-dollar-sign"></i> Pricing
                    </div>

                    <div class="form-group">
                        <label>Rate Type *</label>
                        <select name="rate_type_id" id="rateTypeSelect" onchange="updatePriceFields()" required>
                            <option value="">Select how you want to charge...</option>
                            <?php foreach ($rateTypes as $rt): ?>
                                <option value="<?php echo $rt['RateTypeID']; ?>"
                                    <?php echo ($rate_type_id == $rt['RateTypeID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rt['RateType']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Choose "Both" to let borrowers pick daily or hourly rental.</small>
                    </div>

                    <div class="price-fields-wrap">
                        <div class="price-field" id="fieldPriceDay">
                            <div class="form-group" style="margin-bottom:0;">
                                <label>Price Per Day *</label>
                                <div class="price-input-wrap">
                                    <span class="price-prefix">$</span>
                                    <input type="number" name="price_per_day"
                                           min="0.01" step="0.01" placeholder="0.00"
                                           value="<?php echo htmlspecialchars($price_per_day); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="price-field" id="fieldPriceHour">
                            <div class="form-group" style="margin-bottom:0;">
                                <label>Price Per Hour *</label>
                                <div class="price-input-wrap">
                                    <span class="price-prefix">$</span>
                                    <input type="number" name="price_per_hour"
                                           min="0.01" step="0.01" placeholder="0.00"
                                           value="<?php echo htmlspecialchars($price_per_hour); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="ai-assist-btn" id="aiPriceBtn">
                        <i class="fas fa-tag"></i> Suggest Price with AI
                    </button>
                    <div class="ai-result-box" id="aiPriceResult">
                        <span id="aiPriceText"></span>
                        <div class="ai-result-actions">
                            <button type="button" class="ai-dismiss-btn" id="aiPriceDismiss">Dismiss</button>
                        </div>
                    </div>
                </div>

            
                <!-- Actions -->
                <div class="listing-actions">
                    <a href="my_items.php" class="btn btn-outline">← Cancel</a>
                    <button type="button" class="btn btn-primary" onclick="submitEditListing(this)">Save Changes</button>
                </div>

            </form>
        </div>
    </main>

    <script>
        // ── Nav / dropdown ───────────────────────────────────────
        function toggleUserMenu() {
            document.getElementById('userDropdown').classList.toggle('show');
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
        document.getElementById('clearAllDatesBtn').addEventListener('click', function () {
                availableDates.clear();
                renderAvailableDateInputs();
                renderAvailableDatesList();
                avRender();
            });
        function toggleCatTag(parentId, btn) {
            var cb = document.getElementById('cat-' + parentId);
            var sg = document.getElementById('subs-' + parentId);
            cb.checked = !cb.checked;
            btn.classList.toggle('selected', cb.checked);
            if (sg) sg.style.display = cb.checked ? 'flex' : 'none';
            if (!cb.checked && sg) {
                sg.querySelectorAll('input[type=checkbox]').forEach(function(c) { c.checked = false; });
                sg.querySelectorAll('.cat-sub-tag').forEach(function(b) { b.classList.remove('selected'); });
            }
        }
        function toggleSubTag(subId, btn) {
            var cb = document.getElementById('sub-' + subId);
            cb.checked = !cb.checked;
            btn.classList.toggle('selected', cb.checked);
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#multiCatPicker input[name="category_ids[]"]').forEach(function(cb) {
                if (cb.checked) { var sg = document.getElementById('subs-' + cb.value); if (sg) sg.style.display = 'flex'; }
            });
        });

        // ── Location cascade ─────────────────────────────────────
        async function loadCities() {
            const stateId               = document.getElementById('stateSelect').value;
            const cityContainer         = document.getElementById('cityContainer');
            const citySelect            = document.getElementById('citySelect');
            const neighborhoodContainer = document.getElementById('neighborhoodContainer');
            const neighborhoodSelect    = document.getElementById('neighborhoodSelect');

            citySelect.innerHTML         = '<option value="">Select City</option>';
            neighborhoodSelect.innerHTML = '<option value="">Select Neighborhood</option>';
            neighborhoodContainer.style.display = 'none';

            if (!stateId) {
                cityContainer.style.display = 'none';
                return;
            }

            try {
                const response = await fetch(`get_cities.php?state_id=${stateId}`);
                const cities   = await response.json();

                cities.forEach(city => {
                    const option       = document.createElement('option');
                    option.value       = city.CityID;
                    option.textContent = city.CityName;
                    citySelect.appendChild(option);
                });

                cityContainer.style.display = 'block';
                citySelect.required = true;

                const prefilledCity = document.getElementById('prefilledCityId').value;
                if (prefilledCity) {
                    const matchingOption = citySelect.querySelector(`option[value="${prefilledCity}"]`);
                    if (matchingOption) {
                        citySelect.value = prefilledCity;
                        await loadNeighborhoods();
                    }
                }
            } catch (error) {
                console.error('Error loading cities:', error);
            }
        }

        async function loadNeighborhoods() {
            const cityId                = document.getElementById('citySelect').value;
            const neighborhoodContainer = document.getElementById('neighborhoodContainer');
            const neighborhoodSelect    = document.getElementById('neighborhoodSelect');

            neighborhoodSelect.innerHTML = '<option value="">Select Neighborhood</option>';

            if (!cityId) {
                neighborhoodContainer.style.display = 'none';
                neighborhoodSelect.required = false;
                return;
            }

            try {
                const response      = await fetch(`get_neighborhoods.php?city_id=${cityId}`);
                const neighborhoods = await response.json();

                if (neighborhoods.length === 0) {
                    neighborhoodContainer.style.display = 'none';
                    neighborhoodSelect.required = false;

                    let hiddenInput = document.getElementById('autoNeighborhood');
                    if (!hiddenInput) {
                        hiddenInput      = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.id   = 'autoNeighborhood';
                        hiddenInput.name = 'neighborhood_id';
                        document.getElementById('editListingForm').appendChild(hiddenInput);
                    }
                    hiddenInput.value = cityId;
                } else {
                    neighborhoods.forEach(n => {
                        const option       = document.createElement('option');
                        option.value       = n.NeighborhoodID;
                        option.textContent = n.NeighborhoodName;
                        neighborhoodSelect.appendChild(option);
                    });

                    neighborhoodContainer.style.display = 'block';
                    neighborhoodSelect.required = true;

                    const hiddenInput = document.getElementById('autoNeighborhood');
                    if (hiddenInput) hiddenInput.remove();

                    const prefilledNeighborhood = document.getElementById('prefilledNeighborhoodId').value;
                    if (prefilledNeighborhood) {
                        const matchingOption = neighborhoodSelect.querySelector(`option[value="${prefilledNeighborhood}"]`);
                        if (matchingOption) {
                            neighborhoodSelect.value = prefilledNeighborhood;
                        }
                    }
                }
            } catch (error) {
                console.error('Error loading neighborhoods:', error);
            }
        }

        // ── Price field show/hide (1=Daily, 2=Hourly, 3=Both) ────
        function updatePriceFields() {
            const rateType  = parseInt(document.getElementById('rateTypeSelect').value);
            const dayField  = document.getElementById('fieldPriceDay');
            const hourField = document.getElementById('fieldPriceHour');

            dayField.classList.remove('visible');
            hourField.classList.remove('visible');

            const wrap = document.querySelector('.price-fields-wrap');
            if (rateType === 1) {
                dayField.classList.add('visible');
                if (wrap) wrap.style.gridTemplateColumns = '1fr';
            } else if (rateType === 2) {
                hourField.classList.add('visible');
                if (wrap) wrap.style.gridTemplateColumns = '1fr';
            } else if (rateType === 3) {
                dayField.classList.add('visible');
                hourField.classList.add('visible');
                if (wrap) wrap.style.gridTemplateColumns = '';
            }
        }

        // ── Mark existing photo for deletion ─────────────────────
        function toggleDeletePhoto(photoId) {
            const thumb    = document.getElementById('thumb-' + photoId);
            const checkbox = document.getElementById('del-' + photoId);
            if (checkbox.checked) {
                checkbox.checked = false;
                thumb.classList.remove('marked-delete');
            } else {
                checkbox.checked = true;
                thumb.classList.add('marked-delete');
            }
        }

        // Drag-to-reorder existing photos
        (function() {
            var grid = document.getElementById('existingPhotosGrid');
            if (!grid) return;
            var dragSrc = null;
            function syncOrder() {
                var oc = document.getElementById('photoOrderContainer');
                if (!oc) return;
                oc.innerHTML = '';
                grid.querySelectorAll('.existing-photo-thumb').forEach(function(t) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = 'photo_order[]'; inp.value = t.dataset.photoid;
                    oc.appendChild(inp);
                });
            }
            function updateCover() {
                grid.querySelectorAll('.existing-photo-thumb').forEach(function(t, i) {
                    var b = t.querySelector('.photo-primary-badge');
                    if (i === 0) { if (!b) { b = document.createElement('span'); b.className = 'photo-primary-badge'; b.textContent = 'Cover'; t.appendChild(b); } }
                    else { if (b) b.remove(); }
                });
            }
            grid.querySelectorAll('.existing-photo-thumb').forEach(function(thumb) {
                thumb.setAttribute('draggable', 'true');
                thumb.addEventListener('dragstart', function(e) { dragSrc = this; this.classList.add('drag-source'); e.dataTransfer.effectAllowed = 'move'; });
                thumb.addEventListener('dragend', function() { this.classList.remove('drag-source'); grid.querySelectorAll('.existing-photo-thumb').forEach(function(t) { t.classList.remove('drag-over'); }); dragSrc = null; });
                thumb.addEventListener('dragover', function(e) { e.preventDefault(); grid.querySelectorAll('.existing-photo-thumb').forEach(function(t) { t.classList.remove('drag-over'); }); if (this !== dragSrc) this.classList.add('drag-over'); });
                thumb.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
                thumb.addEventListener('drop', function(e) {
                    e.preventDefault(); this.classList.remove('drag-over');
                    if (!dragSrc || dragSrc === this) return;
                    var all = Array.from(grid.querySelectorAll('.existing-photo-thumb'));
                    if (all.indexOf(dragSrc) < all.indexOf(this)) grid.insertBefore(dragSrc, this.nextSibling);
                    else grid.insertBefore(dragSrc, this);
                    updateCover(); syncOrder();
                });
            });
            syncOrder(); // initialise hidden inputs on load
        })();

        // ── On page load ─────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', async function() {
            updatePriceFields();

            // Show subcats for pre-checked parents on page load
            document.querySelectorAll('#multiCatPicker input[name="category_ids[]"]').forEach(function(cb) {
                if (cb.checked) { var sg = document.getElementById('subs-' + cb.value); if (sg) sg.style.display = 'flex'; }
            });

            const stateSelect = document.getElementById('stateSelect');
            if (stateSelect && stateSelect.value) {
                await loadCities();
            }
        });

        // ── Drag & drop new photo preview ────────────────────────
        let selectedFiles = [];

        const uploadZone  = document.getElementById('uploadZone');
        const photoInput  = document.getElementById('photoInput');
        const previewGrid = document.getElementById('previewGrid');

        uploadZone.addEventListener('dragover', e => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', e => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            addFiles(Array.from(e.dataTransfer.files));
        });

        photoInput.addEventListener('change', function() {
            addFiles(Array.from(this.files));
        });

        // Drag-to-reorder state
        let dragSrcIndex = null;

        function addDragHandlers(thumb) {
            thumb.setAttribute('draggable', 'true');

            thumb.addEventListener('dragstart', function(e) {
                dragSrcIndex = parseInt(this.dataset.index);
                this.classList.add('drag-source');
                e.dataTransfer.effectAllowed = 'move';
            });

            thumb.addEventListener('dragend', function() {
                this.classList.remove('drag-source');
                document.querySelectorAll('.photo-thumb').forEach(t => t.classList.remove('drag-over'));
                dragSrcIndex = null;
            });

            thumb.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                document.querySelectorAll('.photo-thumb').forEach(t => t.classList.remove('drag-over'));
                if (dragSrcIndex !== null && parseInt(this.dataset.index) !== dragSrcIndex) {
                    this.classList.add('drag-over');
                }
            });

            thumb.addEventListener('dragleave', function() {
                this.classList.remove('drag-over');
            });

            thumb.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
                const destIndex = parseInt(this.dataset.index);
                if (dragSrcIndex === null || dragSrcIndex === destIndex) return;
                const tmp = selectedFiles[dragSrcIndex];
                selectedFiles[dragSrcIndex] = selectedFiles[destIndex];
                selectedFiles[destIndex] = tmp;
                previewGrid.innerHTML = '';
                selectedFiles.forEach((f, i) => { if (f) renderThumb(f, i); });
                syncFileInput();
            });
        }

        async function addFiles(newFiles) {
            let isFirst = true;
            for (const file of newFiles) {
                if (selectedFiles.filter(f => f).length >= 10) break;

                if (file.type.startsWith('video/')) {
                    selectedFiles.push(file);
                    renderThumb(file, selectedFiles.length - 1);
                } else {
                    const cropped = await openCropper(file);
                    if (cropped) {
                        selectedFiles.push(cropped);
                        renderThumb(cropped, selectedFiles.length - 1);
                        // Scan only the first new image added per batch
                        if (isFirst) {
                            isFirst = false;
                            autoScanImage(cropped);
                        }
                    }
                }
            }
            syncFileInput();
        }

        function renderThumb(file, index) {
            const thumb = document.createElement('div');
            thumb.className     = 'photo-thumb';
            thumb.dataset.index = index;

            const removeBtn     = document.createElement('button');
            removeBtn.type      = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.onclick   = () => removeFile(index);

            // Cover badge on first photo
            const firstReal = selectedFiles.findIndex(f => f);
            if (index === firstReal) {
                const badge = document.createElement('span');
                badge.className = 'photo-primary-badge';
                badge.textContent = 'Cover';
                thumb.appendChild(badge);
            }

            if (file.type.startsWith('video/')) {
                const video    = document.createElement('video');
                video.src      = URL.createObjectURL(file);
                video.muted    = true;
                video.controls = false;
                thumb.appendChild(video);
            } else {
                const img = document.createElement('img');
                img.src   = URL.createObjectURL(file);
                img.alt   = file.name;
                thumb.appendChild(img);
            }

            const dragHint = document.createElement('span');
            dragHint.className = 'drag-hint';
            dragHint.innerHTML = '<i class="fas fa-grip-vertical"></i>';
            thumb.appendChild(dragHint);

            thumb.appendChild(removeBtn);
            addDragHandlers(thumb);
            previewGrid.appendChild(thumb);
        }

        function removeFile(index) {
            selectedFiles[index] = null;
            const thumb = previewGrid.querySelector(`[data-index="${index}"]`);
            if (thumb) thumb.remove();
            syncFileInput();
        }

        function syncFileInput() {
            const dt = new DataTransfer();
            selectedFiles.forEach(f => { if (f) dt.items.add(f); });
            photoInput.files = dt.files;
        }

        // ── Category / Condition maps for AI prefill ─────────────────────────
        const categoryMap = <?php
            $catMap = [];
            foreach ($categories as $c) {
                $catMap[strtolower($c['CategoryName'])] = $c['CategoryID'];
            }
            echo json_encode($catMap);
        ?>;

        const subcatMap = <?php
            $allSubcatsEdit = $pdo->query("SELECT CategoryID, CategoryName, ParentCategoryID FROM TCategories WHERE ParentCategoryID != 0 ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
            $scMapEdit = [];
            foreach ($allSubcatsEdit as $sc) {
                $scMapEdit[strtolower($sc['CategoryName'])] = ['id' => $sc['CategoryID'], 'parentId' => $sc['ParentCategoryID']];
            }
            echo json_encode($scMapEdit);
        ?>;

        const conditionMap = <?php
            $condMap = [];
            foreach ($conditions as $c) {
                $condMap[strtolower($c['Condition'])] = $c['ConditionID'];
            }
            echo json_encode($condMap);
        ?>;

        // ── Cropper modal ─────────────────────────────────────────────────────
        let cropperInstance = null;
        let cropResolve = null;

        function openCropper(file) {
            return new Promise((resolve) => {
                cropResolve = resolve;
                const modal = document.getElementById('cropModal');
                const img   = document.getElementById('cropImage');
                img.src = URL.createObjectURL(file);
                modal.style.display = 'flex';
                img.onload = function() {
                    if (cropperInstance) cropperInstance.destroy();
                    cropperInstance = new Cropper(img, {
                        aspectRatio: NaN, viewMode: 1, autoCropArea: 1,
                        movable: true, zoomable: true, rotatable: false, scalable: false
                    });
                };
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('cropConfirmBtn')?.addEventListener('click', function() {
                if (!cropperInstance) return;
                cropperInstance.getCroppedCanvas({ maxWidth: 1200, maxHeight: 1200 }).toBlob(function(blob) {
                    const file = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
                    document.getElementById('cropModal').style.display = 'none';
                    cropperInstance.destroy();
                    cropperInstance = null;
                    if (cropResolve) { cropResolve(file); cropResolve = null; }
                }, 'image/jpeg', 0.92);
            });
            document.getElementById('cropCancelBtn')?.addEventListener('click', function() {
                document.getElementById('cropModal').style.display = 'none';
                if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
                if (cropResolve) { cropResolve(null); cropResolve = null; }
            });
        });

        // ── AI Image Scan — fires only when new photos are added ─────────────
        async function autoScanImage(file) {
            let indicator = document.getElementById('aiScanIndicator');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.id = 'aiScanIndicator';
                indicator.style.cssText = 'margin-top:10px;padding:10px 14px;background:#f0f2ff;border-radius:8px;font-size:13px;color:#667eea;display:flex;align-items:center;gap:8px;';
                indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing new photo with AI...';
                document.getElementById('previewGrid').after(indicator);
            } else {
                indicator.style.display = 'flex';
                indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing new photo with AI...';
            }

            const reader = new FileReader();
            reader.onload = async function(e) {
                const base64 = e.target.result;
                try {
                    const resp = await fetch('/api/ai_chatbot.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            mode: 'image_scan',
                            image: base64,
                            title: document.querySelector('input[name="title"]')?.value || '',
                            description: document.querySelector('textarea[name="description"]')?.value || ''
                        })
                    });
                    const json = await resp.json();

                    if (json.flagged || (json.data && json.data.flagged)) {
                        const reason = json.flag_reason || (json.data && json.data.flag_reason) || '';
                        indicator.innerHTML = '<i class="fas fa-ban"></i> Photo removed — see details below.';
                        indicator.style.background = '#fef2f2';
                        indicator.style.color = '#b91c1c';
                        // Remove the flagged file from selectedFiles
                        selectedFiles.pop();
                        const thumbs = document.querySelectorAll('#previewGrid .photo-thumb');
                        if (thumbs.length) thumbs[thumbs.length - 1].remove();
                        syncFileInput();
                        showFlagModal(reason);
                        return;
                    }

                    if (json.success && json.data) {
                        const d = json.data;

                        // Only prefill fields that are currently empty
                        const titleEl = document.querySelector('input[name="title"]');
                        if (titleEl && !titleEl.value.trim()) titleEl.value = d.title || '';

                        const descEl = document.querySelector('textarea[name="description"]');
                        if (descEl && !descEl.value.trim()) descEl.value = d.description || '';

                        // Categories — add to existing selections rather than replacing
                        const cats = d.categories || (d.category ? [d.category] : []);
                        cats.forEach(function(catName) {
                            const catId = categoryMap[catName.toLowerCase()];
                            if (catId) {
                                const catCb = document.getElementById('cat-' + catId);
                                if (catCb && !catCb.checked) {
                                    const catBtn = document.querySelector('#multiCatPicker .cat-tag[onclick*="toggleCatTag(' + catId + ',"]');
                                    if (catBtn) catBtn.click();
                                }
                            }
                        });

                        const subs = d.subcategories || (d.subcategory ? [d.subcategory] : []);
                        subs.forEach(function(subName) {
                            const subEntry = subcatMap[subName.toLowerCase()];
                            if (subEntry) {
                                const parentCb = document.getElementById('cat-' + subEntry.parentId);
                                if (parentCb && !parentCb.checked) {
                                    const parentBtn = document.querySelector('#multiCatPicker .cat-tag[onclick*="toggleCatTag(' + subEntry.parentId + ',"]');
                                    if (parentBtn) parentBtn.click();
                                }
                                const subCb = document.getElementById('sub-' + subEntry.id);
                                if (subCb && !subCb.checked) {
                                    const subBtn = document.querySelector('#multiCatPicker .cat-sub-tag[onclick*="toggleSubTag(' + subEntry.id + ',"]');
                                    if (subBtn) subBtn.click();
                                }
                            }
                        });

                        if (d.condition) {
                            const condId = conditionMap[d.condition.toLowerCase()];
                            if (condId) {
                                const condSelect = document.querySelector('select[name="condition_id"]');
                                if (condSelect && !condSelect.value) condSelect.value = condId;
                            }
                        }

                        indicator.innerHTML = '<i class="fas fa-check-circle"></i> AI suggestions applied — review and adjust as needed.';
                        indicator.style.background = '#f0fdf4';
                        indicator.style.color = '#16a34a';
                    } else {
                        indicator.innerHTML = '<i class="fas fa-exclamation-circle"></i> Could not analyze photo — fill in details manually.';
                        indicator.style.background = '#fff7ed';
                        indicator.style.color = '#ea580c';
                    }
                } catch (err) {
                    indicator.style.display = 'none';
                }
            };
            reader.readAsDataURL(file);
        }
    </script>

    <!-- ── Inline AI buttons for description + price ── -->
    <script>
    const AI_ENDPOINT = '/api/ai_chatbot.php';

    async function scanTextForViolations() {
        const title = document.querySelector('input[name="title"]')?.value || '';
        const desc  = document.querySelector('textarea[name="description"]')?.value || '';
        if (!title && !desc) return { flagged: false };
        try {
            const resp = await fetch('/api/ai_chatbot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: 'text_scan', title, description: desc })
            });
            return await resp.json();
        } catch (e) {
            return { flagged: false };
        }
    }

function toggleAvailabilityMode() {
    const checkbox = document.getElementById('alwaysAvailableCheckbox');
    const section = document.getElementById('availabilityDateSection');
    if (!checkbox || !section) return;

    section.style.display = checkbox.checked ? 'none' : 'block';
}
    function showFlagModal(reason) {
        const defaultReason = 'Our AI content scanner detected prohibited content in your listing text or photos.';
        document.getElementById('flagModalReason').innerHTML =
            '<i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><strong>Detected issue:</strong> ' +
            (reason || defaultReason);
        document.getElementById('contentFlagModal').style.display = 'flex';
    }

    async function submitEditListing(btn) {
        const form = document.getElementById('editListingForm');
        if (!form.checkValidity()) { form.reportValidity(); return; }

        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

        const scan = await scanTextForViolations();

        btn.disabled = false;
        btn.innerHTML = origText;

        if (scan.flagged) {
            showFlagModal(scan.flag_reason || '');
            return;
        }

        form.submit();
    }

    function getListingContext() {
    const titleEl = document.querySelector('input[name="title"]');
    const condEl = document.querySelector('select[name="condition_id"] option:checked');

    const selectedCategories = Array.from(
        document.querySelectorAll('#multiCatPicker input[name="category_ids[]"]:checked')
    ).map(input => {
        const labelBtn = document.querySelector(`#multiCatPicker button[onclick*="toggleCatTag(${input.value},"]`);
        return labelBtn ? labelBtn.textContent.trim() : input.value;
    });

    return {
        title: titleEl && titleEl.value ? titleEl.value : '',
        category: selectedCategories.join(', '),
        condition: condEl && condEl.value ? condEl.textContent.trim() : '',
    };
}

    async function callAI(mode, message) {
        const resp = await fetch(AI_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode, message, context: getListingContext() }),
        });
        return await resp.json();
    }

    // ── Write Description ──────────────────────────────────────────────────
    document.getElementById('aiDescBtn').addEventListener('click', async function() {
        const ctx = getListingContext();
        if (!ctx.title) { alert('Please fill in the Item Title first.'); return; }

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Writing...';

        try {
            const data = await callAI('description', '');
            document.getElementById('aiDescText').textContent = data.response;
            document.getElementById('aiDescResult').style.display = 'block';
        } catch(e) {
            alert('AI is temporarily unavailable. Please try again.');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-magic"></i> Write with AI';
        }
    });

    document.getElementById('aiDescUse').addEventListener('click', function() {
        const text = document.getElementById('aiDescText').textContent;
        document.getElementById('descriptionField').value = text;
        document.getElementById('aiDescResult').style.display = 'none';
    });
    document.getElementById('aiDescDismiss').addEventListener('click', function() {
        document.getElementById('aiDescResult').style.display = 'none';
    });

    // ── Suggest Price ──────────────────────────────────────────────────────
    document.getElementById('aiPriceBtn').addEventListener('click', async function() {
        const ctx = getListingContext();
        if (!ctx.title) { alert('Please fill in the Item Title first.'); return; }

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

        try {
            const data = await callAI('price', '');
            if (data.response) {
                document.getElementById('aiPriceText').textContent = data.response;
                document.getElementById('aiPriceResult').style.display = 'block';
            } else {
                console.error('Price AI error:', data);
                alert('Could not get a price suggestion. Error: ' + (data.error || data.debug_error || JSON.stringify(data)));
            }
        } catch(e) {
            console.error('Price AI fetch error:', e);
            alert('AI request failed: ' + e.message);
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-tag"></i> Suggest Price with AI';
        }
    });
    document.getElementById('aiPriceDismiss').addEventListener('click', function() {
        document.getElementById('aiPriceResult').style.display = 'none';
    });

    // ── Availability Calendar ────────────────────────────────
     //if the avaialbity is unchecked show the dates
const existingAvailableDates = <?php echo $existingAvailableJson ?? '[]'; ?>;
let availableDates = new Set(existingAvailableDates);

let avNow = new Date();
if (existingAvailableDates.length > 0) {
    const firstSaved = new Date(existingAvailableDates.slice().sort()[0] + 'T00:00:00');
    avNow = firstSaved;
}

let avYear = avNow.getFullYear();
let avMonth = avNow.getMonth();

const AV_MONTHS = [
    'January','February','March','April','May','June',
    'July','August','September','October','November','December'
];

document.addEventListener('DOMContentLoaded', function () {
    avRender();
    renderAvailableDateInputs();
    renderAvailableDatesList();

    const checkbox = document.getElementById('alwaysAvailableCheckbox');
    if (checkbox) {
        checkbox.addEventListener('change', toggleAvailabilityMode);
        toggleAvailabilityMode();
    }
});

function avToYMD(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function isAvailableDate(ymd) {
    return availableDates.has(ymd);
}

function toggleAvailableDate(ymd) {
    if (availableDates.has(ymd)) {
        availableDates.delete(ymd);
    } else {
        availableDates.add(ymd);
    }

    renderAvailableDateInputs();
    renderAvailableDatesList();
    avRender();
}

function renderAvailableDateInputs() {
    const hidden = document.getElementById('availableDatesJson');
    if (!hidden) return;

    hidden.value = JSON.stringify(Array.from(availableDates).sort());
}

function renderAvailableDatesList() {
        return;
}

function updateSpecificDaysState() {
    const msg = document.getElementById('specificDaysMessage');
    if (!msg) return;

    msg.textContent = 'Click dates below to mark specific available days.';
}

function avRender() {
    const label = document.getElementById('avMonthLabel');
    const grid = document.getElementById('avGrid');

    if (!label || !grid) return;

    label.textContent = `${AV_MONTHS[avMonth]} ${avYear}`;
    grid.innerHTML = '';

    const firstDay = new Date(avYear, avMonth, 1);
    const startWeekday = firstDay.getDay();
    const daysInMonth = new Date(avYear, avMonth + 1, 0).getDate();

    for (let i = 0; i < startWeekday; i++) {
        const blank = document.createElement('div');
        grid.appendChild(blank);
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let day = 1; day <= daysInMonth; day++) {
        const dateObj = new Date(avYear, avMonth, day);
        const ymd = avToYMD(dateObj);

        const cell = document.createElement('button');
        cell.type = 'button';
        cell.textContent = day;
        cell.style.padding = '10px 0';
        cell.style.borderRadius = '8px';
        cell.style.fontSize = '13px';
        cell.style.border = '1px solid #e5e7eb';

        const isPast = dateObj < today;
        const isSelected = isAvailableDate(ymd);

        if (isPast) {
            cell.disabled = true;
            cell.style.background = '#f3f4f6';
            cell.style.border = '1px solid #e5e7eb';
            cell.style.color = '#9ca3af';
            cell.style.cursor = 'not-allowed';
        } else if (isSelected) {
            cell.style.background = '#d1fae5';
            cell.style.border = '1px solid #6ee7b7';
            cell.style.color = '#166534';
            cell.style.cursor = 'pointer';

            cell.addEventListener('click', function () {
                toggleAvailableDate(ymd);
            });
        } else {
            cell.style.background = '#ffffff';
            cell.style.border = '1px solid #e5e7eb';
            cell.style.color = '#374151';
            cell.style.cursor = 'pointer';

            cell.addEventListener('click', function () {
                toggleAvailableDate(ymd);
            });
        }

        grid.appendChild(cell);
    }

    updateSpecificDaysState();
}

function avPrev() {
    avMonth--;
    if (avMonth < 0) {
        avMonth = 11;
        avYear--;
    }
    avRender();
}

function avNext() {
    avMonth++;
    if (avMonth > 11) {
        avMonth = 0;
        avYear++;
    }
    avRender();
}

document.getElementById('addAvailableRangeBtn').addEventListener('click', function () {
    const start = document.getElementById('rangeStartDate').value;
    const end = document.getElementById('rangeEndDate').value;

    if (!start || !end) {
        alert('Please select both start and end dates.');
        return;
    }

    if (end < start) {
        alert('End date cannot be before start date.');
        return;
    }

    let current = new Date(start + 'T00:00:00');
    const endDate = new Date(end + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    while (current <= endDate) {
        if (current >= today) {
            availableDates.add(avToYMD(current));
        }
        current.setDate(current.getDate() + 1);
    }

    renderAvailableDateInputs();
    renderAvailableDatesList();
    avRender();

    document.getElementById('rangeStartDate').value = '';
    document.getElementById('rangeEndDate').value = '';
});
    </script>

    <!-- ── Floating chat widget ── -->

    <!-- Content Flag Modal -->
    <div id="contentFlagModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:white;border-radius:16px;padding:28px;max-width:480px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,0.25);">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                <div style="width:44px;height:44px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-ban" style="color:#dc2626;font-size:20px;"></i>
                </div>
                <div>
                    <div style="font-size:17px;font-weight:800;color:#1a1a2e;">Content Not Permitted</div>
                    <div style="font-size:13px;color:#6b7280;margin-top:2px;">This listing cannot be submitted</div>
                </div>
            </div>
            <div id="flagModalReason" style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:13px;color:#b91c1c;margin-bottom:16px;line-height:1.5;"></div>
            <div style="background:#f9fafb;border-radius:10px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#374151;line-height:1.6;">
                <strong style="display:block;margin-bottom:6px;color:#1a1a2e;">Community Toolkit does not permit listings involving:</strong>
                <ul style="margin:0;padding-left:18px;">
                    <li>Real firearms of any kind (handguns, rifles, shotguns, etc.)</li>
                    <li>Ammunition or weapon accessories</li>
                    <li>Illegal drugs or drug paraphernalia</li>
                    <li>Explicit sexual content or graphic violence</li>
                    <li>Stolen or counterfeit items</li>
                </ul>
                <div style="margin-top:10px;color:#6b7280;font-size:12px;">Note: Tools such as nail guns, glue guns, and caulk guns are always permitted. Camouflage items, fishing gear, and archery equipment are also allowed.</div>
            </div>
            <button onclick="document.getElementById('contentFlagModal').style.display='none'" style="width:100%;padding:11px;background:#667eea;color:white;border:none;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;">
                Got it — I'll update my listing
            </button>
        </div>
    </div>

    <!-- Cropper Modal -->
    <div id="cropModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:16px;">
        <div style="background:white;border-radius:14px;padding:20px;max-width:min(520px,95vw);width:100%;box-shadow:0 8px 32px rgba(0,0,0,0.3);">
            <h3 style="margin:0 0 14px;font-size:17px;color:#333;">Crop Photo</h3>
            <div style="max-height:60vh;overflow:hidden;border-radius:8px;background:#000;">
                <img id="cropImage" style="display:block;max-width:100%;">
            </div>
            <p style="font-size:12px;color:#999;margin:10px 0 14px;">Drag to reposition &bull; Pinch or scroll to zoom &bull; Drag corners to crop</p>
            <div style="display:flex;gap:10px;">
                <button type="button" id="cropCancelBtn" style="flex:1;padding:10px;border-radius:8px;border:1.5px solid #e5e7eb;background:white;color:#666;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="button" id="cropConfirmBtn" style="flex:2;padding:10px;border-radius:8px;border:none;background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:14px;font-weight:600;cursor:pointer;">Use This Crop</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <?php require_once 'includes/chatbot_widget.php'; ?>
    <?php include 'includes/header_dropdowns.php'; ?>

</body>
</html>
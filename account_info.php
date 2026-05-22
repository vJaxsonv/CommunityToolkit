<?php 
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get current user ID
$userId = $_SESSION['user_id'];

// ── Payment method: delete ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_card'])) {
    $cardId = intval($_POST['card_id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM TUserCards WHERE CardID = ? AND UserID = ?")
            ->execute([$cardId, $userId]);
        // If deleted card was primary, promote the next one
        $remaining = $pdo->prepare("SELECT CardID FROM TUserCards WHERE UserID = ? ORDER BY AddedDate DESC LIMIT 1");
        $remaining->execute([$userId]);
        $next = $remaining->fetchColumn();
        if ($next) {
            $pdo->prepare("UPDATE TUserCards SET PrimaryCard = 1 WHERE CardID = ?")->execute([$next]);
        }
        header('Location: account_info.php?card_deleted=1#payment-methods');
    } catch (PDOException $e) {
        $_SESSION['account_errors'] = ['Could not remove card. Please try again.'];
        header('Location: account_info.php#payment-methods');
    }
    exit;
}

// ── Payment method: set primary ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_primary'])) {
    $cardId = intval($_POST['card_id'] ?? 0);
    try {
        $pdo->prepare("UPDATE TUserCards SET PrimaryCard = 0 WHERE UserID = ?")->execute([$userId]);
        $pdo->prepare("UPDATE TUserCards SET PrimaryCard = 1 WHERE CardID = ? AND UserID = ?")->execute([$cardId, $userId]);
        header('Location: account_info.php?primary_set=1#payment-methods');
    } catch (PDOException $e) {
        $_SESSION['account_errors'] = ['Could not update primary card.'];
        header('Location: account_info.php#payment-methods');
    }
    exit;
}

// ── Fetch saved cards ─────────────────────────────────────────────────────────
$savedCards = [];
try {
    $cardStmt = $pdo->prepare("
        SELECT uc.CardID, uc.LastFourDigits, uc.ExpirationMonth, uc.ExpirationYear,
               uc.BillingName, uc.PrimaryCard, ct.CardTypeName
        FROM TUserCards uc
        INNER JOIN TCardTypes ct ON uc.CardTypeID = ct.CardTypeID
        WHERE uc.UserID = ?
        ORDER BY uc.PrimaryCard DESC, uc.AddedDate DESC
    ");
    $cardStmt->execute([$userId]);
    $savedCards = $cardStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $savedCards = []; }

$cardIcons = [
    'Visa'             => 'fa-cc-visa',
    'Mastercard'       => 'fa-cc-mastercard',
    'American Express' => 'fa-cc-amex',
    'Discover'         => 'fa-cc-discover',
];

// Handle notification prefs save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_notif_prefs') {
    $notifTypes = [1, 2, 3, 4, 5, 6, 7, 8];
    foreach ($notifTypes as $typeId) {
        $enabled = isset($_POST['notif_' . $typeId]) ? 1 : 0;
        $pdo->prepare("
            INSERT INTO TUserNotificationPrefs (UserID, NotificationTypeID, EmailEnabled)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE EmailEnabled = VALUES(EmailEnabled)
        ")->execute([$userId, $typeId, $enabled]);
    }
    header('Location: account_info.php?notif_saved=1');
    exit;
}

// Fetch notification preferences
$notifPrefs = [];
try {
    $npStmt = $pdo->prepare("SELECT NotificationTypeID, EmailEnabled FROM TUserNotificationPrefs WHERE UserID = ?");
    $npStmt->execute([$userId]);
    foreach ($npStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notifPrefs[$row['NotificationTypeID']] = $row['EmailEnabled'];
    }
} catch (PDOException $e) {
    // Table may not exist yet - silently skip
}

// Fetch user data
$stmt = $pdo->prepare("

    SELECT u.*, g.Gender, s.StateName, n.NeighborhoodName, n.City
    FROM TUsers u
    LEFT JOIN TGenders g ON u.GenderID = g.GenderID
    LEFT JOIN TStates s ON u.StateID = s.StateID
    LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
    WHERE u.UserID = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found");
}

// Calculate age from DOB
$age = null;
if ($user['DateOfBirth']) {
    $dob = new DateTime($user['DateOfBirth']);
    $now = new DateTime();
    $age = $now->diff($dob)->y;
}

// Get all genders for display only
$genders = $pdo->query("SELECT * FROM TGenders ORDER BY Gender")->fetchAll(PDO::FETCH_ASSOC);

// Get all states for dropdown
$states = $pdo->query("SELECT * FROM TStates ORDER BY StateName")->fetchAll(PDO::FETCH_ASSOC);

// Fetch the user's CityID so JS can pre-fill the city and neighborhood cascade
$userCityId = 0;
if ($user['NeighborhoodID']) {
    $cityStmt = $pdo->prepare("SELECT CityID FROM TNeighborhoods WHERE NeighborhoodID = ?");
    $cityStmt->execute([$user['NeighborhoodID']]);
    $cityRow = $cityStmt->fetch(PDO::FETCH_ASSOC);
    $userCityId = $cityRow['CityID'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Information - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
    <style>
        .account-alert {
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .account-alert i { margin-top: 2px; flex-shrink: 0; }
        .account-alert-success {
            background: #f0fdf4;
            border: 1px solid #86efac;
            color: #166534;
        }
        .account-alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }
        .account-alert-error ul { margin: 6px 0 0 18px; padding: 0; }
        .account-alert-error li { margin-bottom: 3px; }
        .locked-field {
            background-color: #f5f5f5;
            cursor: not-allowed;
            color: #666;
        }
        .locked-notice {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 16px;
            font-size: 14px;
            color: #856404;
        }
        .locked-notice i { margin-right: 8px; }
        .account-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .account-container h1 {
            margin-bottom: 16px;
        }
        .account-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .form-section { margin-bottom: 30px; }
        .form-section h2 {
            font-size: 20px;
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        /* ── Crop modal ── */
        .crop-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.75);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .crop-modal-overlay.active { display: flex; }
        .crop-modal {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }
        .crop-modal-header {
            padding: 16px 20px;
            font-weight: 700;
            font-size: 16px;
            color: #1a1a2e;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .crop-modal-header i { color: #667eea; }
        .crop-modal-body { padding: 20px; background: #f3f4f6; }
        .crop-container {
            width: 100%;
            max-height: 340px;
            overflow: hidden;
            border-radius: 10px;
            background: #000;
        }
        .crop-container img { display: block; max-width: 100%; }
        .crop-hint {
            text-align: center;
            font-size: 13px;
            color: #6b7280;
            margin-top: 12px;
        }
        .crop-modal-footer {
            padding: 16px 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            border-top: 1px solid #e5e7eb;
        }
        .crop-btn-cancel {
            padding: 9px 20px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: white;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .crop-btn-cancel:hover { background: #f3f4f6; }
        .crop-btn-confirm {
            padding: 9px 20px;
            border-radius: 8px;
            border: none;
            background: #667eea;
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .crop-btn-confirm:hover { background: #5a6fd6; }
        @media (max-width: 480px) {
            .crop-modal { border-radius: 12px; }
            .crop-container { max-height: 260px; }
            .crop-modal-footer { flex-direction: column-reverse; }
            .crop-btn-cancel, .crop-btn-confirm { width: 100%; justify-content: center; }
        }

        /* ── Profile photo section ── */
        .profile-photo-wrap {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .profile-avatar-thumb {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            font-size: 32px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }
        .photo-upload-zone {
            flex: 1;
            min-width: 220px;
            border: 2px dashed #d1d5db;
            border-radius: 10px;
            background: #fafafa;
            transition: border-color 0.2s, background 0.2s;
            overflow: hidden;
        }
        .photo-upload-zone.dragover {
            border-color: #667eea;
            background: #f0f2ff;
        }

        /* Hidden real file input */
        #profile_photo { display: none; }

        /* Drag & drop instruction — desktop only */
        .photo-drop-area {
            padding: 16px 16px 8px;
            text-align: center;
            pointer-events: none;
        }
        .photo-drop-area i {
            font-size: 24px;
            color: #667eea;
            margin-bottom: 6px;
            display: block;
        }
        .photo-drop-area p {
            margin: 0;
            font-size: 13px;
            color: #6b7280;
            line-height: 1.5;
        }
        .photo-drop-area strong { color: #667eea; }

        /* Browse button row — always visible */
        .photo-browse-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px 14px;
            flex-wrap: wrap;
        }
        .photo-browse-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .photo-browse-btn:hover { background: #5a6fd6; }
        .photo-browse-hint { font-size: 12px; color: #9ca3af; }

        /* Selected state */
        .photo-upload-selected {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #374151;
            flex-wrap: wrap;
        }
        .photo-deselect-btn {
            background: #fee2e2;
            color: #b91c1c;
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            margin-left: auto;
        }
        .photo-deselect-btn:hover { background: #fecaca; }

        .remove-photo-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #374151;
            cursor: pointer;
            margin-top: 4px;
        }

        /* Mobile: hide drag & drop instructions, keep browse button */
        @media (hover: none), (max-width: 600px) {
            .photo-drop-area { display: none; }
            .photo-browse-row { padding: 16px; }
            .photo-upload-zone { width: 100%; min-width: unset; border-style: solid; }
            .profile-photo-wrap { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 700px) {
            .notif-pref-grid { grid-template-columns: repeat(2, 1fr) !important; }
        }
        @media (max-width: 480px) {
            .notif-pref-grid { grid-template-columns: 1fr !important; }
        }
    </style>
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
                </nav>
                
                <!-- User Section -->
                <div class="user-section">
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" style="display:none;"></span>
                    </div>
                    <div class="notification-icon" onclick="openChat()" style="cursor: pointer;" title="Messages">
                        <i class="fas fa-comment-dots"></i>
                        <span class="notification-badge" style="display:none;"></span>
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
        <div class="account-container">
            <h1>Account Information</h1>

            <?php if (!empty($_SESSION['account_success'])): ?>
                <div class="account-alert account-alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['account_success']); ?>
                </div>
                <?php unset($_SESSION['account_success']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['account_errors'])): ?>
                <div class="account-alert account-alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Please fix the following:</strong>
                    <ul style="margin: 8px 0 0 18px; padding: 0;">
                        <?php foreach ($_SESSION['account_errors'] as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php unset($_SESSION['account_errors']); ?>
            <?php endif; ?>
            
            <div class="locked-notice">
                <i class="fas fa-lock"></i>
                <strong>Security Notice:</strong> For the security of all of our users, changes to your Name, Gender, or Date of Birth require administrator approval. Please <a href="mailto:customerservice@thecommunitytoolkit.com?subject=Account%20Field%20Change%20Request" style="color: #856404; font-weight: 600;">contact support</a> to update these fields.
            </div>
            
            <div class="account-card">
                <form action="update_account_info.php" method="POST" enctype="multipart/form-data">
                    
                    <!-- Personal Information (LOCKED) -->
                    <div class="form-section">
                        <h2><i class="fas fa-id-card"></i> Personal Information (Contact Support to Change)</h2>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" class="locked-field" value="<?php echo htmlspecialchars($user['FirstName']); ?>" readonly>
                                <small style="color: #666;">Contact support to change</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" class="locked-field" value="<?php echo htmlspecialchars($user['LastName']); ?>" readonly>
                                <small style="color: #666;">Contact support to change</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Gender</label>
                                <input type="text" class="locked-field" value="<?php echo htmlspecialchars($user['Gender'] ?? 'Not specified'); ?>" readonly>
                                <small style="color: #666;">Contact support to change</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Date of Birth <?php if ($age): ?>(Age: <?php echo $age; ?>)<?php endif; ?></label>
                                <input type="text" class="locked-field" value="<?php echo $user['DateOfBirth'] ? date('m/d/Y', strtotime($user['DateOfBirth'])) : 'Not specified'; ?>" readonly>
                                <small style="color: #666;">Contact support to change</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile Photo (EDITABLE) -->
                    <div class="form-section">
                        <h2><i class="fas fa-camera"></i> Profile Photo</h2>

                        <div class="profile-photo-wrap">
                            <!-- Current avatar preview -->
                            <div class="profile-avatar-thumb" id="avatarPreview">
                                <?php if (!empty($user['ProfilePictureURL'])): ?>
                                    <img src="<?php echo htmlspecialchars($user['ProfilePictureURL']); ?>"
                                         alt="Profile photo" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($user['FirstName'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>

                            <!-- Hidden real file input -->
                            <input type="file" name="profile_photo" id="profile_photo"
                                   accept="image/jpeg,image/png,image/gif,image/webp">

                            <!-- Upload zone -->
                            <div class="photo-upload-zone" id="profileUploadZone">

                                <!-- Default state -->
                                <div id="profileUploadInner">
                                    <!-- Drag & drop instruction (hidden on mobile) -->
                                    <div class="photo-drop-area">
                                        <i class="fas fa-camera"></i>
                                        <p><strong>Drag &amp; drop a new photo here</strong></p>
                                        <p style="font-size:12px;color:#9ca3af;">or use the button below</p>
                                    </div>
                                    <!-- Browse button — always visible -->
                                    <div class="photo-browse-row">
                                        <button type="button" class="photo-browse-btn" id="profileBrowseBtn">
                                            <i class="fas fa-folder-open"></i> Choose Photo
                                        </button>
                                        <span class="photo-browse-hint">JPG, PNG, GIF, WEBP · Max 5MB</span>
                                    </div>
                                </div>

                                <!-- Selected state (shown after file chosen) -->
                                <div class="photo-upload-selected" id="profileSelectedWrap" style="display:none;">
                                    <i class="fas fa-check-circle" style="color:#22c55e;font-size:18px;"></i>
                                    <span id="profileSelectedName"></span>
                                    <button type="button" class="photo-deselect-btn" id="profileDeselectBtn">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                </div>

                            </div>
                        </div>

                        <?php if (!empty($user['ProfilePictureURL'])): ?>
                            <label class="remove-photo-label">
                                <input type="checkbox" name="remove_photo" value="1" id="removePhotoCheck">
                                Remove current photo
                            </label>
                        <?php endif; ?>
                    </div>

                    <!-- Contact Information (EDITABLE) -->
                    <div class="form-section">
                        <h2><i class="fas fa-envelope"></i> Contact Information</h2>
                        
                        <div class="form-group">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['Email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number *</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['PhoneNumber']); ?>" required maxlength="14">
                            <small>10 digits, no dashes or spaces</small>
                        </div>
                    </div>
                    
                    <!-- Address Information (EDITABLE) -->
                    <div class="form-section">
                        <h2><i class="fas fa-map-marker-alt"></i> Address</h2>
                        
                        <div class="form-group">
                            <label for="address1">Address Line 1</label>
                            <input type="text" id="address1" name="address1" value="<?php echo htmlspecialchars($user['AddressLine1'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="address2">Address Line 2</label>
                            <input type="text" id="address2" name="address2" value="<?php echo htmlspecialchars($user['AddressLine2'] ?? ''); ?>">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="state">State</label>
                                <select id="state" name="state" onchange="loadCities()">
                                    <option value="">Select State</option>
                                    <?php foreach ($states as $state): ?>
                                        <option value="<?php echo $state['StateID']; ?>"
                                            <?php echo ($user['StateID'] == $state['StateID']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($state['StateName']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="zip">ZIP Code</label>
                                <input type="text" id="zip" name="zip" value="<?php echo htmlspecialchars($user['ZipCode'] ?? ''); ?>" pattern="[0-9]{5}" maxlength="5">
                            </div>
                        </div>

                        <div class="form-group" id="cityContainer" style="display:none;">
                            <label for="citySelect">City</label>
                            <select id="citySelect" name="city" onchange="loadNeighborhoods()">
                                <option value="">Select City</option>
                            </select>
                        </div>

                        <div class="form-group" id="neighborhoodContainer" style="display:none;">
                            <label for="neighborhood">Neighborhood</label>
                            <select id="neighborhood" name="neighborhood">
                                <option value="">Select Neighborhood</option>
                            </select>
                        </div>

                        <!-- Hidden pre-fill values for JS cascade -->
                        <input type="hidden" id="prefilledCityId"         value="<?php echo intval($userCityId); ?>">
                        <input type="hidden" id="prefilledNeighborhoodId" value="<?php echo intval($user['NeighborhoodID'] ?? 0); ?>">
                    </div>
                    
                    <!-- Password Change (EDITABLE) -->
                    <div class="form-section">
                        <h2><i class="fas fa-key"></i> Change Password</h2>
                        <small style="display:block; margin-bottom:16px; color:#6b7280;">
                            To update your password, all three fields below must be filled in. Leave all three blank to keep your current password unchanged.
                        </small>
                        
                        <div class="form-group">
                            <label for="current_password">Current Password *</label>
                            <input type="password" id="current_password" name="current_password"
                                   placeholder="Enter your current password">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_password">New Password *</label>
                                <input type="password" id="new_password" name="new_password"
                                       placeholder="Enter new password">
                                <small>8+ characters, at least one number and one special character (!@#$%^&*)</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password"
                                       placeholder="Re-enter new password">
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                        <a href="home.php" class="btn" style="flex:1;text-align:center;line-height:40px;background:transparent;color:#667eea;border:2px solid #667eea;border-radius:8px;font-weight:600;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='#f0f2ff'" onmouseout="this.style.background='transparent'">Cancel</a>
                    </div>
                </form>

                <!-- Notification Preferences Section -->
                <div class="form-section" style="margin-top:40px;">
                    <h2 style="border-bottom:2px solid #e5e7eb;">Notification Preferences</h2>
                    <p style="color:#6b7280;font-size:14px;margin-bottom:20px;">Choose which e-mail notifications you would like to receive. Please also make sure to add 
                    <br>customerservice@thecommunitytoolkit.com and noreply@thecommunitytoolkit.com to your list of approved e-mail senders so that you receive all of our e-mail communications.
                    </p>

                    <?php if (isset($_GET['notif_saved'])): ?>
                        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-check-circle"></i> Notification preferences saved.
                        </div>
                    <?php endif; ?>

                    <?php
                    $notifLabels = [
                        1 => ['label' => 'Rental Request Received',  'desc' => 'When someone requests to rent one of your items'],
                        2 => ['label' => 'Request Accepted',         'desc' => 'When a lender accepts your rental request'],
                        3 => ['label' => 'Request Declined',         'desc' => 'When a lender declines your rental request'],
                        4 => ['label' => 'Rental Started',           'desc' => 'When a rental period begins'],
                        5 => ['label' => 'Rental Completed',         'desc' => 'When a rental is marked as completed'],
                        6 => ['label' => 'Review Received',          'desc' => 'When someone leaves you a review'],
                        7 => ['label' => 'Extension Requested',      'desc' => 'When a borrower requests a rental extension'],
                        8 => ['label' => 'New Message',              'desc' => 'When someone sends you a message'],
                    ];
                    ?>

                    <form method="POST" action="account_info.php">
                        <input type="hidden" name="action" value="save_notif_prefs">
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;" class="notif-pref-grid">
                        <?php foreach ($notifLabels as $typeId => $info):
                            $checked = ($notifPrefs[$typeId] ?? 1) ? 'checked' : '';
                        ?>
                            <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:white;border:1px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:border-color 0.15s,background 0.15s;" onmouseover="this.style.borderColor='#667eea';this.style.background='#f5f3ff'" onmouseout="this.style.borderColor='#e5e7eb';this.style.background='white'">
                                <input type="checkbox" name="notif_<?php echo $typeId; ?>" value="1" <?php echo $checked; ?>
                                       style="width:15px;height:15px;accent-color:#667eea;cursor:pointer;flex-shrink:0;margin-top:2px;">
                                <div>
                                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;line-height:1.3;"><?php echo $info['label']; ?></div>
                                    <div style="font-size:11px;color:#6b7280;margin-top:3px;line-height:1.4;"><?php echo $info['desc']; ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                        </div>
                        <button type="submit" style="padding:10px 24px;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity 0.2s;" onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </form>
                </div>

                <!-- Delete Account Section -->
                <div class="form-section" id="payment-methods" style="margin-top:40px;">
                    <h2><i class="fas fa-credit-card"></i> Payment Methods</h2>
                    <p style="color:#6b7280;font-size:14px;margin-bottom:20px;">Your saved payment methods are used to process rental deposits and charges. The card marked <strong>Primary</strong> is charged by default.</p>

                    <?php if (isset($_GET['card_deleted'])): ?>
                        <div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-check-circle"></i> Card removed successfully.
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_GET['primary_set'])): ?>
                        <div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-check-circle"></i> Primary card updated.
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_GET['card_saved'])): ?>
                        <div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-check-circle"></i> Payment method saved.
                        </div>
                    <?php endif; ?>

                    <?php
                    $returnTo = htmlspecialchars($_GET['return_to'] ?? '');
                    if (isset($_GET['no_card'])): ?>
                        <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-exclamation-circle" style="flex-shrink:0;"></i>
                            <span>You need a payment method to request a rental. Add one below and you'll be taken straight back to your booking.</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($savedCards)): ?>
                        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">
                            <?php foreach ($savedCards as $card): ?>
                                <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;background:white;border:<?php echo $card['PrimaryCard'] ? '2px solid #667eea' : '1px solid #e5e7eb'; ?>;border-radius:12px;flex-wrap:wrap;">
                                    <i class="fab <?php echo htmlspecialchars($cardIcons[$card['CardTypeName']] ?? 'fa-credit-card'); ?>" style="font-size:28px;color:#667eea;flex-shrink:0;"></i>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:14px;font-weight:700;color:#1a1a2e;">
                                            <?php echo htmlspecialchars($card['CardTypeName']); ?> ····<?php echo htmlspecialchars($card['LastFourDigits']); ?>
                                            <?php if ($card['PrimaryCard']): ?>
                                                <span style="font-size:10px;font-weight:700;background:#667eea;color:white;border-radius:10px;padding:2px 8px;margin-left:6px;letter-spacing:0.5px;">PRIMARY</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                                            Expires <?php echo htmlspecialchars($card['ExpirationMonth'] . '/' . $card['ExpirationYear']); ?>
                                            <?php if (!empty($card['BillingName'])): ?>&nbsp;·&nbsp; <?php echo htmlspecialchars($card['BillingName']); ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;">
                                        <?php if (!$card['PrimaryCard']): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="card_id" value="<?php echo $card['CardID']; ?>">
                                                <button type="submit" name="set_primary" style="font-size:12px;font-weight:600;padding:6px 12px;border:1.5px solid #667eea;border-radius:7px;background:white;color:#667eea;cursor:pointer;">Set Primary</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this card?');">
                                            <input type="hidden" name="card_id" value="<?php echo $card['CardID']; ?>">
                                            <button type="submit" name="delete_card" style="font-size:12px;font-weight:600;padding:6px 12px;border:1.5px solid #fecaca;border-radius:7px;background:#fef2f2;color:#dc2626;cursor:pointer;"><i class="fas fa-trash-alt"></i> Remove</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center;padding:30px;background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;color:#9ca3af;margin-bottom:24px;">
                            <i class="fas fa-credit-card" style="font-size:32px;display:block;margin-bottom:10px;"></i>
                            No payment methods saved yet. Add one below.
                        </div>
                    <?php endif; ?>

                    <details style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;" <?php echo (empty($savedCards) || isset($_GET['no_card'])) ? 'open' : ''; ?>>
                        <summary style="padding:14px 18px;font-size:14px;font-weight:700;color:#1a1a2e;cursor:pointer;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-plus-circle" style="color:#667eea;"></i> Add a Payment Method
                        </summary>
                        <div style="padding:18px;border-top:1px solid #e5e7eb;">
                            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:11px 14px;font-size:13px;color:#0369a1;margin-bottom:18px;display:flex;gap:8px;align-items:flex-start;">
                                <i class="fas fa-shield-alt" style="margin-top:1px;flex-shrink:0;"></i>
                                <span>This is a <strong>simulated payment system</strong> for demonstration purposes. No real charges will be made.</span>
                            </div>
                            <form method="POST" action="process_payment_method.php">
                                <input type="hidden" name="return_to" value="<?php echo !empty($returnTo) ? $returnTo : 'account_info.php?card_saved=1#payment-methods'; ?>">
                                <div style="margin-bottom:14px;">
                                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Cardholder Name *</label>
                                    <input type="text" name="billing_name" placeholder="Name as it appears on card" required value="<?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?>" style="width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;box-sizing:border-box;">
                                </div>
                                <div style="margin-bottom:14px;">
                                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Card Number *</label>
                                    <input type="text" id="acctCardNum" name="card_number" placeholder="1234 5678 9012 3456" maxlength="19" required autocomplete="cc-number" style="width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;box-sizing:border-box;">
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                                    <div>
                                        <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Expiration *</label>
                                        <div style="display:flex;gap:6px;">
                                            <select name="exp_month" required style="flex:1;padding:9px 8px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;">
                                                <option value="">MM</option>
                                                <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo str_pad($m,2,'0',STR_PAD_LEFT); ?>"><?php echo str_pad($m,2,'0',STR_PAD_LEFT); ?></option><?php endfor; ?>
                                            </select>
                                            <select name="exp_year" required style="flex:1;padding:9px 8px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;">
                                                <option value="">YYYY</option>
                                                <?php for ($y = date('Y'); $y <= date('Y')+10; $y++): ?><option value="<?php echo $y; ?>"><?php echo $y; ?></option><?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">CVC *</label>
                                        <input type="text" name="cvc" placeholder="123" maxlength="4" required style="width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;box-sizing:border-box;">
                                    </div>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                                    <div>
                                        <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Billing ZIP *</label>
                                        <input type="text" name="billing_zip" placeholder="45205" maxlength="5" required pattern="[0-9]{5}" style="width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Card Type *</label>
                                        <select id="acctCardType" name="card_type_id" required style="width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;">
                                            <option value="">Select type</option>
                                            <option value="1">Visa</option><option value="2">Mastercard</option><option value="3">American Express</option><option value="4">Discover</option>
                                        </select>
                                    </div>
                                </div>
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;margin-bottom:18px;cursor:pointer;">
                                    <input type="checkbox" name="set_as_primary" value="1" <?php echo empty($savedCards) ? 'checked' : ''; ?> style="width:15px;height:15px;accent-color:#667eea;">
                                    Set as primary payment method
                                </label>
                                <button type="submit" style="display:inline-flex;align-items:center;gap:8px;padding:10px 22px;background:#667eea;color:white;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">
                                    <i class="fas fa-lock"></i> Save Payment Method
                                </button>
                            </form>
                        </div>
                    </details>
                </div>

                <!-- Transaction History Section -->
                <div class="form-section" style="margin-top:40px;">
                    <h2><i class="fas fa-receipt"></i> Transaction History</h2>
                    <p style="color:#6b7280;font-size:14px;margin-bottom:16px;">View a full history of all charges and payments for your rentals, with printable invoices for each transaction.</p>
                    <a href="transaction_history.php" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#667eea;color:white;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;" onmouseover="this.style.background='#5a67d8'" onmouseout="this.style.background='#667eea'">
                        <i class="fas fa-history"></i> View Transaction History
                    </a>
                </div>

                <!-- Delete Account Section -->
                <div class="form-section" style="margin-top:40px;">
                    <h2 style="color:#dc2626;border-bottom:2px solid #dc2626;">Delete Account</h2>
                    <p style="color:#6b7280;font-size:14px;margin-bottom:16px;">Once you delete your account, all of your listings, rentals, and data will be permanently removed. This action cannot be undone.</p>
                    <button type="button" onclick="confirmDeleteAccount()"
                            style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#dc2626;color:white;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background 0.2s;"
                            onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                        <i class="fas fa-trash-alt"></i> Delete My Account
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- Delete Account Confirmation Modal -->
    <div id="deleteAccountModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
        <div style="background:white;border-radius:16px;padding:36px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);text-align:center;">
            <div style="width:64px;height:64px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                <i class="fas fa-exclamation-triangle" style="font-size:28px;color:#dc2626;"></i>
            </div>
            <h2 style="font-size:20px;font-weight:700;color:#1a1a2e;margin-bottom:10px;">Delete Your Account?</h2>
            <p style="color:#6b7280;font-size:14px;line-height:1.6;margin-bottom:28px;">This will permanently delete your account, all your listings, and all associated data. <strong>This cannot be undone.</strong></p>
            <div style="display:flex;gap:12px;">
                <button onclick="closeDeleteModal()"
                        style="flex:1;padding:12px;background:#f3f4f6;color:#374151;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background 0.2s;"
                        onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                    Cancel
                </button>
                <a href="delete_account.php"
                   style="flex:1;padding:12px;background:#dc2626;color:white;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;transition:background 0.2s;"
                   onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                    Yes, Delete My Account
                </a>
            </div>
        </div>
    </div>

    <script>
        function confirmDeleteAccount() {
            document.getElementById('deleteAccountModal').style.display = 'flex';
        }
        function closeDeleteModal() {
            document.getElementById('deleteAccountModal').style.display = 'none';
        }
        document.getElementById('deleteAccountModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    </script>
    
    <!-- ── Crop Modal ── -->
    <div class="crop-modal-overlay" id="cropModalOverlay">
        <div class="crop-modal">
            <div class="crop-modal-header">
                <i class="fas fa-crop-alt"></i> Position Your Photo
            </div>
            <div class="crop-modal-body">
                <div class="crop-container">
                    <img id="cropImage" src="" alt="Crop">
                </div>
                <p class="crop-hint">
                    <i class="fas fa-arrows-alt"></i> Drag to reposition &nbsp;·&nbsp;
                    <i class="fas fa-search-plus"></i> Scroll or pinch to zoom
                </p>
            </div>
            <div class="crop-modal-footer">
                <button type="button" class="crop-btn-cancel" id="cropCancelBtn">Cancel</button>
                <button type="button" class="crop-btn-confirm" id="cropConfirmBtn">
                    <i class="fas fa-check"></i> Use This Photo
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <script>
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // ── Location cascade (identical to register.php) ─────────
        async function loadCities() {
            const stateId               = document.getElementById('state').value;
            const cityContainer         = document.getElementById('cityContainer');
            const citySelect            = document.getElementById('citySelect');
            const neighborhoodContainer = document.getElementById('neighborhoodContainer');
            const neighborhoodSelect    = document.getElementById('neighborhood');

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

                // Auto-select pre-filled city
                const prefilledCity = document.getElementById('prefilledCityId').value;
                if (prefilledCity) {
                    const match = citySelect.querySelector(`option[value="${prefilledCity}"]`);
                    if (match) {
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
            const neighborhoodSelect    = document.getElementById('neighborhood');

            neighborhoodSelect.innerHTML = '<option value="">Select Neighborhood</option>';

            if (!cityId) {
                neighborhoodContainer.style.display = 'none';
                return;
            }

            try {
                const response      = await fetch(`get_neighborhoods.php?city_id=${cityId}`);
                const neighborhoods = await response.json();

                if (neighborhoods.length === 0) {
                    neighborhoodContainer.style.display = 'none';

                    // Auto-select the city's own neighborhood record via hidden input
                    let hiddenInput = document.getElementById('autoNeighborhood');
                    if (!hiddenInput) {
                        hiddenInput      = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.id   = 'autoNeighborhood';
                        hiddenInput.name = 'neighborhood';
                        document.querySelector('form').appendChild(hiddenInput);
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

                    // Remove auto-neighborhood hidden input if present
                    const hiddenInput = document.getElementById('autoNeighborhood');
                    if (hiddenInput) hiddenInput.remove();

                    // Auto-select pre-filled neighborhood
                    const prefilledNeighborhood = document.getElementById('prefilledNeighborhoodId').value;
                    if (prefilledNeighborhood) {
                        const match = neighborhoodSelect.querySelector(`option[value="${prefilledNeighborhood}"]`);
                        if (match) neighborhoodSelect.value = prefilledNeighborhood;
                    }
                }
            } catch (error) {
                console.error('Error loading neighborhoods:', error);
            }
        }

        // On page load: trigger cascade if user has a saved state
        document.addEventListener('DOMContentLoaded', async function() {
            const stateSelect = document.getElementById('state');
            if (stateSelect && stateSelect.value) {
                await loadCities();
            }

            // ── Phone number formatting (same as register.php) ────────────────
            const phoneInput = document.getElementById('phone');
            if (phoneInput) {
                // Format existing value on load
                const raw = phoneInput.value.replace(/\D/g, '').substring(0, 10);
                if (raw.length === 10) {
                    phoneInput.value = '(' + raw.substring(0,3) + ') ' + raw.substring(3,6) + '-' + raw.substring(6);
                }
                phoneInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '').substring(0, 10);
                    let formatted = '';
                    if (value.length === 0) {
                        formatted = '';
                    } else if (value.length <= 3) {
                        formatted = '(' + value;
                    } else if (value.length <= 6) {
                        formatted = '(' + value.substring(0,3) + ') ' + value.substring(3);
                    } else {
                        formatted = '(' + value.substring(0,3) + ') ' + value.substring(3,6) + '-' + value.substring(6);
                    }
                    e.target.value = formatted;
                });
            }

            // ── Strip phone formatting before form submit ─────────────────────
            const mainForm = document.querySelector('form[action="update_account_info.php"]');
            if (mainForm) {
                mainForm.addEventListener('submit', function() {
                    const phone = document.getElementById('phone');
                    if (phone) phone.value = phone.value.replace(/\D/g, '');
                });
            }

            // ── ZIP auto-fill: when user changes zip, cascade state/city/neighborhood ──
            const zipInput = document.getElementById('zip');
            if (zipInput) {
                zipInput.addEventListener('input', function(e) {
                    e.target.value = e.target.value.replace(/\D/g, '').substring(0, 5);
                    if (e.target.value.length === 5) {
                        lookupByZip(e.target.value);
                    }
                });
            }
        });

        async function lookupByZip(zip) {
            try {
                const response = await fetch(`get_location_by_zip.php?zip=${zip}`);
                const data = await response.json();
                if (!data.found) return;

                // Auto-select State
                const stateSelect = document.getElementById('state');
                stateSelect.value = data.state_id;

                // Populate and select City
                const citySelect    = document.getElementById('citySelect');
                const cityContainer = document.getElementById('cityContainer');
                citySelect.innerHTML = '<option value="">Select City</option>';
                data.cities.forEach(city => {
                    const opt = document.createElement('option');
                    opt.value = city.CityID;
                    opt.textContent = city.CityName;
                    if (city.CityID == data.city_id) opt.selected = true;
                    citySelect.appendChild(opt);
                });
                cityContainer.style.display = 'block';

                // Update hidden prefill values so cascade works correctly
                const prefilledCity = document.getElementById('prefilledCityId');
                const prefilledNeigh = document.getElementById('prefilledNeighborhoodId');
                if (prefilledCity) prefilledCity.value = data.city_id;
                if (prefilledNeigh) prefilledNeigh.value = data.neighborhood_id;

                // Populate Neighborhood or auto-select
                const neighborhoodSelect    = document.getElementById('neighborhood');
                const neighborhoodContainer = document.getElementById('neighborhoodContainer');
                const existingHidden = document.getElementById('autoNeighborhoodHidden');
                if (existingHidden) existingHidden.remove();

                if (data.has_neighborhoods) {
                    neighborhoodSelect.innerHTML = '<option value="">Select Neighborhood</option>';
                    data.neighborhoods.forEach(n => {
                        const opt = document.createElement('option');
                        opt.value = n.NeighborhoodID;
                        opt.textContent = n.NeighborhoodName;
                        if (n.NeighborhoodID == data.neighborhood_id) opt.selected = true;
                        neighborhoodSelect.appendChild(opt);
                    });
                    neighborhoodContainer.style.display = 'block';
                    neighborhoodSelect.required = true;
                } else {
                    neighborhoodContainer.style.display = 'none';
                    neighborhoodSelect.required = false;
                    neighborhoodSelect.value = '';
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type  = 'hidden';
                    hiddenInput.id    = 'autoNeighborhoodHidden';
                    hiddenInput.name  = 'neighborhood';
                    hiddenInput.value = data.neighborhood_id;
                    document.querySelector('form').appendChild(hiddenInput);
                }
            } catch (error) {
                console.error('ZIP lookup error:', error);
            }
        }

        // ── Profile photo: drag & drop + browse + circular crop ──
        const uploadZone       = document.getElementById('profileUploadZone');
        const photoInput       = document.getElementById('profile_photo');
        const browseBtn        = document.getElementById('profileBrowseBtn');
        const uploadInner      = document.getElementById('profileUploadInner');
        const selectedWrap     = document.getElementById('profileSelectedWrap');
        const selectedName     = document.getElementById('profileSelectedName');
        const deselectBtn      = document.getElementById('profileDeselectBtn');
        const avatarPreview    = document.getElementById('avatarPreview');
        const removePhotoCheck = document.getElementById('removePhotoCheck');
        // Capture original avatar HTML so we can restore it on deselect or un-check
        const originalAvatarHTML = avatarPreview ? avatarPreview.innerHTML : '';

        // Crop modal elements
        const cropOverlay    = document.getElementById('cropModalOverlay');
        const cropImage      = document.getElementById('cropImage');
        const cropCancelBtn  = document.getElementById('cropCancelBtn');
        const cropConfirmBtn = document.getElementById('cropConfirmBtn');
        let cropper = null;
        let pendingFileName = 'profile_photo.jpg';

        // Browse button opens file picker
        if (browseBtn) browseBtn.addEventListener('click', () => photoInput.click());

        // File chosen via picker
        if (photoInput) {
            photoInput.addEventListener('change', function() {
                if (this.files[0]) openCropper(this.files[0]);
            });
        }

        // Drag & drop (desktop)
        if (uploadZone) {
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
                const file = e.dataTransfer.files[0];
                if (file) openCropper(file);
            });
        }

        // Deselect
        if (deselectBtn) {
            deselectBtn.addEventListener('click', e => {
                e.stopPropagation();
                clearPhotoSelection();
            });
        }

        function openCropper(file) {
            pendingFileName = file.name;
            const reader = new FileReader();
            reader.onload = function(e) {
                cropImage.src = e.target.result;
                cropOverlay.classList.add('active');
                if (cropper) { cropper.destroy(); cropper = null; }
                cropper = new Cropper(cropImage, {
                    aspectRatio: 1,
                    viewMode: 1,
                    dragMode: 'move',
                    cropBoxMovable: false,
                    cropBoxResizable: false,
                    guides: false,
                    center: true,
                    highlight: false,
                    background: true,
                    autoCropArea: 0.85,
                    responsive: true,
                    restore: false,
                });
            };
            reader.readAsDataURL(file);
        }

        // Cancel
        cropCancelBtn.addEventListener('click', () => {
            cropOverlay.classList.remove('active');
            if (cropper) { cropper.destroy(); cropper = null; }
            photoInput.value = '';
        });

        // Confirm crop
        cropConfirmBtn.addEventListener('click', () => {
            if (!cropper) return;

            const canvas = cropper.getCroppedCanvas({
                width: 400,
                height: 400,
                imageSmoothingQuality: 'high',
            });

            canvas.toBlob(blob => {
                const croppedFile = new File([blob], 'profile_photo.jpg', { type: 'image/jpeg' });

                // Inject into file input
                const dt = new DataTransfer();
                dt.items.add(croppedFile);
                photoInput.files = dt.files;

                // Show selected state
                selectedName.textContent = pendingFileName + ' (cropped)';
                selectedWrap.style.display = 'flex';
                uploadInner.style.display  = 'none';

                // Update avatar preview
                avatarPreview.innerHTML = `<img src="${canvas.toDataURL('image/jpeg')}"
                    style="width:100%;height:100%;object-fit:cover;">`;

                // Uncheck remove if new photo chosen
                if (removePhotoCheck) removePhotoCheck.checked = false;

                cropOverlay.classList.remove('active');
                if (cropper) { cropper.destroy(); cropper = null; }
            }, 'image/jpeg', 0.92);
        });

        function clearPhotoSelection() {
            photoInput.value = '';
            selectedWrap.style.display = 'none';
            uploadInner.style.display  = 'block';
            // Restore avatar to original state without reloading
            avatarPreview.innerHTML = originalAvatarHTML;
            avatarPreview.style.fontSize = '';
        }

        // Remove checkbox — disable file input and revert avatar preview to initial
        if (removePhotoCheck) {
            removePhotoCheck.addEventListener('change', function() {
                if (this.checked) {
                    // Clear any pending file selection
                    photoInput.value        = '';
                    photoInput.disabled     = true;
                    selectedWrap.style.display = 'none';
                    uploadInner.style.display  = 'block';
                    // Revert avatar preview to initial letter
                    avatarPreview.innerHTML = `<?php echo strtoupper(substr($user['FirstName'], 0, 1)); ?>`;
                    avatarPreview.style.fontSize = '32px';
                } else {
                    // Un-checking: re-enable upload, restore original avatar
                    photoInput.disabled = false;
                    avatarPreview.innerHTML  = originalAvatarHTML;
                    avatarPreview.style.fontSize = '';
                }
            });
        }

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            const dropdown = document.getElementById('userDropdown');
            if (!event.target.matches('.user-avatar')) {
                if (dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        }
    </script>
    <?php include 'includes/header_dropdowns.php'; ?>
    <?php require_once 'includes/chatbot_widget.php'; ?>
    <script>
    // Payment method card number auto-format and type detection
    (function() {
        const numInput  = document.getElementById('acctCardNum');
        const typeInput = document.getElementById('acctCardType');
        if (!numInput || !typeInput) return;
        numInput.addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '').substring(0, 16);
            this.value = val.replace(/(.{4})/g, '$1 ').trim();
            if (/^4/.test(val))           typeInput.value = '1';
            else if (/^5[1-5]/.test(val)) typeInput.value = '2';
            else if (/^3[47]/.test(val))  typeInput.value = '3';
            else if (/^6/.test(val))      typeInput.value = '4';
        });
    })();
    </script>
</body>
</html>

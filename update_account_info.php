<?php
require_once 'config.php';
require_once 'includes/profanity_check.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: account_info.php');
    exit;
}

$userId = $_SESSION['user_id'];
$errors = [];

// ── Get form data ─────────────────────────────────────────────────────────────
$email          = trim($_POST['email']          ?? '');
$phone          = trim($_POST['phone']          ?? '');
$address1       = trim($_POST['address1']       ?? '');
$address2       = trim($_POST['address2']       ?? '');
$stateId        = $_POST['state']               ?? null;
$zip            = trim($_POST['zip']            ?? '');
$neighborhoodId = $_POST['neighborhood']        ?? null;
$removePhoto    = !empty($_POST['remove_photo']);

$currentPassword = $_POST['current_password'] ?? '';
$newPassword     = $_POST['new_password']     ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// ── Fetch current user record (need existing ProfilePictureURL and Password) ──
$stmt = $pdo->prepare("SELECT Password, ProfilePictureURL FROM TUsers WHERE UserID = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Handle profile photo ──────────────────────────────────────────────────────
$newPhotoURL = $currentUser['ProfilePictureURL']; // default: keep existing

if ($removePhoto) {
    // Delete the physical file if it exists on disk
    if (!empty($currentUser['ProfilePictureURL']) && file_exists($currentUser['ProfilePictureURL'])) {
        unlink($currentUser['ProfilePictureURL']);
    }
    $newPhotoURL = null;

} elseif (!empty($_FILES['profile_photo']['name'])) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize      = 5 * 1024 * 1024; // 5MB
    $mimeType     = mime_content_type($_FILES['profile_photo']['tmp_name']);

    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "Profile photo must be JPG, PNG, GIF, or WEBP.";
    } elseif ($_FILES['profile_photo']['size'] > $maxSize) {
        $errors[] = "Profile photo must be 5MB or smaller.";
    } else {
        // Use absolute path so it works regardless of where PHP executes from
        $uploadDir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext      = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . uniqid('', true) . '.' . strtolower($ext);
        $destPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
            // Delete old photo file if one existed
            if (!empty($currentUser['ProfilePictureURL'])) {
                $oldPath = __DIR__ . '/' . ltrim($currentUser['ProfilePictureURL'], '/');
                if (file_exists($oldPath)) unlink($oldPath);
            }
            // Store as a web-accessible relative path
            $newPhotoURL = 'uploads/profiles/' . $filename;
        } else {
            error_log("Profile photo upload failed. destPath: $destPath, upload_error: " . $_FILES['profile_photo']['error']);
            $errors[] = "Failed to save profile photo. Please check that the uploads/profiles/ folder exists and is writable on the server.";
        }
    }
}

// ── Validate email ────────────────────────────────────────────────────────────
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Valid email address is required.";
}

$stmt = $pdo->prepare("SELECT UserID FROM TUsers WHERE Email = ? AND UserID != ?");
$stmt->execute([$email, $userId]);
if ($stmt->fetch()) {
    $errors[] = "Email address is already in use.";
}

// ── Validate phone ────────────────────────────────────────────────────────────
if (empty($phone) || !preg_match('/^[0-9]{10}$/', $phone)) {
    $errors[] = "Phone number must be exactly 10 digits.";
}

// ── Validate ZIP if provided ──────────────────────────────────────────────────
if (!empty($zip) && !preg_match('/^[0-9]{5}$/', $zip)) {
    $errors[] = "ZIP code must be exactly 5 digits.";
}

// ── Validate password change — all three fields required together ─────────────
if (!empty($currentPassword) || !empty($newPassword) || !empty($confirmPassword)) {
    if (empty($currentPassword)) $errors[] = "Current password is required to change your password.";
    if (empty($newPassword))     $errors[] = "New password is required.";
    if (empty($confirmPassword)) $errors[] = "Please confirm your new password.";

    if (!empty($currentPassword) && !empty($newPassword) && !empty($confirmPassword)) {
        if (!password_verify($currentPassword, $currentUser['Password'])) {
            $errors[] = "Current password is incorrect.";
        }
        if (strlen($newPassword) < 8) {
            $errors[] = "New password must be at least 8 characters.";
        }
        if (!preg_match('/\d/', $newPassword)) {
            $errors[] = "New password must include at least one number (0-9).";
        }
        if (!preg_match('/[!@#$%^&*]/', $newPassword)) {
            $errors[] = "New password must include at least one special character (!@#\$%^&*).";
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = "New passwords do not match.";
        }
    }
}

// ── Profanity check ──────────────────────────────────────────────────────────
$contentFlagged = checkContentBatch([
    'Address line 1' => $address1,
    'Address line 2' => $address2,
]);
if (!empty($contentFlagged)) {
    $_SESSION['account_errors'] = ["Your submission contains inappropriate content. Please review and resubmit."];
    header('Location: account_info.php');
    exit;
}

// ── Redirect back with errors if any ─────────────────────────────────────────
if (!empty($errors)) {
    $_SESSION['account_errors'] = $errors;
    header('Location: account_info.php');
    exit;
}

// ── Save to database ──────────────────────────────────────────────────────────
try {
    if (!empty($newPassword)) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE TUsers
            SET Email             = ?,
                PhoneNumber       = ?,
                AddressLine1      = ?,
                AddressLine2      = ?,
                StateID           = ?,
                ZipCode           = ?,
                NeighborhoodID    = ?,
                ProfilePictureURL = ?,
                Password          = ?
            WHERE UserID = ?
        ");
        $stmt->execute([
            $email,
            $phone,
            $address1       ?: null,
            $address2       ?: null,
            $stateId        ?: null,
            $zip            ?: null,
            $neighborhoodId ?: null,
            $newPhotoURL,
            $hashedPassword,
            $userId
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE TUsers
            SET Email             = ?,
                PhoneNumber       = ?,
                AddressLine1      = ?,
                AddressLine2      = ?,
                StateID           = ?,
                ZipCode           = ?,
                NeighborhoodID    = ?,
                ProfilePictureURL = ?
            WHERE UserID = ?
        ");
        $stmt->execute([
            $email,
            $phone,
            $address1       ?: null,
            $address2       ?: null,
            $stateId        ?: null,
            $zip            ?: null,
            $neighborhoodId ?: null,
            $newPhotoURL,
            $userId
        ]);
    }

    // Update session to reflect new values immediately
    $_SESSION['email']           = $email;
    $_SESSION['profile_picture'] = $newPhotoURL;

    $_SESSION['account_success'] = "Account information updated successfully!";
    header('Location: account_info.php');
    exit;

} catch (PDOException $e) {
    error_log("update_account_info error: " . $e->getMessage());
    $_SESSION['account_errors'] = ["An error occurred while updating your account. Please try again."];
    header('Location: account_info.php');
    exit;
}
?>

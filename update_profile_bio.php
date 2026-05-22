<?php
require_once 'config.php';
require_once 'includes/profanity_check.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}

$userId = $_SESSION['user_id'];
$bio = trim($_POST['bio'] ?? '');

// Profanity check
if (!checkContent($bio)) {
    $_SESSION['profile_errors'] = ["Your bio contains inappropriate content. Please review and resubmit."];
    header('Location: profile.php');
    exit;
}

// Validate bio length
if (strlen($bio) > 500) {
    $_SESSION['profile_errors'] = ["Bio must be 500 characters or less"];
    header('Location: profile.php');
    exit;
}

// Update bio
try {
    $stmt = $pdo->prepare("UPDATE TUsers SET Bio = ? WHERE UserID = ?");
    $stmt->execute([$bio ?: null, $userId]);
    
    $_SESSION['profile_success'] = "Bio updated successfully!";
    header('Location: profile.php');
    exit;
    
} catch (PDOException $e) {
    $_SESSION['profile_errors'] = ["An error occurred while updating your bio. Please try again."];
    header('Location: profile.php');
    exit;
}
?>

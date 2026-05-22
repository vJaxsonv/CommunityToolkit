<?php
require_once 'config.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$token = $_POST['token'] ?? '';
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Validate inputs
if (empty($token) || empty($password) || empty($confirmPassword)) {
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=missing_fields');
    exit;
}

// Check passwords match
if ($password !== $confirmPassword) {
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=password_mismatch');
    exit;
}

// Check password length
if (strlen($password) < 8) {
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=password_length');
    exit;
}

try {
    // Verify token is valid and not expired
    $stmt = $pdo->prepare("
        SELECT pr.ResetID, pr.UserID, pr.ExpiresAt, pr.UsedAt
        FROM TPasswordResets pr
        WHERE pr.Token = ? AND pr.UsedAt IS NULL
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if token is invalid or already used
    if (!$reset) {
        header('Location: login.php?error=invalid_token');
        exit;
    }
    
    // Check if token is expired
    if (strtotime($reset['ExpiresAt']) < time()) {
        header('Location: login.php?error=expired_token');
        exit;
    }
    
    // Hash the new password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Update user's password
    $stmt = $pdo->prepare("UPDATE TUsers SET Password = ? WHERE UserID = ?");
    $stmt->execute([$hashedPassword, $reset['UserID']]);
    
    // Mark token as used
    $stmt = $pdo->prepare("UPDATE TPasswordResets SET UsedAt = NOW() WHERE ResetID = ?");
    $stmt->execute([$reset['ResetID']]);
    
    // Redirect to login with success message
    header('Location: login.php?password_reset=success');
    exit;
    
} catch (PDOException $e) {
    error_log("Password reset processing error: " . $e->getMessage());
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=database');
    exit;
}
?>

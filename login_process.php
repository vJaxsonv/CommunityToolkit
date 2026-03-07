<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    try {
        // Find user by email
        $stmt = $pdo->prepare("SELECT * FROM TUsers WHERE Email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verify password and account status
        if ($user && password_verify($password, $user['Password'])) {
            // Check if account is active
            if (!$user['AccountStatus']) {
                header('Location: login.php?error=inactive');
                exit;
            }
            
            // Password correct - log in user
            $_SESSION['user_id'] = $user['UserID'];
            $_SESSION['firstname'] = $user['FirstName'];
            $_SESSION['lastname'] = $user['LastName'];
            $_SESSION['email'] = $user['Email'];
            
            // Redirect to home page
            header('Location: home.php');
            exit;
        } else {
            // Invalid credentials
            header('Location: login.php?error=1');
            exit;
        }
    } catch(PDOException $e) {
        // Log error for debugging
        error_log("Login error: " . $e->getMessage());
        header('Location: login.php?error=1');
        exit;
    }
} else {
    // Not a POST request
    header('Location: login.php');
    exit;
}
?>

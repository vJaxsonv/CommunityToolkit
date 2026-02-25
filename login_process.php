<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    try {
        // Find user by email
        $stmt = $pdo->prepare("SELECT * FROM Users_CT WHERE Email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verify password
        if ($user && password_verify($password, $user['Password'])) {
            // Password correct - log in user
            $_SESSION['user_id'] = $user['UserID'];
            $_SESSION['username'] = $user['Username'];
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

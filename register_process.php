<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = trim($_POST['phone']);
    
    // Check if passwords match
    if ($password !== $confirm_password) {
        header('Location: register.php?error=password_mismatch');
        exit;
    }
    
    try {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT UserID FROM TUsers WHERE Email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            header('Location: register.php?error=email');
            exit;
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $sql = "INSERT INTO TUsers (FirstName, LastName, Email, Password, PhoneNumber, AccountStatus) 
                VALUES (?, ?, ?, ?, ?, 1)";
        
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$firstname, $lastname, $email, $hashedPassword, $phone])) {
            // Get the new user ID
            $userId = $pdo->lastInsertId();
            
            // Fetch the complete user data
            $stmt = $pdo->prepare("SELECT * FROM TUsers WHERE UserID = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Log them in automatically
            $_SESSION['user_id'] = $userId;
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;
            $_SESSION['email'] = $email;
            
            // Redirect to home page
            header('Location: home.php');
            exit;
        } else {
            header('Location: register.php?error=1');
            exit;
        }
    } catch(PDOException $e) {
        // Log error for debugging
        error_log("Registration error: " . $e->getMessage());
        header('Location: register.php?error=1');
        exit;
    }
} else {
    // Not a POST request
    header('Location: register.php');
    exit;
}
?>

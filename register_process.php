<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $city = trim($_POST['city']);
    $zip = trim($_POST['zip']);
    
    try {
        // Check if username already exists
        $stmt = $pdo->prepare("SELECT UserID FROM Users_CT WHERE Username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            header('Location: register.php?error=username');
            exit;
        }
        
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT UserID FROM Users_CT WHERE Email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            header('Location: register.php?error=email');
            exit;
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user (StateID 35 = Ohio)
        $sql = "INSERT INTO Users_CT (Username, Password, Email, PhoneNumber, FirstName, LastName, 
                StreetAddress, City, StateID, ZipCode) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 35, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$username, $hashedPassword, $email, $phone, $firstname, $lastname, $address, $city, $zip])) {
            // Get the new user ID
            $userId = $pdo->lastInsertId();
            
            // Fetch the complete user data
            $stmt = $pdo->prepare("SELECT * FROM Users_CT WHERE UserID = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Log them in automatically
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
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

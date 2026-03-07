<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $genderId = intval($_POST['gender'] ?? 0);
    $neighborhoodId = intval($_POST['neighborhood'] ?? 0);
    
    // Validate required fields
    if (empty($firstname) || empty($lastname) || empty($email) || empty($password) || $genderId == 0 || $neighborhoodId == 0) {
        header('Location: register.php?error=missing_fields');
        exit;
    }
    
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
        
        // Insert new user with selected gender and neighborhood
        $sql = "INSERT INTO TUsers (FirstName, LastName, Email, Password, PhoneNumber, 
                GenderID, ProfilePictureURL, Bio, NeighborhoodID, AddedDate, AccountStatus) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)";
        
        $stmt = $pdo->prepare($sql);
        
        $profilePic = null;
        $bio = null;
        
        $result = $stmt->execute([
            $firstname, 
            $lastname, 
            $email, 
            $hashedPassword, 
            $phone, 
            $genderId,        // From dropdown
            $profilePic, 
            $bio, 
            $neighborhoodId   // From dropdown
        ]);
        
        if ($result) {
            // Get the new user ID
            $userId = $pdo->lastInsertId();
            
            // Set session variables
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

<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $addressLine1 = trim($_POST['address_line1'] ?? '');
    $addressLine2 = trim($_POST['address_line2'] ?? '');
    $stateId = intval($_POST['state'] ?? 0);
    $zipcode = trim($_POST['zipcode'] ?? '');
    $genderId = intval($_POST['gender'] ?? 0);
    $neighborhoodId = intval($_POST['neighborhood'] ?? 0);
    
    // Validate required fields
    if (empty($firstname) || empty($lastname) || empty($email) || empty($password) || 
        empty($addressLine1) || $stateId == 0 || empty($zipcode) || 
        $genderId == 0 || $neighborhoodId == 0) {
        header('Location: register.php?error=missing_fields');
        exit;
    }
    
    // Validate zip code is exactly 5 digits
    if (!preg_match('/^\d{5}$/', $zipcode)) {
        header('Location: register.php?error=invalid_zipcode');
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
        
        // Insert new user with address fields
        $sql = "INSERT INTO TUsers (FirstName, LastName, Email, Password, PhoneNumber, 
                AddressLine1, AddressLine2, StateID, ZipCode,
                GenderID, ProfilePictureURL, Bio, NeighborhoodID, AddedDate, AccountStatus) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)";
        
        $stmt = $pdo->prepare($sql);
        
        $profilePic = null;
        $bio = null;
        
        $result = $stmt->execute([
            $firstname, 
            $lastname, 
            $email, 
            $hashedPassword, 
            $phone,
            $addressLine1,
            $addressLine2,
            $stateId,
            $zipcode,
            $genderId,
            $profilePic, 
            $bio, 
            $neighborhoodId
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

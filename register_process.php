<?php
// DEBUG VERSION - Shows what data is being received
require_once 'config.php';
require_once 'includes/profanity_check.php';

// Log all POST data to a file for debugging
$debugLog = "=== REGISTRATION DEBUG " . date('Y-m-d H:i:s') . " ===\n";
$debugLog .= print_r($_POST, true);
$debugLog .= "\n\n";
file_put_contents('registration_debug.log', $debugLog, FILE_APPEND);

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
    $bio = trim($_POST['bio'] ?? '');
    
    // Handle optional profile photo upload
    $profilePictureURL = null;
    if (!empty($_FILES['profile_photo']['name'])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize      = 5 * 1024 * 1024; // 5MB
        $mimeType     = mime_content_type($_FILES['profile_photo']['tmp_name']);

        if (!in_array($mimeType, $allowedTypes)) {
            header('Location: register.php?error=invalid_photo_type');
            exit;
        }
        if ($_FILES['profile_photo']['size'] > $maxSize) {
            header('Location: register.php?error=photo_too_large');
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . uniqid('', true) . '.' . strtolower($ext);
        $destPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
            $profilePictureURL = 'uploads/profiles/' . $filename;
        }
    }
    
    // Handle neighborhood - could come from dropdown OR auto-selected for cities without neighborhoods
    $neighborhoodId = intval($_POST['neighborhood'] ?? 0);
    $autoNeighborhood = intval($_POST['auto_neighborhood'] ?? 0);
    
    $debugLog = "Parsed values:\n";
    $debugLog .= "neighborhoodId: $neighborhoodId\n";
    $debugLog .= "autoNeighborhood: $autoNeighborhood\n";
    file_put_contents('registration_debug.log', $debugLog, FILE_APPEND);
    
    // Use auto_neighborhood if no neighborhood was selected (for cities without sub-neighborhoods)
    if ($neighborhoodId == 0 && $autoNeighborhood > 0) {
        // Find the neighborhood that matches this city
        $stmt = $pdo->prepare("SELECT NeighborhoodID FROM TNeighborhoods WHERE CityID = ? LIMIT 1");
        $stmt->execute([$autoNeighborhood]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $neighborhoodId = $result['NeighborhoodID'];
            $debugLog = "Auto neighborhood found: $neighborhoodId\n";
            file_put_contents('registration_debug.log', $debugLog, FILE_APPEND);
        }
    }
    
    // Check terms agreement
    if (empty($_POST['agree_terms'])) {
        header('Location: register.php?error=terms_not_agreed');
        exit;
    }

    // Show validation errors with details
    $errors = [];
    if (empty($firstname)) $errors[] = 'firstname';
    if (empty($lastname)) $errors[] = 'lastname';
    if (empty($email)) $errors[] = 'email';
    if (empty($password)) $errors[] = 'password';
    if (empty($addressLine1)) $errors[] = 'address_line1';
    if ($stateId == 0) $errors[] = 'state';
    if (empty($zipcode)) $errors[] = 'zipcode';
    if ($genderId == 0) $errors[] = 'gender';
    if ($neighborhoodId == 0) $errors[] = 'neighborhood';
    
    // Profanity check
    $contentFlagged = checkContentBatch([
        'First name' => $firstname,
        'Last name'  => $lastname,
        'Bio'        => $bio,
    ]);
    if (!empty($contentFlagged)) {
        header('Location: register.php?error=inappropriate_content');
        exit;
    }

        if (!empty($errors)) {
        $debugLog = "Missing fields: " . implode(', ', $errors) . "\n";
        file_put_contents('registration_debug.log', $debugLog, FILE_APPEND);
        header('Location: register.php?error=missing_fields&missing=' . implode(',', $errors));
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
    
    // Validate password complexity
    if (strlen($password) < 8) {
        header('Location: register.php?error=password_length');
        exit;
    }
    
    if (!preg_match('/\d/', $password)) {
        header('Location: register.php?error=password_number');
        exit;
    }
    
    if (!preg_match('/[!@#$%^&*]/', $password)) {
        header('Location: register.php?error=password_special');
        exit;
    }
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT UserID FROM TUsers WHERE Email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        header('Location: register.php?error=email');
        exit;
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert user
    try {
        $stmt = $pdo->prepare("
            INSERT INTO TUsers (FirstName, LastName, Email, Password, PhoneNumber, AddressLine1, AddressLine2, 
                               StateID, ZipCode, GenderID, Bio, NeighborhoodID, ProfilePictureURL, AddedDate, AccountStatus)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
        ");
        
        $stmt->execute([
            $firstname, $lastname, $email, $hashedPassword, $phone,
            $addressLine1, $addressLine2, $stateId, $zipcode, $genderId,
            $bio, $neighborhoodId, $profilePictureURL
        ]);
        
        // Start session and log user in
        $_SESSION['user_id']         = $pdo->lastInsertId();
        $_SESSION['firstname']       = $firstname;
        $_SESSION['lastname']        = $lastname;
        $_SESSION['email']           = $email;
        $_SESSION['is_admin']        = false;
        $_SESSION['profile_picture'] = $profilePictureURL;
        
        $debugLog = "SUCCESS - User created with ID: " . $_SESSION['user_id'] . "\n\n";
        file_put_contents('registration_debug.log', $debugLog, FILE_APPEND);
        
        header('Location: home.php');
        exit;
        
    } catch (PDOException $e) {
        $debugLog = "DATABASE ERROR: " . $e->getMessage() . "\n\n";
        file_put_contents('registration_debug.log', $debugLog, FILE_APPEND);
        
        header('Location: register.php?error=database');
        exit;
    }
} else {
    header('Location: register.php');
    exit;
}
?>

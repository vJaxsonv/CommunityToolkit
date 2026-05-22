<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email    = trim($_POST['email']);
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

            // Password correct - set session variables
            $_SESSION['user_id']         = $user['UserID'];
            $_SESSION['firstname']       = $user['FirstName'];
            $_SESSION['lastname']        = $user['LastName'];
            $_SESSION['email']           = $user['Email'];
            $_SESSION['is_admin']        = (bool)($user['IsAdmin'] ?? false);
            $_SESSION['profile_picture'] = $user['ProfilePictureURL'] ?? null;

            // Redirect admins to admin dashboard, regular users to index
            if ($_SESSION['is_admin']) {
                header('Location: admin/dashboard.php');
            } else {
                header('Location: index.php');
            }
            exit;

        } else {
            header('Location: login.php?error=1');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        header('Location: login.php?error=1');
        exit;
    }
} else {
    header('Location: login.php');
    exit;
}
?>
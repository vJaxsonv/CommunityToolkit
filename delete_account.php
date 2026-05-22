<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Prevent admins from deleting their own account this way
if (!empty($_SESSION['is_admin'])) {
    $_SESSION['account_errors'] = ["Admin accounts cannot be deleted through this page."];
    header('Location: account_info.php');
    exit;
}

try {
    // Soft-delete all user's listings (set to Removed)
    $pdo->prepare("UPDATE TListings SET ListingStatusID = 5 WHERE UserLenderID = ?")
        ->execute([$userId]);

    // Anonymize and deactivate the user record
    // Personal data wiped — UserID row kept so FK relationships stay intact
    $pdo->prepare("
        UPDATE TUsers SET
            FirstName         = 'Deleted',
            LastName          = 'User',
            Email             = CONCAT('deleted_', UserID, '@deleted.invalid'),
            Password          = '',
            PhoneNumber       = '0000000000',
            AddressLine1      = NULL,
            AddressLine2      = NULL,
            StateID           = NULL,
            ZipCode           = NULL,
            DateOfBirth       = NULL,
            ProfilePictureURL = NULL,
            Bio               = NULL,
            AccountStatus     = 0,
            IsDeleted         = 1
        WHERE UserID = ?
    ")->execute([$userId]);

    // Destroy session
    session_destroy();

    header('Location: index.php?account_deleted=1');
    exit;

} catch (PDOException $e) {
    error_log("delete_account error: " . $e->getMessage());
    $_SESSION['account_errors'] = ["An error occurred while deleting your account. Please try again."];
    header('Location: account_info.php');
    exit;
}
?>

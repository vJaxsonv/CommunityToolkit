<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: payment_methods.php');
    exit;
}

$userId      = $_SESSION['user_id'];
$billingName = trim($_POST['billing_name']   ?? '');
$cardNumber  = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
$expMonth    = trim($_POST['exp_month']      ?? '');
$expYear     = trim($_POST['exp_year']       ?? '');
$cvc         = trim($_POST['cvc']            ?? '');
$billingZip  = trim($_POST['billing_zip']    ?? '');
$cardTypeId  = intval($_POST['card_type_id'] ?? 0);
$setAsPrimary = isset($_POST['set_as_primary']) ? 1 : 0;
$returnTo    = trim($_POST['return_to']      ?? '');

$errors = [];

// Validate
if (empty($billingName))                        $errors[] = 'Cardholder name is required.';
if (strlen($cardNumber) < 13 || strlen($cardNumber) > 16) $errors[] = 'Please enter a valid card number.';
if (empty($expMonth))                           $errors[] = 'Expiration month is required.';
if (empty($expYear))                            $errors[] = 'Expiration year is required.';
if (strlen($cvc) < 3)                           $errors[] = 'Please enter a valid CVC.';
if (!preg_match('/^\d{5}$/', $billingZip))      $errors[] = 'Please enter a valid 5-digit billing ZIP.';
if ($cardTypeId < 1 || $cardTypeId > 4)         $errors[] = 'Please select a card type.';

// Check card not expired
if (empty($errors)) {
    $expiry = mktime(0, 0, 0, intval($expMonth) + 1, 1, intval($expYear));
    if ($expiry < time()) {
        $errors[] = 'This card has expired.';
    }
}

if (!empty($errors)) {
    $_SESSION['payment_errors'] = $errors;
    $redirect = 'payment_methods.php' . (!empty($returnTo) ? '?return=' . urlencode($returnTo) : '');
    header('Location: ' . $redirect);
    exit;
}

// Generate pseudo payment token
$lastFour = substr($cardNumber, -4);
$token    = 'tok_' . $userId . '_' . strtolower(substr(str_replace(' ', '', $_POST['card_type_id'] == 1 ? 'visa' : ($cardTypeId == 2 ? 'mc' : ($cardTypeId == 3 ? 'amex' : 'disc'))), 0, 4)) . '_' . bin2hex(random_bytes(6));

try {
    $pdo->beginTransaction();

    // If setting as primary, clear existing primary first
    if ($setAsPrimary) {
        $pdo->prepare("UPDATE TUserCards SET PrimaryCard = 0 WHERE UserID = ?")->execute([$userId]);
    }

    // Insert new card
    $pdo->prepare("
        INSERT INTO TUserCards
            (UserID, PaymentToken, CardTypeID, LastFourDigits, ExpirationMonth, ExpirationYear,
             CVC, BillingName, BillingZip, PrimaryCard, AddedDate)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $userId, $token, $cardTypeId, $lastFour,
        $expMonth, $expYear, $cvc,
        $billingName, $billingZip, $setAsPrimary
    ]);

    $pdo->commit();

    // Redirect back to rental flow if we came from there
    if (!empty($returnTo)) {
        header('Location: ' . $returnTo);
        exit;
    }

    header('Location: payment_methods.php?saved=1');
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('process_payment_method.php error: ' . $e->getMessage());
    $_SESSION['payment_errors'] = ['Something went wrong saving your card. Please try again.'];
    $redirect = 'payment_methods.php' . (!empty($returnTo) ? '?return=' . urlencode($returnTo) : '');
    header('Location: ' . $redirect);
    exit;
}

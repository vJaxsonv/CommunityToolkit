<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Handle delete card
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_card'])) {
    $cardId = intval($_POST['card_id'] ?? 0);
    try {
        // Only delete if it belongs to this user
        $pdo->prepare("DELETE FROM TUserCards WHERE CardID = ? AND UserID = ?")
            ->execute([$cardId, $userId]);
        header('Location: payment_methods.php?deleted=1');
        exit;
    } catch (PDOException $e) {
        $error = 'Could not remove card. Please try again.';
    }
}

// Handle set primary
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_primary'])) {
    $cardId = intval($_POST['card_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE TUserCards SET PrimaryCard = 0 WHERE UserID = ?")->execute([$userId]);
        $pdo->prepare("UPDATE TUserCards SET PrimaryCard = 1 WHERE CardID = ? AND UserID = ?")->execute([$cardId, $userId]);
        $pdo->commit();
        header('Location: payment_methods.php?primary_set=1');
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Could not update primary card.';
    }
}

// Fetch user's saved cards
$cards = [];
try {
    $stmt = $pdo->prepare("
        SELECT uc.*, ct.CardTypeName
        FROM TUserCards uc
        INNER JOIN TCardTypes ct ON uc.CardTypeID = ct.CardTypeID
        WHERE uc.UserID = ?
        ORDER BY uc.PrimaryCard DESC, uc.AddedDate DESC
    ");
    $stmt->execute([$userId]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cards = [];
}

// Card type icons
$cardIcons = [
    'Visa'             => 'fa-cc-visa',
    'Mastercard'       => 'fa-cc-mastercard',
    'American Express' => 'fa-cc-amex',
    'Discover'         => 'fa-cc-discover',
];

// Return URL for redirect-back flow (e.g. from rental_request_process.php)
$returnTo = $_GET['return'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Methods — Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .pm-wrap {
            max-width: 720px;
            margin: 40px auto 80px;
            padding: 0 24px;
        }
        .pm-wrap h1 {
            font-size: 26px;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 6px;
        }
        .pm-sub {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 28px;
        }
        .alert {
            border-radius: 10px;
            padding: 13px 18px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-info    { background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; }

        /* Card list */
        .card-list { display: flex; flex-direction: column; gap: 14px; margin-bottom: 28px; }
        .card-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .card-item.primary-card { border-color: #667eea; background: #f8f7ff; }
        .card-icon { font-size: 36px; color: #374151; flex-shrink: 0; }
        .card-icon .fa-cc-visa       { color: #1a1f71; }
        .card-icon .fa-cc-mastercard { color: #eb001b; }
        .card-icon .fa-cc-amex       { color: #007bc1; }
        .card-icon .fa-cc-discover   { color: #f76f20; }
        .card-details { flex: 1; min-width: 0; }
        .card-number {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .primary-badge {
            background: #667eea;
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 0.3px;
        }
        .card-meta { font-size: 13px; color: #6b7280; margin-top: 3px; }
        .card-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }
        .btn-sm {
            padding: 6px 14px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: opacity 0.2s;
        }
        .btn-sm:hover { opacity: 0.85; }
        .btn-primary-sm  { background: #667eea; color: white; }
        .btn-danger-sm   { background: #fee2e2; color: #b91c1c; }
        .btn-outline-sm  { background: white; color: #667eea; border: 1.5px solid #667eea; }

        /* Add card section */
        .add-card-section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
        }
        .add-card-header {
            padding: 18px 22px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .add-card-header i { color: #667eea; }
        .add-card-body { padding: 22px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            color: #1a1a2e;
            background: white;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.12);
        }
        .pseudo-notice {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            color: #0369a1;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .btn-save-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s;
        }
        .btn-save-card:hover { opacity: 0.9; }
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #9ca3af;
            font-size: 14px;
        }
        .empty-state i { font-size: 36px; margin-bottom: 10px; display: block; }
        @media (max-width: 540px) {
            .form-row { grid-template-columns: 1fr; }
            .card-item { flex-wrap: wrap; }
            .card-actions { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="site-logo">
                    <img src="images/Community.png" alt="Community Toolkit" style="height:50px;width:auto;">
                </a>
                <?php include 'includes/search_bar.php'; ?>
                <nav class="main-nav">
                    <a href="index.php" class="nav-link"><i class="fas fa-home"></i><span>Home</span></a>
                    <a href="my_items.php" class="nav-link"><i class="fas fa-box"></i><span>My Items</span></a>
                    <a href="create_listing.php" class="nav-link"><i class="fas fa-plus-circle"></i><span>List Item</span></a>
                    <a href="my_rentals.php" class="nav-link"><i class="fas fa-calendar"></i><span>My Rentals</span></a>
                </nav>
                <div class="user-section">
                    <div class="notification-icon"><i class="fas fa-bell"></i><span class="notification-badge" style="display:none;"></span></div>
                    <div class="notification-icon" style="cursor:pointer;"><i class="fas fa-comment-dots"></i><span class="notification-badge" style="display:none;"></span></div>
                    <div class="user-menu-container">
                        <div class="user-avatar" onclick="toggleUserMenu()">
                            <?php if (!empty($_SESSION['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-dropdown" id="userDropdown">
                            <div class="user-dropdown-header">
                                <strong><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></strong>
                                <small><?php echo htmlspecialchars($_SESSION['email']); ?></small>
                            </div>
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <a href="account_info.php"><i class="fas fa-cog"></i> Account Info</a>
                            <a href="payment_methods.php"><i class="fas fa-credit-card"></i> Payment Methods</a>
                            <a href="my_bookmarks.php"><i class="fas fa-bookmark"></i> Bookmarked Items</a>
                            <hr>
                            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="pm-wrap">

            <h1><i class="fas fa-credit-card" style="color:#667eea;margin-right:8px;"></i>Payment Methods</h1>
            <p class="pm-sub">Manage the payment method on file for your rentals.</p>

            <?php if (!empty($returnTo)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <span>You need a payment method on file before completing your rental request. Add one below and you'll be returned to your booking.</span>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> Card removed successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET['primary_set'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> Primary card updated.</div>
            <?php endif; ?>
            <?php if (isset($_GET['saved'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> Payment method saved successfully.</div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Saved cards -->
            <?php if (!empty($cards)): ?>
                <div class="card-list">
                    <?php foreach ($cards as $card): ?>
                        <div class="card-item <?php echo $card['PrimaryCard'] ? 'primary-card' : ''; ?>">
                            <div class="card-icon">
                                <i class="fab <?php echo htmlspecialchars($cardIcons[$card['CardTypeName']] ?? 'fa-credit-card'); ?>"></i>
                            </div>
                            <div class="card-details">
                                <div class="card-number">
                                    <?php echo htmlspecialchars($card['CardTypeName']); ?> ····<?php echo htmlspecialchars($card['LastFourDigits']); ?>
                                    <?php if ($card['PrimaryCard']): ?>
                                        <span class="primary-badge">PRIMARY</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-meta">
                                    Expires <?php echo htmlspecialchars($card['ExpirationMonth'] . '/' . $card['ExpirationYear']); ?>
                                    &nbsp;·&nbsp; <?php echo htmlspecialchars($card['BillingName'] ?? ''); ?>
                                </div>
                            </div>
                            <div class="card-actions">
                                <?php if (!$card['PrimaryCard']): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="card_id" value="<?php echo $card['CardID']; ?>">
                                        <button type="submit" name="set_primary" class="btn-sm btn-outline-sm">
                                            Set Primary
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Remove this card?');">
                                    <input type="hidden" name="card_id" value="<?php echo $card['CardID']; ?>">
                                    <button type="submit" name="delete_card" class="btn-sm btn-danger-sm">
                                        <i class="fas fa-trash-alt"></i> Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-credit-card"></i>
                    No payment methods saved yet.
                </div>
            <?php endif; ?>

            <!-- Add card form -->
            <div class="add-card-section">
                <div class="add-card-header">
                    <i class="fas fa-plus-circle"></i> Add a Payment Method
                </div>
                <div class="add-card-body">
                    <div class="pseudo-notice">
                        <i class="fas fa-shield-alt" style="margin-top:1px;flex-shrink:0;"></i>
                        <span>This is a <strong>simulated payment system</strong> for demonstration purposes. No real charges will be made. Any card number you enter will be validated and accepted as long as the format is correct.</span>
                    </div>

                    <form method="POST" action="process_payment_method.php" id="addCardForm">
                        <?php if (!empty($returnTo)): ?>
                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="billing_name">Cardholder Name *</label>
                            <input type="text" id="billing_name" name="billing_name"
                                   placeholder="Name as it appears on card" required
                                   value="<?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="card_number">Card Number *</label>
                            <input type="text" id="card_number" name="card_number"
                                   placeholder="1234 5678 9012 3456"
                                   maxlength="19" required autocomplete="cc-number">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Expiration Date *</label>
                                <div style="display:flex;gap:8px;">
                                    <select name="exp_month" required style="flex:1;">
                                        <option value="">MM</option>
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>">
                                                <?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <select name="exp_year" required style="flex:1;">
                                        <option value="">YYYY</option>
                                        <?php for ($y = date('Y'); $y <= date('Y') + 10; $y++): ?>
                                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="cvc">CVC *</label>
                                <input type="text" id="cvc" name="cvc"
                                       placeholder="123" maxlength="4" required autocomplete="cc-csc">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="billing_zip">Billing ZIP Code *</label>
                                <input type="text" id="billing_zip" name="billing_zip"
                                       placeholder="45205" maxlength="5" required pattern="[0-9]{5}">
                            </div>
                            <div class="form-group">
                                <label for="card_type">Card Type *</label>
                                <select id="card_type" name="card_type_id" required>
                                    <option value="">Select type</option>
                                    <option value="1">Visa</option>
                                    <option value="2">Mastercard</option>
                                    <option value="3">American Express</option>
                                    <option value="4">Discover</option>
                                </select>
                            </div>
                        </div>

                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;margin-bottom:20px;cursor:pointer;">
                            <input type="checkbox" name="set_as_primary" value="1" checked
                                   style="width:15px;height:15px;accent-color:#667eea;">
                            Set as primary payment method
                        </label>

                        <button type="submit" class="btn-save-card">
                            <i class="fas fa-lock"></i> Save Payment Method
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </main>

    <script>
        function toggleUserMenu() {
            document.getElementById('userDropdown').classList.toggle('show');
        }
        window.onclick = function(e) {
            if (!e.target.matches('.user-avatar')) {
                const d = document.getElementById('userDropdown');
                if (d && d.classList.contains('show')) d.classList.remove('show');
            }
        };

        // Auto-format card number with spaces
        document.getElementById('card_number').addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '').substring(0, 16);
            this.value = val.replace(/(.{4})/g, '$1 ').trim();
        });

        // Auto-detect card type from number
        document.getElementById('card_number').addEventListener('input', function() {
            const num = this.value.replace(/\D/g, '');
            const sel = document.getElementById('card_type');
            if (/^4/.test(num))          sel.value = '1'; // Visa
            else if (/^5[1-5]/.test(num)) sel.value = '2'; // Mastercard
            else if (/^3[47]/.test(num))  sel.value = '3'; // Amex
            else if (/^6/.test(num))      sel.value = '4'; // Discover
        });
    </script>
    <?php require_once 'includes/chatbot_widget.php'; ?>
    <?php include 'includes/header_dropdowns.php'; ?>
</body>
</html>

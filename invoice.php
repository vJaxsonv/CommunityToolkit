<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$rentalId = (int)($_GET['rental_id'] ?? 0);
$txId     = (int)($_GET['tx_id'] ?? 0); // optional — specific transaction

if ($rentalId <= 0) {
    header('Location: transaction_history.php');
    exit;
}

// Fetch rental + listing + parties
$stmt = $pdo->prepare("
    SELECT
        r.RentalID, r.RentalStatusID,
        r.UserBorrowerID, r.UserLenderID,
        COALESCE(l.Title, '[Deleted Listing]') AS ListingTitle, l.PricePerDay, l.PricePerHour, l.RateTypeID,
        rr.StartDate, rr.EndDate,
        borrower.FirstName AS BorrowerFirst, borrower.LastName AS BorrowerLast,
        borrower.Email     AS BorrowerEmail,
        lender.FirstName   AS LenderFirst,   lender.LastName   AS LenderLast,
        lender.Email       AS LenderEmail
    FROM TRentals r
    INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
    LEFT JOIN TListings        l  ON r.ListingID       = l.ListingID
    INNER JOIN TUsers borrower    ON r.UserBorrowerID  = borrower.UserID
    INNER JOIN TUsers lender      ON r.UserLenderID    = lender.UserID
    WHERE r.RentalID = ?
    AND (r.UserBorrowerID = ? OR r.UserLenderID = ?)
    LIMIT 1
");
$stmt->execute([$rentalId, $userId, $userId]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    header('Location: transaction_history.php');
    exit;
}

// Fetch transactions for this rental (or just one if tx_id specified)
$cardJoin = "
    LEFT JOIN TUserCards bc ON t.UserBorrowerCardID = bc.CardID
    LEFT JOIN TUserCards lc ON t.UserLenderCardID   = lc.CardID
    LEFT JOIN TCardTypes bct ON bc.CardTypeID = bct.CardTypeID
    LEFT JOIN TCardTypes lct ON lc.CardTypeID = lct.CardTypeID
";
$cardFields = ",
    bc.LastFourDigits AS BorrowerCardLast4,
    bc.ExpirationMonth AS BorrowerCardExpM,
    bc.ExpirationYear  AS BorrowerCardExpY,
    bct.CardTypeName   AS BorrowerCardType,
    lc.LastFourDigits  AS LenderCardLast4,
    lc.ExpirationMonth AS LenderCardExpM,
    lc.ExpirationYear  AS LenderCardExpY,
    lct.CardTypeName   AS LenderCardType
";
// Always fetch ALL transactions for this rental so the invoice is complete.
// tx_id in the URL is used only for the invoice number reference, not to filter rows.
$txStmt = $pdo->prepare("
    SELECT t.*, tt.TransactionType, ts.Status AS TransactionStatus
    $cardFields
    FROM TTransactions t
    INNER JOIN TTransactionType     tt ON t.TransactionTypeID   = tt.TransactionTypeID
    INNER JOIN TTransactionStatuses ts ON t.TransactionStatusID = ts.TransactionStatusID
    $cardJoin
    WHERE t.RentalID = ?
    ORDER BY t.AddedDate ASC
");
$txStmt->execute([$rentalId]);
$transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($transactions)) {
    header('Location: transaction_history.php');
    exit;
}

$isRenter   = (int)$rental['UserBorrowerID'] === $userId;
$days       = max(1, (int)ceil((strtotime($rental['EndDate']) - strtotime($rental['StartDate'])) / 86400));
$rateType   = (int)$rental['RateTypeID'];
$totalCost  = ($rateType === 2)
    ? $days * floatval($rental['PricePerHour'])
    : $days * floatval($rental['PricePerDay']);
$invoiceNum = 'CTK-' . str_pad($rentalId, 5, '0', STR_PAD_LEFT) . ($txId ? '-' . $txId : '');
$invoiceDate = date('M j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo $invoiceNum; ?> – Community Toolkit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f3f4f6;
            color: #1a1a2e;
            font-size: 14px;
        }

        /* Print controls — hidden when printing */
        .print-bar {
            background: #1a1a2e;
            color: white;
            padding: 12px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .print-bar a, .print-bar span {
            color: #c7d2fe;
            text-decoration: none;
            font-size: 13px;
        }
        .print-bar a:hover { color: white; }
        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-print:hover { background: #5a67d8; }

        /* Invoice page */
        .invoice-page {
            max-width: 760px;
            margin: 30px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        /* Header band */
        .inv-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 32px 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            flex-wrap: wrap;
        }
        .inv-brand { font-size: 20px; font-weight: 800; letter-spacing: -0.5px; }
        .inv-brand span { opacity: 0.7; font-weight: 400; font-size: 13px; display: block; margin-top: 3px; }
        .inv-meta { text-align: right; }
        .inv-meta .inv-number { font-size: 18px; font-weight: 700; }
        .inv-meta .inv-date { font-size: 12px; opacity: 0.8; margin-top: 4px; }

        /* Body */
        .inv-body { padding: 36px 40px; }

        /* Parties */
        .inv-parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }
        .inv-party h4 {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9ca3af;
            margin-bottom: 8px;
        }
        .inv-party .party-name { font-size: 15px; font-weight: 700; }
        .inv-party .party-email { font-size: 12px; color: #6b7280; margin-top: 3px; }
        .inv-party .party-role {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
            margin-top: 6px;
        }
        .role-renter { background: #e0e7ff; color: #3730a3; }
        .role-lender { background: #dcfce7; color: #166534; }

        /* Rental summary */
        .inv-rental-summary {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 28px;
        }
        .inv-rental-summary h3 {
            font-size: 13px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .inv-detail-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            padding: 4px 0;
            color: #374151;
        }
        .inv-detail-row span:first-child { color: #6b7280; }

        /* Transactions table */
        .inv-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 13px;
        }
        .inv-table th {
            text-align: left;
            padding: 10px 12px;
            background: #f3f4f6;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }
        .inv-table td {
            padding: 12px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .inv-table tr:last-child td { border-bottom: none; }
        .inv-table .col-amount {
            text-align: right;
            font-weight: 700;
        }
        .amount-charge { color: #dc2626; }
        .amount-earn   { color: #16a34a; }

        .tx-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
        }
        .badge-deposit  { background: #fef3c7; color: #92400e; }
        .badge-balance  { background: #d1fae5; color: #065f46; }
        .badge-payment  { background: #e0e7ff; color: #3730a3; }
        .badge-refund   { background: #fee2e2; color: #991b1b; }
        .badge-success  { background: #d1fae5; color: #065f46; }
        .badge-pending  { background: #fef3c7; color: #92400e; }
        .badge-failed   { background: #fee2e2; color: #991b1b; }

        /* Totals */
        .inv-totals {
            margin-left: auto;
            max-width: 280px;
        }
        .inv-total-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 13px;
            color: #374151;
        }
        .inv-total-row span:first-child { color: #6b7280; }
        .inv-total-row.grand {
            border-top: 2px solid #1a1a2e;
            margin-top: 8px;
            padding-top: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
        }

        /* Footer */
        .inv-footer {
            border-top: 1px solid #f3f4f6;
            padding: 20px 40px;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
        }

        @media print {
            body { background: white; }
            .print-bar { display: none; }
            .invoice-page { box-shadow: none; margin: 0; border-radius: 0; }
        }

        @media (max-width: 600px) {
            .inv-parties { grid-template-columns: 1fr; }
            .inv-header, .inv-body { padding: 24px 20px; }
            .inv-footer { padding: 16px 20px; }
        }
    </style>
</head>
<body>

<!-- Print control bar -->
<div class="print-bar">
    <a href="transaction_history.php"><i class="fas fa-arrow-left"></i> Back to History</a>
    <span>Invoice <?php echo $invoiceNum; ?></span>
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print / Save PDF
    </button>
</div>

<div class="invoice-page">

    <!-- Header -->
    <div class="inv-header">
        <div class="inv-brand">
            Community Toolkit
            <span>thecommunitytoolkit.com</span>
        </div>
        <div class="inv-meta">
            <div class="inv-number">Invoice <?php echo $invoiceNum; ?></div>
            <div class="inv-date">Generated <?php echo $invoiceDate; ?></div>
        </div>
    </div>

    <div class="inv-body">

        <!-- Parties -->
        <div class="inv-parties">
            <div class="inv-party">
                <h4>Renter (Borrower)</h4>
                <div class="party-name"><?php echo htmlspecialchars($rental['BorrowerFirst'] . ' ' . $rental['BorrowerLast']); ?></div>
                <div class="party-email"><?php echo htmlspecialchars($rental['BorrowerEmail']); ?></div>
                <?php if ($isRenter): ?>
                    <span class="party-role role-renter">You</span>
                <?php else: ?>
                    <span class="party-role role-renter">Renter</span>
                <?php endif; ?>
            </div>
            <div class="inv-party">
                <h4>Lender (Owner)</h4>
                <div class="party-name"><?php echo htmlspecialchars($rental['LenderFirst'] . ' ' . $rental['LenderLast']); ?></div>
                <div class="party-email"><?php echo htmlspecialchars($rental['LenderEmail']); ?></div>
                <?php if (!$isRenter): ?>
                    <span class="party-role role-lender">You</span>
                <?php else: ?>
                    <span class="party-role role-lender">Lender</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rental summary -->
        <div class="inv-rental-summary">
            <h3><i class="fas fa-box" style="margin-right:6px;"></i>Rental Details</h3>
            <div class="inv-detail-row">
                <span>Item</span>
                <span><?php echo htmlspecialchars($rental['ListingTitle']); ?></span>
            </div>
            <div class="inv-detail-row">
                <span>Rental ID</span>
                <span>#<?php echo $rentalId; ?></span>
            </div>
            <div class="inv-detail-row">
                <span>Start Date</span>
                <span><?php echo date('M j, Y', strtotime($rental['StartDate'])); ?></span>
            </div>
            <div class="inv-detail-row">
                <span>End Date</span>
                <span><?php echo date('M j, Y', strtotime($rental['EndDate'])); ?></span>
            </div>
            <div class="inv-detail-row">
                <span>Duration</span>
                <span><?php echo $days; ?> day<?php echo $days !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="inv-detail-row">
                <span><?php echo $rateType === 2 ? 'Rate / hour' : 'Rate / day'; ?></span>
                <span>$<?php echo number_format($rateType === 2 ? floatval($rental['PricePerHour']) : floatval($rental['PricePerDay']), 2); ?></span>
            </div>
            <div class="inv-detail-row" style="font-weight:700;padding-top:8px;border-top:1px solid #e5e7eb;margin-top:6px;">
                <span>Rental Total</span>
                <span>$<?php echo number_format($totalCost, 2); ?></span>
            </div>
        </div>

        <!-- Transactions table -->
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $txTotal = 0;
                foreach ($transactions as $t):
                    $typeId   = (int)$t['TransactionTypeID'];
                    $statusId = (int)$t['TransactionStatusID'];
                    $amt      = floatval($t['Amount']);
                    $txTotal += $amt;
                    $typeBadgeClass = match($typeId) {
                        2       => 'badge-deposit',
                        4       => 'badge-balance',
                        3       => 'badge-refund',
                        default => 'badge-payment'
                    };
                    $statusBadgeClass = match($statusId) {
                        2       => 'badge-success',
                        1       => 'badge-pending',
                        3       => 'badge-failed',
                        default => 'badge-pending'
                    };
                    $description = match($typeId) {
                        2 => '10% Deposit — ' . htmlspecialchars($rental['ListingTitle']),
                        4 => '90% Balance Payment — ' . htmlspecialchars($rental['ListingTitle']),
                        3 => 'Refund — ' . htmlspecialchars($rental['ListingTitle']),
                        1 => 'Rental Payment — ' . htmlspecialchars($rental['ListingTitle']),
                        default => htmlspecialchars($t['TransactionType'])
                    };
                ?>
                    <tr>
                        <td style="white-space:nowrap;color:#6b7280;"><?php echo date('M j, Y', strtotime($t['AddedDate'])); ?></td>
                        <td>
                            <span class="tx-badge <?php echo $typeBadgeClass; ?>"><?php echo htmlspecialchars($t['TransactionType']); ?></span>
                            <span style="margin-left:8px;"><?php echo $description; ?></span>
                            <?php
                            // Show the card used — borrower's card for charges, lender's for receipts
                            $cardType = $isRenter ? $t['BorrowerCardType'] : $t['LenderCardType'];
                            $cardLast4 = $isRenter ? $t['BorrowerCardLast4'] : $t['LenderCardLast4'];
                            $cardExpM  = $isRenter ? $t['BorrowerCardExpM']  : $t['LenderCardExpM'];
                            $cardExpY  = $isRenter ? $t['BorrowerCardExpY']  : $t['LenderCardExpY'];
                            if ($cardType && $cardLast4): ?>
                                <div style="font-size:11px;color:#9ca3af;margin-top:3px;">
                                    <?php echo htmlspecialchars($cardType); ?> ····<?php echo htmlspecialchars($cardLast4); ?>
                                    &nbsp;·&nbsp; Exp <?php echo htmlspecialchars($cardExpM . '/' . $cardExpY); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="tx-badge <?php echo $statusBadgeClass; ?>"><?php echo htmlspecialchars($t['TransactionStatus']); ?></span></td>
                        <td class="col-amount <?php echo $isRenter ? 'amount-charge' : 'amount-earn'; ?>">
                            <?php echo $isRenter ? '-' : '+'; ?>$<?php echo number_format($amt, 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="inv-totals">
            <div class="inv-total-row">
                <span>Rental Total</span>
                <span>$<?php echo number_format($totalCost, 2); ?></span>
            </div>
            <?php if (count($transactions) > 1): ?>
                <?php foreach ($transactions as $t): ?>
                    <div class="inv-total-row">
                        <span><?php echo htmlspecialchars($t['TransactionType']); ?></span>
                        <span><?php echo $isRenter ? '-' : '+'; ?>$<?php echo number_format(floatval($t['Amount']), 2); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="inv-total-row grand">
                <span><?php echo $isRenter ? 'Total Paid' : 'Total Earned'; ?></span>
                <span><?php echo $isRenter ? '-' : '+'; ?>$<?php echo number_format($txTotal, 2); ?></span>
            </div>
        </div>

    </div><!-- /inv-body -->

    <div class="inv-footer">
        Community Toolkit &nbsp;·&nbsp; thecommunitytoolkit.com &nbsp;·&nbsp;
        This invoice is for informational purposes. All transactions are processed through the Community Toolkit platform.
    </div>

</div><!-- /invoice-page -->

</body>
</html>

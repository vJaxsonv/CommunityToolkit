<?php
/**
 * Community Toolkit — Chatbot Context API
 * Place at: public_html/api/chatbot_context.php
 */
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true);
$listingId = intval($input['listing_id'] ?? 0);

// ── Get current user's location ───────────────────────────────────────────────
try {
    $userStmt = $pdo->prepare("
        SELECT u.NeighborhoodID, u.ZipCode, n.CenterLatitude, n.CenterLongitude, n.NeighborhoodName, n.City, s.StateName, s.StateAbbreviation
        FROM TUsers u
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        LEFT JOIN TStates s ON u.StateID = s.StateID
        WHERE u.UserID = ?
    ");
    $userStmt->execute([$_SESSION['user_id']]);
    $userLocation = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $userLocation = null;
}

$userLat   = floatval($userLocation['CenterLatitude']  ?? 39.1031);
$userLng   = floatval($userLocation['CenterLongitude'] ?? -84.5120);
$userCity  = $userLocation['City'] ?? 'Cincinnati';
$userState = $userLocation['StateAbbreviation'] ?? 'OH';
$userZip   = $userLocation['ZipCode'] ?? '';
// Use 'City, State' for search URLs -- specific enough without being a street address
$userArea  = trim($userCity . ', ' . $userState, ', ');
if (empty($userArea)) $userArea = 'Cincinnati, OH';

// ── Current listing context ───────────────────────────────────────────────────
$currentItem = null;
if ($listingId > 0) {
    try {
        $itemStmt = $pdo->prepare("
            SELECT l.Title, l.Description, l.PricePerDay, l.PricePerHour,
                   c.CategoryName, cond.Condition,
                   u.FirstName, u.LastName
            FROM TListings l
            INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
            INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
            INNER JOIN TUsers u ON l.UserLenderID = u.UserID
            WHERE l.ListingID = ?
        ");
        $itemStmt->execute([$listingId]);
        $currentItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $currentItem = null;
    }
}

// ── Available inventory near user (simple query, no HAVING) ───────────────────
$inventoryList = [];
try {
    $availStmt = $pdo->prepare("
        SELECT l.ListingID, l.Title, l.PricePerDay, l.PricePerHour,
               c.CategoryName, cond.Condition,
               ls.Status AS ListingStatus,
               n.NeighborhoodName, n.City,
               n.CenterLatitude, n.CenterLongitude
        FROM TListings l
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        INNER JOIN TUsers lender ON l.UserLenderID = lender.UserID
        LEFT JOIN TNeighborhoods n ON lender.NeighborhoodID = n.NeighborhoodID
        WHERE ls.Status IN ('Available', 'Pending', 'Rented')
        AND l.UserLenderID != ?
        LIMIT 100
    ");
    $availStmt->execute([$_SESSION['user_id']]);
    $rows = $availStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $item) {
        // Calculate distance in PHP instead of SQL to avoid HAVING issues
        if (!empty($item['CenterLatitude']) && !empty($item['CenterLongitude'])) {
            $lat1 = deg2rad($userLat);
            $lat2 = deg2rad(floatval($item['CenterLatitude']));
            $dlat = deg2rad(floatval($item['CenterLatitude']) - $userLat);
            $dlng = deg2rad(floatval($item['CenterLongitude']) - $userLng);
            $a    = sin($dlat/2) * sin($dlat/2) +
                    cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
            $dist = 2 * atan2(sqrt($a), sqrt(1-$a)) * 3958.8;
            // No hard distance cap -- include all items so GPT sees full inventory
        } else {
            $dist = null;
        }

        $price = '';
        if ($item['PricePerDay'])  $price .= '$' . number_format($item['PricePerDay'], 2) . '/day';
        if ($item['PricePerHour']) $price .= ($price ? ' or $' : '$') . number_format($item['PricePerHour'], 2) . '/hr';
        $location = trim(($item['NeighborhoodName'] ?? '') . ', ' . ($item['City'] ?? ''), ', ');

        $inventoryList[] = [
            'id'       => $item['ListingID'],
            'title'    => $item['Title'],
            'category' => $item['CategoryName'],
            'condition'=> $item['Condition'],
            'status'   => $item['ListingStatus'],
            'price'    => $price ?: 'Price varies',
            'location' => $location,
            'distance' => $dist !== null ? round($dist, 1) . ' mi away' : 'nearby',
        ];
    }

    // Sort by distance
    usort($inventoryList, function($a, $b) {
        $da = $a['distance'] === 'nearby' ? 999 : floatval($a['distance']);
        $db = $b['distance'] === 'nearby' ? 999 : floatval($b['distance']);
        return $da <=> $db;
    });
    $inventoryList = array_slice($inventoryList, 0, 100);

} catch (PDOException $e) {
    // Return empty inventory rather than failing
    $inventoryList = [];
}

echo json_encode([
    'success'      => true,
    'user_area'    => $userArea,
    'user_zip'     => $userZip,
    'user_lat'     => $userLat,
    'user_lng'     => $userLng,
    'current_item' => $currentItem,
    'inventory'    => $inventoryList,
    'total'        => count($inventoryList),
]);

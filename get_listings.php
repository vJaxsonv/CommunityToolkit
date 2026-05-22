<?php
/**
 * Community Toolkit — Paginated Listings API
 * Place at: public_html/api/get_listings.php
 * Returns JSON: { items: [...], hasMore: bool, nextOffset: int }
 */
require_once '../config.php';
require_once '../location_helper.php';

header('Content-Type: application/json');

$isLoggedIn    = isset($_SESSION['user_id']);
$currentUserId = $isLoggedIn ? (int)$_SESSION['user_id'] : null;

// Pagination
$limit  = 12;
$offset = max(0, (int)($_GET['offset'] ?? 0));

// Filters — same as index.php
$search           = $_GET['search']      ?? '';
$category         = $_GET['category']    ?? '';
$subcategory      = $_GET['subcategory'] ?? '';
$price_ranges     = $_GET['price_ranges'] ?? [];
$min_price        = $_GET['min_price']   ?? '';
$max_price_custom = $_GET['max_price']   ?? '';
$radius           = isset($_GET['radius']) ? floatval($_GET['radius']) : 10;

// User location
$user_lat = null;
$user_lng = null;
if ($isLoggedIn) {
    $userLocation = getUserLocation($pdo);
    $user_lat = $userLocation['latitude']  ?? null;
    $user_lng = $userLocation['longitude'] ?? null;
}

$items = [];

// ── Price condition builder ───────────────────────────────────────────────────
function buildPriceConditions($price_ranges, $min_price, $max_price_custom, &$params) {
    $priceConditions = [];
    $rangeMap = [
        '0-49'      => [0,    49],
        '50-99'     => [50,   99],
        '100-199'   => [100,  199],
        '200-299'   => [200,  299],
        '300-399'   => [300,  399],
        '400-499'   => [400,  499],
        '500-599'   => [500,  599],
        '600-699'   => [600,  699],
        '700-799'   => [700,  799],
        '800-899'   => [800,  899],
        '900-999'   => [900,  999],
        '1000-1499' => [1000, 1499],
        '1500-1999' => [1500, 1999],
        '2000-2499' => [2000, 2499],
    ];
    if (!empty($price_ranges) && is_array($price_ranges)) {
        foreach ($price_ranges as $range) {
            if ($range === '2500+') {
                $priceConditions[] = "(l.PricePerDay >= 2500)";
            } elseif (isset($rangeMap[$range])) {
                [$lo, $hi] = $rangeMap[$range];
                $priceConditions[] = "(l.PricePerDay >= $lo AND l.PricePerDay <= $hi)";
            }
        }
    }
    if (!empty($min_price) || !empty($max_price_custom)) {
        $custom = [];
        if (!empty($min_price))        { $custom[] = "l.PricePerDay >= ?"; $params[] = $min_price; }
        if (!empty($max_price_custom)) { $custom[] = "l.PricePerDay <= ?"; $params[] = $max_price_custom; }
        if ($custom) $priceConditions[] = "(" . implode(" AND ", $custom) . ")";
    }
    return $priceConditions;
}

if ($user_lat !== null && $user_lng !== null) {
    // ── Location-aware query ──────────────────────────────────────────────────
    $sql = "
        SELECT * FROM (
            SELECT
                l.*, u.FirstName, u.LastName,
                u.ProfilePictureURL AS OwnerProfilePicture,
                u.UserID AS OwnerUserID,
                c.CategoryName, cond.Condition, ls.Status,
                n.NeighborhoodName, n.City,
                n.CenterLatitude, n.CenterLongitude,
                (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) AS PrimaryImage,
                (3959 * ACOS(LEAST(1, GREATEST(-1,
                    COS(RADIANS(?)) * COS(RADIANS(n.CenterLatitude)) *
                    COS(RADIANS(n.CenterLongitude) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(n.CenterLatitude))
                )))) AS distance
            FROM TListings l
            INNER JOIN TUsers u ON l.UserLenderID = u.UserID
            LEFT JOIN TCategories c ON l.CategoryID = c.CategoryID
            LEFT JOIN TConditions cond ON l.ConditionID = cond.ConditionID
            LEFT JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
            LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
            WHERE n.CenterLatitude IS NOT NULL AND n.CenterLongitude IS NOT NULL
              AND ls.Status NOT IN ('Removed', 'Inactive')
    ";
    $params = [$user_lat, $user_lng, $user_lat];

    if ($isLoggedIn)      { $sql .= " AND l.UserLenderID <> ?"; $params[] = $currentUserId; }
    if (!empty($search))  { $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

    if (!empty($subcategory)) {
        $sql .= " AND l.CategoryID = ?"; $params[] = $subcategory;
    } elseif (!empty($category)) {
        $sql .= " AND (l.CategoryID = ? OR l.CategoryID IN (SELECT CategoryID FROM TCategories WHERE ParentCategoryID = ?))";
        $params[] = $category; $params[] = $category;
    }

    $priceConditions = buildPriceConditions($price_ranges, $min_price, $max_price_custom, $params);
    if ($priceConditions) $sql .= " AND (" . implode(" OR ", $priceConditions) . ")";

    $sql .= ") AS nearby_items WHERE distance <= ? ORDER BY distance ASC, AddedDate DESC LIMIT ? OFFSET ?";
    $params[] = $radius;
    $params[] = $limit + 1; // fetch one extra to know if more exist
    $params[] = $offset;

} else {
    // ── No-location fallback query ────────────────────────────────────────────
    $sql = "
        SELECT l.*, u.FirstName, u.LastName,
               u.ProfilePictureURL AS OwnerProfilePicture,
               u.UserID AS OwnerUserID,
               c.CategoryName, cond.Condition, ls.Status,
               n.NeighborhoodName, n.City,
               n.CenterLatitude, n.CenterLongitude,
               (SELECT PhotoURL FROM TListingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) AS PrimaryImage,
               NULL AS distance
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        LEFT JOIN TCategories c ON l.CategoryID = c.CategoryID
        LEFT JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        LEFT JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        WHERE ls.Status NOT IN ('Removed', 'Inactive')
    ";
    $params = [];

    if ($isLoggedIn)      { $sql .= " AND l.UserLenderID <> ?"; $params[] = $currentUserId; }
    if (!empty($search))  { $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

    if (!empty($subcategory)) {
        $sql .= " AND l.CategoryID = ?"; $params[] = $subcategory;
    } elseif (!empty($category)) {
        $sql .= " AND (l.CategoryID = ? OR l.CategoryID IN (SELECT CategoryID FROM TCategories WHERE ParentCategoryID = ?))";
        $params[] = $category; $params[] = $category;
    }

    $priceConditions = buildPriceConditions($price_ranges, $min_price, $max_price_custom, $params);
    if ($priceConditions) $sql .= " AND (" . implode(" OR ", $priceConditions) . ")";

    $sql .= " ORDER BY l.AddedDate DESC LIMIT ? OFFSET ?";
    $params[] = $limit + 1;
    $params[] = $offset;
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Query failed', 'items' => [], 'hasMore' => false]);
    exit;
}

$hasMore = count($rows) > $limit;
if ($hasMore) array_pop($rows); // remove the extra sentinel row

// Fetch photos for these listings
$listingPhotos = [];
if (!empty($rows)) {
    $ids = array_column($rows, 'ListingID');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $photoStmt = $pdo->prepare("SELECT ListingID, PhotoURL FROM TListingPhotos WHERE ListingID IN ($ph) ORDER BY ListingID, SortOrder ASC");
    $photoStmt->execute($ids);
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $listingPhotos[$p['ListingID']][] = $p['PhotoURL'];
    }
}

// Attach photos to each item
foreach ($rows as &$item) {
    $item['photos'] = $listingPhotos[$item['ListingID']] ?? [];
}
unset($item);

// Fetch bookmarks for logged-in user
$bookmarks = [];
if ($isLoggedIn && !empty($rows)) {
    $ids = array_column($rows, 'ListingID');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $bStmt = $pdo->prepare("SELECT ListingID FROM TBookmarks WHERE UserID = ? AND ListingID IN ($ph)");
    $bStmt->execute(array_merge([$currentUserId], $ids));
    $bookmarks = array_column($bStmt->fetchAll(PDO::FETCH_ASSOC), 'ListingID');
}

foreach ($rows as &$item) {
    $item['isBookmarked'] = in_array($item['ListingID'], $bookmarks);
}
unset($item);

echo json_encode([
    'items'      => $rows,
    'hasMore'    => $hasMore,
    'nextOffset' => $offset + count($rows),
]);

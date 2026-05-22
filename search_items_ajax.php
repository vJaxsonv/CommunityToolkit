<?php
require_once 'config.php';

header('Content-Type: application/json');

try {
    $q = trim($_GET['q'] ?? '');

    if ($q === '') {
        echo json_encode([]);
        exit;
    }

    // 👇 get current user id from session
    $currentUserId = $_SESSION['user_id'] ?? 0;

    $search = '%' . $q . '%';

    $sql = "
        SELECT 
            l.ListingID,
            l.Title,
            l.PricePerDay,
            c.CategoryName,
            (
                SELECT PhotoURL
                FROM TListingPhotos p
                WHERE p.ListingID = l.ListingID
                ORDER BY p.SortOrder ASC
                LIMIT 1
            ) AS PhotoURL
        FROM TListings l
        LEFT JOIN TCategories c ON l.CategoryID = c.CategoryID
        WHERE 
            (
                l.Title LIKE :search1
                OR l.Description LIKE :search2
                OR c.CategoryName LIKE :search3
            )

            -- ✅ only allowed statuses
            AND l.ListingStatusID IN (1, 2, 3)

            -- ✅ hide user's own listings
            AND l.UserLenderID != :currentUserId

        ORDER BY l.AddedDate DESC
        LIMIT 8
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':search1' => $search,
        ':search2' => $search,
        ':search3' => $search,
        ':currentUserId' => $currentUserId
    ]);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
    exit;
}
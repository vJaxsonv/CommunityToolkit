<?php
/**
 * Community Toolkit — Get Neighborhoods by City
 * Place at: public_html/get_neighborhoods.php
 */
require_once 'config.php';

header('Content-Type: application/json');

$cityId = intval($_GET['city_id'] ?? 0);

if ($cityId <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT NeighborhoodID, NeighborhoodName
        FROM TNeighborhoods
        WHERE CityID = ?
        ORDER BY NeighborhoodName ASC
    ");
    $stmt->execute([$cityId]);
    $neighborhoods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($neighborhoods);
} catch (PDOException $e) {
    echo json_encode([]);
}

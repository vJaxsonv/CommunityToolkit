<?php
/**
 * Community Toolkit — Get Cities by State
 * Place at: public_html/get_cities.php
 */
require_once 'config.php';

header('Content-Type: application/json');

$stateId = intval($_GET['state_id'] ?? 0);

if ($stateId <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT CityID, CityName
        FROM TCities
        WHERE StateID = ?
        ORDER BY CityName ASC
    ");
    $stmt->execute([$stateId]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($cities);
} catch (PDOException $e) {
    echo json_encode([]);
}

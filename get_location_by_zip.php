<?php
/**
 * Community Toolkit — Get Location by ZIP Code
 * Returns state, city, and neighborhood matching the zip code
 * Place at: public_html/get_location_by_zip.php
 */
require_once 'config.php';

header('Content-Type: application/json');

$zip = preg_replace('/\D/', '', $_GET['zip'] ?? '');

if (strlen($zip) !== 5) {
    echo json_encode(['found' => false]);
    exit;
}

try {
    // Look up neighborhood by zip — join to city and state
    $stmt = $pdo->prepare("
        SELECT
            n.NeighborhoodID,
            n.NeighborhoodName,
            n.CityID,
            c.CityName,
            n.StateID,
            s.StateName
        FROM TNeighborhoods n
        INNER JOIN TCities  c ON n.CityID   = c.CityID
        INNER JOIN TStates  s ON n.StateID  = s.StateID
        WHERE n.ZipCode = ?
        LIMIT 1
    ");
    $stmt->execute([$zip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['found' => false]);
        exit;
    }

    // Check if this city has multiple neighborhoods (to know whether to show dropdown)
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM TNeighborhoods WHERE CityID = ?");
    $countStmt->execute([$row['CityID']]);
    $neighborhoodCount = (int)$countStmt->fetchColumn();

    // Get all cities for this state (to populate city dropdown)
    $citiesStmt = $pdo->prepare("SELECT CityID, CityName FROM TCities WHERE StateID = ? ORDER BY CityName ASC");
    $citiesStmt->execute([$row['StateID']]);
    $cities = $citiesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all neighborhoods for this city (to populate neighborhood dropdown if needed)
    $neighStmt = $pdo->prepare("SELECT NeighborhoodID, NeighborhoodName FROM TNeighborhoods WHERE CityID = ? ORDER BY NeighborhoodName ASC");
    $neighStmt->execute([$row['CityID']]);
    $neighborhoods = $neighStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'found'              => true,
        'state_id'           => $row['StateID'],
        'state_name'         => $row['StateName'],
        'city_id'            => $row['CityID'],
        'city_name'          => $row['CityName'],
        'neighborhood_id'    => $row['NeighborhoodID'],
        'neighborhood_name'  => $row['NeighborhoodName'],
        'has_neighborhoods'  => $neighborhoodCount > 1,
        'cities'             => $cities,
        'neighborhoods'      => $neighborhoods,
    ]);

} catch (PDOException $e) {
    echo json_encode(['found' => false]);
}

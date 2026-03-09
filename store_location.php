<?php
require_once 'config.php';

header('Content-Type: application/json');

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['latitude']) && isset($data['longitude'])) {
    $_SESSION['user_latitude'] = floatval($data['latitude']);
    $_SESSION['user_longitude'] = floatval($data['longitude']);
    $_SESSION['location_source'] = $data['source'] ?? 'unknown';
    $_SESSION['location_timestamp'] = time();
    
    echo json_encode(['success' => true]);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>

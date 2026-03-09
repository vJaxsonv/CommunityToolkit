<?php
/**
 * Location Helper Functions
 * Add these functions to a new file: includes/location_helper.php
 * Or add to an existing utilities file
 */

/**
 * Get user's current location
 * Priority: 1) Account Address, 2) Browser Geolocation, 3) IP Fallback
 * 
 * @param PDO $pdo Database connection
 * @return array ['latitude' => float, 'longitude' => float, 'source' => string]
 */
function getUserLocation($pdo) {
    // Priority 1: If logged in, use account address
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("
            SELECT u.AddressLine1, u.ZipCode, n.CenterLatitude, n.CenterLongitude, 
                   n.NeighborhoodName, n.City, s.StateName
            FROM TUsers u
            LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
            LEFT JOIN TStates s ON u.StateID = s.StateID
            WHERE u.UserID = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If user has neighborhood with coordinates, use those
        if ($user && $user['CenterLatitude'] && $user['CenterLongitude']) {
            return [
                'latitude' => floatval($user['CenterLatitude']),
                'longitude' => floatval($user['CenterLongitude']),
                'source' => 'account_neighborhood',
                'location_name' => $user['NeighborhoodName'] . ', ' . ($user['City'] ?: $user['StateName'])
            ];
        }
        
        // If user has zip code, geocode it
        if ($user && $user['ZipCode']) {
            $coords = geocodeZipCode($user['ZipCode']);
            if ($coords) {
                return [
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                    'source' => 'account_zipcode',
                    'location_name' => $user['ZipCode']
                ];
            }
        }
    }
    
    // Priority 2: Browser geolocation from session
    if (isset($_SESSION['user_latitude']) && isset($_SESSION['user_longitude'])) {
        return [
            'latitude' => floatval($_SESSION['user_latitude']),
            'longitude' => floatval($_SESSION['user_longitude']),
            'source' => $_SESSION['location_source'] ?? 'browser',
            'location_name' => 'Current Location'
        ];
    }
    
    // Priority 3: IP-based geolocation (fallback)
    $ipLocation = getLocationFromIP();
    if ($ipLocation) {
        return $ipLocation;
    }
    
    // Final fallback: Cincinnati center
    return [
        'latitude' => 39.1031,
        'longitude' => -84.5120,
        'source' => 'default',
        'location_name' => 'Cincinnati, OH'
    ];
}

/**
 * Geocode a US zip code to lat/lng
 * Uses a simple lookup table for common Cincinnati zip codes
 * 
 * @param string $zipCode
 * @return array|null ['latitude' => float, 'longitude' => float]
 */
function geocodeZipCode($zipCode) {
    // Common Cincinnati area zip codes
    $zipCodes = [
        '45202' => ['latitude' => 39.1031, 'longitude' => -84.5120], // Downtown
        '45208' => ['latitude' => 39.1414, 'longitude' => -84.4436], // Hyde Park
        '45209' => ['latitude' => 39.1456, 'longitude' => -84.4242], // Oakley
        '45220' => ['latitude' => 39.1342, 'longitude' => -84.5186], // Clifton
        '45227' => ['latitude' => 39.1456, 'longitude' => -84.4328], // Madisonville
        '45230' => ['latitude' => 39.0953, 'longitude' => -84.4242], // Mount Washington
        '45238' => ['latitude' => 39.1531, 'longitude' => -84.6042], // Westwood
        // Add more as needed
    ];
    
    if (isset($zipCodes[$zipCode])) {
        return $zipCodes[$zipCode];
    }
    
    // For production, you'd call a geocoding API here
    // Example: Google Geocoding API, OpenCage, etc.
    
    return null;
}

/**
 * Get approximate location from IP address
 * 
 * @return array|null ['latitude' => float, 'longitude' => float, 'source' => string]
 */
function getLocationFromIP() {
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // Don't try to geocode local/private IPs
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0) {
        return null;
    }
    
    // Use a free IP geolocation service
    try {
        $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=lat,lon,city,regionName");
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['lat']) && isset($data['lon'])) {
                return [
                    'latitude' => floatval($data['lat']),
                    'longitude' => floatval($data['lon']),
                    'source' => 'ip',
                    'location_name' => ($data['city'] ?? '') . ', ' . ($data['regionName'] ?? '')
                ];
            }
        }
    } catch (Exception $e) {
        // Fail silently
    }
    
    return null;
}

/**
 * Calculate distance between two lat/lng points in miles
 * Uses Haversine formula
 * 
 * @param float $lat1 Latitude of point 1
 * @param float $lng1 Longitude of point 1
 * @param float $lat2 Latitude of point 2
 * @param float $lng2 Longitude of point 2
 * @return float Distance in miles
 */
function calculateDistance($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 3959; // miles (use 6371 for kilometers)
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

/**
 * Add distance to listings based on user location
 * 
 * @param array $listings Array of listing items
 * @param float $userLat User's latitude
 * @param float $userLng User's longitude
 * @return array Listings with 'distance' field added
 */
function addDistanceToListings($listings, $userLat, $userLng) {
    foreach ($listings as &$listing) {
        if (isset($listing['CenterLatitude']) && isset($listing['CenterLongitude'])) {
            $listing['distance'] = calculateDistance(
                $userLat, 
                $userLng, 
                $listing['CenterLatitude'], 
                $listing['CenterLongitude']
            );
        } else {
            $listing['distance'] = null;
        }
    }
    
    // Sort by distance (closest first)
    usort($listings, function($a, $b) {
        if ($a['distance'] === null) return 1;
        if ($b['distance'] === null) return -1;
        return $a['distance'] <=> $b['distance'];
    });
    
    return $listings;
}
?>

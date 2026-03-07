<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database credentials
$host = 'localhost';
$dbname = 'thecommu_communitytoolkit';
$username = 'thecommu_cfrasierdb';
$password = 'nPhilip1031*';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>✅ Database Connected Successfully</h2>";
    
    // Check TUsers table structure
    echo "<h3>TUsers Table Structure:</h3>";
    $stmt = $pdo->query("SHOW CREATE TABLE TUsers");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . htmlspecialchars($result['Create Table']) . "</pre>";
    
    // Check if UserID is AUTO_INCREMENT
    echo "<h3>UserID Column Details:</h3>";
    $stmt = $pdo->query("SHOW COLUMNS FROM TUsers WHERE Field = 'UserID'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($column);
    echo "</pre>";
    
    if (strpos($column['Extra'], 'auto_increment') !== false) {
        echo "✅ <strong>UserID IS AUTO_INCREMENT</strong><br>";
    } else {
        echo "❌ <strong>UserID IS NOT AUTO_INCREMENT - This is the problem!</strong><br>";
        echo "<p>Run this SQL command in phpMyAdmin:</p>";
        echo "<pre>ALTER TABLE `TUsers` MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT;</pre>";
    }
    
    // Check current AUTO_INCREMENT value
    $stmt = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = 'TUsers'");
    $autoInc = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Current AUTO_INCREMENT value: " . ($autoInc['AUTO_INCREMENT'] ?? 'NULL') . "</p>";
    
    // Count existing users
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM TUsers");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total users in database: " . $count['count'] . "</p>";
    
    // Test insert (without committing)
    echo "<h3>Testing INSERT (not committed):</h3>";
    try {
        $pdo->beginTransaction();
        
        $sql = "INSERT INTO TUsers (FirstName, LastName, Email, Password, PhoneNumber, 
                GenderID, ProfilePictureURL, Bio, NeighborhoodID, AddedDate, AccountStatus) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)";
        
        $stmt = $pdo->prepare($sql);
        $testData = [
            'TestFirst',
            'TestLast', 
            'test_' . time() . '@example.com',
            password_hash('testpass', PASSWORD_DEFAULT),
            '5555555555',
            3,
            null,
            null,
            1
        ];
        
        $stmt->execute($testData);
        $newId = $pdo->lastInsertId();
        
        echo "✅ <strong>Test insert successful! New UserID would be: $newId</strong><br>";
        
        $pdo->rollBack(); // Don't actually save it
        echo "(Test data was rolled back - not saved)";
        
    } catch (PDOException $e) {
        echo "❌ <strong>Test insert FAILED:</strong> " . $e->getMessage();
        $pdo->rollBack();
    }
    
} catch(PDOException $e) {
    echo "❌ <strong>Database Error:</strong> " . $e->getMessage();
}
?>
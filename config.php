<?php
// Database credentials
$host = 'localhost';
$dbname = 'thecommu_communitytoolkit';
$username = 'thecommu_cfrasierdb';
$password = 'nPhilip1031*';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Start session
session_start();
?>
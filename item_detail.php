<?php 
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Details - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div style="text-align: center; padding: 100px 20px; font-family: Arial, sans-serif;">
        <h1 style="color: #667eea;">🚧 Page Under Construction 🚧</h1>
        <p style="font-size: 18px; color: #666;">The "Item Details" page is currently being developed.</p>
        <p style="color: #999;">Check back soon!</p>
        <br>
        <a href="home.php" style="display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 8px;">← Back to Home</a>
    </div>
</body>
</html>

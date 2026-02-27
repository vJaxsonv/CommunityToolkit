<?php
/**
 * Simple Connection Test - ERD 3.0
 */

require_once 'config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connection Test - Community Toolkit</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 100px auto;
            padding: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .test-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .success {
            color: #28a745;
            font-size: 48px;
            margin-bottom: 20px;
        }
        .error {
            color: #dc3545;
            font-size: 48px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        p {
            color: #666;
            line-height: 1.6;
        }
        .info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: left;
        }
        .info strong {
            color: #667eea;
        }
        a {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
        }
        a:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="test-card">
        <?php
        try {
            // Test basic connection
            $stmt = $pdo->query("SELECT DATABASE() as db");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Test if new tables exist
            $stmt = $pdo->query("SHOW TABLES LIKE 'TUsers'");
            $tableExists = $stmt->fetch();
            
            if ($tableExists) {
                // Get counts
                $userCount = $pdo->query("SELECT COUNT(*) as count FROM TUsers")->fetch()['count'];
                $listingCount = $pdo->query("SELECT COUNT(*) as count FROM TListings")->fetch()['count'];
                $categoryCount = $pdo->query("SELECT COUNT(*) as count FROM TCategories")->fetch()['count'];
                
                echo '<div class="success">✓</div>';
                echo '<h1>Connection Successful!</h1>';
                echo '<p>ERD 3.0 database is properly configured</p>';
                echo '<div class="info">';
                echo '<strong>Database:</strong> ' . htmlspecialchars($result['db']) . '<br>';
                echo '<strong>Users:</strong> ' . $userCount . '<br>';
                echo '<strong>Listings:</strong> ' . $listingCount . '<br>';
                echo '<strong>Categories:</strong> ' . $categoryCount . '<br>';
                echo '<strong>Schema Version:</strong> ERD 3.0';
                echo '</div>';
                echo '<a href="index.html">Go to Homepage</a>';
                echo '<a href="test_db.php" style="margin-left: 10px;">Detailed Test</a>';
            } else {
                echo '<div class="error">⚠</div>';
                echo '<h1>Old Schema Detected</h1>';
                echo '<p>Database is connected but still using old table names.</p>';
                echo '<p>Please run the <strong>CommunityToolkit_ERD3_MySQL.sql</strong> script.</p>';
                echo '<a href="test_db.php">Run Tests Anyway</a>';
            }
            
        } catch(PDOException $e) {
            echo '<div class="error">✗</div>';
            echo '<h1>Connection Failed</h1>';
            echo '<p>Unable to connect to the database.</p>';
            echo '<div class="info">';
            echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
            echo '</div>';
            echo '<p>Check your <code>config.php</code> settings.</p>';
        }
        ?>
    </div>
</body>
</html>

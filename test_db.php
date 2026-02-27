<?php
/**
 * Database Connection Test - ERD 3.0
 * Tests connection and displays sample data from new schema
 */

require_once 'config.php';

echo "<h1>Database Connection Test - ERD 3.0</h1>";

// Test 1: Connection
try {
    echo "<h2>✅ Test 1: Database Connection</h2>";
    echo "<p>Successfully connected to database!</p>";
} catch(PDOException $e) {
    echo "<h2>❌ Test 1: Database Connection FAILED</h2>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    exit;
}

// Test 2: Check if new tables exist
echo "<h2>Test 2: Checking New Tables</h2>";
$tables = [
    'TUsers', 
    'TListings', 
    'TCategories', 
    'TConditions', 
    'TListingStatuses',
    'TRentalRequests',
    'TRentals',
    'TNotifications',
    'TConversations'
];

foreach($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>✅ <strong>$table</strong> exists - {$result['count']} records</p>";
    } catch(PDOException $e) {
        echo "<p>❌ <strong>$table</strong> - Error: " . $e->getMessage() . "</p>";
    }
}

// Test 3: Display Categories
echo "<h2>Test 3: Sample Categories</h2>";
try {
    $stmt = $pdo->query("SELECT CategoryID, CategoryName, ParentCategoryID FROM TCategories ORDER BY CategoryID LIMIT 10");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($categories) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Category Name</th><th>Parent ID</th></tr>";
        foreach($categories as $cat) {
            echo "<tr>";
            echo "<td>{$cat['CategoryID']}</td>";
            echo "<td>{$cat['CategoryName']}</td>";
            echo "<td>" . ($cat['ParentCategoryID'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No categories found</p>";
    }
} catch(PDOException $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

// Test 4: Display Conditions
echo "<h2>Test 4: Conditions</h2>";
try {
    $stmt = $pdo->query("SELECT ConditionID, ConditionName FROM TConditions");
    $conditions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($conditions) > 0) {
        echo "<ul>";
        foreach($conditions as $cond) {
            echo "<li>{$cond['ConditionID']}: {$cond['ConditionName']}</li>";
        }
        echo "</ul>";
    }
} catch(PDOException $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

// Test 5: Display Users
echo "<h2>Test 5: Users</h2>";
try {
    $stmt = $pdo->query("SELECT UserID, FirstName, LastName, Email, AddedDate FROM TUsers ORDER BY AddedDate DESC LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($users) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Registered</th></tr>";
        foreach($users as $user) {
            echo "<tr>";
            echo "<td>{$user['UserID']}</td>";
            echo "<td>{$user['FirstName']} {$user['LastName']}</td>";
            echo "<td>{$user['Email']}</td>";
            echo "<td>{$user['AddedDate']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No users found. <a href='register.php'>Register a test user</a></p>";
    }
} catch(PDOException $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

// Test 6: Display Listings
echo "<h2>Test 6: Listings</h2>";
try {
    $stmt = $pdo->query("SELECT l.ListingID, l.Title, l.PricePerDay, u.FirstName, u.LastName, c.CategoryName, cond.ConditionName
                        FROM TListings l
                        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
                        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
                        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
                        ORDER BY l.AddedDate DESC LIMIT 5");
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($listings) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Title</th><th>Owner</th><th>Category</th><th>Condition</th><th>Price/Day</th></tr>";
        foreach($listings as $item) {
            echo "<tr>";
            echo "<td>{$item['ListingID']}</td>";
            echo "<td>{$item['Title']}</td>";
            echo "<td>{$item['FirstName']} {$item['LastName']}</td>";
            echo "<td>{$item['CategoryName']}</td>";
            echo "<td>{$item['ConditionName']}</td>";
            echo "<td>\${$item['PricePerDay']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No listings found. <a href='create_listing.php'>Create a test listing</a></p>";
    }
} catch(PDOException $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>✅ All Tests Complete!</h2>";
echo "<p><a href='index.html'>← Back to Home</a> | <a href='home.php'>Browse Listings</a></p>";
?>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 900px;
    margin: 50px auto;
    padding: 20px;
    background: #f5f5f5;
}
h1 { color: #667eea; }
h2 { 
    color: #333; 
    border-bottom: 2px solid #667eea;
    padding-bottom: 10px;
    margin-top: 30px;
}
table {
    width: 100%;
    background: white;
    border-collapse: collapse;
    margin: 10px 0;
}
th {
    background: #667eea;
    color: white;
    padding: 10px;
}
td {
    padding: 8px;
}
tr:nth-child(even) {
    background: #f9f9f9;
}
a {
    color: #667eea;
    text-decoration: none;
    font-weight: bold;
}
a:hover {
    text-decoration: underline;
}
</style>

<?php
require_once 'config.php';

header('Content-Type: application/json');

$parentId = intval($_GET['parent'] ?? 0);

if ($parentId > 0) {
    $stmt = $pdo->prepare("SELECT CategoryID, CategoryName FROM TCategories WHERE ParentCategoryID = ? ORDER BY CategoryName");
    $stmt->execute([$parentId]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($subcategories);
} else {
    echo json_encode([]);
}
?>

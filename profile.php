

<?php 
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$max_price = $_GET['max_price'] ?? '';

// Build query with CORRECT column names
$sql = "SELECT l.*, 
        u.FirstName, u.LastName, u.UserID as OwnerUserID,
        c.CategoryName,
        cond.ConditionName,
        ls.StatusName as ListingStatus,
        n.NeighborhoodName, n.City,
        (SELECT PhotoURL FROM TLisitingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
        FROM TListings l
        INNER JOIN TUsers u ON l.UserLenderID = u.UserID
        INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
        INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
        INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
        LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
        WHERE ls.StatusName = 'Active'";

$params = [];

// Add search filter
if (!empty($search)) {
    $sql .= " AND (l.Title LIKE ? OR l.Description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Add category filter
if (!empty($category)) {
    $sql .= " AND l.CategoryID = ?";
    $params[] = $category;
}

// Add price filter
if (!empty($max_price)) {
    $sql .= " AND l.PricePerDay <= ?";
    $params[] = $max_price;
}

$sql .= " ORDER BY l.AddedDate DESC LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $pdo->query("SELECT * FROM TCategories WHERE ParentCategoryID IS NULL ORDER BY CategoryName")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Items - Community Toolkit</title>
    <link rel="stylesheet" href="style.css?v=999">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>
<body>

    <!-- Header/Navigation -->
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <!-- Search Bar -->
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <form action="home.php" method="GET">
                        <input type="text" name="search" placeholder="Search for items near you..." class="search-input" value="<?php echo htmlspecialchars($search); ?>">
                    </form>
                </div>
                
                <!-- Navigation -->
                <nav class="main-nav">
                    <a href="index.html" class="nav-link active">
                        <i class="fas fa-home"></i>
                        <span>Home</span>
                    </a>
                    <a href="myitems.php" class="nav-link">
                        <i class="fas fa-box"></i>
                        <span>My Items</span>
                    </a>
                    <a href="create_listing.php" class="nav-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>List Item</span>
                    </a>
                    <a href="my_rentals.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>My Rentals</span>
                    </a>
                </nav>
                
                <!-- User Section -->
                <div class="user-section">
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">0</span>
                    </div>
                    <div class="user-menu-container">
                        <div class="user-avatar" onclick="toggleUserMenu()">
                            <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
                        </div>
                        <div class="user-dropdown" id="userDropdown">
                            <div class="user-dropdown-header">
                                <strong><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></strong>
                                <small><?php echo htmlspecialchars($_SESSION['email']); ?></small>
                            </div>
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <a href="my_items.php"><i class="fas fa-box"></i> My Items</a>
                            <a href="my_rentals.php"><i class="fas fa-calendar"></i> My Rentals</a>
                            <hr>
                            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
  <div class="profile-container">

    <div class="profile-left">
        <div class="profile-avatar">
            <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
        </div>
    </div>

    <div class="profile-form">
        <h2>Edit Profile</h2>

        <form action="update_profile.php" method="POST">

            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="firstname"
                    value="<?php echo htmlspecialchars($_SESSION['firstname']); ?>">
            </div>

            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="lastname"
                    value="<?php echo htmlspecialchars($_SESSION['lastname']); ?>">
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email"
                    value="<?php echo htmlspecialchars($_SESSION['email']); ?>">
            </div>

            <button type="submit" class="save-btn">
                Save Changes
            </button>

        </form>
    </div>

</div>

</div>
    



<script>
function toggleUserMenu() {
const dropdown = document.getElementById('userDropdown');
dropdown.classList.toggle('show');
}
        
// Close dropdown when clicking outside
window.onclick = function(event) {
if (!event.target.matches('.user-avatar')) {
const dropdown = document.getElementById('userDropdown');
if (dropdown.classList.contains('show')) {
dropdown.classList.remove('show');
}
}
}
</script>

</body>
</html>
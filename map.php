<?php
require_once 'config.php';

$sql .= " ORDER BY l.AddedDate DESC LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $pdo->query("
    SELECT * 
    FROM TCategories 
    WHERE ParentCategoryID IS NULL 
    ORDER BY CategoryName
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">

<title>Guides</title>

<meta name="viewport" content="initial-scale=1,maximum-scale=1,user-scalable=no">

<link href="https://api.mapbox.com/mapbox-gl-js/v3.19.1/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.19.1/mapbox-gl.js"></script>

<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
body {
    margin: 0;
    padding: 0;
}

.main-header,
.filters-section {
    position: relative;
    z-index: 10;
}

#map {
    position: absolute;
    top: 150px; /* header + filters height */
    left: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    z-index: 1;
}
</style>
</head>

<body>

<header class="main-header">
<div class="container">
<div class="header-content">

<!-- Search Bar -->
<div class="search-container">
<i class="fas fa-search search-icon"></i>

<form action="home.php" method="GET">
<input
    type="text"
    name="search"
    placeholder="Search for items near you..."
    class="search-input"
    value="<?php echo htmlspecialchars($search); ?>"
>
</form>

</div>

<!-- Navigation -->
<nav class="main-nav">

<a href="home.php" class="nav-link active">
<i class="fas fa-home"></i>
<span>Home</span>
</a>

<a href="map.php" class="nav-link">
<i class="fas fa-map-marker-alt"></i>
<span>Map</span>
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
<strong>
<?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?>
</strong>

<small>
<?php echo htmlspecialchars($_SESSION['email']); ?>
</small>
</div>

<a href="profile.php">
<i class="fas fa-user"></i> My Profile
</a>

<a href="my_items.php">
<i class="fas fa-box"></i> My Items
</a>

<a href="my_rentals.php">
<i class="fas fa-calendar"></i> My Rentals
</a>

<hr>

<a href="logout.php">
<i class="fas fa-sign-out-alt"></i> Logout
</a>

</div>
</div>
</div>

</div>
</div>
</header>


<!-- Filters Section -->
<section class="filters-section">

<div class="container">

<form action="home.php" method="GET" class="filters">

<input
    type="hidden"
    name="search"
    value="<?php echo htmlspecialchars($search); ?>"
>

<div class="filter-dropdown">

<select name="category" class="filter-select" onchange="this.form.submit()">

<option value="">All Categories</option>

<?php foreach ($categories as $cat): ?>

<option
    value="<?php echo $cat['CategoryID']; ?>"
    <?php echo ($category == $cat['CategoryID']) ? 'selected' : ''; ?>
>
<?php echo htmlspecialchars($cat['CategoryName']); ?>
</option>

<?php endforeach; ?>

</select>

</div>


<select name="max_price" class="filter-chip" onchange="this.form.submit()">

<option value="">Any Price</option>

<option value="5" <?php echo ($max_price == '5') ? 'selected' : ''; ?>>
Under $5
</option>

<option value="10" <?php echo ($max_price == '10') ? 'selected' : ''; ?>>
Under $10
</option>

<option value="20" <?php echo ($max_price == '20') ? 'selected' : ''; ?>>
Under $20
</option>

<option value="50" <?php echo ($max_price == '50') ? 'selected' : ''; ?>>
Under $50
</option>

</select>


<?php if (!empty($search) || !empty($category) || !empty($max_price)): ?>

<a href="home.php" class="btn btn-outline btn-sm">
Clear Filters
</a>

<?php endif; ?>

</form>

</div>
</section>


<div id="map"></div>


<script>

const map = new mapboxgl.Map({
    container: 'map',
    style: 'mapbox://styles/mapbox/standard',
    projection: 'globe',
    zoom: 11,
    center: [-84.5120, 39.1031] // Cincinnati
});

map.addControl(new mapboxgl.NavigationControl());

map.scrollZoom.disable();

map.on('style.load', () => {
    map.setFog({});
});

</script>


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
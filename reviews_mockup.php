<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Fake review data for mockup/testing
$fakeReviews = [
    [
        'reviewer' => 'Sarah M.',
        'review_type' => 'Lender Review',
        'rating' => 5,
        'date' => '2026-04-08',
        'comment' => 'The lender was very responsive, the item was exactly as described, and pickup was easy.'
    ],
    [
        'reviewer' => 'James T.',
        'review_type' => 'Borrower Review',
        'rating' => 4,
        'date' => '2026-04-05',
        'comment' => 'The borrower communicated well, returned the item on time, and handled it carefully.'
    ],
    [
        'reviewer' => 'Emily R.',
        'review_type' => 'Lender Review',
        'rating' => 5,
        'date' => '2026-04-02',
        'comment' => 'Great lending experience. The item worked perfectly and communication was excellent.'
    ]
];

$averageRating = 4.7;
$totalReviews = count($fakeReviews);

// Mockup values for page context
$itemTitle = "DeWalt Power Drill Set";
$lenderName = "Michael B.";
$borrowerName = "Jessica S.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Write a Review - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reviews-page {
            max-width: 900px;
            margin: 30px auto;
        }

        .reviews-summary,
        .review-form-card,
        .review-card,
        .review-context-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 24px;
            margin-bottom: 20px;
        }

        .reviews-summary h1,
        .review-context-card h2,
        .review-form-card h2 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .rating-display {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 600;
            color: #333;
            flex-wrap: wrap;
        }

        .stars {
            color: #f5b301;
        }

        .review-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .reviewer-name {
            font-weight: 600;
            color: #222;
        }

        .review-date {
            color: #777;
            font-size: 14px;
        }

        .review-comment {
            color: #444;
            line-height: 1.6;
        }

        .review-type-badge {
            display: inline-block;
            background: #eef2ff;
            color: #4f46e5;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .review-form-card h2 {
            margin-top: 0;
        }

        .context-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 16px;
        }

        .context-box {
            background: #f8f9fc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
        }

        .context-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .context-value {
            font-size: 16px;
            font-weight: 600;
            color: #222;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d9d9d9;
            border-radius: 8px;
            font-size: 15px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .review-help-text {
            font-size: 14px;
            color: #666;
            margin-top: -4px;
            margin-bottom: 16px;
        }

        .submit-review-btn {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .submit-review-btn:hover {
            background: #586de0;
        }

        @media (max-width: 700px) {
            .context-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="container">
        <div class="header-content">
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" placeholder="Search for items near you..." class="search-input">
            </div>

            <nav class="main-nav">
                <a href="home.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                <a href="my_items.php" class="nav-link">
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

            <div class="user-section">
                <div class="notification-icon">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">0</span>
                </div>
                <div class="user-menu-container">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1)); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="main-content">
    <div class="container">
        <div class="reviews-page">

            <div class="reviews-summary">
                <h1>Reviews</h1>
                <div class="rating-display">
                    <span class="stars">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star-half-alt"></i>
                    </span>
                    <span><?php echo $averageRating; ?>/5</span>
                    <span>(<?php echo $totalReviews; ?> total reviews)</span>
                </div>
            </div>

            <div class="review-context-card">
                <h2>Transaction Review</h2>
                <p>Use this page to leave a review for either the lender who provided the item or the borrower who rented it.</p>

                <div class="context-grid">
                    <div class="context-box">
                        <div class="context-label">Item</div>
                        <div class="context-value"><?php echo htmlspecialchars($itemTitle); ?></div>
                    </div>

                    <div class="context-box">
                        <div class="context-label">Lender</div>
                        <div class="context-value"><?php echo htmlspecialchars($lenderName); ?></div>
                    </div>

                    <div class="context-box">
                        <div class="context-label">Borrower</div>
                        <div class="context-value"><?php echo htmlspecialchars($borrowerName); ?></div>
                    </div>

                    <div class="context-box">
                        <div class="context-label">Purpose</div>
                        <div class="context-value">Leave feedback about the lending or borrowing experience</div>
                    </div>
                </div>
            </div>

            <div class="review-form-card">
                <h2>Write a Review</h2>
                <form action="#" method="POST">
                    <div class="form-group">
                        <label for="review_type">Who are you reviewing?</label>
                        <select id="review_type" name="review_type">
                            <option value="">Select review type</option>
                            <option value="lender">Review the lender</option>
                            <option value="borrower">Review the borrower</option>
                        </select>
                    </div>

                    <div class="review-help-text">
                        Choose <strong>Review the lender</strong> if you borrowed an item and want to review the owner. Choose <strong>Review the borrower</strong> if you lent an item and want to review the renter.
                    </div>

                    <div class="form-group">
                        <label for="rating">Rating</label>
                        <select id="rating" name="rating">
                            <option value="">Select a rating</option>
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Very Good</option>
                            <option value="3">3 - Good</option>
                            <option value="2">2 - Fair</option>
                            <option value="1">1 - Poor</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="comment">Comment</label>
                        <textarea id="comment" name="comment" placeholder="Describe your experience with the lender or borrower..."></textarea>
                    </div>

                    <button type="submit" class="submit-review-btn">Submit Review</button>
                </form>
            </div>

            <?php foreach ($fakeReviews as $review): ?>
                <div class="review-card">
                    <div class="review-type-badge">
                        <?php echo htmlspecialchars($review['review_type']); ?>
                    </div>

                    <div class="review-meta">
                        <div class="reviewer-name"><?php echo htmlspecialchars($review['reviewer']); ?></div>
                        <div class="review-date"><?php echo htmlspecialchars($review['date']); ?></div>
                    </div>

                    <div class="stars" style="margin-bottom: 10px;">
                        <?php for ($i = 0; $i < $review['rating']; $i++): ?>
                            <i class="fas fa-star"></i>
                        <?php endfor; ?>
                        <?php for ($i = $review['rating']; $i < 5; $i++): ?>
                            <i class="far fa-star"></i>
                        <?php endfor; ?>
                    </div>

                    <div class="review-comment">
                        <?php echo htmlspecialchars($review['comment']); ?>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>
    </div>
</main>

</body>
</html>
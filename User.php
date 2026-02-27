<?php
/**
 * User Model - ERD 3.0 FINAL
 * All column names corrected
 */

class User {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Create new user
     */
    public function create($data) {
        $sql = "INSERT INTO TUsers (FirstName, LastName, Email, Password, PhoneNumber, 
                ProfilePictureURL, Bio, NeighborhoodID, AccountStatus) 
                VALUES (:firstname, :lastname, :email, :password, :phone, 
                :profile_pic, :bio, :neighborhood, :account_status)";
        
        $params = [
            ':firstname' => $data['firstname'],
            ':lastname' => $data['lastname'],
            ':email' => $data['email'],
            ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':phone' => $data['phone'] ?? null,
            ':profile_pic' => $data['profile_picture_url'] ?? null,
            ':bio' => $data['bio'] ?? null,
            ':neighborhood' => $data['neighborhood_id'] ?? null,
            ':account_status' => $data['account_status'] ?? true
        ];
        
        if ($this->db->execute($sql, $params)) {
            return $this->db->lastInsertId();
        }
        return false;
    }
    
    /**
     * Find user by ID
     */
    public function findById($id) {
        $sql = "SELECT u.*, n.NeighborhoodName, n.City, s.StateName
                FROM TUsers u
                LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
                LEFT JOIN TStates s ON n.StateID = s.StateID
                WHERE u.UserID = :id";
        
        return $this->db->queryOne($sql, [':id' => $id]);
    }
    
    /**
     * Find user by email
     */
    public function findByEmail($email) {
        $sql = "SELECT * FROM TUsers WHERE Email = :email";
        return $this->db->queryOne($sql, [':email' => $email]);
    }
    
    /**
     * Verify password
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Update user profile
     */
    public function update($id, $data) {
        $sql = "UPDATE TUsers SET 
                FirstName = :firstname,
                LastName = :lastname,
                PhoneNumber = :phone,
                ProfilePictureURL = :profile_pic,
                Bio = :bio,
                NeighborhoodID = :neighborhood
                WHERE UserID = :id";
        
        $params = [
            ':id' => $id,
            ':firstname' => $data['firstname'],
            ':lastname' => $data['lastname'],
            ':phone' => $data['phone'],
            ':profile_pic' => $data['profile_picture_url'] ?? null,
            ':bio' => $data['bio'] ?? null,
            ':neighborhood' => $data['neighborhood_id'] ?? null
        ];
        
        return $this->db->execute($sql, $params);
    }
    
    /**
     * Get user's listings
     */
    public function getListings($userId) {
        $sql = "SELECT l.*, c.CategoryName, cond.ConditionName, ls.StatusName as ListingStatus,
                (SELECT PhotoURL FROM TLisitingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
                FROM TListings l
                LEFT JOIN TCategories c ON l.CategoryID = c.CategoryID
                LEFT JOIN TConditions cond ON l.ConditionID = cond.ConditionID
                LEFT JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
                WHERE l.UserLenderID = :user_id
                ORDER BY l.AddedDate DESC";
        
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * Get user's rentals as borrower
     */
    public function getRentalsAsBorrower($userId) {
        $sql = "SELECT r.*, l.Title as ListingTitle, l.PricePerDay, rs.StatusName as RentalStatus,
                rr.StartTime, rr.EndDate,
                u.FirstName as LenderFirstName, u.LastName as LenderLastName
                FROM TRentals r
                INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
                INNER JOIN TListings l ON r.ListingID = l.ListingID
                INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
                INNER JOIN TUsers u ON l.UserLenderID = u.UserID
                WHERE r.UserBorrowerID = :user_id
                ORDER BY r.AddedDate DESC";
        
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * Get user's rentals as lender
     */
    public function getRentalsAsLender($userId) {
        $sql = "SELECT r.*, l.Title as ListingTitle, l.PricePerDay, rs.StatusName as RentalStatus,
                rr.StartTime, rr.EndDate,
                u.FirstName as BorrowerFirstName, u.LastName as BorrowerLastName
                FROM TRentals r
                INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
                INNER JOIN TListings l ON r.ListingID = l.ListingID
                INNER JOIN TRentalStatuses rs ON r.RentalStatusID = rs.RentalStatusID
                INNER JOIN TUsers u ON r.UserBorrowerID = u.UserID
                WHERE l.UserLenderID = :user_id
                ORDER BY r.AddedDate DESC";
        
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * Get user's reviews
     */
    public function getReviews($userId) {
        $sql = "SELECT rev.*, u.FirstName, u.LastName, rt.ReviewType,
                l.Title as ListingTitle
                FROM TReviews rev
                INNER JOIN TUsers u ON rev.UserReviewerID = u.UserID
                INNER JOIN TReviewTypes rt ON rev.ReviewTypeID = rt.ReviewTypeID
                INNER JOIN TRentals r ON rev.RentalID = r.RentalID
                INNER JOIN TListings l ON r.ListingID = l.ListingID
                WHERE rev.UserRevieweeID = :user_id
                ORDER BY rev.AddedDate DESC";
        
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * Calculate user's average rating
     */
    public function getAverageRating($userId) {
        $sql = "SELECT AVG(ReviewRating) as avg_rating, COUNT(*) as review_count
                FROM TReviews
                WHERE UserRevieweeID = :user_id";
        
        return $this->db->queryOne($sql, [':user_id' => $userId]);
    }
    
    /**
     * Check if email exists
     */
    public function emailExists($email, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) as count FROM TUsers WHERE Email = :email";
        $params = [':email' => $email];
        
        if ($excludeUserId) {
            $sql .= " AND UserID != :user_id";
            $params[':user_id'] = $excludeUserId;
        }
        
        $result = $this->db->queryOne($sql, $params);
        return $result['count'] > 0;
    }
    
    /**
     * Update account status
     */
    public function updateAccountStatus($userId, $status) {
        $sql = "UPDATE TUsers SET AccountStatus = :status WHERE UserID = :id";
        return $this->db->execute($sql, [':id' => $userId, ':status' => $status]);
    }
}

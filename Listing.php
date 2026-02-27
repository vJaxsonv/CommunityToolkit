<?php
/**
 * Listing Model - ERD 3.0 FINAL
 * All column names corrected
 */

class Listing {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get all listings with filters
     */
    public function getAll($filters = []) {
        $sql = "SELECT l.*, 
                c.CategoryName, 
                cond.ConditionName,
                ls.StatusName as ListingStatus,
                rt.RateType,
                u.FirstName as OwnerFirstName, u.LastName as OwnerLastName,
                u.UserID as OwnerUserID,
                n.NeighborhoodName, n.City,
                (SELECT PhotoURL FROM TLisitingPhotos WHERE ListingID = l.ListingID ORDER BY SortOrder LIMIT 1) as PrimaryImage
                FROM TListings l
                INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
                INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
                INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
                INNER JOIN TRateTypes rt ON l.RateTypeID = rt.RateTypeID
                INNER JOIN TUsers u ON l.UserLenderID = u.UserID
                LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
                WHERE ls.StatusName = 'Active'";
        
        $params = [];
        
        // Filter by category
        if (!empty($filters['category_id'])) {
            $sql .= " AND l.CategoryID = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        // Filter by max price
        if (!empty($filters['max_price'])) {
            $sql .= " AND (l.PricePerDay <= :max_price OR l.PricePerHour <= :max_price)";
            $params[':max_price'] = $filters['max_price'];
        }
        
        // Filter by search query
        if (!empty($filters['search'])) {
            $sql .= " AND (l.Title LIKE :search OR l.Description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        // Sort options
        $sortOptions = [
            'price_low' => 'l.PricePerDay ASC',
            'price_high' => 'l.PricePerDay DESC',
            'newest' => 'l.AddedDate DESC',
            'title' => 'l.Title ASC'
        ];
        
        $orderBy = $sortOptions[$filters['sort'] ?? 'newest'] ?? 'l.AddedDate DESC';
        $sql .= " ORDER BY " . $orderBy;
        
        // Limit
        $limit = $filters['limit'] ?? 20;
        $offset = $filters['offset'] ?? 0;
        $sql .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = (int)$limit;
        $params[':offset'] = (int)$offset;
        
        return $this->db->query($sql, $params);
    }
    
    /**
     * Get listing by ID
     */
    public function findById($id) {
        $sql = "SELECT l.*, 
                c.CategoryName, parent.CategoryName as ParentCategory,
                cond.ConditionName,
                ls.StatusName as ListingStatus,
                rt.RateType,
                n.NeighborhoodName, n.City, n.ZipCode,
                u.UserID as OwnerUserID, u.FirstName as OwnerFirstName, 
                u.LastName as OwnerLastName, u.Email as OwnerEmail,
                u.PhoneNumber as OwnerPhone, u.ProfilePictureURL
                FROM TListings l
                INNER JOIN TCategories c ON l.CategoryID = c.CategoryID
                LEFT JOIN TCategories parent ON c.ParentCategoryID = parent.CategoryID
                INNER JOIN TConditions cond ON l.ConditionID = cond.ConditionID
                INNER JOIN TListingStatuses ls ON l.ListingStatusID = ls.ListingStatusID
                INNER JOIN TRateTypes rt ON l.RateTypeID = rt.RateTypeID
                LEFT JOIN TUsers u ON l.UserLenderID = u.UserID
                LEFT JOIN TNeighborhoods n ON u.NeighborhoodID = n.NeighborhoodID
                WHERE l.ListingID = :id";
        
        return $this->db->queryOne($sql, [':id' => $id]);
    }
    
    /**
     * Create new listing
     */
    public function create($data) {
        $sql = "INSERT INTO TListings (UserLenderID, CategoryID, Title, ListingStatusID,
                ConditionID, Description, RateTypeID, PricePerDay, PricePerHour)
                VALUES (:owner, :category, :title, :status, :condition, :description, 
                :rate_type, :price_day, :price_hour)";
        
        $params = [
            ':owner' => $data['user_lender_id'],
            ':category' => $data['category_id'],
            ':title' => $data['title'],
            ':status' => $data['listing_status_id'] ?? 1, // Default: Active
            ':condition' => $data['condition_id'],
            ':description' => $data['description'],
            ':rate_type' => $data['rate_type_id'] ?? 1, // Default: Per Day
            ':price_day' => $data['price_per_day'] ?? null,
            ':price_hour' => $data['price_per_hour'] ?? null
        ];
        
        if ($this->db->execute($sql, $params)) {
            return $this->db->lastInsertId();
        }
        return false;
    }
    
    /**
     * Update listing
     */
    public function update($id, $data) {
        $sql = "UPDATE TListings SET
                CategoryID = :category,
                Title = :title,
                ListingStatusID = :status,
                ConditionID = :condition,
                Description = :description,
                RateTypeID = :rate_type,
                PricePerDay = :price_day,
                PricePerHour = :price_hour
                WHERE ListingID = :id";
        
        $params = [
            ':id' => $id,
            ':category' => $data['category_id'],
            ':title' => $data['title'],
            ':status' => $data['listing_status_id'],
            ':condition' => $data['condition_id'],
            ':description' => $data['description'],
            ':rate_type' => $data['rate_type_id'],
            ':price_day' => $data['price_per_day'] ?? null,
            ':price_hour' => $data['price_per_hour'] ?? null
        ];
        
        return $this->db->execute($sql, $params);
    }
    
    /**
     * Delete listing (soft delete - set status to Deleted)
     */
    public function delete($id) {
        $sql = "UPDATE TListings SET ListingStatusID = 4 WHERE ListingID = :id";
        return $this->db->execute($sql, [':id' => $id]);
    }
    
    /**
     * Get listing photos
     */
    public function getPhotos($listingId) {
        $sql = "SELECT * FROM TLisitingPhotos 
                WHERE ListingID = :listing_id 
                ORDER BY SortOrder ASC, AddedDate ASC";
        
        return $this->db->query($sql, [':listing_id' => $listingId]);
    }
    
    /**
     * Add listing photo
     */
    public function addPhoto($listingId, $photoUrl, $sortOrder = 0) {
        $sql = "INSERT INTO TLisitingPhotos (ListingID, PhotoURL, SortOrder) 
                VALUES (:listing_id, :photo_url, :sort_order)";
        
        $params = [
            ':listing_id' => $listingId,
            ':photo_url' => $photoUrl,
            ':sort_order' => $sortOrder
        ];
        
        return $this->db->execute($sql, $params);
    }
    
    /**
     * Check listing availability for date range
     */
    public function checkAvailability($listingId, $startDate, $endDate) {
        // Check if any dates in range are blocked
        $sql = "SELECT COUNT(*) as count 
                FROM TListingAvailability 
                WHERE ListingID = :listing_id 
                AND UnavailableDate BETWEEN :start_date AND :end_date";
        
        $params = [
            ':listing_id' => $listingId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];
        
        $result = $this->db->queryOne($sql, $params);
        
        // Also check for conflicting rentals
        $rentalSql = "SELECT COUNT(*) as count 
                FROM TRentals r
                INNER JOIN TRentalRequests rr ON r.RentalRequestID = rr.RentalRequestID
                WHERE r.ListingID = :listing_id 
                AND r.RentalStatusID IN (1, 2)
                AND (
                    (:start_date BETWEEN rr.StartTime AND rr.EndDate) OR
                    (:end_date BETWEEN rr.StartTime AND rr.EndDate) OR
                    (rr.StartTime BETWEEN :start_date AND :end_date)
                )";
        
        $rentalResult = $this->db->queryOne($rentalSql, $params);
        
        return ($result['count'] == 0 && $rentalResult['count'] == 0);
    }
    
    /**
     * Get all categories
     */
    public function getCategories() {
        $sql = "SELECT c.*, parent.CategoryName as ParentName
                FROM TCategories c
                LEFT JOIN TCategories parent ON c.ParentCategoryID = parent.CategoryID
                ORDER BY parent.CategoryName, c.CategoryName";
        
        return $this->db->query($sql);
    }
    
    /**
     * Get all conditions
     */
    public function getConditions() {
        $sql = "SELECT * FROM TConditions ORDER BY ConditionID";
        return $this->db->query($sql);
    }
    
    /**
     * Get all rate types
     */
    public function getRateTypes() {
        $sql = "SELECT * FROM TRateTypes ORDER BY RateTypeID";
        return $this->db->query($sql);
    }
    
    /**
     * Get all listing statuses
     */
    public function getListingStatuses() {
        $sql = "SELECT * FROM TListingStatuses ORDER BY ListingStatusID";
        return $this->db->query($sql);
    }
}

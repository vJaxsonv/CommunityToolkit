-- ============================================================================
-- Community Toolkit - MySQL Tables (For Existing Database)
-- Proper DROP order with Foreign Key handling
-- 17 Tables | Full Normalization | Production-Ready
-- ============================================================================

-- Disable foreign key checks temporarily to allow dropping tables
SET FOREIGN_KEY_CHECKS = 0;

-- Drop all tables (can now be in any order)
DROP TABLE IF EXISTS Reviews;
DROP TABLE IF EXISTS RentalImages;
DROP TABLE IF EXISTS DepositTransactions;
DROP TABLE IF EXISTS Rentals;
DROP TABLE IF EXISTS ItemImages;
DROP TABLE IF EXISTS Items;
DROP TABLE IF EXISTS PaymentMethods;
DROP TABLE IF EXISTS UserVerifications;
DROP TABLE IF EXISTS Users_CT;
DROP TABLE IF EXISTS ItemCategories;
DROP TABLE IF EXISTS RentalStatuses;
DROP TABLE IF EXISTS ItemConditions;
DROP TABLE IF EXISTS CardTypes;
DROP TABLE IF EXISTS Neighborhoods;
DROP TABLE IF EXISTS Genders_CT;
DROP TABLE IF EXISTS States;
DROP TABLE IF EXISTS Booleans;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SECTION 1: LOOKUP TABLES (No dependencies)
-- ============================================================================

-- Table 1: Booleans - Universal True/False lookup
CREATE TABLE Booleans (
    BooleanID INT PRIMARY KEY,
    BooleanValue VARCHAR(5) NOT NULL UNIQUE,
    INDEX idx_boolean_value (BooleanValue)
) ENGINE=InnoDB;

INSERT INTO Booleans (BooleanID, BooleanValue) VALUES
(0, 'False'),
(1, 'True');

-- Table 2: States - US States for addresses
CREATE TABLE States (
    StateID INT AUTO_INCREMENT PRIMARY KEY,
    StateCode CHAR(2) NOT NULL UNIQUE,
    StateName VARCHAR(50) NOT NULL UNIQUE,
    INDEX idx_state_code (StateCode)
) ENGINE=InnoDB;

INSERT INTO States (StateCode, StateName) VALUES
('AL', 'Alabama'), ('AK', 'Alaska'), ('AZ', 'Arizona'), ('AR', 'Arkansas'),
('CA', 'California'), ('CO', 'Colorado'), ('CT', 'Connecticut'), ('DE', 'Delaware'),
('FL', 'Florida'), ('GA', 'Georgia'), ('HI', 'Hawaii'), ('ID', 'Idaho'),
('IL', 'Illinois'), ('IN', 'Indiana'), ('IA', 'Iowa'), ('KS', 'Kansas'),
('KY', 'Kentucky'), ('LA', 'Louisiana'), ('ME', 'Maine'), ('MD', 'Maryland'),
('MA', 'Massachusetts'), ('MI', 'Michigan'), ('MN', 'Minnesota'), ('MS', 'Mississippi'),
('MO', 'Missouri'), ('MT', 'Montana'), ('NE', 'Nebraska'), ('NV', 'Nevada'),
('NH', 'New Hampshire'), ('NJ', 'New Jersey'), ('NM', 'New Mexico'), ('NY', 'New York'),
('NC', 'North Carolina'), ('ND', 'North Dakota'), ('OH', 'Ohio'), ('OK', 'Oklahoma'),
('OR', 'Oregon'), ('PA', 'Pennsylvania'), ('RI', 'Rhode Island'), ('SC', 'South Carolina'),
('SD', 'South Dakota'), ('TN', 'Tennessee'), ('TX', 'Texas'), ('UT', 'Utah'),
('VT', 'Vermont'), ('VA', 'Virginia'), ('WA', 'Washington'), ('WV', 'West Virginia'),
('WI', 'Wisconsin'), ('WY', 'Wyoming');

-- Table 3: Genders - Gender options (renamed to avoid conflict)
CREATE TABLE Genders_CT (
    GenderID INT AUTO_INCREMENT PRIMARY KEY,
    GenderName VARCHAR(50) NOT NULL UNIQUE,
    INDEX idx_gender_name (GenderName)
) ENGINE=InnoDB;

INSERT INTO Genders_CT (GenderName) VALUES
('Man'),
('Woman'),
('Non-binary'),
('Prefer not to say');

-- Table 4: Neighborhoods - Cincinnati area neighborhoods
CREATE TABLE Neighborhoods (
    NeighborhoodID INT AUTO_INCREMENT PRIMARY KEY,
    NeighborhoodName VARCHAR(100) NOT NULL,
    City VARCHAR(50) NOT NULL,
    ZipCodes VARCHAR(255),
    INDEX idx_neighborhood_name (NeighborhoodName),
    INDEX idx_city (City)
) ENGINE=InnoDB;

INSERT INTO Neighborhoods (NeighborhoodName, City, ZipCodes) VALUES
-- Cincinnati Proper - East Side
('Hyde Park', 'Cincinnati', '45208, 45209'),
('Oakley', 'Cincinnati', '45209'),
('Mount Lookout', 'Cincinnati', '45208'),
('East Walnut Hills', 'Cincinnati', '45206'),
('Columbia-Tusculum', 'Cincinnati', '45226'),
('Mount Washington', 'Cincinnati', '45230'),
('Anderson Township', 'Cincinnati', '45230, 45244, 45255'),
('Mariemont', 'Cincinnati', '45227'),
('Madisonville', 'Cincinnati', '45227'),
('Newtown', 'Cincinnati', '45244'),

-- Cincinnati Proper - West Side
('Westwood', 'Cincinnati', '45205, 45238'),
('Western Hills', 'Cincinnati', '45238'),
('Price Hill', 'Cincinnati', '45204, 45205, 45207'),
('Delhi Township', 'Cincinnati', '45238'),
('Green Township', 'Cincinnati', '45238, 45239'),
('Cheviot', 'Cincinnati', '45211'),
('Bridgetown', 'Cincinnati', '45211'),

-- Cincinnati Proper - Downtown/Central
('Downtown', 'Cincinnati', '45202'),
('Over-the-Rhine', 'Cincinnati', '45202'),
('Mount Adams', 'Cincinnati', '45202'),
('Pendleton', 'Cincinnati', '45202'),
('The Banks', 'Cincinnati', '45202'),
('West End', 'Cincinnati', '45203'),
('Clifton', 'Cincinnati', '45220, 45221'),
('Corryville', 'Cincinnati', '45219, 45220'),
('Walnut Hills', 'Cincinnati', '45206'),
('Avondale', 'Cincinnati', '45229'),

-- Cincinnati Proper - North
('Northside', 'Cincinnati', '45223'),
('Camp Washington', 'Cincinnati', '45214'),
('Clifton Heights', 'Cincinnati', '45220'),
('University Heights', 'Cincinnati', '45219'),
('North Avondale', 'Cincinnati', '45229'),

-- Hamilton County Suburbs
('Blue Ash', 'Blue Ash', '45242'),
('Deer Park', 'Deer Park', '45236'),
('Evendale', 'Evendale', '45241'),
('Forest Park', 'Forest Park', '45240'),
('Madeira', 'Madeira', '45243'),
('Montgomery', 'Montgomery', '45242'),
('Norwood', 'Norwood', '45212'),
('Reading', 'Reading', '45215'),
('Sharonville', 'Sharonville', '45241'),
('Springdale', 'Springdale', '45246'),
('Springfield Township', 'Cincinnati', '45215, 45231, 45246'),
('Sycamore Township', 'Cincinnati', '45236, 45242'),
('Symmes Township', 'Cincinnati', '45249'),
('Wyoming', 'Wyoming', '45215'),
('Loveland', 'Loveland', '45140'),
('Milford', 'Milford', '45150'),
('Amberley Village', 'Amberley Village', '45236'),
('Indian Hill', 'Indian Hill', '45243'),
('Terrace Park', 'Terrace Park', '45174'),

-- Butler County (North of Cincinnati)
('Hamilton', 'Hamilton', '45011, 45013, 45015'),
('Fairfield', 'Fairfield', '45014'),
('West Chester Township', 'West Chester', '45069, 45011'),
('Liberty Township', 'Liberty Township', '45011, 45044'),
('Monroe', 'Monroe', '45050'),
('Trenton', 'Trenton', '45067'),
('Middletown', 'Middletown', '45042, 45044'),
('Oxford', 'Oxford', '45056'),

-- Warren County (Northeast)
('Mason', 'Mason', '45040'),
('Lebanon', 'Lebanon', '45036'),
('Springboro', 'Springboro', '45066'),
('Franklin', 'Franklin', '45005'),
('Carlisle', 'Carlisle', '45005'),
('Waynesville', 'Waynesville', '45068'),
('Deerfield Township', 'Cincinnati', '45140, 45242'),

-- Clermont County (East)
('Batavia', 'Batavia', '45103'),
('Milford (Clermont)', 'Milford', '45150'),
('Amelia', 'Amelia', '45102'),
('Bethel', 'Bethel', '45106'),
('New Richmond', 'New Richmond', '45157'),
('Union Township (Clermont)', 'Cincinnati', '45103, 45245'),
('Pierce Township', 'Cincinnati', '45245, 45255'),
('Williamsburg', 'Williamsburg', '45176'),

-- Northern Kentucky - Kenton County
('Covington', 'Covington', '41011, 41012, 41014, 41015, 41016, 41017, 41018, 41019'),
('Fort Wright', 'Fort Wright', '41011, 41017'),
('Park Hills', 'Park Hills', '41011'),
('Villa Hills', 'Villa Hills', '41017'),
('Edgewood', 'Edgewood', '41017, 41018'),
('Erlanger', 'Erlanger', '41018'),
('Elsmere', 'Elsmere', '41018'),
('Independence', 'Independence', '41051'),
('Taylor Mill', 'Taylor Mill', '41015'),
('Lakeside Park', 'Lakeside Park', '41017'),
('Crescent Springs', 'Crescent Springs', '41017'),
('Crestview Hills', 'Crestview Hills', '41017'),

-- Northern Kentucky - Campbell County
('Newport', 'Newport', '41071, 41072, 41073, 41074, 41075, 41076'),
('Bellevue', 'Bellevue', '41073'),
('Dayton', 'Dayton', '41074'),
('Fort Thomas', 'Fort Thomas', '41075'),
('Highland Heights', 'Highland Heights', '41076'),
('Wilder', 'Wilder', '41071'),
('Cold Spring', 'Cold Spring', '41076'),
('Alexandria', 'Alexandria', '41001'),
('Melbourne', 'Melbourne', '41059'),
('Silver Grove', 'Silver Grove', '41085'),
('Southgate', 'Southgate', '41071'),
('Woodlawn', 'Woodlawn', '41071'),
('California', 'California', '41007'),

-- Northern Kentucky - Boone County
('Florence', 'Florence', '41042, 41022'),
('Union', 'Union', '41091'),
('Walton', 'Walton', '41094'),
('Burlington', 'Burlington', '41005'),
('Hebron', 'Hebron', '41048'),
('Petersburg', 'Petersburg', '41080'),
('Oakbrook', 'Oakbrook', '41031'),
('Richwood', 'Richwood', '41094'),
('Verona', 'Verona', '41092');

-- Table 5: CardTypes - Credit card brands
CREATE TABLE CardTypes (
    CardTypeID INT AUTO_INCREMENT PRIMARY KEY,
    CardTypeName VARCHAR(20) NOT NULL UNIQUE,
    INDEX idx_card_type_name (CardTypeName)
) ENGINE=InnoDB;

INSERT INTO CardTypes (CardTypeName) VALUES
('Visa'),
('MasterCard'),
('American Express'),
('Discover');

-- Table 6: ItemConditions - Item condition ratings
CREATE TABLE ItemConditions (
    ConditionID INT AUTO_INCREMENT PRIMARY KEY,
    ConditionName VARCHAR(20) NOT NULL UNIQUE,
    ConditionDescription VARCHAR(100),
    INDEX idx_condition_name (ConditionName)
) ENGINE=InnoDB;

INSERT INTO ItemConditions (ConditionName, ConditionDescription) VALUES
('New', 'Brand new, never used'),
('Like New', 'Lightly used, excellent condition'),
('Good', 'Normal wear, fully functional'),
('Fair', 'Shows wear, works as intended'),
('Poor', 'Heavy wear, may need repair');

-- Table 7: RentalStatuses - Rental workflow statuses
CREATE TABLE RentalStatuses (
    StatusID INT PRIMARY KEY,
    StatusName VARCHAR(20) NOT NULL UNIQUE,
    StatusDescription VARCHAR(100),
    INDEX idx_status_name (StatusName)
) ENGINE=InnoDB;

INSERT INTO RentalStatuses (StatusID, StatusName, StatusDescription) VALUES
(1, 'Pending', 'Request sent, awaiting lender approval'),
(2, 'Approved', 'Lender approved, not yet picked up'),
(3, 'Active', 'Item currently with renter'),
(4, 'Completed', 'Item returned successfully'),
(5, 'Cancelled', 'Rental was cancelled'),
(6, 'Overdue', 'Item not returned on time'),
(7, 'Disputed', 'Issue reported - under review');

-- Table 8: ItemCategories - Hierarchical item categories
CREATE TABLE ItemCategories (
    CategoryID INT AUTO_INCREMENT PRIMARY KEY,
    CategoryName VARCHAR(50) NOT NULL UNIQUE,
    ParentCategoryID INT,
    FOREIGN KEY (ParentCategoryID) REFERENCES ItemCategories(CategoryID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    INDEX idx_category_name (CategoryName),
    INDEX idx_parent_category (ParentCategoryID)
) ENGINE=InnoDB;

-- Insert parent categories first
INSERT INTO ItemCategories (CategoryName, ParentCategoryID) VALUES
('Power Tools', NULL),
('Hand Tools', NULL),
('Garden Equipment', NULL),
('Kitchen Appliances', NULL),
('Party Supplies', NULL),
('Sports Equipment', NULL),
('Books', NULL),
('Furniture', NULL),
('Cleaning Equipment', NULL),
('Automotive Tools', NULL),
('Ladders & Scaffolding', NULL),
('Electronics', NULL);

-- Insert subcategories
INSERT INTO ItemCategories (CategoryName, ParentCategoryID) VALUES
('Drills', 1),
('Sanders', 1),
('Saws', 1),
('Nail Guns', 1),
('Lawn Mowers', 3),
('String Trimmers', 3),
('Leaf Blowers', 3),
('Pressure Washers', 9),
('Carpet Cleaners', 9),
('Wrenches', 10),
('Socket Sets', 10);

-- ============================================================================
-- SECTION 2: CORE TABLES
-- ============================================================================

-- Table 9: Users - All user accounts (renamed to avoid conflict)
CREATE TABLE Users_CT (
    UserID INT AUTO_INCREMENT PRIMARY KEY,
    Username VARCHAR(50) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    Email VARCHAR(100) NOT NULL UNIQUE,
    PhoneNumber VARCHAR(20) NOT NULL,
    FirstName VARCHAR(50) NOT NULL,
    LastName VARCHAR(50) NOT NULL,
    StreetAddress VARCHAR(100) NOT NULL,
    City VARCHAR(50) NOT NULL,
    StateID INT NOT NULL,
    ZipCode VARCHAR(10) NOT NULL,
    NeighborhoodID INT,
    GenderID INT,
    Latitude DECIMAL(9,6),
    Longitude DECIMAL(9,6),
    AverageRating DECIMAL(3,2) DEFAULT 0.00,
    DateCreated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (StateID) REFERENCES States(StateID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (NeighborhoodID) REFERENCES Neighborhoods(NeighborhoodID)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    FOREIGN KEY (GenderID) REFERENCES Genders_CT(GenderID)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
        
    CONSTRAINT chk_rating CHECK (AverageRating >= 0.00 AND AverageRating <= 5.00),
    
    INDEX idx_username (Username),
    INDEX idx_email (Email),
    INDEX idx_neighborhood (NeighborhoodID),
    INDEX idx_state (StateID),
    INDEX idx_rating (AverageRating),
    INDEX idx_location (Latitude, Longitude)
) ENGINE=InnoDB;

-- Table 10: UserVerifications
CREATE TABLE UserVerifications (
    VerificationID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL UNIQUE,
    EmailVerifiedID INT NOT NULL DEFAULT 0,
    PhoneVerifiedID INT NOT NULL DEFAULT 0,
    IDVerifiedID INT NOT NULL DEFAULT 0,
    EmailVerifiedDate DATETIME,
    PhoneVerifiedDate DATETIME,
    IDVerifiedDate DATETIME,
    
    FOREIGN KEY (UserID) REFERENCES Users_CT(UserID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (EmailVerifiedID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (PhoneVerifiedID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (IDVerifiedID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
        
    INDEX idx_user (UserID),
    INDEX idx_email_verified (EmailVerifiedID),
    INDEX idx_phone_verified (PhoneVerifiedID),
    INDEX idx_id_verified (IDVerifiedID)
) ENGINE=InnoDB;

-- Table 11: PaymentMethods
CREATE TABLE PaymentMethods (
    PaymentMethodID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    CardTypeID INT NOT NULL,
    Last4Digits CHAR(4) NOT NULL,
    ExpirationMonth INT NOT NULL,
    ExpirationYear INT NOT NULL,
    IsDefaultID INT NOT NULL DEFAULT 0,
    DateAdded DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (UserID) REFERENCES Users_CT(UserID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (CardTypeID) REFERENCES CardTypes(CardTypeID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (IsDefaultID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
        
    CONSTRAINT chk_exp_month CHECK (ExpirationMonth >= 1 AND ExpirationMonth <= 12),
    CONSTRAINT chk_exp_year CHECK (ExpirationYear >= 2024),
    
    INDEX idx_user (UserID),
    INDEX idx_card_type (CardTypeID),
    INDEX idx_default (IsDefaultID)
) ENGINE=InnoDB;

-- Table 12: Items
CREATE TABLE Items (
    ItemID INT AUTO_INCREMENT PRIMARY KEY,
    OwnerUserID INT NOT NULL,
    CategoryID INT NOT NULL,
    ConditionID INT NOT NULL,
    NeighborhoodID INT,
    ItemName VARCHAR(100) NOT NULL,
    Description TEXT NOT NULL,
    DailyRentalPrice DECIMAL(10,2) NOT NULL,
    Latitude DECIMAL(9,6),
    Longitude DECIMAL(9,6),
    IsAvailableID INT NOT NULL DEFAULT 1,
    DateListed DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (OwnerUserID) REFERENCES Users_CT(UserID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (CategoryID) REFERENCES ItemCategories(CategoryID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (ConditionID) REFERENCES ItemConditions(ConditionID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (NeighborhoodID) REFERENCES Neighborhoods(NeighborhoodID)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    FOREIGN KEY (IsAvailableID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
        
    CONSTRAINT chk_price CHECK (DailyRentalPrice >= 0),
    
    INDEX idx_owner (OwnerUserID),
    INDEX idx_category (CategoryID),
    INDEX idx_condition (ConditionID),
    INDEX idx_neighborhood (NeighborhoodID),
    INDEX idx_available (IsAvailableID),
    INDEX idx_price (DailyRentalPrice),
    INDEX idx_location (Latitude, Longitude),
    INDEX idx_date_listed (DateListed)
) ENGINE=InnoDB;

-- Table 13: ItemImages
CREATE TABLE ItemImages (
    ImageID INT AUTO_INCREMENT PRIMARY KEY,
    ItemID INT NOT NULL,
    ImageURL VARCHAR(255) NOT NULL,
    IsPrimaryID INT NOT NULL DEFAULT 0,
    UploadedDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (ItemID) REFERENCES Items(ItemID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (IsPrimaryID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
        
    INDEX idx_item (ItemID),
    INDEX idx_primary (IsPrimaryID)
) ENGINE=InnoDB;

-- Table 14: Rentals
CREATE TABLE Rentals (
    RentalID INT AUTO_INCREMENT PRIMARY KEY,
    ItemID INT NOT NULL,
    RenterUserID INT NOT NULL,
    LenderUserID INT NOT NULL,
    PaymentMethodID INT NOT NULL,
    StatusID INT NOT NULL DEFAULT 1,
    StartDate DATE NOT NULL,
    EndDate DATE NOT NULL,
    TotalCost DECIMAL(10,2) NOT NULL,
    LateFees DECIMAL(10,2) DEFAULT 0.00,
    DamageFees DECIMAL(10,2) DEFAULT 0.00,
    Notes TEXT,
    RequestedDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (ItemID) REFERENCES Items(ItemID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (RenterUserID) REFERENCES Users_CT(UserID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (LenderUserID) REFERENCES Users_CT(UserID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (PaymentMethodID) REFERENCES PaymentMethods(PaymentMethodID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (StatusID) REFERENCES RentalStatuses(StatusID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
        
    CONSTRAINT chk_dates CHECK (EndDate >= StartDate),
    CONSTRAINT chk_users CHECK (RenterUserID != LenderUserID),
    CONSTRAINT chk_cost CHECK (TotalCost >= 0),
    CONSTRAINT chk_late_fees CHECK (LateFees >= 0),
    CONSTRAINT chk_damage_fees CHECK (DamageFees >= 0),
    
    INDEX idx_item (ItemID),
    INDEX idx_renter (RenterUserID),
    INDEX idx_lender (LenderUserID),
    INDEX idx_payment (PaymentMethodID),
    INDEX idx_status (StatusID),
    INDEX idx_dates (StartDate, EndDate),
    INDEX idx_requested (RequestedDate)
) ENGINE=InnoDB;

-- Table 15: DepositTransactions
CREATE TABLE DepositTransactions (
    DepositTransactionID INT AUTO_INCREMENT PRIMARY KEY,
    RentalID INT NOT NULL,
    DepositAmount DECIMAL(10,2) NOT NULL,
    DepositHeldDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DepositReleasedDate DATETIME,
    IsRefundedID INT NOT NULL DEFAULT 0,
    RefundAmount DECIMAL(10,2),
    
    FOREIGN KEY (RentalID) REFERENCES Rentals(RentalID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (IsRefundedID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
        
    CONSTRAINT chk_deposit_amount CHECK (DepositAmount >= 0),
    CONSTRAINT chk_refund_amount CHECK (RefundAmount IS NULL OR RefundAmount >= 0),
    
    INDEX idx_rental (RentalID),
    INDEX idx_refunded (IsRefundedID),
    INDEX idx_held_date (DepositHeldDate)
) ENGINE=InnoDB;

-- Table 16: RentalImages
CREATE TABLE RentalImages (
    RentalImageID INT AUTO_INCREMENT PRIMARY KEY,
    RentalID INT NOT NULL,
    ImageURL VARCHAR(255) NOT NULL,
    IsPickupPhotoID INT NOT NULL,
    CapturedDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Notes TEXT,
    
    FOREIGN KEY (RentalID) REFERENCES Rentals(RentalID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (IsPickupPhotoID) REFERENCES Booleans(BooleanID)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
        
    INDEX idx_rental (RentalID),
    INDEX idx_pickup_photo (IsPickupPhotoID),
    INDEX idx_captured_date (CapturedDate)
) ENGINE=InnoDB;

-- Table 17: Reviews
CREATE TABLE Reviews (
    ReviewID INT AUTO_INCREMENT PRIMARY KEY,
    RentalID INT NOT NULL,
    ReviewerUserID INT NOT NULL,
    ReviewedUserID INT NOT NULL,
    Rating INT NOT NULL,
    Comment TEXT,
    ReviewDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (RentalID) REFERENCES Rentals(RentalID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (ReviewerUserID) REFERENCES Users_CT(UserID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (ReviewedUserID) REFERENCES Users_CT(UserID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
        
    CONSTRAINT chk_rating CHECK (Rating >= 1 AND Rating <= 5),
    CONSTRAINT chk_review_users CHECK (ReviewerUserID != ReviewedUserID),
    UNIQUE KEY unique_review (RentalID, ReviewerUserID),
    
    INDEX idx_rental (RentalID),
    INDEX idx_reviewer (ReviewerUserID),
    INDEX idx_reviewed (ReviewedUserID),
    INDEX idx_rating (Rating),
    INDEX idx_date (ReviewDate)
) ENGINE=InnoDB;

-- ============================================================================
-- SAMPLE DATA
-- ============================================================================

-- Sample Users
INSERT INTO Users_CT (Username, Password, Email, PhoneNumber, FirstName, LastName, 
                   StreetAddress, City, StateID, ZipCode, NeighborhoodID, Latitude, Longitude) VALUES
('john_smith', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
 'john@example.com', '513-555-0101', 'John', 'Smith', 
 '123 Main St', 'Cincinnati', 35, '45202', 4, 39.1031, -84.5120),
('mary_johnson', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'mary@example.com', '513-555-0102', 'Mary', 'Johnson',
 '456 Oak Ave', 'Cincinnati', 35, '45220', 6, 39.1131, -84.5020),
('annie_builder', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'annie@example.com', '513-555-0103', 'Annie', 'Builder',
 '789 Elm St', 'Cincinnati', 35, '45215', 11, 39.1231, -84.4920);

-- Sample Payment Methods
INSERT INTO PaymentMethods (UserID, CardTypeID, Last4Digits, ExpirationMonth, ExpirationYear, IsDefaultID) VALUES
(1, 1, '1234', 12, 2028, 1),
(2, 2, '5678', 6, 2027, 1),
(3, 1, '9012', 3, 2029, 1);

-- Sample Items
INSERT INTO Items (OwnerUserID, CategoryID, ConditionID, NeighborhoodID, ItemName, Description, 
                  DailyRentalPrice, Latitude, Longitude, IsAvailableID) VALUES
(2, 13, 3, 6, 'DeWalt Cordless Drill', 
 '18V cordless drill with battery and charger. Perfect for home projects.', 
 8.00, 39.1131, -84.5020, 1),
(3, 17, 2, 11, 'Honda Lawn Mower', 
 'Self-propelled 21" cut lawn mower. Recently serviced and ready to go.', 
 25.00, 39.1231, -84.4920, 1),
(2, 19, 3, 6, 'Bissell Carpet Cleaner', 
 'Professional carpet cleaning machine. Includes cleaning solution.', 
 30.00, 39.1131, -84.5020, 1);

-- Sample Item Images
INSERT INTO ItemImages (ItemID, ImageURL, IsPrimaryID) VALUES
(1, '/uploads/items/drill_001.jpg', 1),
(2, '/uploads/items/mower_001.jpg', 1),
(3, '/uploads/items/cleaner_001.jpg', 1);

-- Sample Rental
INSERT INTO Rentals (ItemID, RenterUserID, LenderUserID, PaymentMethodID, StatusID,
                    StartDate, EndDate, TotalCost) VALUES
(1, 1, 2, 1, 4, '2026-02-15', '2026-02-17', 16.00);

-- Sample Reviews
INSERT INTO Reviews (RentalID, ReviewerUserID, ReviewedUserID, Rating, Comment) VALUES
(1, 1, 2, 5, 'Great drill! Mary was very helpful and the tool worked perfectly.'),
(1, 2, 1, 5, 'John was respectful and returned the drill in perfect condition.');

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================

SELECT 'SUCCESS! Community Toolkit database created!' AS Status;
SELECT '17 tables added to your existing database' AS Info;
SELECT 'Tables: Booleans, States, Genders_CT, Neighborhoods, CardTypes, ItemConditions, RentalStatuses, ItemCategories, Users_CT, UserVerifications, PaymentMethods, Items, ItemImages, Rentals, DepositTransactions, RentalImages, Reviews' AS TableList;

-- Community Toolkit Database - ERD Version 3.0
-- Updated schema with messaging, transactions, and enhanced features
-- MySQL/MariaDB Compatible

-- Drop existing tables (in reverse dependency order)
DROP TABLE IF EXISTS TNotifications;
DROP TABLE IF EXISTS TTransactions;
DROP TABLE IF EXISTS TMessages;
DROP TABLE IF EXISTS TUserConversations;
DROP TABLE IF EXISTS TConversations;
DROP TABLE IF EXISTS TReviews;
DROP TABLE IF EXISTS TRentalExtentions;
DROP TABLE IF EXISTS TRentals;
DROP TABLE IF EXISTS TRentalRequests;
DROP TABLE IF EXISTS TListingAvailability;
DROP TABLE IF EXISTS TLisitingPhotos;
DROP TABLE IF EXISTS TListings;
DROP TABLE IF EXISTS TUserCards;
DROP TABLE IF EXISTS TUsers;
DROP TABLE IF EXISTS TNeighborhoods;
DROP TABLE IF EXISTS TStates;
DROP TABLE IF EXISTS TCategories;
DROP TABLE IF EXISTS TListingStatuses;
DROP TABLE IF EXISTS TConditions;
DROP TABLE IF EXISTS TCardTypes;
DROP TABLE IF EXISTS TRateTypes;
DROP TABLE IF EXISTS TBlockReasons;
DROP TABLE IF EXISTS TRentalStatuses;
DROP TABLE IF EXISTS TExtensionStatuses;
DROP TABLE IF EXISTS TReviewTypes;
DROP TABLE IF EXISTS TRequestStatuses;
DROP TABLE IF EXISTS TNotificationTypes;
DROP TABLE IF EXISTS TTransactionTypes;
DROP TABLE IF EXISTS TTransactionStatuses;

-- ============================================
-- LOOKUP/REFERENCE TABLES
-- ============================================

CREATE TABLE TStates (
    StateID INT AUTO_INCREMENT PRIMARY KEY,
    StateName VARCHAR(50) NOT NULL
);

CREATE TABLE TNeighborhoods (
    NeighborhoodID INT AUTO_INCREMENT PRIMARY KEY,
    NeighborhoodName VARCHAR(100) NOT NULL,
    City VARCHAR(100) NOT NULL,
    StateID INT NOT NULL,
    ZipCode VARCHAR(20),
    CenterLatitude DECIMAL(10,8),
    CenterLongitude DECIMAL(11,8),
    FOREIGN KEY (StateID) REFERENCES TStates(StateID)
);

CREATE TABLE TCategories (
    CategoryID INT AUTO_INCREMENT PRIMARY KEY,
    CategoryName VARCHAR(100) NOT NULL,
    ParentCategoryID INT NULL,
    FOREIGN KEY (ParentCategoryID) REFERENCES TCategories(CategoryID)
);

CREATE TABLE TListingStatuses (
    ListingStatusID INT AUTO_INCREMENT PRIMARY KEY,
    StatusName VARCHAR(50) NOT NULL
);

CREATE TABLE TConditions (
    ConditionID INT AUTO_INCREMENT PRIMARY KEY,
    ConditionName VARCHAR(50) NOT NULL
);

CREATE TABLE TCardTypes (
    CardTypeID INT AUTO_INCREMENT PRIMARY KEY,
    CardType VARCHAR(50) NOT NULL
);

CREATE TABLE TRateTypes (
    RateTypeID INT AUTO_INCREMENT PRIMARY KEY,
    RateType VARCHAR(50) NOT NULL
);

CREATE TABLE TBlockReasons (
    BlockReasonID INT AUTO_INCREMENT PRIMARY KEY,
    BlockReason TEXT NOT NULL
);

CREATE TABLE TRentalStatuses (
    RentalStatusID INT AUTO_INCREMENT PRIMARY KEY,
    StatusName VARCHAR(50) NOT NULL
);

CREATE TABLE TExtensionStatuses (
    ExtensionStatusID INT AUTO_INCREMENT PRIMARY KEY,
    StatusName VARCHAR(50) NOT NULL
);

CREATE TABLE TReviewTypes (
    ReviewTypeID INT AUTO_INCREMENT PRIMARY KEY,
    ReviewType VARCHAR(50) NOT NULL
);

CREATE TABLE TRequestStatuses (
    RequestStatusID INT AUTO_INCREMENT PRIMARY KEY,
    StatusName VARCHAR(50) NOT NULL
);

CREATE TABLE TNotificationTypes (
    NotificationTypeID INT AUTO_INCREMENT PRIMARY KEY,
    NotificationName VARCHAR(100) NOT NULL,
    NotificationDescription TEXT
);

CREATE TABLE TTransactionTypes (
    TransactionTypeID INT AUTO_INCREMENT PRIMARY KEY,
    TransactionType VARCHAR(50) NOT NULL
);

CREATE TABLE TTransactionStatuses (
    TransactionStatusID INT AUTO_INCREMENT PRIMARY KEY,
    StatusName VARCHAR(50) NOT NULL
);

-- ============================================
-- USER TABLES
-- ============================================

CREATE TABLE TUsers (
    UserID INT AUTO_INCREMENT PRIMARY KEY,
    FirstName VARCHAR(100) NOT NULL,
    LastName VARCHAR(100) NOT NULL,
    Email VARCHAR(255) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    PhoneNumber VARCHAR(20),
    ProfilePictureURL TEXT,
    Bio TEXT,
    NeighborhoodID INT,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    AccountStatus BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (NeighborhoodID) REFERENCES TNeighborhoods(NeighborhoodID)
);

CREATE TABLE TUserCards (
    CardID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    PaymentToken TEXT NOT NULL,
    CardTypeID INT NOT NULL,
    LastFourDigits VARCHAR(4) NOT NULL,
    ExpirationMonth VARCHAR(2) NOT NULL,
    ExpirationYear VARCHAR(4) NOT NULL,
    CVC VARCHAR(4),
    IsDefault BOOLEAN DEFAULT FALSE,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (CardTypeID) REFERENCES TCardTypes(CardTypeID)
);

-- ============================================
-- LISTING TABLES
-- ============================================

CREATE TABLE TListings (
    ListingID INT AUTO_INCREMENT PRIMARY KEY,
    UserLenderID INT NOT NULL,
    CategoryID INT NOT NULL,
    Title VARCHAR(255) NOT NULL,
    ListingStatusID INT NOT NULL,
    ConditionID INT NOT NULL,
    Description TEXT,
    RateTypeID INT NOT NULL,
    PricePerDay DECIMAL(10,2),
    PricePerHour DECIMAL(10,2),
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (UserLenderID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (CategoryID) REFERENCES TCategories(CategoryID),
    FOREIGN KEY (ListingStatusID) REFERENCES TListingStatuses(ListingStatusID),
    FOREIGN KEY (ConditionID) REFERENCES TConditions(ConditionID),
    FOREIGN KEY (RateTypeID) REFERENCES TRateTypes(RateTypeID)
);

CREATE TABLE TLisitingPhotos (
    ListingPhotoID INT AUTO_INCREMENT PRIMARY KEY,
    ListingID INT NOT NULL,
    PhotoURL TEXT NOT NULL,
    SortOrder INT DEFAULT 0,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ListingID) REFERENCES TListings(ListingID) ON DELETE CASCADE
);

CREATE TABLE TListingAvailability (
    ListingAvailabilityID INT AUTO_INCREMENT PRIMARY KEY,
    ListingID INT NOT NULL,
    UnavailableDate DATE NOT NULL,
    BlockReasonID INT,
    FOREIGN KEY (ListingID) REFERENCES TListings(ListingID) ON DELETE CASCADE,
    FOREIGN KEY (BlockReasonID) REFERENCES TBlockReasons(BlockReasonID)
);

-- ============================================
-- RENTAL REQUEST & RENTAL TABLES
-- ============================================

CREATE TABLE TRentalRequests (
    RentalRequestID INT AUTO_INCREMENT PRIMARY KEY,
    ListingID INT NOT NULL,
    UserBorrowerID INT NOT NULL,
    StartTime DATETIME NOT NULL,
    EndDate DATETIME NOT NULL,
    RequestStatusID INT NOT NULL,
    RequestDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ListingID) REFERENCES TListings(ListingID) ON DELETE CASCADE,
    FOREIGN KEY (UserBorrowerID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (RequestStatusID) REFERENCES TRequestStatuses(RequestStatusID)
);

CREATE TABLE TRentals (
    RentalID INT AUTO_INCREMENT PRIMARY KEY,
    RentalRequestID INT NOT NULL,
    ListingID INT NOT NULL,
    UserBorrowerID INT NOT NULL,
    RentalStatusID INT NOT NULL,
    PickUpPhotoURL TEXT,
    ReturnPhotoURL TEXT,
    UserBorrowerCardID INT,
    UserLenderCardID INT,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedDate DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (RentalRequestID) REFERENCES TRentalRequests(RentalRequestID),
    FOREIGN KEY (ListingID) REFERENCES TListings(ListingID) ON DELETE CASCADE,
    FOREIGN KEY (UserBorrowerID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (RentalStatusID) REFERENCES TRentalStatuses(RentalStatusID),
    FOREIGN KEY (UserBorrowerCardID) REFERENCES TUserCards(CardID),
    FOREIGN KEY (UserLenderCardID) REFERENCES TUserCards(CardID)
);

CREATE TABLE TRentalExtentions (
    RentalExtensionID INT AUTO_INCREMENT PRIMARY KEY,
    RentalID INT NOT NULL,
    ExtensionStatusID INT NOT NULL,
    EndDate DATETIME NOT NULL,
    RequestDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (RentalID) REFERENCES TRentals(RentalID) ON DELETE CASCADE,
    FOREIGN KEY (ExtensionStatusID) REFERENCES TExtensionStatuses(ExtensionStatusID)
);

-- ============================================
-- MESSAGING TABLES
-- ============================================

CREATE TABLE TConversations (
    ConversationID INT AUTO_INCREMENT PRIMARY KEY,
    RentalID INT,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    LastMessageDate DATETIME,
    FOREIGN KEY (RentalID) REFERENCES TRentals(RentalID) ON DELETE CASCADE
);

CREATE TABLE TUserConversations (
    UserConversationID INT AUTO_INCREMENT PRIMARY KEY,
    ConversationID INT NOT NULL,
    UserID INT NOT NULL,
    LastReadDate DATETIME,
    FOREIGN KEY (ConversationID) REFERENCES TConversations(ConversationID) ON DELETE CASCADE,
    FOREIGN KEY (UserID) REFERENCES TUsers(UserID) ON DELETE CASCADE
);

CREATE TABLE TMessages (
    MessageID INT AUTO_INCREMENT PRIMARY KEY,
    ConversationID INT NOT NULL,
    UserSenderID INT NOT NULL,
    MessageBody TEXT NOT NULL,
    SystemMessage BOOLEAN DEFAULT FALSE,
    SentDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ConversationID) REFERENCES TConversations(ConversationID) ON DELETE CASCADE,
    FOREIGN KEY (UserSenderID) REFERENCES TUsers(UserID) ON DELETE CASCADE
);

-- ============================================
-- REVIEW TABLES
-- ============================================

CREATE TABLE TReviews (
    ReviewID INT AUTO_INCREMENT PRIMARY KEY,
    RentalID INT NOT NULL,
    UserReviewerID INT NOT NULL,
    UserRevieweeID INT NOT NULL,
    ReviewRating INT NOT NULL,
    ReviewTypeID INT NOT NULL,
    ReviewText TEXT,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (RentalID) REFERENCES TRentals(RentalID) ON DELETE CASCADE,
    FOREIGN KEY (UserReviewerID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (UserRevieweeID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (ReviewTypeID) REFERENCES TReviewTypes(ReviewTypeID)
);

-- ============================================
-- NOTIFICATION TABLES
-- ============================================

CREATE TABLE TNotifications (
    NotificationID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    NotificationTypeID INT NOT NULL,
    RentalRequestID INT,
    RentalID INT,
    ConversationID INT,
    RentalExtensionID INT,
    ReviewID INT,
    Message TEXT NOT NULL,
    ReadStatus BOOLEAN DEFAULT FALSE,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (NotificationTypeID) REFERENCES TNotificationTypes(NotificationTypeID),
    FOREIGN KEY (RentalRequestID) REFERENCES TRentalRequests(RentalRequestID) ON DELETE CASCADE,
    FOREIGN KEY (RentalID) REFERENCES TRentals(RentalID) ON DELETE CASCADE,
    FOREIGN KEY (ConversationID) REFERENCES TConversations(ConversationID) ON DELETE CASCADE,
    FOREIGN KEY (RentalExtensionID) REFERENCES TRentalExtentions(RentalExtensionID) ON DELETE CASCADE,
    FOREIGN KEY (ReviewID) REFERENCES TReviews(ReviewID) ON DELETE CASCADE
);

-- ============================================
-- TRANSACTION TABLES
-- ============================================

CREATE TABLE TTransactions (
    TransactionID INT AUTO_INCREMENT PRIMARY KEY,
    RentalID INT NOT NULL,
    TransactionTypeID INT NOT NULL,
    TransactionStatusID INT NOT NULL,
    UserBorrowerID INT NOT NULL,
    UserLenderID INT NOT NULL,
    UserBorrowerCardID INT,
    UserLenderCardID INT,
    Amount DECIMAL(10,2) NOT NULL,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (RentalID) REFERENCES TRentals(RentalID) ON DELETE CASCADE,
    FOREIGN KEY (TransactionTypeID) REFERENCES TTransactionTypes(TransactionTypeID),
    FOREIGN KEY (TransactionStatusID) REFERENCES TTransactionStatuses(TransactionStatusID),
    FOREIGN KEY (UserBorrowerID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (UserLenderID) REFERENCES TUsers(UserID) ON DELETE CASCADE,
    FOREIGN KEY (UserBorrowerCardID) REFERENCES TUserCards(CardID),
    FOREIGN KEY (UserLenderCardID) REFERENCES TUserCards(CardID)
);

-- ============================================
-- SEED DATA FOR LOOKUP TABLES
-- ============================================

-- Insert States
INSERT INTO TStates (StateName) VALUES 
('Alabama'), ('Alaska'), ('Arizona'), ('Arkansas'), ('California'),
('Colorado'), ('Connecticut'), ('Delaware'), ('Florida'), ('Georgia'),
('Hawaii'), ('Idaho'), ('Illinois'), ('Indiana'), ('Iowa'),
('Kansas'), ('Kentucky'), ('Louisiana'), ('Maine'), ('Maryland'),
('Massachusetts'), ('Michigan'), ('Minnesota'), ('Mississippi'), ('Missouri'),
('Montana'), ('Nebraska'), ('Nevada'), ('New Hampshire'), ('New Jersey'),
('New Mexico'), ('New York'), ('North Carolina'), ('North Dakota'), ('Ohio'),
('Oklahoma'), ('Oregon'), ('Pennsylvania'), ('Rhode Island'), ('South Carolina'),
('South Dakota'), ('Tennessee'), ('Texas'), ('Utah'), ('Vermont'),
('Virginia'), ('Washington'), ('West Virginia'), ('Wisconsin'), ('Wyoming');

-- Insert Listing Statuses
INSERT INTO TListingStatuses (StatusName) VALUES 
('Active'), ('Inactive'), ('Pending'), ('Deleted');

-- Insert Conditions
INSERT INTO TConditions (ConditionName) VALUES 
('New'), ('Like New'), ('Good'), ('Fair'), ('Poor');

-- Insert Card Types
INSERT INTO TCardTypes (CardType) VALUES 
('Visa'), ('Mastercard'), ('American Express'), ('Discover');

-- Insert Rate Types
INSERT INTO TRateTypes (RateType) VALUES 
('Per Day'), ('Per Hour'), ('Both');

-- Insert Block Reasons
INSERT INTO TBlockReasons (BlockReason) VALUES 
('Rented'), ('Maintenance'), ('Personal Use'), ('Other');

-- Insert Rental Statuses
INSERT INTO TRentalStatuses (StatusName) VALUES 
('Pending'), ('Active'), ('Completed'), ('Cancelled'), ('Overdue');

-- Insert Extension Statuses
INSERT INTO TExtensionStatuses (StatusName) VALUES 
('Pending'), ('Approved'), ('Denied');

-- Insert Review Types
INSERT INTO TReviewTypes (ReviewType) VALUES 
('Borrower Review'), ('Lender Review'), ('Item Review');

-- Insert Request Statuses
INSERT INTO TRequestStatuses (StatusName) VALUES 
('Pending'), ('Approved'), ('Declined'), ('Cancelled');

-- Insert Notification Types
INSERT INTO TNotificationTypes (NotificationName, NotificationDescription) VALUES 
('Rental Request', 'New rental request received'),
('Request Approved', 'Your rental request was approved'),
('Request Declined', 'Your rental request was declined'),
('Extension Request', 'Rental extension requested'),
('New Message', 'New message received'),
('Review Received', 'New review received'),
('Payment Received', 'Payment received'),
('Rental Reminder', 'Upcoming rental reminder');

-- Insert Transaction Types
INSERT INTO TTransactionTypes (TransactionType) VALUES 
('Rental Payment'), ('Security Deposit'), ('Refund'), ('Late Fee');

-- Insert Transaction Statuses
INSERT INTO TTransactionStatuses (StatusName) VALUES 
('Pending'), ('Completed'), ('Failed'), ('Refunded');

-- Insert Sample Categories
INSERT INTO TCategories (CategoryName, ParentCategoryID) VALUES 
('Power Tools', NULL),
('Hand Tools', NULL),
('Lawn & Garden', NULL),
('Home Improvement', NULL),
('Party & Events', NULL);

INSERT INTO TCategories (CategoryName, ParentCategoryID) VALUES 
('Drills', 1),
('Saws', 1),
('Sanders', 1),
('Wrenches', 2),
('Hammers', 2),
('Lawn Mowers', 3),
('Trimmers', 3),
('Ladders', 4),
('Paint Equipment', 4),
('Tables & Chairs', 5),
('Audio Equipment', 5);

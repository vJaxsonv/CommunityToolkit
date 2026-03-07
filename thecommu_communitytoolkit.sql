-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Mar 06, 2026 at 11:57 PM
-- Server version: 11.4.10-MariaDB
-- PHP Version: 8.4.17

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `thecommu_communitytoolkit`
--

-- --------------------------------------------------------

--
-- Table structure for table `TBlockReasons`
--

CREATE TABLE `TBlockReasons` (
  `BlockReasonID` int(11) NOT NULL,
  `BlockReason` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TBlockReasons`
--

INSERT INTO `TBlockReasons` (`BlockReasonID`, `BlockReason`) VALUES
(1, 'Booked'),
(2, 'Maintenance'),
(3, 'Owner blocked'),
(4, 'Holiday'),
(5, 'Cleaning');

-- --------------------------------------------------------

--
-- Table structure for table `TCardTypes`
--

CREATE TABLE `TCardTypes` (
  `CardTypeID` int(11) NOT NULL,
  `CardTypeName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TCardTypes`
--

INSERT INTO `TCardTypes` (`CardTypeID`, `CardTypeName`) VALUES
(1, 'Visa'),
(2, 'Mastercard'),
(3, 'American Express'),
(4, 'Discover');

-- --------------------------------------------------------

--
-- Table structure for table `TCategories`
--

CREATE TABLE `TCategories` (
  `CategoryID` int(11) NOT NULL,
  `CategoryName` varchar(255) NOT NULL,
  `ParentCategoryID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TCategories`
--

INSERT INTO `TCategories` (`CategoryID`, `CategoryName`, `ParentCategoryID`) VALUES
(1, 'Tools', 0),
(2, 'Home & Kitchen', 0),
(3, 'Electronics', 0),
(4, 'Outdoor', 0),
(5, 'Party & Events', 0),
(6, 'Sports & Fitness', 0),
(10, 'Power Tools', 1),
(11, 'Hand Tools', 1),
(12, 'Ladders', 1),
(20, 'Small Appliances', 2),
(21, 'Cleaning Equipment', 2),
(30, 'Cameras', 3),
(31, 'Audio', 3),
(40, 'Camping Gear', 4),
(41, 'Bikes', 4),
(50, 'Tables & Chairs', 5),
(51, 'Decorations', 5),
(60, 'Weights', 6),
(61, 'Balls & Games', 6);

-- --------------------------------------------------------

--
-- Table structure for table `TConditions`
--

CREATE TABLE `TConditions` (
  `ConditionID` int(11) NOT NULL,
  `ConditionName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TConditions`
--

INSERT INTO `TConditions` (`ConditionID`, `ConditionName`) VALUES
(1, 'New'),
(2, 'Like New'),
(3, 'Good'),
(4, 'Fair'),
(5, 'Used'),
(6, 'Refurbished');

-- --------------------------------------------------------

--
-- Table structure for table `TConversations`
--

CREATE TABLE `TConversations` (
  `ConversationID` int(11) NOT NULL,
  `RentalID` int(11) NOT NULL,
  `AddedDate` datetime NOT NULL,
  `LastMessageDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TConversations`
--

INSERT INTO `TConversations` (`ConversationID`, `RentalID`, `AddedDate`, `LastMessageDate`) VALUES
(2, 1, '2026-03-02 12:50:51', '2026-03-02 12:50:51');

-- --------------------------------------------------------

--
-- Table structure for table `TExtensionStatuses`
--

CREATE TABLE `TExtensionStatuses` (
  `ExtensionStatusID` int(11) NOT NULL,
  `Status` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TExtensionStatuses`
--

INSERT INTO `TExtensionStatuses` (`ExtensionStatusID`, `Status`) VALUES
(1, 'Pending'),
(2, 'Approved'),
(3, 'Rejected'),
(4, 'Cancelled');

-- --------------------------------------------------------

--
-- Table structure for table `TGenders`
--

CREATE TABLE `TGenders` (
  `GenderID` int(11) NOT NULL,
  `GenderName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TGenders`
--

INSERT INTO `TGenders` (`GenderID`, `GenderName`) VALUES
(1, 'Male'),
(2, 'Female'),
(3, 'Other');

-- --------------------------------------------------------

--
-- Table structure for table `TListingAvailability`
--

CREATE TABLE `TListingAvailability` (
  `ListingAvailabilityID` int(11) NOT NULL,
  `ListingID` int(11) NOT NULL,
  `UnavailableDate` datetime NOT NULL,
  `BlockReasonID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TListingAvailability`
--

INSERT INTO `TListingAvailability` (`ListingAvailabilityID`, `ListingID`, `UnavailableDate`, `BlockReasonID`) VALUES
(1, 1, '2026-03-07 00:00:00', 3),
(2, 1, '2026-03-08 00:00:00', 3),
(3, 1, '2026-03-20 00:00:00', 2);

-- --------------------------------------------------------

--
-- Table structure for table `TListingPhotos`
--

CREATE TABLE `TListingPhotos` (
  `ListingPhotoID` int(11) NOT NULL,
  `ListingID` int(11) NOT NULL,
  `PhotoURL` varchar(255) NOT NULL,
  `SortOrder` int(11) NOT NULL,
  `AddedDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TListingPhotos`
--

INSERT INTO `TListingPhotos` (`ListingPhotoID`, `ListingID`, `PhotoURL`, `SortOrder`, `AddedDate`) VALUES
(1, 1, 'https://example.com/images/drill1.jpg', 1, '2026-02-28 15:21:55'),
(2, 1, 'https://example.com/images/drill2.jpg', 2, '2026-02-28 15:21:55'),
(3, 2, 'https://example.com/images/mixer1.jpg', 1, '2026-02-28 15:21:55'),
(4, 2, 'https://example.com/images/mixer2.jpg', 2, '2026-02-28 15:21:55'),
(5, 3, 'https://example.com/images/camera1.jpg', 1, '2026-02-28 15:21:55'),
(6, 3, 'https://example.com/images/camera2.jpg', 2, '2026-02-28 15:21:55'),
(7, 4, 'https://example.com/images/tent1.jpg', 1, '2026-02-28 15:21:55'),
(8, 4, 'https://example.com/images/tent2.jpg', 2, '2026-02-28 15:21:55'),
(9, 5, 'https://example.com/images/dumbbells1.jpg', 1, '2026-02-28 15:21:55'),
(10, 5, 'https://example.com/images/dumbbells2.jpg', 2, '2026-02-28 15:21:55');

-- --------------------------------------------------------

--
-- Table structure for table `TListings`
--

CREATE TABLE `TListings` (
  `ListingID` int(11) NOT NULL,
  `UserLenderID` int(11) NOT NULL,
  `CategoryID` int(11) NOT NULL,
  `ListingStatusID` int(11) NOT NULL,
  `Title` varchar(255) NOT NULL,
  `NeighborhoodID` int(11) NOT NULL,
  `Description` varchar(255) NOT NULL,
  `ConditionID` int(11) NOT NULL,
  `RateTypeID` int(11) NOT NULL,
  `PricePerDay` decimal(10,0) DEFAULT NULL,
  `PricePerHour` int(11) DEFAULT NULL,
  `AddedDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TListings`
--

INSERT INTO `TListings` (`ListingID`, `UserLenderID`, `CategoryID`, `ListingStatusID`, `Title`, `NeighborhoodID`, `Description`, `ConditionID`, `RateTypeID`, `PricePerDay`, `PricePerHour`, `AddedDate`) VALUES
(1, 1, 1, 1, 'Cordless Drill', 1, '18V cordless drill with charger.', 3, 1, 15, 5, '2026-02-28 15:18:38'),
(2, 2, 2, 1, 'Stand Mixer', 2, 'KitchenAid stand mixer.', 2, 1, 20, 8, '2026-02-28 15:18:38'),
(3, 3, 3, 1, 'DSLR Camera', 3, 'Canon DSLR with two lenses.', 2, 1, 35, 12, '2026-02-28 15:18:38'),
(4, 4, 4, 1, 'Camping Tent', 18, '4-person waterproof tent.', 3, 1, 18, 6, '2026-02-28 15:18:38'),
(5, 5, 6, 1, 'Adjustable Dumbbells', 19, '50lb adjustable dumbbell set.', 3, 2, 12, 4, '2026-02-28 15:18:38');

-- --------------------------------------------------------

--
-- Table structure for table `TListingStatuses`
--

CREATE TABLE `TListingStatuses` (
  `ListingStatusID` int(11) NOT NULL,
  `StatusName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TListingStatuses`
--

INSERT INTO `TListingStatuses` (`ListingStatusID`, `StatusName`) VALUES
(1, 'Available'),
(2, 'Pending'),
(3, 'Rented'),
(4, 'Inactive'),
(5, 'Removed');

-- --------------------------------------------------------

--
-- Table structure for table `TMessages`
--

CREATE TABLE `TMessages` (
  `MessageID` int(11) NOT NULL,
  `ConversationID` int(11) NOT NULL,
  `UserSenderID` int(11) NOT NULL,
  `MessageBody` varchar(255) NOT NULL,
  `SystemMessage` varchar(255) NOT NULL,
  `SentDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TMessages`
--

INSERT INTO `TMessages` (`MessageID`, `ConversationID`, `UserSenderID`, `MessageBody`, `SystemMessage`, `SentDate`) VALUES
(1, 2, 6, 'Hi! I just requested this rental.', '0', '2026-03-02 13:16:25'),
(2, 2, 1, 'Thanks! I accepted your request.', '0', '2026-03-02 13:16:25'),
(3, 2, 1, 'System: You received a new review.', '1', '2026-03-02 13:16:25'),
(4, 2, 6, 'Hi! I just requested this rental.', '0', '2026-03-02 13:23:23'),
(5, 2, 1, 'Thanks! I accepted your request.', '0', '2026-03-02 13:23:23');

-- --------------------------------------------------------

--
-- Table structure for table `TNeighborhoods`
--

CREATE TABLE `TNeighborhoods` (
  `NeighborhoodID` int(11) NOT NULL,
  `NeighborhoodName` varchar(255) NOT NULL,
  `City` varchar(100) NOT NULL,
  `StateID` int(11) NOT NULL,
  `ZipCode` varchar(255) NOT NULL,
  `CenterLatitude` varchar(255) DEFAULT NULL,
  `CenterLongitude` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TNeighborhoods`
--

INSERT INTO `TNeighborhoods` (`NeighborhoodID`, `NeighborhoodName`, `City`, `StateID`, `ZipCode`, `CenterLatitude`, `CenterLongitude`) VALUES
(1, 'Hyde Park', 'Cincinnati', 1, '45208, 45209', NULL, NULL),
(2, 'Oakley', 'Cincinnati', 1, '45209', NULL, NULL),
(3, 'Mount Lookout', 'Cincinnati', 1, '45208', NULL, NULL),
(4, 'East Walnut Hills', 'Cincinnati', 1, '45206', NULL, NULL),
(5, 'Columbia-Tusculum', 'Cincinnati', 1, '45226', NULL, NULL),
(6, 'Mount Washington', 'Cincinnati', 1, '45230', NULL, NULL),
(7, 'Anderson Township', 'Cincinnati', 1, '45230, 45244, 45255', NULL, NULL),
(8, 'Mariemont', 'Cincinnati', 1, '45227', NULL, NULL),
(9, 'Madisonville', 'Cincinnati', 1, '45227', NULL, NULL),
(10, 'Newtown', 'Cincinnati', 1, '45244', NULL, NULL),
(11, 'Westwood', 'Cincinnati', 1, '45205, 45238', NULL, NULL),
(12, 'Western Hills', 'Cincinnati', 1, '45238', NULL, NULL),
(13, 'Price Hill', 'Cincinnati', 1, '45204, 45205, 45207', NULL, NULL),
(14, 'Delhi Township', 'Cincinnati', 1, '45238', NULL, NULL),
(15, 'Green Township', 'Cincinnati', 1, '45238, 45239', NULL, NULL),
(16, 'Cheviot', 'Cincinnati', 1, '45211', NULL, NULL),
(17, 'Bridgetown', 'Cincinnati', 1, '45211', NULL, NULL),
(18, 'Downtown', 'Cincinnati', 1, '45202', NULL, NULL),
(19, 'Over-the-Rhine', 'Cincinnati', 1, '45202', NULL, NULL),
(20, 'Mount Adams', 'Cincinnati', 1, '45202', NULL, NULL),
(21, 'Pendleton', 'Cincinnati', 1, '45202', NULL, NULL),
(22, 'The Banks', 'Cincinnati', 1, '45202', NULL, NULL),
(23, 'West End', 'Cincinnati', 1, '45203', NULL, NULL),
(24, 'Clifton', 'Cincinnati', 1, '45220, 45221', NULL, NULL),
(25, 'Corryville', 'Cincinnati', 1, '45219, 45220', NULL, NULL),
(26, 'Newport - East Row', 'Newport', 2, '41071', NULL, NULL),
(27, 'Newport - Mansion Hill', 'Newport', 2, '41071', NULL, NULL),
(28, 'Newport - Monmouth Street District', 'Newport', 2, '41071', NULL, NULL),
(29, 'Newport - Riverfront', 'Newport', 2, '41071', NULL, NULL),
(30, 'Downtown Indianapolis', 'Indianapolis', 3, '46204', NULL, NULL),
(31, 'Broad Ripple', 'Indianapolis', 3, '46220', NULL, NULL),
(32, 'Fountain Square', 'Indianapolis', 3, '46203', NULL, NULL),
(33, 'Irvington', 'Indianapolis', 3, '46219', NULL, NULL),
(34, 'Carmel Arts District', 'Indianapolis', 3, '46032', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `TNotifications`
--

CREATE TABLE `TNotifications` (
  `NotificationID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `NotificationTypeID` int(11) NOT NULL,
  `RentalRequestID` int(11) NOT NULL,
  `ConversationID` int(11) NOT NULL,
  `MessageID` int(11) NOT NULL,
  `RentalExtensionID` int(11) NOT NULL,
  `RentalID` int(11) NOT NULL,
  `ReviewID` int(11) NOT NULL,
  `Message` varchar(255) NOT NULL,
  `ReadStatus` tinyint(1) NOT NULL,
  `AddedDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TNotifications`
--

INSERT INTO `TNotifications` (`NotificationID`, `UserID`, `NotificationTypeID`, `RentalRequestID`, `ConversationID`, `MessageID`, `RentalExtensionID`, `RentalID`, `ReviewID`, `Message`, `ReadStatus`, `AddedDate`) VALUES
(1, 6, 1, 1, 2, 1, 2, 1, 2, 'Your rental request was submitted.', 0, '2026-03-02 13:49:30'),
(2, 6, 2, 1, 2, 5, 2, 1, 2, 'Your rental request was accepted!', 0, '2026-03-02 13:49:30'),
(3, 1, 6, 1, 2, 5, 2, 1, 2, 'You received a new review.', 0, '2026-03-02 13:49:30');

-- --------------------------------------------------------

--
-- Table structure for table `TNotificationTypes`
--

CREATE TABLE `TNotificationTypes` (
  `NotificationTypeID` int(11) NOT NULL,
  `Name` varchar(255) NOT NULL,
  `Description` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TNotificationTypes`
--

INSERT INTO `TNotificationTypes` (`NotificationTypeID`, `Name`, `Description`) VALUES
(1, 'Rental Request', 'A new rental request was created'),
(2, 'Request Accepted', 'Your rental request was accepted'),
(3, 'Request Declined', 'Your rental request was declined'),
(4, 'Rental Started', 'A rental has started'),
(5, 'Rental Completed', 'A rental has completed'),
(6, 'Review Received', 'A review was received'),
(7, 'Extension Requested', 'A rental extension was requested');

-- --------------------------------------------------------

--
-- Table structure for table `TRateTypes`
--

CREATE TABLE `TRateTypes` (
  `RateTypeID` int(11) NOT NULL,
  `RateType` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TRateTypes`
--

INSERT INTO `TRateTypes` (`RateTypeID`, `RateType`) VALUES
(1, 'Daily'),
(2, 'Hourly');

-- --------------------------------------------------------

--
-- Table structure for table `TRentalExtensions`
--

CREATE TABLE `TRentalExtensions` (
  `RentalExtensionID` int(11) NOT NULL,
  `RentalID` int(11) NOT NULL,
  `ExtensionStatusID` int(11) NOT NULL,
  `EndDate` datetime NOT NULL,
  `RequestDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TRentalExtensions`
--

INSERT INTO `TRentalExtensions` (`RentalExtensionID`, `RentalID`, `ExtensionStatusID`, `EndDate`, `RequestDate`) VALUES
(1, 1, 1, '2026-03-04 11:56:37', '2026-03-02 11:56:37'),
(2, 1, 1, '2026-03-04 13:23:03', '2026-03-02 13:23:03');

-- --------------------------------------------------------

--
-- Table structure for table `TRentalRequests`
--

CREATE TABLE `TRentalRequests` (
  `RentalRequestID` int(11) NOT NULL,
  `ListingID` int(11) NOT NULL,
  `UserBorrowerID` int(11) NOT NULL,
  `StartDate` datetime NOT NULL,
  `EndDate` datetime NOT NULL,
  `RequestStatusID` int(11) NOT NULL,
  `RequestDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TRentalRequests`
--

INSERT INTO `TRentalRequests` (`RentalRequestID`, `ListingID`, `UserBorrowerID`, `StartDate`, `EndDate`, `RequestStatusID`, `RequestDate`) VALUES
(1, 1, 6, '2026-03-05 10:00:00', '2026-03-06 10:00:00', 2, '2026-02-28 15:38:59'),
(2, 1, 6, '2026-03-05 10:00:00', '2026-03-06 10:00:00', 2, '2026-02-28 15:53:39');

-- --------------------------------------------------------

--
-- Table structure for table `TRentals`
--

CREATE TABLE `TRentals` (
  `RentalID` int(11) NOT NULL,
  `ListingID` int(11) NOT NULL,
  `UserBorrowerID` int(11) NOT NULL,
  `UserLenderID` int(11) NOT NULL,
  `RentalRequestID` int(11) NOT NULL,
  `RentalStatusID` int(11) NOT NULL,
  `PickUpPhotoURL` varchar(255) DEFAULT NULL,
  `ReturnPhotoURL` varchar(255) DEFAULT NULL,
  `UserBorrowerCardID` int(11) DEFAULT NULL,
  `UserLenderCardID` int(11) DEFAULT NULL,
  `AddedDate` datetime NOT NULL,
  `UpdatedDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TRentals`
--

INSERT INTO `TRentals` (`RentalID`, `ListingID`, `UserBorrowerID`, `UserLenderID`, `RentalRequestID`, `RentalStatusID`, `PickUpPhotoURL`, `ReturnPhotoURL`, `UserBorrowerCardID`, `UserLenderCardID`, `AddedDate`, `UpdatedDate`) VALUES
(1, 1, 6, 1, 1, 5, NULL, 'https://example.com/return.jpg', NULL, NULL, '2026-02-28 16:04:55', '2026-02-28 16:42:53'),
(3, 1, 6, 1, 2, 4, NULL, NULL, NULL, NULL, '2026-02-28 16:42:39', '2026-02-28 16:42:39');

-- --------------------------------------------------------

--
-- Table structure for table `TRentalStatuses`
--

CREATE TABLE `TRentalStatuses` (
  `RentalStatusID` int(11) NOT NULL,
  `Status` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TRentalStatuses`
--

INSERT INTO `TRentalStatuses` (`RentalStatusID`, `Status`) VALUES
(1, 'Pending'),
(2, 'Approved'),
(3, 'Rejected'),
(4, 'Active'),
(5, 'Completed'),
(6, 'Cancelled');

-- --------------------------------------------------------

--
-- Table structure for table `TRequestStatuses`
--

CREATE TABLE `TRequestStatuses` (
  `RequestStatusID` int(11) NOT NULL,
  `Status` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TRequestStatuses`
--

INSERT INTO `TRequestStatuses` (`RequestStatusID`, `Status`) VALUES
(1, 'Pending'),
(2, 'Accepted'),
(3, 'Declined'),
(4, 'Cancelled'),
(5, 'Pending'),
(6, 'Accepted'),
(7, 'Declined'),
(8, 'Cancelled');

-- --------------------------------------------------------

--
-- Table structure for table `TReviews`
--

CREATE TABLE `TReviews` (
  `ReviewID` int(11) NOT NULL,
  `RentalID` int(11) NOT NULL,
  `UserReviewerID` int(11) NOT NULL,
  `UserRevieweeID` int(11) NOT NULL,
  `ReviewTypeID` int(11) NOT NULL,
  `ReviewRating` varchar(255) NOT NULL,
  `ReviewText` varchar(255) NOT NULL,
  `AddedDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TReviews`
--

INSERT INTO `TReviews` (`ReviewID`, `RentalID`, `UserReviewerID`, `UserRevieweeID`, `ReviewTypeID`, `ReviewRating`, `ReviewText`, `AddedDate`) VALUES
(1, 1, 6, 1, 1, '5', 'Great experience — item was exactly as described.', '2026-03-02 11:57:31'),
(2, 1, 1, 6, 2, '5', 'Returned on time and in perfect condition.', '2026-03-02 11:57:41');

-- --------------------------------------------------------

--
-- Table structure for table `TReviewTypes`
--

CREATE TABLE `TReviewTypes` (
  `ReviewTypeID` int(11) NOT NULL,
  `Review Type` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TReviewTypes`
--

INSERT INTO `TReviewTypes` (`ReviewTypeID`, `Review Type`) VALUES
(1, 'Borrower -> Lender'),
(2, 'Lender -> Borrower');

-- --------------------------------------------------------

--
-- Table structure for table `TStates`
--

CREATE TABLE `TStates` (
  `StateID` int(11) NOT NULL,
  `StateName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TStates`
--

INSERT INTO `TStates` (`StateID`, `StateName`) VALUES
(1, 'Ohio'),
(2, 'Kentucky'),
(3, 'Indiana');

-- --------------------------------------------------------

--
-- Table structure for table `TTransactions`
--

CREATE TABLE `TTransactions` (
  `TransactionID` int(11) NOT NULL,
  `RentalID` int(11) NOT NULL,
  `TransactionTypeID` int(11) NOT NULL,
  `TransactionStatusID` int(11) NOT NULL,
  `UserBorrowerID` int(11) NOT NULL,
  `UserLenderID` int(11) NOT NULL,
  `UserBorrowerCardID` int(11) NOT NULL,
  `UserLenderCardID` int(11) NOT NULL,
  `Amount` int(11) NOT NULL,
  `AddedDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TTransactions`
--

INSERT INTO `TTransactions` (`TransactionID`, `RentalID`, `TransactionTypeID`, `TransactionStatusID`, `UserBorrowerID`, `UserLenderID`, `UserBorrowerCardID`, `UserLenderCardID`, `Amount`, `AddedDate`) VALUES
(1, 1, 2, 2, 6, 1, 6, 1, 50, '2026-03-02 11:29:40'),
(2, 1, 1, 2, 6, 1, 6, 1, 15, '2026-03-02 11:30:31'),
(3, 1, 3, 1, 6, 1, 6, 1, 50, '2026-03-02 11:30:42'),
(4, 1, 2, 2, 6, 1, 6, 1, 50, '2026-03-02 11:35:50'),
(5, 1, 1, 2, 6, 1, 6, 1, 15, '2026-03-02 11:35:50'),
(6, 1, 3, 1, 6, 1, 6, 1, 50, '2026-03-02 11:35:50'),
(7, 1, 2, 2, 6, 1, 6, 1, 50, '2026-03-02 11:41:18'),
(8, 1, 1, 2, 6, 1, 6, 1, 15, '2026-03-02 11:41:18'),
(9, 1, 3, 1, 6, 1, 6, 1, 50, '2026-03-02 11:41:18');

-- --------------------------------------------------------

--
-- Table structure for table `TTransactionStatuses`
--

CREATE TABLE `TTransactionStatuses` (
  `TransactionStatusID` int(11) NOT NULL,
  `Status` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TTransactionStatuses`
--

INSERT INTO `TTransactionStatuses` (`TransactionStatusID`, `Status`) VALUES
(1, 'Pending'),
(2, 'Successful'),
(3, 'Failed'),
(4, 'Refunded');

-- --------------------------------------------------------

--
-- Table structure for table `TTransactionType`
--

CREATE TABLE `TTransactionType` (
  `TransactionTypeID` int(11) NOT NULL,
  `TransactionType` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TTransactionType`
--

INSERT INTO `TTransactionType` (`TransactionTypeID`, `TransactionType`) VALUES
(1, 'Rental Payment'),
(2, 'Deposit'),
(3, 'Refund');

-- --------------------------------------------------------

--
-- Table structure for table `TUserCards`
--

CREATE TABLE `TUserCards` (
  `CardID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `PaymentToken` varchar(255) NOT NULL,
  `CardTypeID` int(11) NOT NULL,
  `LastFourDigits` varchar(255) NOT NULL,
  `ExpirationMonth` varchar(255) NOT NULL,
  `ExpirationYear` varchar(255) NOT NULL,
  `CVC` varchar(255) NOT NULL,
  `PrimaryCard` tinyint(1) NOT NULL,
  `AddedDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TUserCards`
--

INSERT INTO `TUserCards` (`CardID`, `UserID`, `PaymentToken`, `CardTypeID`, `LastFourDigits`, `ExpirationMonth`, `ExpirationYear`, `CVC`, `PrimaryCard`, `AddedDate`) VALUES
(1, 1, 'tok_user1_visa_001', 1, '1111', '03', '2027', '123', 1, '2026-02-28 16:27:16'),
(2, 2, 'tok_user2_mc_001', 2, '2222', '07', '2028', '234', 1, '2026-02-28 16:27:16'),
(3, 3, 'tok_user3_amex_001', 3, '3333', '11', '2027', '345', 1, '2026-02-28 16:27:16'),
(4, 4, 'tok_user4_visa_001', 1, '4444', '02', '2029', '456', 1, '2026-02-28 16:27:16'),
(5, 5, 'tok_user5_disc_001', 4, '5555', '09', '2028', '567', 1, '2026-02-28 16:27:16'),
(6, 6, 'tok_user6_mc_001', 2, '6666', '12', '2027', '678', 1, '2026-02-28 16:27:16'),
(7, 7, 'tok_user7_visa_001', 1, '7777', '06', '2029', '789', 1, '2026-02-28 16:27:16'),
(8, 8, 'tok_user8_amex_001', 3, '8888', '01', '2028', '890', 1, '2026-02-28 16:27:16'),
(9, 9, 'tok_user9_mc_001', 2, '9999', '04', '2027', '901', 1, '2026-02-28 16:27:16'),
(10, 10, 'tok_user10_visa_001', 1, '0000', '08', '2029', '012', 1, '2026-02-28 16:27:16');

-- --------------------------------------------------------

--
-- Table structure for table `TUserConversations`
--

CREATE TABLE `TUserConversations` (
  `UserConversationID` int(11) NOT NULL,
  `ConversationID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `LastReadDate` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TUserConversations`
--

INSERT INTO `TUserConversations` (`UserConversationID`, `ConversationID`, `UserID`, `LastReadDate`) VALUES
(3, 2, 6, '2026-03-02 12:51:58'),
(4, 2, 1, '2026-03-02 12:51:58'),
(5, 2, 6, '2026-03-02 13:16:58'),
(6, 2, 1, '2026-03-02 13:16:58'),
(7, 2, 6, '2026-03-02 13:23:43'),
(8, 2, 1, '2026-03-02 13:23:43');

-- --------------------------------------------------------

--
-- Table structure for table `TUsers`
--

CREATE TABLE `TUsers` (
  `UserID` int(11) NOT NULL,
  `FirstName` varchar(255) NOT NULL,
  `LastName` varchar(255) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `PhoneNumber` varchar(11) NOT NULL,
  `GenderID` int(11) NOT NULL,
  `ProfilePictureURL` varchar(255) DEFAULT NULL,
  `Bio` varchar(255) DEFAULT NULL,
  `NeighborhoodID` int(11) NOT NULL,
  `AddedDate` datetime NOT NULL,
  `AccountStatus` tinyint(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TUsers`
--

INSERT INTO `TUsers` (`UserID`, `FirstName`, `LastName`, `Email`, `Password`, `PhoneNumber`, `GenderID`, `ProfilePictureURL`, `Bio`, `NeighborhoodID`, `AddedDate`, `AccountStatus`) VALUES
(1, 'Sarah', 'Johnson', 'sarah.johnson1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550101', 2, NULL, 'New to the community!', 1, '2026-02-28 15:03:13', 1),
(2, 'Mike', 'Brown', 'mike.brown1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550102', 1, NULL, 'DIY and tools.', 2, '2026-02-28 15:03:13', 1),
(3, 'Emily', 'Davis', 'emily.davis1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550103', 2, NULL, 'Party supplies lender.', 3, '2026-02-28 15:03:13', 1),
(4, 'David', 'Wilson', 'david.wilson1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550104', 1, NULL, 'Always happy to help.', 18, '2026-02-28 15:03:13', 1),
(5, 'Ava', 'Martinez', 'ava.martinez1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550105', 3, NULL, 'Gardening fan.', 19, '2026-02-28 15:03:13', 1),
(6, 'Olivia', 'Taylor', 'olivia.taylor1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550106', 2, NULL, 'Loves community sharing.', 10, '2026-02-28 15:04:17', 1),
(7, 'Ethan', 'Moore', 'ethan.moore1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550107', 1, NULL, 'Tool and equipment lender.', 11, '2026-02-28 15:04:17', 1),
(8, 'Sophia', 'Clark', 'sophia.clark1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550108', 2, NULL, 'Party planner supplies.', 12, '2026-02-28 15:04:17', 1),
(9, 'Liam', 'Walker', 'liam.walker1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550109', 1, NULL, 'Always renting camping gear.', 13, '2026-02-28 15:04:17', 1),
(10, 'Mia', 'Hall', 'mia.hall1@example.com', 'testing12345', '5135550110', 2, NULL, 'Neighborhood organizer.', 14, '2026-02-28 15:04:17', 1),
(12, 'Courtney', 'Frasier', 'frasier_cs@yahoo.com', '$2y$12$G7Z/AESB.4/kSWqiPA/xHuzB35sPgZvDnRHMUpK/EylxPmfuJmCRG', '5133077119', 3, NULL, NULL, 1, '2026-03-04 20:34:40', 1),
(13, 'Testing', 'Account', 'testingaccount@testingaccount.com', '$2y$12$4ZOlRVLOGkHo0O6tYiFYQeLuQZqTQFoGFTOMSOwFHly6noHQFHWIe', '5133077119', 2, NULL, NULL, 13, '2026-03-06 22:15:31', 1),
(14, 'Testing', 'Account', 'testingacct@testingacct.com', '$2y$12$hh48R5E8flResBdUiIqoUO91aDBfBqZ9Z5y5dfVgKI6jrhnnsZfsy', '5133333333', 1, NULL, NULL, 13, '2026-03-06 22:27:29', 1),
(15, 'Another', 'Account', 'anotheraccount@anotheraccount.com', '$2y$12$LeIy4hMzUdFZNIOJVoZoW.4qgIfF3Pgt3vD5SohxnHbHVGoQk8mSi', '5133333333', 2, NULL, NULL, 9, '2026-03-06 22:28:24', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `TBlockReasons`
--
ALTER TABLE `TBlockReasons`
  ADD PRIMARY KEY (`BlockReasonID`);

--
-- Indexes for table `TCardTypes`
--
ALTER TABLE `TCardTypes`
  ADD PRIMARY KEY (`CardTypeID`);

--
-- Indexes for table `TCategories`
--
ALTER TABLE `TCategories`
  ADD PRIMARY KEY (`CategoryID`),
  ADD KEY `ParentCategoryID` (`ParentCategoryID`);

--
-- Indexes for table `TConditions`
--
ALTER TABLE `TConditions`
  ADD PRIMARY KEY (`ConditionID`);

--
-- Indexes for table `TConversations`
--
ALTER TABLE `TConversations`
  ADD PRIMARY KEY (`ConversationID`),
  ADD KEY `RentalID` (`RentalID`);

--
-- Indexes for table `TExtensionStatuses`
--
ALTER TABLE `TExtensionStatuses`
  ADD PRIMARY KEY (`ExtensionStatusID`);

--
-- Indexes for table `TGenders`
--
ALTER TABLE `TGenders`
  ADD PRIMARY KEY (`GenderID`);

--
-- Indexes for table `TListingAvailability`
--
ALTER TABLE `TListingAvailability`
  ADD PRIMARY KEY (`ListingAvailabilityID`),
  ADD KEY `ListingID` (`ListingID`),
  ADD KEY `BlockReasonID` (`BlockReasonID`);

--
-- Indexes for table `TListingPhotos`
--
ALTER TABLE `TListingPhotos`
  ADD PRIMARY KEY (`ListingPhotoID`),
  ADD KEY `ListingID` (`ListingID`);

--
-- Indexes for table `TListings`
--
ALTER TABLE `TListings`
  ADD PRIMARY KEY (`ListingID`),
  ADD KEY `UserLenderID` (`UserLenderID`),
  ADD KEY `CategoryID` (`CategoryID`),
  ADD KEY `ListingStatusID` (`ListingStatusID`),
  ADD KEY `NeighborhoodID` (`NeighborhoodID`),
  ADD KEY `ConditionID` (`ConditionID`),
  ADD KEY `RateTypeID` (`RateTypeID`);

--
-- Indexes for table `TListingStatuses`
--
ALTER TABLE `TListingStatuses`
  ADD PRIMARY KEY (`ListingStatusID`);

--
-- Indexes for table `TMessages`
--
ALTER TABLE `TMessages`
  ADD PRIMARY KEY (`MessageID`),
  ADD KEY `ConversationID` (`ConversationID`),
  ADD KEY `UserSenderID` (`UserSenderID`);

--
-- Indexes for table `TNeighborhoods`
--
ALTER TABLE `TNeighborhoods`
  ADD PRIMARY KEY (`NeighborhoodID`),
  ADD KEY `StateID` (`StateID`);

--
-- Indexes for table `TNotifications`
--
ALTER TABLE `TNotifications`
  ADD PRIMARY KEY (`NotificationID`),
  ADD KEY `UserID` (`UserID`),
  ADD KEY `NotificationTypeID` (`NotificationTypeID`),
  ADD KEY `RentalRequestID` (`RentalRequestID`),
  ADD KEY `ConversationID` (`ConversationID`),
  ADD KEY `MessageID` (`MessageID`),
  ADD KEY `RentalExtensionID` (`RentalExtensionID`),
  ADD KEY `RentalID` (`RentalID`),
  ADD KEY `ReviewID` (`ReviewID`);

--
-- Indexes for table `TNotificationTypes`
--
ALTER TABLE `TNotificationTypes`
  ADD PRIMARY KEY (`NotificationTypeID`);

--
-- Indexes for table `TRateTypes`
--
ALTER TABLE `TRateTypes`
  ADD PRIMARY KEY (`RateTypeID`);

--
-- Indexes for table `TRentalExtensions`
--
ALTER TABLE `TRentalExtensions`
  ADD PRIMARY KEY (`RentalExtensionID`),
  ADD KEY `RentalID` (`RentalID`),
  ADD KEY `ExtensionStatusID` (`ExtensionStatusID`);

--
-- Indexes for table `TRentalRequests`
--
ALTER TABLE `TRentalRequests`
  ADD PRIMARY KEY (`RentalRequestID`),
  ADD KEY `ListingID` (`ListingID`),
  ADD KEY `UserBorrowerID` (`UserBorrowerID`),
  ADD KEY `RequestStatusID` (`RequestStatusID`);

--
-- Indexes for table `TRentals`
--
ALTER TABLE `TRentals`
  ADD PRIMARY KEY (`RentalID`),
  ADD KEY `ListingID` (`ListingID`),
  ADD KEY `UserBorrowerID` (`UserBorrowerID`),
  ADD KEY `RentalRequestID` (`RentalRequestID`),
  ADD KEY `UserLenderID` (`UserLenderID`),
  ADD KEY `RentalStatusID` (`RentalStatusID`),
  ADD KEY `UserBorrowerCardID` (`UserBorrowerCardID`),
  ADD KEY `UserLenderCardID` (`UserLenderCardID`);

--
-- Indexes for table `TRentalStatuses`
--
ALTER TABLE `TRentalStatuses`
  ADD PRIMARY KEY (`RentalStatusID`);

--
-- Indexes for table `TRequestStatuses`
--
ALTER TABLE `TRequestStatuses`
  ADD PRIMARY KEY (`RequestStatusID`);

--
-- Indexes for table `TReviews`
--
ALTER TABLE `TReviews`
  ADD PRIMARY KEY (`ReviewID`),
  ADD KEY `RentalID` (`RentalID`),
  ADD KEY `UserReviewerID` (`UserReviewerID`),
  ADD KEY `UserRevieweeID` (`UserRevieweeID`),
  ADD KEY `ReviewTypeID` (`ReviewTypeID`);

--
-- Indexes for table `TReviewTypes`
--
ALTER TABLE `TReviewTypes`
  ADD PRIMARY KEY (`ReviewTypeID`);

--
-- Indexes for table `TStates`
--
ALTER TABLE `TStates`
  ADD PRIMARY KEY (`StateID`);

--
-- Indexes for table `TTransactions`
--
ALTER TABLE `TTransactions`
  ADD PRIMARY KEY (`TransactionID`),
  ADD KEY `RentalID` (`RentalID`),
  ADD KEY `TransactiontTypeID` (`TransactionTypeID`),
  ADD KEY `TransactionStatusID` (`TransactionStatusID`),
  ADD KEY `UserBorrowerID` (`UserBorrowerID`),
  ADD KEY `UserLenderID` (`UserLenderID`),
  ADD KEY `UserBorrowerCardID` (`UserBorrowerCardID`),
  ADD KEY `UserLenderCardID` (`UserLenderCardID`);

--
-- Indexes for table `TTransactionStatuses`
--
ALTER TABLE `TTransactionStatuses`
  ADD PRIMARY KEY (`TransactionStatusID`);

--
-- Indexes for table `TTransactionType`
--
ALTER TABLE `TTransactionType`
  ADD PRIMARY KEY (`TransactionTypeID`);

--
-- Indexes for table `TUserCards`
--
ALTER TABLE `TUserCards`
  ADD PRIMARY KEY (`CardID`),
  ADD KEY `UserID` (`UserID`),
  ADD KEY `CardTypeID` (`CardTypeID`);

--
-- Indexes for table `TUserConversations`
--
ALTER TABLE `TUserConversations`
  ADD PRIMARY KEY (`UserConversationID`),
  ADD KEY `ConversationID` (`ConversationID`),
  ADD KEY `UserID` (`UserID`);

--
-- Indexes for table `TUsers`
--
ALTER TABLE `TUsers`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `Email` (`Email`),
  ADD KEY `GenderID` (`GenderID`),
  ADD KEY `NeighborhoodID` (`NeighborhoodID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `TBlockReasons`
--
ALTER TABLE `TBlockReasons`
  MODIFY `BlockReasonID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `TConversations`
--
ALTER TABLE `TConversations`
  MODIFY `ConversationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `TListingAvailability`
--
ALTER TABLE `TListingAvailability`
  MODIFY `ListingAvailabilityID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `TMessages`
--
ALTER TABLE `TMessages`
  MODIFY `MessageID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `TNeighborhoods`
--
ALTER TABLE `TNeighborhoods`
  MODIFY `NeighborhoodID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `TNotifications`
--
ALTER TABLE `TNotifications`
  MODIFY `NotificationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `TNotificationTypes`
--
ALTER TABLE `TNotificationTypes`
  MODIFY `NotificationTypeID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `TRentalExtensions`
--
ALTER TABLE `TRentalExtensions`
  MODIFY `RentalExtensionID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `TRentalRequests`
--
ALTER TABLE `TRentalRequests`
  MODIFY `RentalRequestID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `TRentals`
--
ALTER TABLE `TRentals`
  MODIFY `RentalID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `TRentalStatuses`
--
ALTER TABLE `TRentalStatuses`
  MODIFY `RentalStatusID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `TRequestStatuses`
--
ALTER TABLE `TRequestStatuses`
  MODIFY `RequestStatusID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `TReviews`
--
ALTER TABLE `TReviews`
  MODIFY `ReviewID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `TStates`
--
ALTER TABLE `TStates`
  MODIFY `StateID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `TTransactions`
--
ALTER TABLE `TTransactions`
  MODIFY `TransactionID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `TUserCards`
--
ALTER TABLE `TUserCards`
  MODIFY `CardID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `TUserConversations`
--
ALTER TABLE `TUserConversations`
  MODIFY `UserConversationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `TUsers`
--
ALTER TABLE `TUsers`
  MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

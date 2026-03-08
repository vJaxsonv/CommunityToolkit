-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Mar 08, 2026 at 12:01 AM
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

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspAcceptRentalRequest` (IN `p_intRentalRequestID` INT, IN `p_intUserLenderID` INT)   BEGIN

    DECLARE intListingID        INT;
    DECLARE intUserBorrowerID   INT;
    DECLARE dtmStartTime        DATETIME;
    DECLARE dtmEndTime          DATETIME;
    DECLARE intReqStatusID      INT;
    DECLARE intBorrowerCardID   INT;
    DECLARE intLenderCardID     INT;
    DECLARE decPricePerDay      DECIMAL(10,2);
    DECLARE decPricePerHour     DECIMAL(10,2);
    DECLARE intRateTypeID       INT;
    DECLARE intRentalID         INT;
    DECLARE intConversationID   INT;
    DECLARE intMessageID        INT;
    DECLARE decHoldAmount       DECIMAL(10,2);
    DECLARE decTotalAmount      DECIMAL(10,2);
    DECLARE intDayCount         INT;
    DECLARE decHourCount        DECIMAL(10,2);
    DECLARE strSysMsg           VARCHAR(500);
    DECLARE dtmLoopDate         DATE;
    DECLARE dtmEndDate          DATE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Step 1: Fetch and validate the rental request
    SELECT rr.ListingID, rr.UserBorrowerID, rr.StartTime, rr.EndTime, rr.RentalStatusID
    INTO   intListingID, intUserBorrowerID, dtmStartTime, dtmEndTime, intReqStatusID
    FROM   TRentalRequests rr
    WHERE  rr.RentalRequestID = p_intRentalRequestID;

    IF intListingID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rental request not found.';
    END IF;

    IF intReqStatusID != 1 THEN    -- 1 = Pending
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This request is no longer pending.';
    END IF;

    -- Step 2: Validate lender owns the listing
    IF NOT EXISTS (
        SELECT 1 FROM TListings
        WHERE ListingID = intListingID AND UserLenderID = p_intUserLenderID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'You do not own this listing.';
    END IF;

    -- Step 3: Get listing pricing
    SELECT PricePerDay, PricePerHour, RateTypeID
    INTO   decPricePerDay, decPricePerHour, intRateTypeID
    FROM   TListings
    WHERE  ListingID = intListingID;

    -- Step 4: Get borrower default card
    SELECT CardID INTO intBorrowerCardID
    FROM   TUserCards
    WHERE  UserID    = intUserBorrowerID
      AND  `Default` = 1
    LIMIT  1;

    IF intBorrowerCardID IS NULL THEN
        SELECT CardID INTO intBorrowerCardID
        FROM   TUserCards
        WHERE  UserID = intUserBorrowerID
        ORDER BY AddedDate DESC
        LIMIT  1;
    END IF;

    IF intBorrowerCardID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Borrower has no payment card on file.';
    END IF;

    -- Step 5: Get lender default card
    SELECT CardID INTO intLenderCardID
    FROM   TUserCards
    WHERE  UserID    = p_intUserLenderID
      AND  `Default` = 1
    LIMIT  1;

    IF intLenderCardID IS NULL THEN
        SELECT CardID INTO intLenderCardID
        FROM   TUserCards
        WHERE  UserID = p_intUserLenderID
        ORDER BY AddedDate DESC
        LIMIT  1;
    END IF;

    IF intLenderCardID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Lender has no payout card on file.';
    END IF;

    -- Step 6: Calculate total and 1% hold
    IF intRateTypeID = 2 OR intRateTypeID = 3 THEN    -- Daily or Both
        SET intDayCount    = DATEDIFF( DATE( dtmEndTime ), DATE( dtmStartTime ) );
        SET decTotalAmount = decPricePerDay * intDayCount;
    ELSE                                               -- Hourly
        SET decHourCount   = TIMESTAMPDIFF( HOUR, dtmStartTime, dtmEndTime );
        SET decTotalAmount = decPricePerHour * decHourCount;
    END IF;

    SET decHoldAmount = ROUND( decTotalAmount * 0.01, 2 );

    -- Step 7: Update request status → Accepted
    UPDATE TRentalRequests
    SET RentalStatusID = 2    -- 2 = Accepted
    WHERE RentalRequestID = p_intRentalRequestID;

    -- Step 8: Create TRentals record
    INSERT INTO TRentals
    (
         ListingID
        ,UserBorrowerID
        ,UserLenderID
        ,RentalRequestID
        ,RentalStatusID
        ,PickUpPhotoURL
        ,ReturnPhotoURL
        ,UserBorrowerCardID
        ,UserLenderCardID
        ,AddedDate
        ,UpdatedDate
    )
    VALUES
    (
         intListingID
        ,intUserBorrowerID
        ,p_intUserLenderID
        ,p_intRentalRequestID
        ,2                      -- 2 = Active
        ,NULL
        ,NULL
        ,intBorrowerCardID
        ,intLenderCardID
        ,NOW()
        ,NOW()
    );

    SET intRentalID = LAST_INSERT_ID();

    -- Step 9: Create TConversations record
    INSERT INTO TConversations
    (
         RentalID
        ,AddedDate
        ,LastMessageDate
    )
    VALUES
    (
         intRentalID
        ,NOW()
        ,NOW()
    );

    SET intConversationID = LAST_INSERT_ID();

    -- Step 10: Create TUserConversations rows for both parties
    INSERT INTO TUserConversations ( ConversationID, UserID, LastReadDate )
    VALUES ( intConversationID, p_intUserLenderID, NOW() );

    INSERT INTO TUserConversations ( ConversationID, UserID, LastReadDate )
    VALUES ( intConversationID, intUserBorrowerID, NULL );

    -- Step 11: Insert system message
    SET strSysMsg = 'Rental request accepted! You can now message each other to arrange pickup.';

    INSERT INTO TMessages
    (
         ConversationID
        ,MessageSenderID
        ,MessageBody
        ,SystemMessage
        ,SentDate
    )
    VALUES
    (
         intConversationID
        ,p_intUserLenderID
        ,strSysMsg
        ,1
        ,NOW()
    );

    SET intMessageID = LAST_INSERT_ID();

    -- Step 12: Insert 1% hold transaction
    INSERT INTO TTransactions
    (
         RentalID
        ,TransactionTypeID
        ,TransactionStatusID
        ,UserBorrowerID
        ,UserLenderID
        ,UserBorrowerCardID
        ,UserLenderCardID
        ,Amount
        ,AddedDate
    )
    VALUES
    (
         intRentalID
        ,1                  -- 1 = Hold
        ,2                  -- 2 = Completed
        ,intUserBorrowerID
        ,p_intUserLenderID
        ,intBorrowerCardID
        ,intLenderCardID
        ,decHoldAmount
        ,NOW()
    );

    -- Step 13: Block rental dates in TListingAvailability
    SET dtmLoopDate = DATE( dtmStartTime );
    SET dtmEndDate  = DATE( dtmEndTime );

    WHILE dtmLoopDate <= dtmEndDate DO
        IF NOT EXISTS (
            SELECT 1 FROM TListingAvailability
            WHERE ListingID       = intListingID
              AND DATE( UnavailableDate ) = dtmLoopDate
        ) THEN
            INSERT INTO TListingAvailability ( ListingID, UnavailableDate, BlockReasonID )
            VALUES ( intListingID, dtmLoopDate, 1 );    -- 1 = Already Rented
        END IF;
        SET dtmLoopDate = DATE_ADD( dtmLoopDate, INTERVAL 1 DAY );
    END WHILE;

    -- Step 14: Update listing status → Rented
    UPDATE TListings
    SET ListingStatusID = 2    -- 2 = Rented
    WHERE ListingID = intListingID;

    -- Step 15: Notify borrower
    CALL uspSendNotification(
         intUserBorrowerID
        ,3                          -- NotificationTypeID: Request Accepted
        ,p_intRentalRequestID
        ,intConversationID
        ,NULL
        ,NULL
        ,intRentalID
        ,NULL
        ,'Your rental request was accepted!'
    );

    COMMIT;

    SELECT
         intRentalID       AS RentalID
        ,intConversationID AS ConversationID
        ,decHoldAmount     AS HoldAmount
        ,decTotalAmount    AS TotalRentalAmount
        ,'Rental accepted. Conversation created and security hold charged.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspAddListingPhoto` (IN `p_intListingID` INT, IN `p_intUserLenderID` INT, IN `p_strPhotoURL` VARCHAR(500), IN `p_intSortOrder` INT)   BEGIN

    DECLARE intNextSort INT DEFAULT 1;

    -- Validate listing ownership
    IF NOT EXISTS (
        SELECT 1 FROM TListings
        WHERE ListingID = p_intListingID AND UserLenderID = p_intUserLenderID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Listing not found or you do not own this listing.';
    END IF;

    -- Auto-assign sort order if not provided
    IF p_intSortOrder IS NULL THEN
        SELECT COALESCE( MAX( SortOrder ), 0 ) + 1 INTO intNextSort
        FROM TListingPhotos
        WHERE ListingID = p_intListingID;
    ELSE
        SET intNextSort = p_intSortOrder;
    END IF;

    INSERT INTO TListingPhotos
    (
         ListingID
        ,PhotoURL
        ,SortOrder
        ,AddedDate
    )
    VALUES
    (
         p_intListingID
        ,p_strPhotoURL
        ,intNextSort
        ,NOW()
    );

    SELECT LAST_INSERT_ID() AS ListingPhotoID, 'Photo added successfully.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspAddUserCard` (IN `p_intUserID` INT, IN `p_strPayToken` VARCHAR(255), IN `p_intCardTypeID` INT, IN `p_strLastFour` VARCHAR(4), IN `p_strExpMonth` VARCHAR(2), IN `p_strExpYear` VARCHAR(4), IN `p_strCVC` VARCHAR(4))   BEGIN

    DECLARE intCardCount  INT     DEFAULT 0;
    DECLARE blnIsDefault  TINYINT DEFAULT 0;

    -- Validate user exists and is active
    IF NOT EXISTS ( SELECT 1 FROM TUsers WHERE UserID = p_intUserID AND AccountStatus = 1 ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'User not found or account is inactive.';
    END IF;

    -- Validate card type
    IF NOT EXISTS ( SELECT 1 FROM TCardTypes WHERE CardTypeID = p_intCardTypeID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid card type.';
    END IF;

    -- Auto-set default if this is the user's first card
    SELECT COUNT(*) INTO intCardCount
    FROM TUserCards
    WHERE UserID = p_intUserID;

    IF intCardCount = 0 THEN
        SET blnIsDefault = 1;
    END IF;

    INSERT INTO TUserCards
    (
         UserID
        ,PaymentToken
        ,CardTypeID
        ,LastFourDigits
        ,ExpirationMonth
        ,ExpirationYear
        ,CVC
        ,`Default`
        ,AddedDate
    )
    VALUES
    (
         p_intUserID
        ,p_strPayToken
        ,p_intCardTypeID
        ,p_strLastFour
        ,p_strExpMonth
        ,p_strExpYear
        ,p_strCVC
        ,blnIsDefault
        ,NOW()
    );

    SELECT LAST_INSERT_ID() AS CardID, 'Card added successfully.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspApproveRentalExtension` (IN `p_intRentalExtensionID` INT, IN `p_intUserLenderID` INT)   BEGIN

    DECLARE intRentalID         INT;
    DECLARE intExtStatusID      INT;
    DECLARE intUserBorrowerID   INT;
    DECLARE intBorrowerCardID   INT;
    DECLARE intLenderCardID     INT;
    DECLARE dtmNewEndDate       DATE;
    DECLARE dtmCurrentEndDate   DATE;
    DECLARE intListingID        INT;
    DECLARE decPricePerDay      DECIMAL(10,2);
    DECLARE intRateTypeID       INT;
    DECLARE intExtraDays        INT;
    DECLARE decExtraCharge      DECIMAL(10,2);
    DECLARE dtmLoopDate         DATE;
    DECLARE intRequestID        INT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Fetch and validate the extension request
    SELECT re.RentalID, re.ExtensionStatusID, re.EndDate
    INTO   intRentalID, intExtStatusID, dtmNewEndDate
    FROM   TRentalExtensions re
    WHERE  re.RentalExtensionID = p_intRentalExtensionID;

    IF intRentalID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Extension request not found.';
    END IF;

    IF intExtStatusID != 1 THEN    -- 1 = Pending
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This extension request is no longer pending.';
    END IF;

    -- Validate lender owns this rental
    SELECT r.UserBorrowerID, r.UserBorrowerCardID, r.UserLenderCardID
          ,r.ListingID, r.RentalRequestID
    INTO   intUserBorrowerID, intBorrowerCardID, intLenderCardID
          ,intListingID, intRequestID
    FROM   TRentals r
    WHERE  r.RentalID      = intRentalID
      AND  r.UserLenderID  = p_intUserLenderID;

    IF intUserBorrowerID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'You do not own this rental.';
    END IF;

    -- Get current end date from original request
    SELECT DATE( EndTime ) INTO dtmCurrentEndDate
    FROM   TRentalRequests
    WHERE  RentalRequestID = intRequestID;

    -- Get listing pricing
    SELECT PricePerDay, RateTypeID
    INTO   decPricePerDay, intRateTypeID
    FROM   TListings
    WHERE  ListingID = intListingID;

    -- Calculate additional charge for extended days
    SET intExtraDays   = DATEDIFF( dtmNewEndDate, dtmCurrentEndDate );
    SET decExtraCharge = decPricePerDay * intExtraDays;

    -- Approve extension
    UPDATE TRentalExtensions
    SET ExtensionStatusID = 2    -- 2 = Approved
    WHERE RentalExtensionID = p_intRentalExtensionID;

    -- Update rental request end time to new end date
    UPDATE TRentalRequests
    SET EndTime = TIMESTAMP( dtmNewEndDate, '23:59:59' )
    WHERE RentalRequestID = intRequestID;

    -- Charge borrower for additional days
    INSERT INTO TTransactions
    (
         RentalID
        ,TransactionTypeID
        ,TransactionStatusID
        ,UserBorrowerID
        ,UserLenderID
        ,UserBorrowerCardID
        ,UserLenderCardID
        ,Amount
        ,AddedDate
    )
    VALUES
    (
         intRentalID
        ,2              -- 2 = Final Charge
        ,2              -- 2 = Completed
        ,intUserBorrowerID
        ,p_intUserLenderID
        ,intBorrowerCardID
        ,intLenderCardID
        ,decExtraCharge
        ,NOW()
    );

    -- Block new dates in availability calendar
    SET dtmLoopDate = DATE_ADD( dtmCurrentEndDate, INTERVAL 1 DAY );
    WHILE dtmLoopDate <= dtmNewEndDate DO
        IF NOT EXISTS (
            SELECT 1 FROM TListingAvailability
            WHERE ListingID       = intListingID
              AND DATE( UnavailableDate ) = dtmLoopDate
        ) THEN
            INSERT INTO TListingAvailability ( ListingID, UnavailableDate, BlockReasonID )
            VALUES ( intListingID, dtmLoopDate, 1 );    -- 1 = Already Rented
        END IF;
        SET dtmLoopDate = DATE_ADD( dtmLoopDate, INTERVAL 1 DAY );
    END WHILE;

    -- Notify borrower
    CALL uspSendNotification(
         intUserBorrowerID
        ,8                          -- NotificationTypeID: Extension Approved
        ,NULL, NULL, NULL
        ,p_intRentalExtensionID
        ,intRentalID
        ,NULL
        ,'Your rental extension has been approved.'
    );

    COMMIT;

    SELECT 'Extension approved. Additional charge processed.' AS Message
          ,decExtraCharge AS AdditionalCharge;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspBlockListingDate` (IN `p_intListingID` INT, IN `p_intUserLenderID` INT, IN `p_dtmUnavailDate` DATE, IN `p_intBlockReasonID` INT)   BEGIN

    -- Validate listing ownership
    IF NOT EXISTS (
        SELECT 1 FROM TListings
        WHERE ListingID = p_intListingID AND UserLenderID = p_intUserLenderID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Listing not found or you do not own this listing.';
    END IF;

    -- Check date is not already blocked
    IF EXISTS (
        SELECT 1 FROM TListingAvailability
        WHERE ListingID      = p_intListingID
          AND DATE( UnavailableDate ) = p_dtmUnavailDate
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This date is already blocked.';
    END IF;

    -- Validate block reason
    IF NOT EXISTS ( SELECT 1 FROM TBlockReasons WHERE BlockReasonID = p_intBlockReasonID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid block reason.';
    END IF;

    INSERT INTO TListingAvailability
    (
         ListingID
        ,UnavailableDate
        ,BlockReasonID
    )
    VALUES
    (
         p_intListingID
        ,p_dtmUnavailDate
        ,p_intBlockReasonID
    );

    SELECT LAST_INSERT_ID() AS ListingAvailabilityID, 'Date blocked successfully.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspCancelRentalRequest` (IN `p_intRentalRequestID` INT, IN `p_intUserBorrowerID` INT)   BEGIN

    DECLARE intStatusID INT;

    -- Validate request belongs to this borrower
    SELECT RentalStatusID INTO intStatusID
    FROM   TRentalRequests
    WHERE  RentalRequestID = p_intRentalRequestID
      AND  UserBorrowerID  = p_intUserBorrowerID;

    IF intStatusID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rental request not found.';
    END IF;

    IF intStatusID != 1 THEN    -- 1 = Pending
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only pending requests can be cancelled.';
    END IF;

    UPDATE TRentalRequests
    SET RentalStatusID = 4    -- 4 = Cancelled
    WHERE RentalRequestID = p_intRentalRequestID;

    SELECT 'Rental request cancelled.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspCompleteRentalTransactions` (IN `p_intRentalID` INT)   BEGIN

    DECLARE intUserBorrowerID   INT;
    DECLARE intUserLenderID     INT;
    DECLARE intBorrowerCardID   INT;
    DECLARE intLenderCardID     INT;
    DECLARE intListingID        INT;
    DECLARE dtmStartTime        DATETIME;
    DECLARE dtmEndTime          DATETIME;
    DECLARE decPricePerDay      DECIMAL(10,2);
    DECLARE decPricePerHour     DECIMAL(10,2);
    DECLARE intRateTypeID       INT;
    DECLARE decTotalAmount      DECIMAL(10,2);
    DECLARE decHoldAmount       DECIMAL(10,2);
    DECLARE intDayCount         INT;
    DECLARE decHourCount        DECIMAL(10,2);
    DECLARE intRequestID        INT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Fetch rental details
    SELECT r.UserBorrowerID, r.UserLenderID, r.UserBorrowerCardID, r.UserLenderCardID
          ,r.ListingID, r.RentalRequestID
    INTO   intUserBorrowerID, intUserLenderID, intBorrowerCardID, intLenderCardID
          ,intListingID, intRequestID
    FROM   TRentals r
    WHERE  r.RentalID = p_intRentalID;

    -- Fetch rental dates from original request
    SELECT StartTime, EndTime
    INTO   dtmStartTime, dtmEndTime
    FROM   TRentalRequests
    WHERE  RentalRequestID = intRequestID;

    -- Fetch listing pricing
    SELECT PricePerDay, PricePerHour, RateTypeID
    INTO   decPricePerDay, decPricePerHour, intRateTypeID
    FROM   TListings
    WHERE  ListingID = intListingID;

    -- Calculate total amount
    IF intRateTypeID = 2 OR intRateTypeID = 3 THEN
        SET intDayCount    = DATEDIFF( DATE( dtmEndTime ), DATE( dtmStartTime ) );
        SET decTotalAmount = decPricePerDay * intDayCount;
    ELSE
        SET decHourCount   = TIMESTAMPDIFF( HOUR, dtmStartTime, dtmEndTime );
        SET decTotalAmount = decPricePerHour * decHourCount;
    END IF;

    SET decHoldAmount = ROUND( decTotalAmount * 0.01, 2 );

    -- Transaction 1: Final Charge on borrower
    INSERT INTO TTransactions
    (
         RentalID
        ,TransactionTypeID
        ,TransactionStatusID
        ,UserBorrowerID
        ,UserLenderID
        ,UserBorrowerCardID
        ,UserLenderCardID
        ,Amount
        ,AddedDate
    )
    VALUES
    (
         p_intRentalID
        ,2              -- 2 = Final Charge
        ,2              -- 2 = Completed
        ,intUserBorrowerID
        ,intUserLenderID
        ,intBorrowerCardID
        ,intLenderCardID
        ,decTotalAmount
        ,NOW()
    );

    -- Transaction 2: Payout to lender
    INSERT INTO TTransactions
    (
         RentalID
        ,TransactionTypeID
        ,TransactionStatusID
        ,UserBorrowerID
        ,UserLenderID
        ,UserBorrowerCardID
        ,UserLenderCardID
        ,Amount
        ,AddedDate
    )
    VALUES
    (
         p_intRentalID
        ,3              -- 3 = Payout
        ,2              -- 2 = Completed
        ,intUserBorrowerID
        ,intUserLenderID
        ,intBorrowerCardID
        ,intLenderCardID
        ,decTotalAmount
        ,NOW()
    );

    -- Transaction 3: Refund 1% hold to borrower
    INSERT INTO TTransactions
    (
         RentalID
        ,TransactionTypeID
        ,TransactionStatusID
        ,UserBorrowerID
        ,UserLenderID
        ,UserBorrowerCardID
        ,UserLenderCardID
        ,Amount
        ,AddedDate
    )
    VALUES
    (
         p_intRentalID
        ,4              -- 4 = Hold Refund
        ,2              -- 2 = Completed
        ,intUserBorrowerID
        ,intUserLenderID
        ,intBorrowerCardID
        ,intLenderCardID
        ,decHoldAmount
        ,NOW()
    );

    -- Restore listing to Available
    UPDATE TListings
    SET ListingStatusID = 1    -- 1 = Available
    WHERE ListingID = intListingID;

    -- Remove rental-blocked dates from availability calendar
    DELETE la
    FROM   TListingAvailability la
    WHERE  la.ListingID      = intListingID
      AND  la.BlockReasonID  = 1    -- 1 = Already Rented
      AND  la.UnavailableDate >= DATE( dtmStartTime )
      AND  la.UnavailableDate <= DATE( dtmEndTime );

    COMMIT;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspConfirmPickup` (IN `p_intRentalID` INT, IN `p_intUserBorrowerID` INT, IN `p_strPickUpPhotoURL` VARCHAR(500))   BEGIN

    DECLARE intRentalStatusID INT;
    DECLARE intUserLenderID   INT;

    -- Validate rental belongs to borrower and is Active
    SELECT RentalStatusID, UserLenderID
    INTO   intRentalStatusID, intUserLenderID
    FROM   TRentals
    WHERE  RentalID       = p_intRentalID
      AND  UserBorrowerID = p_intUserBorrowerID;

    IF intRentalStatusID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rental not found.';
    END IF;

    IF intRentalStatusID != 2 THEN    -- 2 = Active
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Pickup can only be confirmed on an Active rental.';
    END IF;

    IF p_strPickUpPhotoURL IS NULL OR p_strPickUpPhotoURL = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A pickup photo URL is required.';
    END IF;

    UPDATE TRentals
    SET
         PickUpPhotoURL = p_strPickUpPhotoURL
        ,RentalStatusID = 3             -- 3 = In Progress
        ,UpdatedDate    = NOW()
    WHERE RentalID = p_intRentalID;

    -- Notify lender
    CALL uspSendNotification(
         intUserLenderID
        ,5                  -- NotificationTypeID: Item Picked Up
        ,NULL, NULL, NULL, NULL
        ,p_intRentalID
        ,NULL
        ,'The borrower has confirmed item pickup.'
    );

    SELECT 'Pickup confirmed. Rental is now In Progress.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspConfirmReturn` (IN `p_intRentalID` INT, IN `p_intUserBorrowerID` INT, IN `p_strReturnPhotoURL` VARCHAR(500))   BEGIN

    DECLARE intRentalStatusID INT;
    DECLARE intUserLenderID   INT;

    -- Validate rental belongs to borrower and is In Progress
    SELECT RentalStatusID, UserLenderID
    INTO   intRentalStatusID, intUserLenderID
    FROM   TRentals
    WHERE  RentalID       = p_intRentalID
      AND  UserBorrowerID = p_intUserBorrowerID;

    IF intRentalStatusID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rental not found.';
    END IF;

    IF intRentalStatusID != 3 THEN    -- 3 = In Progress
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Return can only be confirmed on an In Progress rental.';
    END IF;

    IF p_strReturnPhotoURL IS NULL OR p_strReturnPhotoURL = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A return photo URL is required.';
    END IF;

    UPDATE TRentals
    SET
         ReturnPhotoURL = p_strReturnPhotoURL
        ,RentalStatusID = 4             -- 4 = Completed
        ,UpdatedDate    = NOW()
    WHERE RentalID = p_intRentalID;

    -- Fire all post-return financial transactions
    CALL uspCompleteRentalTransactions( p_intRentalID );

    -- Notify lender
    CALL uspSendNotification(
         intUserLenderID
        ,6                  -- NotificationTypeID: Item Returned
        ,NULL, NULL, NULL, NULL
        ,p_intRentalID
        ,NULL
        ,'The borrower has returned the item. Payment is being processed.'
    );

    SELECT 'Return confirmed. Rental completed and payment processed.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspCreateListing` (IN `p_intUserLenderID` INT, IN `p_intNeighborhoodID` INT, IN `p_intCategoryID` INT, IN `p_strTitle` VARCHAR(255), IN `p_strDescription` TEXT, IN `p_intConditionID` INT, IN `p_intRateTypeID` INT, IN `p_decPricePerDay` DECIMAL(10,2), IN `p_decPricePerHour` DECIMAL(10,2))   BEGIN

    -- Validate lender exists and is active
    IF NOT EXISTS ( SELECT 1 FROM TUsers WHERE UserID = p_intUserLenderID AND AccountStatus = 1 ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'User not found or account is inactive.';
    END IF;

    -- Validate neighborhood
    IF NOT EXISTS ( SELECT 1 FROM TNeighborhoods WHERE NeighborhoodID = p_intNeighborhoodID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid neighborhood.';
    END IF;

    -- Validate category
    IF NOT EXISTS ( SELECT 1 FROM TCategories WHERE CategoryID = p_intCategoryID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid category.';
    END IF;

    -- Validate condition
    IF NOT EXISTS ( SELECT 1 FROM TConditions WHERE ConditionID = p_intConditionID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid item condition.';
    END IF;

    -- Validate rate type
    IF NOT EXISTS ( SELECT 1 FROM TRateTypes WHERE RateTypeID = p_intRateTypeID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid rate type.';
    END IF;

    -- Enforce pricing rules
    IF p_intRateTypeID = 1 AND ( p_decPricePerHour IS NULL OR p_decPricePerHour <= 0 ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Hourly rate type requires a valid Price Per Hour.';
    END IF;

    IF p_intRateTypeID = 2 AND ( p_decPricePerDay IS NULL OR p_decPricePerDay <= 0 ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Daily rate type requires a valid Price Per Day.';
    END IF;

    IF p_intRateTypeID = 3 AND (
           p_decPricePerDay  IS NULL OR p_decPricePerDay  <= 0
        OR p_decPricePerHour IS NULL OR p_decPricePerHour <= 0
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Both rate type requires a valid Price Per Day AND Price Per Hour.';
    END IF;

    INSERT INTO TListings
    (
         UserLenderID
        ,NeighborhoodID
        ,CategoryID
        ,ListingStatusID
        ,Title
        ,Description
        ,ConditionID
        ,RateTypeID
        ,PricePerDay
        ,PricePerHour
        ,AddedDate
    )
    VALUES
    (
         p_intUserLenderID
        ,p_intNeighborhoodID
        ,p_intCategoryID
        ,1                      -- 1 = Available
        ,p_strTitle
        ,p_strDescription
        ,p_intConditionID
        ,p_intRateTypeID
        ,p_decPricePerDay
        ,p_decPricePerHour
        ,NOW()
    );

    SELECT LAST_INSERT_ID() AS ListingID, 'Listing created successfully.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspDeactivateListing` (IN `p_intListingID` INT, IN `p_intUserLenderID` INT)   BEGIN

    -- Validate ownership
    IF NOT EXISTS (
        SELECT 1 FROM TListings
        WHERE ListingID = p_intListingID AND UserLenderID = p_intUserLenderID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Listing not found or you do not own this listing.';
    END IF;

    -- Block if active or pending rentals exist
    IF EXISTS (
        SELECT 1 FROM TRentals
        WHERE ListingID = p_intListingID
          AND RentalStatusID NOT IN ( 4, 5 )    -- 4=Completed, 5=Cancelled
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot deactivate — listing has active or pending rentals.';
    END IF;

    UPDATE TListings
    SET ListingStatusID = 3    -- 3 = Inactive
    WHERE ListingID = p_intListingID;

    SELECT 'Listing deactivated.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspDeclineRentalRequest` (IN `p_intRentalRequestID` INT, IN `p_intUserLenderID` INT)   BEGIN

    DECLARE intListingID       INT;
    DECLARE intUserBorrowerID  INT;
    DECLARE intReqStatusID     INT;

    -- Fetch and validate request belongs to this lender's listing
    SELECT rr.ListingID, rr.UserBorrowerID, rr.RentalStatusID
    INTO   intListingID, intUserBorrowerID, intReqStatusID
    FROM   TRentalRequests rr
    JOIN   TListings l ON rr.ListingID = l.ListingID
    WHERE  rr.RentalRequestID = p_intRentalRequestID
      AND  l.UserLenderID     = p_intUserLenderID;

    IF intListingID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rental request not found or you do not own this listing.';
    END IF;

    IF intReqStatusID != 1 THEN    -- 1 = Pending
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only pending requests can be declined.';
    END IF;

    UPDATE TRentalRequests
    SET RentalStatusID = 3    -- 3 = Declined
    WHERE RentalRequestID = p_intRentalRequestID;

    -- Notify borrower
    CALL uspSendNotification(
         intUserBorrowerID
        ,4                          -- NotificationTypeID: Request Declined
        ,p_intRentalRequestID
        ,NULL, NULL, NULL, NULL, NULL
        ,'Your rental request was declined.'
    );

    SELECT 'Rental request declined.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspDenyRentalExtension` (IN `p_intRentalExtensionID` INT, IN `p_intUserLenderID` INT)   BEGIN

    DECLARE intRentalID       INT;
    DECLARE intExtStatusID    INT;
    DECLARE intUserBorrowerID INT;

    -- Fetch extension and validate lender ownership
    SELECT re.RentalID, re.ExtensionStatusID, r.UserBorrowerID
    INTO   intRentalID, intExtStatusID, intUserBorrowerID
    FROM   TRentalExtensions re
    JOIN   TRentals r ON re.RentalID = r.RentalID
    WHERE  re.RentalExtensionID = p_intRentalExtensionID
      AND  r.UserLenderID       = p_intUserLenderID;

    IF intRentalID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Extension request not found or you do not own this rental.';
    END IF;

    IF intExtStatusID != 1 THEN    -- 1 = Pending
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This extension request is no longer pending.';
    END IF;

    UPDATE TRentalExtensions
    SET ExtensionStatusID = 3    -- 3 = Denied
    WHERE RentalExtensionID = p_intRentalExtensionID;

    -- Notify borrower
    CALL uspSendNotification(
         intUserBorrowerID
        ,9                          -- NotificationTypeID: Extension Denied
        ,NULL, NULL, NULL
        ,p_intRentalExtensionID
        ,intRentalID
        ,NULL
        ,'Your rental extension request was denied.'
    );

    SELECT 'Extension denied.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspMarkConversationRead` (IN `p_intConversationID` INT, IN `p_intUserID` INT)   BEGIN

    -- Validate participation
    IF NOT EXISTS (
        SELECT 1 FROM TUserConversations
        WHERE ConversationID = p_intConversationID
          AND UserID         = p_intUserID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Conversation not found for this user.';
    END IF;

    UPDATE TUserConversations
    SET LastReadDate = NOW()
    WHERE ConversationID = p_intConversationID
      AND UserID         = p_intUserID;

    SELECT 'Conversation marked as read.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspRemoveListingPhoto` (IN `p_intListingPhotoID` INT, IN `p_intUserLenderID` INT)   BEGIN

    DECLARE intListingID INT;
    DECLARE intSortOrder INT;

    -- Validate photo belongs to a listing owned by this user
    SELECT lp.ListingID, lp.SortOrder
    INTO   intListingID, intSortOrder
    FROM   TListingPhotos lp
    JOIN   TListings l ON lp.ListingID = l.ListingID
    WHERE  lp.ListingPhotoID = p_intListingPhotoID
      AND  l.UserLenderID    = p_intUserLenderID
    LIMIT 1;

    IF intListingID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Photo not found or you do not own this listing.';
    END IF;

    DELETE FROM TListingPhotos WHERE ListingPhotoID = p_intListingPhotoID;

    -- Promote next photo to primary if primary was deleted
    IF intSortOrder = 1 THEN
        UPDATE TListingPhotos
        SET    SortOrder = 1
        WHERE  ListingID = intListingID
        ORDER BY SortOrder ASC
        LIMIT  1;
    END IF;

    SELECT 'Photo removed.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspRemoveUserCard` (IN `p_intUserID` INT, IN `p_intCardID` INT)   BEGIN

    DECLARE intActiveCount  INT     DEFAULT 0;
    DECLARE blnIsDefault    TINYINT DEFAULT 0;

    -- Validate card belongs to this user
    IF NOT EXISTS (
        SELECT 1 FROM TUserCards
        WHERE CardID = p_intCardID AND UserID = p_intUserID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Card not found for this user.';
    END IF;

    -- Block removal if card is on an active or pending rental
    SELECT COUNT(*) INTO intActiveCount
    FROM TRentals
    WHERE ( UserBorrowerCardID = p_intCardID OR UserLenderCardID = p_intCardID )
      AND RentalStatusID NOT IN ( 4, 5 );    -- 4=Completed, 5=Cancelled

    IF intActiveCount > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot remove card — it is tied to an active rental.';
    END IF;

    -- Check if removing the default card
    SELECT `Default` INTO blnIsDefault
    FROM TUserCards WHERE CardID = p_intCardID;

    DELETE FROM TUserCards WHERE CardID = p_intCardID;

    -- Auto-promote next most recent card to default if needed
    IF blnIsDefault = 1 THEN
        UPDATE TUserCards
        SET `Default` = 1
        WHERE UserID = p_intUserID
        ORDER BY AddedDate DESC
        LIMIT 1;
    END IF;

    SELECT 'Card removed successfully.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspRequestRentalExtension` (IN `p_intRentalID` INT, IN `p_intUserBorrowerID` INT, IN `p_dtmNewEndDate` DATE)   BEGIN

    DECLARE intUserLenderID   INT;
    DECLARE intListingID      INT;
    DECLARE intRentalStatusID INT;
    DECLARE dtmCurrentEndDate DATE;
    DECLARE intBlockedCount   INT DEFAULT 0;
    DECLARE intRequestID      INT;
    DECLARE intExtensionID    INT;

    -- Validate rental and retrieve details
    SELECT r.UserLenderID, r.ListingID, r.RentalStatusID
          ,DATE( req.EndTime ), r.RentalRequestID
    INTO   intUserLenderID, intListingID, intRentalStatusID
          ,dtmCurrentEndDate, intRequestID
    FROM   TRentals r
    JOIN   TRentalRequests req ON r.RentalRequestID = req.RentalRequestID
    WHERE  r.RentalID       = p_intRentalID
      AND  r.UserBorrowerID = p_intUserBorrowerID;

    IF intUserLenderID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rental not found.';
    END IF;

    IF intRentalStatusID NOT IN ( 2, 3 ) THEN    -- 2=Active, 3=In Progress
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Extensions can only be requested on active rentals.';
    END IF;

    IF p_dtmNewEndDate <= dtmCurrentEndDate THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'New end date must be after the current end date.';
    END IF;

    -- Block if a pending extension already exists for this rental
    IF EXISTS (
        SELECT 1 FROM TRentalExtensions
        WHERE RentalID           = p_intRentalID
          AND ExtensionStatusID  = 1    -- 1 = Pending
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A pending extension request already exists for this rental.';
    END IF;

    -- Check extension dates are not blocked
    SELECT COUNT(*) INTO intBlockedCount
    FROM   TListingAvailability
    WHERE  ListingID        = intListingID
      AND  DATE( UnavailableDate ) >  dtmCurrentEndDate
      AND  DATE( UnavailableDate ) <= p_dtmNewEndDate;

    IF intBlockedCount > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'One or more extension dates are unavailable.';
    END IF;

    INSERT INTO TRentalExtensions
    (
         RentalID
        ,ExtensionStatusID
        ,EndDate
        ,RequestDate
    )
    VALUES
    (
         p_intRentalID
        ,1                  -- 1 = Pending
        ,p_dtmNewEndDate
        ,NOW()
    );

    SET intExtensionID = LAST_INSERT_ID();

    -- Notify lender of extension request
    CALL uspSendNotification(
         intUserLenderID
        ,7                  -- NotificationTypeID: Extension Requested
        ,NULL, NULL, NULL
        ,intExtensionID
        ,p_intRentalID
        ,NULL
        ,'The borrower has requested a rental extension.'
    );

    SELECT intExtensionID AS RentalExtensionID, 'Extension request submitted.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspSendMessage` (IN `p_intConversationID` INT, IN `p_intSenderUserID` INT, IN `p_strMessageBody` TEXT)   BEGIN

    DECLARE intRecipientID    INT;
    DECLARE intMessageID      INT;
    DECLARE intIsParticipant  INT DEFAULT 0;

    -- Validate sender is a participant in this conversation
    SELECT COUNT(*) INTO intIsParticipant
    FROM   TUserConversations
    WHERE  ConversationID = p_intConversationID
      AND  UserID         = p_intSenderUserID;

    IF intIsParticipant = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'You are not a participant in this conversation.';
    END IF;

    IF p_strMessageBody IS NULL OR TRIM( p_strMessageBody ) = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Message body cannot be empty.';
    END IF;

    INSERT INTO TMessages
    (
         ConversationID
        ,MessageSenderID
        ,MessageBody
        ,SystemMessage
        ,SentDate
    )
    VALUES
    (
         p_intConversationID
        ,p_intSenderUserID
        ,p_strMessageBody
        ,0
        ,NOW()
    );

    SET intMessageID = LAST_INSERT_ID();

    -- Update conversation last message date
    UPDATE TConversations
    SET LastMessageDate = NOW()
    WHERE ConversationID = p_intConversationID;

    -- Find the other participant to notify
    SELECT UserID INTO intRecipientID
    FROM   TUserConversations
    WHERE  ConversationID = p_intConversationID
      AND  UserID         != p_intSenderUserID
    LIMIT  1;

    -- Notify recipient — MessageID enables deep-link to the exact message
    CALL uspSendNotification(
         intRecipientID
        ,1                          -- NotificationTypeID: New Message
        ,NULL
        ,p_intConversationID
        ,intMessageID               -- Deep-link to specific message
        ,NULL, NULL, NULL
        ,'You have a new message.'
    );

    SELECT intMessageID AS MessageID, 'Message sent.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspSendNotification` (IN `p_intUserID` INT, IN `p_intNotifTypeID` INT, IN `p_intRentalRequestID` INT, IN `p_intConversationID` INT, IN `p_intMessageID` INT, IN `p_intRentalExtensionID` INT, IN `p_intRentalID` INT, IN `p_intReviewID` INT, IN `p_strMessage` VARCHAR(500))   BEGIN

    INSERT INTO TNotifications
    (
         UserID
        ,NotificationTypeID
        ,RentalRequestID
        ,ConversationID
        ,MessageID
        ,RentalExtensionID
        ,RentalID
        ,ReviewID
        ,Message
        ,ReadStatus
        ,AddedDate
    )
    VALUES
    (
         p_intUserID
        ,p_intNotifTypeID
        ,p_intRentalRequestID
        ,p_intConversationID
        ,p_intMessageID
        ,p_intRentalExtensionID
        ,p_intRentalID
        ,p_intReviewID
        ,p_strMessage
        ,0
        ,NOW()
    );

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspSetDefaultCard` (IN `p_intUserID` INT, IN `p_intCardID` INT)   BEGIN

    -- Validate card belongs to this user
    IF NOT EXISTS (
        SELECT 1 FROM TUserCards
        WHERE CardID = p_intCardID AND UserID = p_intUserID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Card not found for this user.';
    END IF;

    -- Clear default on all cards for this user
    UPDATE TUserCards
    SET `Default` = 0
    WHERE UserID = p_intUserID;

    -- Set chosen card as default
    UPDATE TUserCards
    SET `Default` = 1
    WHERE CardID = p_intCardID;

    SELECT 'Default card updated.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspSubmitRentalRequest` (IN `p_intListingID` INT, IN `p_intUserBorrowerID` INT, IN `p_dtmStartTime` DATETIME, IN `p_dtmEndTime` DATETIME)   BEGIN

    DECLARE intLenderID     INT;
    DECLARE intStatusID     INT;
    DECLARE intBlockedCount INT DEFAULT 0;
    DECLARE intCardCount    INT DEFAULT 0;
    DECLARE intRequestID    INT;

    -- Validate listing exists and is Available
    SELECT UserLenderID, ListingStatusID
    INTO   intLenderID, intStatusID
    FROM   TListings
    WHERE  ListingID = p_intListingID;

    IF intLenderID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Listing not found.';
    END IF;

    IF intStatusID != 1 THEN    -- 1 = Available
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This listing is not currently available.';
    END IF;

    -- Borrower cannot rent their own listing
    IF p_intUserBorrowerID = intLenderID THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'You cannot rent your own listing.';
    END IF;

    -- Validate end time is after start time
    IF p_dtmEndTime <= p_dtmStartTime THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'End time must be after start time.';
    END IF;

    -- Validate borrower has a payment card on file
    SELECT COUNT(*) INTO intCardCount
    FROM TUserCards
    WHERE UserID = p_intUserBorrowerID;

    IF intCardCount = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'You must have a payment card on file before requesting a rental.';
    END IF;

    -- Check that all requested dates are available
    SELECT COUNT(*) INTO intBlockedCount
    FROM TListingAvailability
    WHERE ListingID        = p_intListingID
      AND UnavailableDate >= DATE( p_dtmStartTime )
      AND UnavailableDate <= DATE( p_dtmEndTime );

    IF intBlockedCount > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'One or more of the requested dates are unavailable.';
    END IF;

    INSERT INTO TRentalRequests
    (
         ListingID
        ,UserBorrowerID
        ,RentalStatusID
        ,StartTime
        ,EndTime
        ,RequestDate
    )
    VALUES
    (
         p_intListingID
        ,p_intUserBorrowerID
        ,1                      -- 1 = Pending
        ,p_dtmStartTime
        ,p_dtmEndTime
        ,NOW()
    );

    SET intRequestID = LAST_INSERT_ID();

    -- Notify the lender of the new request
    CALL uspSendNotification(
         intLenderID
        ,2                  -- NotificationTypeID: Rental Request Received
        ,intRequestID
        ,NULL, NULL, NULL, NULL, NULL
        ,'You have a new rental request.'
    );

    SELECT intRequestID AS RentalRequestID, 'Rental request submitted.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspSubmitReview` (IN `p_intRentalID` INT, IN `p_intUserReviewerID` INT, IN `p_intUserReviewedID` INT, IN `p_intReviewTypeID` INT, IN `p_intRating` INT, IN `p_strReviewText` TEXT)   BEGIN

    DECLARE intStatusID   INT;
    DECLARE intBorrowerID INT;
    DECLARE intLenderID   INT;
    DECLARE intDupeCount  INT DEFAULT 0;
    DECLARE intReviewID   INT;

    -- Validate rental exists and is Completed
    SELECT RentalStatusID, UserBorrowerID, UserLenderID
    INTO   intStatusID, intBorrowerID, intLenderID
    FROM   TRentals
    WHERE  RentalID = p_intRentalID;

    IF intStatusID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rental not found.';
    END IF;

    IF intStatusID != 4 THEN    -- 4 = Completed
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Reviews can only be submitted after a completed rental.';
    END IF;

    -- Validate reviewer is a party to this rental
    IF p_intUserReviewerID != intBorrowerID AND p_intUserReviewerID != intLenderID THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'You are not a party to this rental.';
    END IF;

    -- Validate rating range
    IF p_intRating < 1 OR p_intRating > 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rating must be between 1 and 5.';
    END IF;

    -- Validate review type exists
    IF NOT EXISTS ( SELECT 1 FROM TReviewTypes WHERE ReviewTypeID = p_intReviewTypeID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid review type.';
    END IF;

    -- Check for duplicate (same reviewer, same rental, same review type)
    SELECT COUNT(*) INTO intDupeCount
    FROM   TReviews
    WHERE  RentalID        = p_intRentalID
      AND  UserReviewerID  = p_intUserReviewerID
      AND  ReviewTypeID    = p_intReviewTypeID;

    IF intDupeCount > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'You have already submitted this type of review for this rental.';
    END IF;

    INSERT INTO TReviews
    (
         RentalID
        ,UserReviewerID
        ,UserReviewedID
        ,ReviewTypeID
        ,ReviewRating
        ,ReviewText
        ,AddedDate
    )
    VALUES
    (
         p_intRentalID
        ,p_intUserReviewerID
        ,p_intUserReviewedID
        ,p_intReviewTypeID
        ,p_intRating
        ,p_strReviewText
        ,NOW()
    );

    SET intReviewID = LAST_INSERT_ID();

    -- Notify the person being reviewed
    CALL uspSendNotification(
         p_intUserReviewedID
        ,10                     -- NotificationTypeID: New Review Received
        ,NULL, NULL, NULL, NULL
        ,p_intRentalID
        ,intReviewID
        ,'You received a new review.'
    );

    SELECT intReviewID AS ReviewID, 'Review submitted.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspUnblockListingDate` (IN `p_intListingAvailID` INT, IN `p_intUserLenderID` INT)   BEGIN

    DECLARE intBlockReasonID INT;
    DECLARE intListingID     INT;

    -- Validate ownership and retrieve block reason
    SELECT la.BlockReasonID, la.ListingID
    INTO   intBlockReasonID, intListingID
    FROM   TListingAvailability la
    JOIN   TListings l ON la.ListingID = l.ListingID
    WHERE  la.ListingAvailabilityID = p_intListingAvailID
      AND  l.UserLenderID           = p_intUserLenderID
    LIMIT 1;

    IF intListingID IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Availability record not found or you do not own this listing.';
    END IF;

    -- Block removal if date is reserved by an active rental
    IF intBlockReasonID = 1 THEN    -- 1 = Already Rented
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot unblock — this date is reserved by an active rental.';
    END IF;

    DELETE FROM TListingAvailability
    WHERE ListingAvailabilityID = p_intListingAvailID;

    SELECT 'Date unblocked.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspUpdateListing` (IN `p_intListingID` INT, IN `p_intUserLenderID` INT, IN `p_intNeighborhoodID` INT, IN `p_intCategoryID` INT, IN `p_strTitle` VARCHAR(255), IN `p_strDescription` TEXT, IN `p_intConditionID` INT, IN `p_intRateTypeID` INT, IN `p_decPricePerDay` DECIMAL(10,2), IN `p_decPricePerHour` DECIMAL(10,2))   BEGIN

    DECLARE intRateTypeID  INT;
    DECLARE decPriceDay    DECIMAL(10,2);
    DECLARE decPriceHour   DECIMAL(10,2);

    -- Validate listing belongs to this lender
    IF NOT EXISTS (
        SELECT 1 FROM TListings
        WHERE ListingID = p_intListingID AND UserLenderID = p_intUserLenderID
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Listing not found or you do not own this listing.';
    END IF;

    -- Block edits on inactive listings
    IF EXISTS (
        SELECT 1 FROM TListings
        WHERE ListingID = p_intListingID AND ListingStatusID = 3   -- 3 = Inactive
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot edit an inactive listing.';
    END IF;

    -- Apply updates (COALESCE preserves existing value when NULL is passed)
    UPDATE TListings
    SET
         NeighborhoodID = COALESCE( p_intNeighborhoodID, NeighborhoodID )
        ,CategoryID     = COALESCE( p_intCategoryID,     CategoryID )
        ,Title          = COALESCE( p_strTitle,          Title )
        ,Description    = COALESCE( p_strDescription,    Description )
        ,ConditionID    = COALESCE( p_intConditionID,    ConditionID )
        ,RateTypeID     = COALESCE( p_intRateTypeID,     RateTypeID )
        ,PricePerDay    = COALESCE( p_decPricePerDay,    PricePerDay )
        ,PricePerHour   = COALESCE( p_decPricePerHour,   PricePerHour )
    WHERE ListingID = p_intListingID;

    -- Re-validate pricing consistency after update
    SELECT RateTypeID, PricePerDay, PricePerHour
    INTO   intRateTypeID, decPriceDay, decPriceHour
    FROM   TListings
    WHERE  ListingID = p_intListingID;

    IF intRateTypeID = 1 AND ( decPriceHour IS NULL OR decPriceHour <= 0 ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Pricing error: Hourly rate type requires a valid Price Per Hour.';
    END IF;

    IF intRateTypeID = 2 AND ( decPriceDay IS NULL OR decPriceDay <= 0 ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Pricing error: Daily rate type requires a valid Price Per Day.';
    END IF;

    IF intRateTypeID = 3 AND (
           decPriceDay  IS NULL OR decPriceDay  <= 0
        OR decPriceHour IS NULL OR decPriceHour <= 0
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Pricing error: Both rate type requires a valid Price Per Day AND Price Per Hour.';
    END IF;

    SELECT 'Listing updated successfully.' AS Message;

END$$

CREATE DEFINER=`thecommu_nkahsaydb`@`localhost` PROCEDURE `uspUpdateUserProfile` (IN `p_intUserID` INT, IN `p_strFirstName` VARCHAR(100), IN `p_strLastName` VARCHAR(100), IN `p_strPhoneNumber` VARCHAR(20), IN `p_intGenderID` INT, IN `p_strProfilePicURL` VARCHAR(500), IN `p_strBio` TEXT, IN `p_intNeighborhoodID` INT)   BEGIN

    -- Validate user exists
    IF NOT EXISTS ( SELECT 1 FROM TUsers WHERE UserID = p_intUserID ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'User not found.';
    END IF;

    UPDATE TUsers
    SET
         FirstName         = COALESCE( p_strFirstName,      FirstName )
        ,LastName          = COALESCE( p_strLastName,       LastName )
        ,PhoneNumber       = COALESCE( p_strPhoneNumber,    PhoneNumber )
        ,GenderID          = COALESCE( p_intGenderID,       GenderID )
        ,ProfilePictureURL = COALESCE( p_strProfilePicURL,  ProfilePictureURL )
        ,Bio               = COALESCE( p_strBio,            Bio )
        ,NeighborhoodID    = COALESCE( p_intNeighborhoodID, NeighborhoodID )
    WHERE UserID = p_intUserID;

    SELECT 'Profile updated successfully.' AS Message;

END$$

DELIMITER ;

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
  `Condition` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TConditions`
--

INSERT INTO `TConditions` (`ConditionID`, `Condition`) VALUES
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
  `Gender` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TGenders`
--

INSERT INTO `TGenders` (`GenderID`, `Gender`) VALUES
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
  `Status` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TListingStatuses`
--

INSERT INTO `TListingStatuses` (`ListingStatusID`, `Status`) VALUES
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
  `City` varchar(255) DEFAULT NULL,
  `StateID` int(11) NOT NULL,
  `ZipCode` varchar(255) NOT NULL,
  `CenterLatitude` varchar(255) DEFAULT NULL,
  `CenterLongitude` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `TNeighborhoods`
--

INSERT INTO `TNeighborhoods` (`NeighborhoodID`, `NeighborhoodName`, `City`, `StateID`, `ZipCode`, `CenterLatitude`, `CenterLongitude`) VALUES
(1, 'Avondale', 'Cincinnati', 1, '45229', '39.1331', '-84.5008'),
(2, 'Bond Hill', 'Cincinnati', 1, '45237', '39.1753', '-84.4689'),
(3, 'California', 'Cincinnati', 1, '45223', '39.1556', '-84.5403'),
(4, 'Camp Washington', 'Cincinnati', 1, '45225', '39.1331', '-84.5403'),
(5, 'Carthage', 'Cincinnati', 1, '45212', '39.1903', '-84.5264'),
(6, 'Clifton', 'Cincinnati', 1, '45220', '39.1342', '-84.5186'),
(7, 'College Hill', 'Cincinnati', 1, '45224', '39.1919', '-84.5503'),
(8, 'Columbia-Tusculum', 'Cincinnati', 1, '45226', '39.1103', '-84.4253'),
(9, 'Corryville', 'Cincinnati', 1, '45219', '39.1342', '-84.5133'),
(10, 'CUF (Clifton Heights, University Heights, Fairview)', 'Cincinnati', 1, '45219', '39.1403', '-84.5208'),
(11, 'Downtown', 'Cincinnati', 1, '45202', '39.1031', '-84.5120'),
(12, 'East End', 'Cincinnati', 1, '45226', '39.1086', '-84.4442'),
(13, 'East Price Hill', 'Cincinnati', 1, '45205', '39.1200', '-84.5758'),
(14, 'East Walnut Hills', 'Cincinnati', 1, '45206', '39.1242', '-84.4831'),
(15, 'East Westwood', 'Cincinnati', 1, '45216', '39.1586', '-84.5686'),
(16, 'English Woods', 'Cincinnati', 1, '45216', '39.1664', '-84.5597'),
(17, 'Evanston', 'Cincinnati', 1, '45207', '39.1450', '-84.4936'),
(18, 'Fairview', 'Cincinnati', 1, '45219', '39.1431', '-84.5231'),
(19, 'Hartwell', 'Cincinnati', 1, '45216', '39.1753', '-84.5231'),
(20, 'Hyde Park', 'Cincinnati', 1, '45208', '39.1414', '-84.4436'),
(21, 'Kennedy Heights', 'Cincinnati', 1, '45213', '39.1753', '-84.4531'),
(22, 'Linwood', 'Cincinnati', 1, '45227', '39.1242', '-84.4550'),
(23, 'Lower Price Hill', 'Cincinnati', 1, '45204', '39.1031', '-84.5381'),
(24, 'Madisonville', 'Cincinnati', 1, '45227', '39.1456', '-84.4328'),
(25, 'Millvale', 'Cincinnati', 1, '45215', '39.1786', '-84.5131'),
(26, 'Mount Adams', 'Cincinnati', 1, '45202', '39.1086', '-84.5000'),
(27, 'Mount Airy', 'Cincinnati', 1, '45223', '39.1753', '-84.5517'),
(28, 'Mount Auburn', 'Cincinnati', 1, '45219', '39.1186', '-84.5186'),
(29, 'Mount Lookout', 'Cincinnati', 1, '45208', '39.1314', '-84.4419'),
(30, 'Mount Washington', 'Cincinnati', 1, '45230', '39.0953', '-84.4242'),
(31, 'North Avondale', 'Cincinnati', 1, '45229', '39.1514', '-84.4911'),
(32, 'Northside', 'Cincinnati', 1, '45223', '39.1664', '-84.5381'),
(33, 'Oakley', 'Cincinnati', 1, '45209', '39.1456', '-84.4242'),
(34, 'Over-the-Rhine', 'Cincinnati', 1, '45202', '39.1142', '-84.5186'),
(35, 'Paddock Hills', 'Cincinnati', 1, '45229', '39.1553', '-84.4775'),
(36, 'Pendleton', 'Cincinnati', 1, '45202', '39.1114', '-84.4986'),
(37, 'Pleasant Ridge', 'Cincinnati', 1, '45213', '39.1642', '-84.4658'),
(38, 'Price Hill', 'Cincinnati', 1, '45205', '39.1200', '-84.5758'),
(39, 'Riverside', 'Cincinnati', 1, '45202', '39.1031', '-84.4897'),
(40, 'Roselawn', 'Cincinnati', 1, '45237', '39.1836', '-84.4831'),
(41, 'Sayler Park', 'Cincinnati', 1, '45233', '39.1142', '-84.6278'),
(42, 'Sedamsville', 'Cincinnati', 1, '45204', '39.0953', '-84.5403'),
(43, 'South Cumminsville', 'Cincinnati', 1, '45214', '39.1456', '-84.5436'),
(44, 'South Fairmount', 'Cincinnati', 1, '45223', '39.1364', '-84.5531'),
(45, 'Spring Grove Village', 'Cincinnati', 1, '45214', '39.1586', '-84.5453'),
(46, 'The Banks', 'Cincinnati', 1, '45202', '39.0975', '-84.5064'),
(47, 'Walnut Hills', 'Cincinnati', 1, '45206', '39.1242', '-84.4950'),
(48, 'West End', 'Cincinnati', 1, '45203', '39.1142', '-84.5264'),
(49, 'West Price Hill', 'Cincinnati', 1, '45205', '39.1114', '-84.5842'),
(50, 'Westwood', 'Cincinnati', 1, '45238', '39.1531', '-84.6042'),
(51, 'Western Hills', 'Cincinnati', 1, '45238', '39.1531', '-84.6042'),
(52, 'Winton Hills', 'Cincinnati', 1, '45232', '39.1686', '-84.5381'),
(53, 'Winton Place', 'Cincinnati', 1, '45232', '39.1753', '-84.5319'),
(54, 'Anderson Township', NULL, 1, '45255', '39.0975', '-84.3653'),
(55, 'Blue Ash', NULL, 1, '45242', '39.2320', '-84.3783'),
(56, 'Bridgetown', NULL, 1, '45248', '39.1314', '-84.6314'),
(57, 'Cheviot', NULL, 1, '45211', '39.1575', '-84.6136'),
(58, 'Deer Park', NULL, 1, '45236', '39.2053', '-84.3981'),
(59, 'Delhi Township', NULL, 1, '45238', '39.0897', '-84.6192'),
(60, 'Forest Park', NULL, 1, '45240', '39.2903', '-84.5203'),
(61, 'Golf Manor', NULL, 1, '45237', '39.1869', '-84.4450'),
(62, 'Green Township', NULL, 1, '45247', '39.1342', '-84.6514'),
(63, 'Greenhills', NULL, 1, '45218', '39.2697', '-84.5203'),
(64, 'Harrison', NULL, 1, '45030', '39.2620', '-84.8219'),
(65, 'Loveland', NULL, 1, '45140', '39.2689', '-84.2638'),
(66, 'Madeira', NULL, 1, '45243', '39.1903', '-84.3628'),
(67, 'Mariemont', NULL, 1, '45227', '39.1456', '-84.3736'),
(68, 'Montgomery', NULL, 1, '45242', '39.2264', '-84.3547'),
(69, 'Newtown', NULL, 1, '45244', '39.1203', '-84.3631'),
(70, 'Norwood', NULL, 1, '45212', '39.1558', '-84.4597'),
(71, 'Reading', NULL, 1, '45215', '39.2236', '-84.4419'),
(72, 'Sharonville', NULL, 1, '45241', '39.2681', '-84.4133'),
(73, 'Silverton', NULL, 1, '45236', '39.1919', '-84.3997'),
(74, 'Springdale', NULL, 1, '45246', '39.2869', '-84.4853'),
(75, 'St. Bernard', NULL, 1, '45217', '39.1675', '-84.4986'),
(76, 'Sycamore Township', NULL, 1, '45236', '39.2181', '-84.3686'),
(77, 'Wyoming', NULL, 1, '45215', '39.2286', '-84.4658'),
(78, 'Bellevue', NULL, 2, '41073', '39.1075', '-84.4808'),
(79, 'Covington', NULL, 2, '41011', '39.0836', '-84.5086'),
(80, 'Dayton', NULL, 2, '41074', '39.1114', '-84.4703'),
(81, 'Erlanger', NULL, 2, '41018', '39.0167', '-84.6008'),
(82, 'Florence', NULL, 2, '41042', '39.0072', '-84.6267'),
(83, 'Fort Mitchell', NULL, 2, '41017', '39.0486', '-84.5464'),
(84, 'Fort Thomas', NULL, 2, '41075', '39.0753', '-84.4478'),
(85, 'Fort Wright', NULL, 2, '41011', '39.0528', '-84.5300'),
(86, 'Highland Heights', NULL, 2, '41076', '39.0336', '-84.4558'),
(87, 'Independence', NULL, 2, '41051', '38.9431', '-84.5439'),
(88, 'Lakeside Park', NULL, 2, '41017', '39.0400', '-84.5606'),
(89, 'Ludlow', NULL, 2, '41016', '39.0928', '-84.5464'),
(90, 'Newport', NULL, 2, '41071', '39.0917', '-84.4950'),
(91, 'Park Hills', NULL, 2, '41011', '39.0700', '-84.5381'),
(92, 'Villa Hills', NULL, 2, '41017', '39.0633', '-84.5906'),
(93, 'Wilder', NULL, 2, '41071', '39.0753', '-84.4808'),
(94, 'Aurora', NULL, 3, '47001', '39.0569', '-84.9047'),
(95, 'Greendale', NULL, 3, '47025', '39.1136', '-84.8647'),
(96, 'Hidden Valley', NULL, 3, '47025', '39.0736', '-84.8603'),
(97, 'Lawrenceburg', NULL, 3, '47025', '39.0911', '-84.9000');

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
  `AddressLine1` varchar(255) DEFAULT NULL,
  `AddressLine2` varchar(255) DEFAULT NULL,
  `StateID` int(11) DEFAULT NULL,
  `ZipCode` varchar(5) DEFAULT NULL,
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

INSERT INTO `TUsers` (`UserID`, `FirstName`, `LastName`, `Email`, `Password`, `PhoneNumber`, `AddressLine1`, `AddressLine2`, `StateID`, `ZipCode`, `GenderID`, `ProfilePictureURL`, `Bio`, `NeighborhoodID`, `AddedDate`, `AccountStatus`) VALUES
(1, 'Sarah', 'Johnson', 'sarah.johnson1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550101', NULL, NULL, NULL, NULL, 2, NULL, 'New to the community!', 1, '2026-02-28 15:03:13', 1),
(2, 'Mike', 'Brown', 'mike.brown1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550102', NULL, NULL, NULL, NULL, 1, NULL, 'DIY and tools.', 2, '2026-02-28 15:03:13', 1),
(3, 'Emily', 'Davis', 'emily.davis1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550103', NULL, NULL, NULL, NULL, 2, NULL, 'Party supplies lender.', 3, '2026-02-28 15:03:13', 1),
(4, 'David', 'Wilson', 'david.wilson1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550104', NULL, NULL, NULL, NULL, 1, NULL, 'Always happy to help.', 18, '2026-02-28 15:03:13', 1),
(5, 'Ava', 'Martinez', 'ava.martinez1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550105', NULL, NULL, NULL, NULL, 3, NULL, 'Gardening fan.', 19, '2026-02-28 15:03:13', 1),
(6, 'Olivia', 'Taylor', 'olivia.taylor1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550106', NULL, NULL, NULL, NULL, 2, NULL, 'Loves community sharing.', 10, '2026-02-28 15:04:17', 1),
(7, 'Ethan', 'Moore', 'ethan.moore1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550107', NULL, NULL, NULL, NULL, 1, NULL, 'Tool and equipment lender.', 11, '2026-02-28 15:04:17', 1),
(8, 'Sophia', 'Clark', 'sophia.clark1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550108', NULL, NULL, NULL, NULL, 2, NULL, 'Party planner supplies.', 12, '2026-02-28 15:04:17', 1),
(9, 'Liam', 'Walker', 'liam.walker1@example.com', '$2y$10$LzGtkMhW7lQ2uGK9tfHH3eK1mJvsmGCx0X8IXQhBxir2LcXJIlkqq', '5135550109', NULL, NULL, NULL, NULL, 1, NULL, 'Always renting camping gear.', 13, '2026-02-28 15:04:17', 1),
(10, 'Mia', 'Hall', 'mia.hall1@example.com', 'testing12345', '5135550110', NULL, NULL, NULL, NULL, 2, NULL, 'Neighborhood organizer.', 14, '2026-02-28 15:04:17', 1),
(12, 'Courtney', 'Frasier', 'frasier_cs@yahoo.com', '$2y$12$G7Z/AESB.4/kSWqiPA/xHuzB35sPgZvDnRHMUpK/EylxPmfuJmCRG', '5133077119', NULL, NULL, NULL, NULL, 3, NULL, NULL, 1, '2026-03-04 20:34:40', 1),
(13, 'Testing', 'Account', 'testingaccount@testingaccount.com', '$2y$12$4ZOlRVLOGkHo0O6tYiFYQeLuQZqTQFoGFTOMSOwFHly6noHQFHWIe', '5133077119', NULL, NULL, NULL, NULL, 2, NULL, NULL, 13, '2026-03-06 22:15:31', 1),
(14, 'Testing', 'Account', 'testingacct@testingacct.com', '$2y$12$hh48R5E8flResBdUiIqoUO91aDBfBqZ9Z5y5dfVgKI6jrhnnsZfsy', '5133333333', NULL, NULL, NULL, NULL, 1, NULL, NULL, 13, '2026-03-06 22:27:29', 1),
(15, 'Another', 'Account', 'anotheraccount@anotheraccount.com', '$2y$12$LeIy4hMzUdFZNIOJVoZoW.4qgIfF3Pgt3vD5SohxnHbHVGoQk8mSi', '5133333333', NULL, NULL, NULL, NULL, 2, NULL, NULL, 9, '2026-03-06 22:28:24', 1),
(16, 'Jaxson', 'Test', 'JaxDeHave@yahoo.com', '$2y$12$1vlUrCvVQ.T.rf5sujLZ.utAppQMCwQlD3v0yAbL8CMMEpxwyeOpS', '51321221212', NULL, NULL, NULL, NULL, 1, NULL, NULL, 16, '2026-03-07 15:43:48', 1),
(17, 'J', 'M', 'Jm@yahoo.com', '$2y$12$bw9XK51q5LAeY4qj7pCiaunXVeGj3DWou3jjz4evrNcgwa3CDitUC', '123123123', NULL, NULL, NULL, NULL, 2, NULL, NULL, 18, '2026-03-07 15:53:28', 1);

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
  ADD KEY `NeighborhoodID` (`NeighborhoodID`),
  ADD KEY `FK_Users_States` (`StateID`);

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
  MODIFY `NeighborhoodID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

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
  MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `TUsers`
--
ALTER TABLE `TUsers`
  ADD CONSTRAINT `FK_Users_States` FOREIGN KEY (`StateID`) REFERENCES `TStates` (`StateID`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

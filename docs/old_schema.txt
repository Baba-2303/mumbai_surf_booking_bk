-- ============================================================================
-- MUMBAI SURF BOOKING - DATABASE SCHEMA
-- ============================================================================
-- Description: Complete database schema for surf/water sports booking system
-- Features: Activity bookings, Package bookings, Stay bookings, Slot management
-- Version: 1.0
-- Created: 2024
-- ============================================================================

-- Drop tables if they exist (in reverse order of dependencies)
DROP TABLE IF EXISTS `package_person_sessions`;
DROP TABLE IF EXISTS `package_sessions`;
DROP TABLE IF EXISTS `slot_activity_availability`;
DROP TABLE IF EXISTS `slot_activities`;
DROP TABLE IF EXISTS `activity_bookings`;
DROP TABLE IF EXISTS `package_bookings`;
DROP TABLE IF EXISTS `stay_bookings`;
DROP TABLE IF EXISTS `booking_people`;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `admin_users`;
DROP TABLE IF EXISTS `slots`;

-- ============================================================================
-- ADMIN USERS TABLE
-- ============================================================================
-- Purpose: Stores admin/staff user accounts for backend access
-- Features: Username-based auth, password hashing, activity tracking
-- ============================================================================

CREATE TABLE `admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL COMMENT 'Unique username for login',
  `password_hash` varchar(255) NOT NULL COMMENT 'Hashed password (bcrypt/argon2)',
  `email` varchar(255) NOT NULL COMMENT 'Admin email address',
  `full_name` varchar(255) NOT NULL COMMENT 'Full name of admin user',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Account status: 1=active, 0=disabled',
  `last_login` timestamp NULL DEFAULT NULL COMMENT 'Last successful login timestamp',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Account creation timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Admin user accounts for system management';

-- ============================================================================
-- CUSTOMERS TABLE
-- ============================================================================
-- Purpose: Stores customer information for bookings
-- Features: Contact details, unique email constraint, timestamp tracking
-- ============================================================================

CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT 'Customer full name',
  `email` varchar(255) NOT NULL COMMENT 'Customer email (unique identifier)',
  `phone` varchar(20) NOT NULL COMMENT 'Customer phone number',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'First booking/registration date',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_phone` (`phone`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Customer contact and identification information';

-- ============================================================================
-- SLOTS TABLE
-- ============================================================================
-- Purpose: Defines available time slots for activities by day of week
-- Features: Recurring weekly schedule, day-based organization
-- Note: day_of_week: 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 
--       5=Friday, 6=Saturday, 7=Sunday
-- ============================================================================

CREATE TABLE `slots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `day_of_week` tinyint NOT NULL COMMENT '1=Monday, 2=Tuesday, ... 7=Sunday',
  `start_time` time NOT NULL COMMENT 'Slot start time',
  `end_time` time NOT NULL COMMENT 'Slot end time',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Slot availability: 1=active, 0=inactive',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_day_time` (`day_of_week`,`start_time`),
  KEY `idx_day_active` (`day_of_week`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Time slots configuration for activities (recurring weekly schedule)';

-- ============================================================================
-- SLOT ACTIVITIES TABLE
-- ============================================================================
-- Purpose: Defines which activities are available in each slot with capacity
-- Features: Activity-specific capacity per slot, unique slot-activity pairs
-- ============================================================================

CREATE TABLE `slot_activities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slot_id` int NOT NULL COMMENT 'Reference to slots table',
  `activity_type` enum('surf','sup','kayak') NOT NULL COMMENT 'Activity type available in this slot',
  `max_capacity` int NOT NULL DEFAULT '0' COMMENT 'Maximum people allowed for this activity in this slot',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slot_activity` (`slot_id`,`activity_type`),
  CONSTRAINT `slot_activities_ibfk_1` FOREIGN KEY (`slot_id`) 
    REFERENCES `slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Activity availability and capacity configuration per slot';

-- ============================================================================
-- SLOT ACTIVITY AVAILABILITY TABLE
-- ============================================================================
-- Purpose: Tracks daily bookings and capacity for each slot-activity combination
-- Features: Real-time availability tracking, prevents overbooking
-- ============================================================================

CREATE TABLE `slot_activity_availability` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slot_id` int NOT NULL COMMENT 'Reference to slots table',
  `booking_date` date NOT NULL COMMENT 'Specific date for this availability record',
  `activity_type` enum('surf','sup','kayak') NOT NULL COMMENT 'Activity type',
  `booked_count` int DEFAULT '0' COMMENT 'Number of people currently booked',
  `max_capacity` int NOT NULL COMMENT 'Maximum capacity for this date (copied from slot_activities)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slot_date_activity` (`slot_id`,`booking_date`,`activity_type`),
  KEY `idx_date_activity` (`booking_date`,`activity_type`),
  KEY `idx_slot_date` (`slot_id`,`booking_date`),
  KEY `idx_date_slot_activity` (`booking_date`,`slot_id`,`activity_type`),
  CONSTRAINT `slot_activity_availability_ibfk_1` FOREIGN KEY (`slot_id`) 
    REFERENCES `slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Daily availability tracking for slot-activity combinations';

-- ============================================================================
-- BOOKINGS TABLE (Master/Parent Table)
-- ============================================================================
-- Purpose: Main booking record for all booking types
-- Features: Payment tracking, GST calculation, booking status management
-- Booking Types:
--   - activity: Single activity session booking
--   - package: Multi-night stay with multiple activity sessions
--   - stay_only: Accommodation without activities
-- ============================================================================

CREATE TABLE `bookings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL COMMENT 'Reference to customers table',
  `booking_type` enum('activity','package','stay_only') NOT NULL COMMENT 'Type of booking',
  `total_people` int NOT NULL COMMENT 'Total number of people in booking',
  `base_amount` decimal(10,2) NOT NULL COMMENT 'Base price before GST',
  `gst_amount` decimal(10,2) NOT NULL COMMENT 'GST amount calculated',
  `total_amount` decimal(10,2) NOT NULL COMMENT 'Final amount (base + GST)',
  `payment_status` enum('pending','completed','failed') DEFAULT 'pending' COMMENT 'Payment status',
  `payment_id` varchar(255) DEFAULT NULL COMMENT 'Payment gateway transaction ID',
  `razorpay_order_id` varchar(255) DEFAULT NULL COMMENT 'Razorpay order ID',
  `booking_status` enum('confirmed','completed','cancelled') DEFAULT 'confirmed' COMMENT 'Booking status',
  `notes` text COMMENT 'Additional notes or special requests',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Booking creation timestamp',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_booking_type` (`booking_type`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_created_date` (`created_at`),
  KEY `idx_booking_status` (`booking_status`),
  KEY `idx_customer_payment` (`customer_id`,`payment_status`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`customer_id`) 
    REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Master booking table for all types of bookings';

-- ============================================================================
-- BOOKING PEOPLE TABLE
-- ============================================================================
-- Purpose: Stores individual participants in a booking
-- Features: Per-person details including age and activity preference
-- Note: Used by both activity bookings and package bookings
-- ============================================================================

CREATE TABLE `booking_people` (
  `id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL COMMENT 'Reference to bookings table',
  `name` varchar(255) NOT NULL COMMENT 'Participant full name',
  `age` int NOT NULL COMMENT 'Participant age (for safety/grouping)',
  `activity_type` enum('surf','sup','kayak') NOT NULL COMMENT 'Preferred/assigned activity',
  PRIMARY KEY (`id`),
  KEY `idx_booking_people` (`booking_id`),
  KEY `idx_booking_activity` (`booking_id`,`activity_type`),
  CONSTRAINT `booking_people_ibfk_1` FOREIGN KEY (`booking_id`) 
    REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Individual participants information for bookings';

-- ============================================================================
-- ACTIVITY BOOKINGS TABLE
-- ============================================================================
-- Purpose: Stores single activity session bookings (booking_type='activity')
-- Features: One-time activity sessions without accommodation
-- ============================================================================

CREATE TABLE `activity_bookings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL COMMENT 'Reference to bookings table',
  `activity_type` enum('surf','sup','kayak') NOT NULL DEFAULT 'surf' COMMENT 'Activity type for this booking',
  `session_date` date NOT NULL COMMENT 'Date of activity session',
  `slot_id` int NOT NULL COMMENT 'Time slot for activity',
  PRIMARY KEY (`id`),
  KEY `slot_id` (`slot_id`),
  KEY `idx_session_date` (`session_date`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_session_activity` (`session_date`,`activity_type`),
  KEY `idx_booking_slot` (`booking_id`,`slot_id`),
  CONSTRAINT `activity_bookings_ibfk_1` FOREIGN KEY (`booking_id`) 
    REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_bookings_ibfk_2` FOREIGN KEY (`slot_id`) 
    REFERENCES `slots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Single activity session bookings without accommodation';

-- ============================================================================
-- PACKAGE BOOKINGS TABLE
-- ============================================================================
-- Purpose: Stores package bookings (booking_type='package')
-- Features: Multi-night stays with multiple activity sessions
-- Package Types:
--   - 1_night_1_session: 1 night stay + 1 activity session
--   - 1_night_2_sessions: 1 night stay + 2 activity sessions
--   - 2_nights_3_sessions: 2 nights stay + 3 activity sessions
-- ============================================================================

CREATE TABLE `package_bookings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL COMMENT 'Reference to bookings table',
  `package_type` enum('1_night_1_session','1_night_2_sessions','2_nights_3_sessions') NOT NULL COMMENT 'Package type',
  `accommodation_type` enum('tent','dorm','cottage') NOT NULL COMMENT 'Type of accommodation',
  `service_type` enum('surf','sup') NOT NULL COMMENT 'Primary activity service type',
  `check_in_date` date NOT NULL COMMENT 'Package check-in date',
  `check_out_date` date NOT NULL COMMENT 'Package check-out date',
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `idx_check_in` (`check_in_date`),
  KEY `idx_check_out` (`check_out_date`),
  KEY `idx_package_type` (`package_type`),
  KEY `idx_accommodation` (`accommodation_type`),
  CONSTRAINT `package_bookings_ibfk_1` FOREIGN KEY (`booking_id`) 
    REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Package bookings with accommodation and multiple activity sessions';

-- ============================================================================
-- PACKAGE SESSIONS TABLE
-- ============================================================================
-- Purpose: Stores scheduled sessions for package bookings
-- Features: Links package bookings to specific date/time slots
-- Note: Session numbers indicate order (1st session, 2nd session, etc.)
-- ============================================================================

CREATE TABLE `package_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `package_booking_id` int NOT NULL COMMENT 'Reference to package_bookings table',
  `session_date` date NOT NULL COMMENT 'Date of this session',
  `slot_id` int NOT NULL COMMENT 'Time slot for this session',
  `session_number` int NOT NULL COMMENT '1st session, 2nd session, etc.',
  PRIMARY KEY (`id`),
  KEY `slot_id` (`slot_id`),
  KEY `idx_package_date` (`package_booking_id`,`session_date`),
  KEY `idx_session_slot` (`session_date`,`slot_id`),
  CONSTRAINT `package_sessions_ibfk_1` FOREIGN KEY (`package_booking_id`) 
    REFERENCES `package_bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `package_sessions_ibfk_2` FOREIGN KEY (`slot_id`) 
    REFERENCES `slots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Scheduled sessions for package bookings';

-- ============================================================================
-- PACKAGE PERSON SESSIONS TABLE
-- ============================================================================
-- Purpose: Tracks individual participant activities for each package session
-- Features: Per-person, per-session activity assignment and scheduling
-- Use Case: Allows different people in same booking to do different activities
--           in different sessions (e.g., Person A does surf in session 1, 
--           Person B does SUP in session 1, etc.)
-- ============================================================================

CREATE TABLE `package_person_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `package_booking_id` int NOT NULL COMMENT 'References package_bookings.id',
  `booking_person_id` int NOT NULL COMMENT 'References booking_people.id',
  `session_number` int NOT NULL COMMENT 'Session number (1, 2, or 3)',
  `session_date` date NOT NULL COMMENT 'Date of this session',
  `slot_id` int NOT NULL COMMENT 'Slot for this session',
  `activity_type` enum('surf','sup') NOT NULL COMMENT 'Activity this person does in this session',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_package_person` (`package_booking_id`,`booking_person_id`),
  KEY `idx_session_activity` (`session_date`,`slot_id`,`activity_type`),
  KEY `idx_booking_person` (`booking_person_id`),
  KEY `idx_slot` (`slot_id`),
  CONSTRAINT `package_person_sessions_ibfk_1` FOREIGN KEY (`package_booking_id`) 
    REFERENCES `package_bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `package_person_sessions_ibfk_2` FOREIGN KEY (`booking_person_id`) 
    REFERENCES `booking_people` (`id`) ON DELETE CASCADE,
  CONSTRAINT `package_person_sessions_ibfk_3` FOREIGN KEY (`slot_id`) 
    REFERENCES `slots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Tracks which person does which activity in each package session';

-- ============================================================================
-- STAY BOOKINGS TABLE
-- ============================================================================
-- Purpose: Stores accommodation-only bookings (booking_type='stay_only')
-- Features: Accommodation without activity sessions, meal options
-- ============================================================================

CREATE TABLE `stay_bookings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL COMMENT 'Reference to bookings table',
  `accommodation_type` enum('tent','dorm','cottage') NOT NULL COMMENT 'Type of accommodation',
  `check_in_date` date NOT NULL COMMENT 'Check-in date',
  `check_out_date` date NOT NULL COMMENT 'Check-out date',
  `includes_dinner` tinyint(1) DEFAULT '0' COMMENT 'Dinner included: 1=yes, 0=no',
  `includes_breakfast` tinyint(1) DEFAULT '0' COMMENT 'Breakfast included: 1=yes, 0=no',
  `nights_count` int NOT NULL COMMENT 'Number of nights booked',
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `idx_stay_check_in` (`check_in_date`),
  KEY `idx_stay_check_out` (`check_out_date`),
  KEY `idx_stay_accommodation` (`accommodation_type`),
  CONSTRAINT `stay_bookings_ibfk_1` FOREIGN KEY (`booking_id`) 
    REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Accommodation-only bookings without activities';

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================
-- Notes:
-- 1. All timestamps use CURRENT_TIMESTAMP for automatic tracking
-- 2. Foreign keys use ON DELETE CASCADE to maintain referential integrity
-- 3. Indexes are optimized for common query patterns
-- 4. ENUM types ensure data consistency for predefined values
-- 5. Comments provide context for each field and table
-- ============================================================================


-- Password: admin123 (hashed with bcrypt cost 12)
INSERT INTO `admin_users` (`username`, `password_hash`, `email`, `full_name`, `is_active`) 
VALUES (
  'admin',
  '$2a$12$vqwbzH8PQiPNM1iwOW8EvO3A0iqzrU86Kl0RSMUXCSVFIF.OLAhS6',
  'admin@mumbaisurfclub.com',
  'System Administrator',
  1
);

-- ============================================================================
-- STEP 5: INSERT DEFAULT SLOTS (Monday to Sunday, 3 slots per day)
-- ============================================================================

-- Monday (day_of_week = 1)
INSERT INTO `slots` (`day_of_week`, `start_time`, `end_time`, `is_active`) VALUES
(1, '07:30:00', '09:00:00', 1),
(1, '09:30:00', '11:00:00', 1),
(1, '11:30:00', '13:00:00', 1);

-- Tuesday (day_of_week = 2)
INSERT INTO `slots` (`day_of_week`, `start_time`, `end_time`, `is_active`) VALUES
(2, '07:30:00', '09:00:00', 1),
(2, '09:30:00', '11:00:00', 1),
(2, '11:30:00', '13:00:00', 1);

-- Wednesday (day_of_week = 3)
INSERT INTO `slots` (`day_of_week`, `start_time`, `end_time`, `is_active`) VALUES
(3, '07:30:00', '09:00:00', 1),
(3, '09:30:00', '11:00:00', 1),
(3, '11:30:00', '13:00:00', 1);

-- Thursday (day_of_week = 4)
INSERT INTO `slots` (`day_of_week`, `start_time`, `end_time`, `is_active`) VALUES
(4, '07:30:00', '09:00:00', 1),
(4, '09:30:00', '11:00:00', 1),
(4, '11:30:00', '13:00:00', 1);

-- Friday (day_of_week = 5)
INSERT INTO `slots` (`day_of_week`, `start_time`, `end_time`, `is_active`) VALUES
(5, '07:30:00', '09:00:00', 1),
(5, '09:30:00', '11:00:00', 1),
(5, '11:30:00', '13:00:00', 1);

-- Saturday (day_of_week = 6)
INSERT INTO `slots` (`day_of_week`, `start_time`, `end_time`, `is_active`) VALUES
(6, '07:30:00', '09:00:00', 1),
(6, '09:30:00', '11:00:00', 1),
(6, '11:30:00', '13:00:00', 1);

-- Sunday (day_of_week = 7)
INSERT INTO `slots` (`day_of_week`, `start_time`, `end_time`, `is_active`) VALUES
(7, '07:30:00', '09:00:00', 1),
(7, '09:30:00', '11:00:00', 1),
(7, '11:30:00', '13:00:00', 1);

-- ============================================================================
-- STEP 6: INSERT ACTIVITY CAPACITIES FOR ALL SLOTS
-- ============================================================================

-- For each slot (21 slots total), add capacity for surf, sup, and kayak
-- Slot IDs will be 1-21 after the above inserts

-- Surf capacity (40 per slot)
INSERT INTO `slot_activities` (`slot_id`, `activity_type`, `max_capacity`)
SELECT `id`, 'surf', 40 FROM `slots`;

-- SUP capacity (12 per slot)
INSERT INTO `slot_activities` (`slot_id`, `activity_type`, `max_capacity`)
SELECT `id`, 'sup', 12 FROM `slots`;

-- Kayak capacity (2 per slot)
INSERT INTO `slot_activities` (`slot_id`, `activity_type`, `max_capacity`)
SELECT `id`, 'kayak', 2 FROM `slots`;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Verify slots created
SELECT 
    day_of_week,
    COUNT(*) as slots_per_day,
    GROUP_CONCAT(CONCAT(start_time, '-', end_time) ORDER BY start_time SEPARATOR ', ') as time_slots
FROM slots
GROUP BY day_of_week
ORDER BY day_of_week;

-- Verify activity capacities
SELECT 
    activity_type,
    COUNT(*) as total_slots_with_activity,
    SUM(max_capacity) as total_capacity,
    AVG(max_capacity) as avg_capacity_per_slot
FROM slot_activities
GROUP BY activity_type;

-- Verify admin user created
SELECT id, username, email, full_name, is_active FROM admin_users;

-- Verify new table structure
SHOW CREATE TABLE package_person_sessions;

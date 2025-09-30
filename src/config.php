<?php
/**
 * ============================================================================
 * MUMBAI SURF CLUB - BOOKING SYSTEM CONFIGURATION FILE
 * ============================================================================
 * 
 * PURPOSE: This is the central configuration file for the entire booking system.
 * Think of it as the "control center" where all important settings live.
 * 
 * WHAT IT CONTAINS:
 * - Database connection settings (how to connect to MySQL)
 * - Business rules (prices, capacities, booking windows)
 * - Helper functions (reusable code snippets)
 * - API keys for payment gateway (Razorpay)
 * 
 * WHY IT'S IMPORTANT:
 * - Every other file in the system reads from this file
 * - Change a price here, it updates everywhere automatically
 * - Makes the system easy to maintain and update
 * ============================================================================
 */

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================
// These constants tell PHP how to connect to your MySQL database
// IMPORTANT: define() creates constants that never change during runtime

define('DB_HOST', '127.0.0.1');           // Database server location (127.0.0.1 = your local machine)
define('DB_NAME', 'mumbai_surf_booking'); // Name of the database we created
define('DB_USER', 'booking_user');        // MySQL username with access permissions
define('DB_PASS', 'admin@mumbai');        // Password for the database user
define('DB_CHARSET', 'utf8mb4');          // Character encoding (utf8mb4 supports emojis and special characters)


// ============================================================================
// SITE CONFIGURATION
// ============================================================================
// Basic information about your website

define('SITE_NAME', 'Mumbai Surf Club');
define('SITE_URL', 'https://staging.mumbaisurfclub.com');  // Used for generating links in emails
define('SITE_EMAIL', 'info@mumbaisurfclub.com');           // Default contact email


// ============================================================================
// ENVIRONMENT SETTINGS
// ============================================================================
// Controls whether we show detailed errors or hide them

define('ENVIRONMENT', 'development'); // Options: 'development' or 'production'

// Conditional error display based on environment
if (ENVIRONMENT === 'development') {
    // DEVELOPMENT MODE: Show all errors to help with debugging
    error_reporting(E_ALL);        // Report all types of errors
    ini_set('display_errors', 1);  // Display errors on screen
} else {
    // PRODUCTION MODE: Hide errors from users (log them instead)
    error_reporting(0);            // Don't report errors
    ini_set('display_errors', 0);  // Don't display errors on screen
}


// ============================================================================
// TIMEZONE CONFIGURATION
// ============================================================================
// Sets the default timezone for all date/time functions in PHP

date_default_timezone_set('Asia/Kolkata'); // Indian Standard Time (IST)


// ============================================================================
// SESSION CONFIGURATION
// ============================================================================
// Sessions keep admin users logged in as they navigate between pages

define('ADMIN_SESSION_NAME', 'surf_admin_session'); // Unique name for admin session cookie
define('SESSION_TIMEOUT', 86400);                    // Session expires after 24 hours (86400 seconds)


// ============================================================================
// BOOKING SYSTEM CONFIGURATION
// ============================================================================
// Core business rules for how the booking system operates

define('BOOKING_ADVANCE_DAYS', 7);      // Customers can book up to 7 days in advance
define('SLOT_DURATION_MINUTES', 90);    // Each activity session lasts 90 minutes (1.5 hours)


// ============================================================================
// ACTIVITY CONFIGURATION
// ============================================================================
// Defines all available water sports activities with their properties
// This is an ASSOCIATIVE ARRAY where each activity has a unique key (surf, sup, kayak)

define('ACTIVITY_TYPES', [
    'surf' => [
        'name' => 'Surfing',
        'description' => 'Learn to ride the waves on our surfboards',
        'default_capacity' => 40,  // Maximum 40 people can surf at the same time
        'price_per_person' => 1700 // Base price before GST
    ],
    'sup' => [
        'name' => 'Stand Up Paddling',
        'description' => 'Balance and paddle on a stand-up paddleboard',
        'default_capacity' => 12,  // Maximum 12 people for SUP (requires more space per person)
        'price_per_person' => 1700
    ],
    'kayak' => [
        'name' => 'Kayaking',
        'description' => 'Paddle through calm waters in a kayak',
        'default_capacity' => 2,   // Only 2 kayaks available (very limited)
        'price_per_person' => 1700
    ]
]);

// ============================================================================
// WEEKLY BOOKING WINDOW CONFIGURATION
// ============================================================================
// Controls how far in advance customers can book
// BUSINESS RULE: Booking window runs from TODAY until NEXT MONDAY

define('BOOKING_WINDOW_TYPE', 'weekly');  // 'weekly' = Monday to Monday system
define('WEEK_START_DAY', 'monday');       // Booking week starts on Monday


// ============================================================================
// ACCOMMODATION CAPACITY LIMITS
// ============================================================================
// Defines physical inventory: how many tents, dorms, cottages are available
// This prevents overbooking beyond your actual capacity

define('ACCOMMODATION_CAPACITY', [
    'tent' => [
        'max_people_per_unit' => 1, // Each tent holds 1 person (single occupancy)
        'total_units' => 100,       // You have 100 tents available
        'max_total_capacity' => 100 // Maximum 100 people total in tents
    ],
    'dorm' => [
        'max_people_per_unit' => 1, // Each dorm bed for 1 person
        'total_units' => 100,       // 100 dorm beds available
        'max_total_capacity' => 100 // Maximum 100 people in dorms
    ],
    'cottage' => [
        'max_people_per_unit' => 4, // Each cottage accommodates up to 4 people
        'total_units' => 2,         // Only 2 cottages available (LIMITED!)
        'max_total_capacity' => 8   // Maximum 8 people total across both cottages
    ]
]);


// ============================================================================
// PRICING CONFIGURATION (All prices in Indian Rupees - INR)
// ============================================================================

define('SURF_SUP_BASE_PRICE', 1700); // Standard price for single activity sessions
define('GST_RATE', 0.18);            // 18% GST (Goods and Services Tax) - Indian tax law


// ----------------------------------------------------------------------------
// PACKAGE PRICES (Stay + Activity Combo Deals)
// ----------------------------------------------------------------------------
// Prices vary by: package type (nights/sessions) + accommodation type + number of people
// For cottages: pricing is tiered based on occupancy (1, 2, 3, or 4 people)

define('PACKAGE_PRICES', [
    '1_night_1_session' => [        // 1 night stay + 1 surf/SUP session
        'tent' => 3000,             // Per person for tent
        'dorm' => 3250,             // Per person for dorm (slightly higher - has AC)
        'cottage_1' => 9000,        // Cottage with 1 person
        'cottage_2' => 10500,       // Cottage with 2 people
        'cottage_3' => 12750,       // Cottage with 3 people
        'cottage_4' => 15000        // Cottage with 4 people (full capacity)
    ],
    '1_night_2_sessions' => [       // 1 night stay + 2 surf/SUP sessions
        'tent' => 5000,
        'dorm' => 5000,
        'cottage_1' => 10000,
        'cottage_2' => 14000,
        'cottage_3' => 18000,
        'cottage_4' => 22000
    ],
    '2_nights_3_sessions' => [      // 2 nights stay + 3 surf/SUP sessions (most popular)
        'tent' => 8000,
        'dorm' => 8000,
        'cottage_1' => 18000,
        'cottage_2' => 24000,
        'cottage_3' => 30000,
        'cottage_4' => 36000
    ]
]);


// ----------------------------------------------------------------------------
// STAY-ONLY PRICES (Accommodation without activities)
// ----------------------------------------------------------------------------
// Prices per person per night (except cottage which is per unit)

define('STAY_PRICES', [
    'tent' => [
        'without_meals' => 1000,    // Just tent, no food
        'with_meals' => 1500        // Tent + breakfast + dinner
    ],
    'dorm' => [
        'without_meals' => 1200,    // Just dorm bed
        'with_meals' => 1700        // Dorm + meals
    ],
    'cottage' => [
        'base_price' => 6000,              // Per cottage per night (regardless of occupancy)
        'meal_price_per_person' => 500     // Add ₹500 per person for meals
    ]
]);


// ----------------------------------------------------------------------------
// EXTENDED ADVENTURE (Special 6 Nights / 7 Days Package)
// ----------------------------------------------------------------------------
// BUSINESS RULE: Extended stay only available for DORM accommodation

define('EXTENDED_ADVENTURE', [
    'dorm' => [
        'without_meals' => 6000,    // 6 nights, no meals (₹1000 per night)
        'with_meals' => 11000       // 6 nights + all meals included
    ]
]);

// ============================================================================
// PAYMENT GATEWAY CONFIGURATION (RAZORPAY)
// ============================================================================
// Razorpay is India's leading payment gateway for online transactions
// IMPORTANT: Replace these test keys with your actual production keys

define('RAZORPAY_KEY_ID', 'rzp_test_your_key_here');         // Get from Razorpay dashboard
define('RAZORPAY_SECRET', 'your_secret_here');               // Keep this SECRET - never expose to frontend
define('RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret_here'); // Used to verify payment callbacks


// ============================================================================
// EMAIL CONFIGURATION (SMTP)
// ============================================================================
// Settings for sending booking confirmations and notifications via email
// SMTP = Simple Mail Transfer Protocol (standard for sending emails)

define('SMTP_HOST', 'mail.mumbaisurfclub.com');       // Your email server
define('SMTP_PORT', 587);                             // Port 587 for TLS (secure email)
define('SMTP_USERNAME', 'booking@mumbaisurfclub.com'); // Email account username
define('SMTP_PASSWORD', 'your_email_password');       // Email account password
define('FROM_EMAIL', 'booking@mumbaisurfclub.com');   // "From" address on emails
define('FROM_NAME', 'Mumbai Surf Club');              // "From" name on emails


// ============================================================================
// FILE UPLOAD CONFIGURATION
// ============================================================================

define('UPLOAD_DIR', 'uploads/');                // Directory where uploaded files are stored
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);      // 5MB maximum file size (calculated in bytes)


// ============================================================================
// SECURITY CONFIGURATION
// ============================================================================

define('ENCRYPTION_KEY', 'your-32-character-secret-key-here'); // Used to encrypt sensitive data
define('CSRF_TOKEN_NAME', 'csrf_token');                       // CSRF = Cross-Site Request Forgery protection


// ============================================================================
// API RATE LIMITING
// ============================================================================
// Prevents abuse by limiting how many API requests can be made per hour

define('API_RATE_LIMIT', 100);    // Maximum 100 requests per hour
define('API_RATE_WINDOW', 3600);  // Time window: 3600 seconds = 1 hour

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
// These are reusable functions that other files can call
// They perform common calculations and data formatting

/**
 * Calculate total amount including GST
 * 
 * @param float $baseAmount - The base price before tax
 * @return array - Returns breakdown: base, GST amount, and total
 * 
 * EXAMPLE: If base is ₹1700, GST is 18%
 * - GST amount = 1700 × 0.18 = ₹306
 * - Total = 1700 + 306 = ₹2006
 */
function calculateTotalAmount($baseAmount) {
    $gstAmount = $baseAmount * GST_RATE;
    return [
        'base_amount' => $baseAmount,
        'gst_amount' => $gstAmount,
        'total_amount' => $baseAmount + $gstAmount
    ];
}

/**
 * Format currency in Indian Rupee format
 * 
 * @param float $amount - The amount to format
 * @return string - Formatted string like "₹2,006.00"
 * 
 * WHY: Makes prices look professional and readable
 */
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2); // number_format adds commas and 2 decimal places
}

/**
 * Get current week dates (Monday to Sunday)
 * 
 * @return array - Array of dates in YYYY-MM-DD format
 * 
 * LOGIC: Finds this week's Monday, then generates next 7 days
 */
function getCurrentWeekDates() {
    $monday = date('Y-m-d', strtotime('monday this week')); // Get Monday of current week
    $dates = [];
    
    // Loop through 7 days starting from Monday
    for ($i = 0; $i < 7; $i++) {
        $dates[] = date('Y-m-d', strtotime($monday . ' +' . $i . ' days'));
    }
    
    return $dates;
}

/**
 * Get all activity types
 * 
 * @return array - Returns the ACTIVITY_TYPES constant
 * 
 * WHY: Provides a function interface to access the constant
 */
function getActivityTypes() {
    return ACTIVITY_TYPES;
}

/**
 * Get information about a specific activity
 * 
 * @param string $activityType - The activity key ('surf', 'sup', or 'kayak')
 * @return array|null - Activity info or null if not found
 * 
 * USAGE: $surfInfo = getActivityInfo('surf');
 */
function getActivityInfo($activityType) {
    // The ?? null is called "null coalescing operator"
    // It returns null if the key doesn't exist instead of throwing an error
    return ACTIVITY_TYPES[$activityType] ?? null;
}

/**
 * Get the weekly booking window dates
 * 
 * BUSINESS LOGIC: Customers can book from TODAY until NEXT MONDAY
 * 
 * EXAMPLE:
 * - If today is Wednesday, they can book Wed, Thu, Fri, Sat, Sun, Mon (6 days)
 * - If today is Monday, they can book all 7 days until next Monday
 * 
 * @return array - Contains dates array and window information
 */
function getWeeklyBookingWindow() {
    $today = new DateTime(); // Create DateTime object for today
    $currentDayOfWeek = (int)$today->format('N'); // N = 1 (Mon) through 7 (Sun)
    
    // Calculate how many days until next Monday
    $daysUntilNextMonday = (8 - $currentDayOfWeek) % 7;
    
    // Special case: If today IS Monday, next Monday is 7 days away
    if ($daysUntilNextMonday === 0) {
        $daysUntilNextMonday = 7;
    }
    
    // Create next Monday date
    $nextMonday = clone $today; // Clone creates a copy (so we don't modify $today)
    $nextMonday->modify("+{$daysUntilNextMonday} days");
    
    // Build array of all bookable dates from today until next Monday
    $dates = [];
    $current = clone $today;
    
    while ($current < $nextMonday) {
        $dates[] = [
            'date' => $current->format('Y-m-d'),              // Database format: 2025-09-30
            'day_name' => $current->format('l'),              // Full day name: "Tuesday"
            'formatted_date' => $current->format('M d, Y'),   // Display format: "Sep 30, 2025"
            'is_today' => $current->format('Y-m-d') === $today->format('Y-m-d'), // Boolean flag
            'is_weekend' => in_array((int)$current->format('N'), [6, 7]) // Saturday (6) or Sunday (7)
        ];
        $current->modify('+1 day'); // Move to next day
    }
    
    return [
        'dates' => $dates,                          // Array of all bookable dates
        'window_end' => $nextMonday->format('Y-m-d'), // When booking window closes
        'days_available' => count($dates)           // How many days are bookable
    ];
}

/**
 * Calculate accommodation requirements based on people count
 * 
 * PURPOSE: Figures out how many tents/dorms/cottages are needed and validates capacity
 * 
 * @param string $accommodationType - 'tent', 'dorm', or 'cottage'
 * @param int $peopleCount - Number of people booking
 * @return array - Breakdown of accommodation requirements
 * @throws Exception - If capacity exceeded
 * 
 * EXAMPLE: 
 * - 6 people in cottages = need 2 cottages (4 + 2 people)
 * - 3 people in tents = need 3 tents (1 person each)
 */
function calculateAccommodationRequirements($accommodationType, $peopleCount) {
    $capacity = ACCOMMODATION_CAPACITY[$accommodationType]; // Get capacity rules for this type
    
    // VALIDATION 1: Check if we can physically accommodate this many people
    if ($peopleCount > $capacity['max_total_capacity']) {
        throw new Exception("Cannot accommodate $peopleCount people in $accommodationType. Maximum capacity is {$capacity['max_total_capacity']} people.");
    }
    
    // CALCULATION: How many units (tents/dorms/cottages) do we need?
    // ceil() rounds UP to nearest integer (e.g., 6 people ÷ 4 per cottage = 1.5 → 2 cottages)
    $unitsNeeded = ceil($peopleCount / $capacity['max_people_per_unit']);
    
    // VALIDATION 2: Do we have enough physical units available?
    if ($unitsNeeded > $capacity['total_units']) {
        throw new Exception("Need $unitsNeeded {$accommodationType}s but only {$capacity['total_units']} available.");
    }
    
    // Return detailed breakdown
    return [
        'accommodation_type' => $accommodationType,
        'people_count' => $peopleCount,
        'units_needed' => $unitsNeeded,
        'max_people_per_unit' => $capacity['max_people_per_unit'],
        'total_units_available' => $capacity['total_units'],
        'is_valid' => true
    ];
}

/**
 * Calculate package pricing with capacity validation
 * 
 * PURPOSE: Calculates the base price for packages (stay + activities)
 * COMPLEXITY: Cottages have special pricing tiers based on occupancy
 * 
 * @param string $packageType - '1_night_1_session', '1_night_2_sessions', or '2_nights_3_sessions'
 * @param string $accommodationType - 'tent', 'dorm', or 'cottage'
 * @param int $peopleCount - Number of people booking
 * @return array - Price breakdown and accommodation details
 */
function calculatePackagePriceWithCapacity($packageType, $accommodationType, $peopleCount) {
    // STEP 1: Validate capacity first (throws exception if invalid)
    $requirements = calculateAccommodationRequirements($accommodationType, $peopleCount);
    
    $prices = PACKAGE_PRICES[$packageType]; // Get price structure for this package type
    $baseAmount = 0;
    
    // COTTAGE PRICING: Special tiered pricing based on occupancy
    if ($accommodationType === 'cottage') {
        $cottagesNeeded = $requirements['units_needed'];
        
        if ($cottagesNeeded == 1) {
            // SIMPLE CASE: 1-4 people fit in 1 cottage
            // Use cottage_1, cottage_2, cottage_3, or cottage_4 pricing
            $cottageKey = 'cottage_' . min($peopleCount, 4);
            $baseAmount = $prices[$cottageKey];
        } else {
            // COMPLEX CASE: Need multiple cottages (5-8 people)
            // Distribute people across cottages and sum up the prices
            $baseAmount = 0;
            $remainingPeople = $peopleCount;
            
            for ($i = 0; $i < $cottagesNeeded; $i++) {
                // Fill each cottage with maximum 4 people
                $peopleInThisCottage = min(4, $remainingPeople);
                $cottageKey = 'cottage_' . $peopleInThisCottage;
                $baseAmount += $prices[$cottageKey];
                $remainingPeople -= $peopleInThisCottage;
            }
        }
    } else {
        // TENT/DORM PRICING: Simple per-person multiplication
        $baseAmount = $prices[$accommodationType] * $peopleCount;
    }
    
    return [
        'base_amount' => $baseAmount,
        'accommodation_requirements' => $requirements,
        'pricing_details' => [
            'units_needed' => $requirements['units_needed'],
            'price_per_unit' => $accommodationType === 'cottage' ? 
                ($requirements['units_needed'] == 1 ? $baseAmount : $prices['cottage_4']) : 
                $prices[$accommodationType],
            'total_people' => $peopleCount
        ]
    ];
}

/**
 * Calculate stay-only pricing with capacity validation
 * 
 * PURPOSE: Calculates price for accommodation without activities
 * HANDLES: Regular stays, extended stays (6N7D), and meal options
 * 
 * @param string $accommodationType - 'tent', 'dorm', or 'cottage'
 * @param int $peopleCount - Number of people
 * @param int $nights - Number of nights staying
 * @param bool $includesMeals - Whether to include breakfast + dinner
 * @return array - Price breakdown and accommodation details
 */
function calculateStayPriceWithCapacity($accommodationType, $peopleCount, $nights, $includesMeals) {
    // STEP 1: Validate capacity
    $requirements = calculateAccommodationRequirements($accommodationType, $peopleCount);
    
    $baseAmount = 0;
    
    // COTTAGE PRICING: Per cottage base + optional meal charges per person
    if ($accommodationType === 'cottage') {
        $cottagesNeeded = $requirements['units_needed'];
        $basePrice = STAY_PRICES['cottage']['base_price'] * $cottagesNeeded * $nights;
        $baseAmount = $basePrice;
        
        // Add meal charges if selected (₹500 per person per night)
        if ($includesMeals) {
            $mealPrice = STAY_PRICES['cottage']['meal_price_per_person'] * $peopleCount * $nights;
            $baseAmount += $mealPrice;
        }
    } else {
        // TENT/DORM PRICING
        
        // SPECIAL CASE: Extended stay (6 nights) for dorm has fixed package pricing
        if ($accommodationType === 'dorm' && $nights === 6) {
            $baseAmount = $includesMeals ? 
                EXTENDED_ADVENTURE['dorm']['with_meals'] * $peopleCount : 
                EXTENDED_ADVENTURE['dorm']['without_meals'] * $peopleCount;
        } else {
            // REGULAR CASE: Per person per night pricing
            $pricePerNight = $includesMeals ? 
                STAY_PRICES[$accommodationType]['with_meals'] : 
                STAY_PRICES[$accommodationType]['without_meals'];
            
            $baseAmount = $pricePerNight * $peopleCount * $nights;
        }
    }
    
    return [
        'base_amount' => $baseAmount,
        'accommodation_requirements' => $requirements,
        'pricing_details' => [
            'units_needed' => $requirements['units_needed'],
            'nights' => $nights,
            'includes_meals' => $includesMeals,
            'total_people' => $peopleCount
        ]
    ];
}

/**
 * Legacy helper function for backward compatibility
 * 
 * PURPOSE: Older code might call this function, so we keep it but use new logic internally
 * 
 * @return array - Simple array of date strings
 */
function getBookingWindowDates() {
    $window = getWeeklyBookingWindow();
    // array_column extracts just the 'date' values from the complex array
    return array_column($window['dates'], 'date');
}
?>
<?php
/**
 * Mumbai Surf Club Booking System Configuration
 * Updated for Activity-Based System
 */

// Database Configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'mumbai_surf_booking');
define('DB_USER', 'booking_user');
define('DB_PASS', 'admin@mumbai');
define('DB_CHARSET', 'utf8mb4');

// Site Configuration
define('SITE_NAME', 'Mumbai Surf Club');
define('SITE_URL', 'https://staging.mumbaisurfclub.com');
define('SITE_EMAIL', 'info@mumbaisurfclub.com');

// Development/Production Environment
define('ENVIRONMENT', 'development'); // Change to 'production' when live

// Error Reporting (disable in production)
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Session Configuration
define('ADMIN_SESSION_NAME', 'surf_admin_session');
define('SESSION_TIMEOUT', 86400);

// Booking Configuration
define('BOOKING_ADVANCE_DAYS', 7); // How many days in advance can book
define('SLOT_DURATION_MINUTES', 90); // 1.5 hours

// Activity Configuration - NEW
define('ACTIVITY_TYPES', [
    'surf' => [
        'name' => 'Surfing',
        'description' => 'Learn to ride the waves on our surfboards',
        'default_capacity' => 40,
        'price_per_person' => 1700
    ],
    'sup' => [
        'name' => 'Stand Up Paddling',
        'description' => 'Balance and paddle on a stand-up paddleboard',
        'default_capacity' => 12,
        'price_per_person' => 1700
    ],
    'kayak' => [
        'name' => 'Kayaking',
        'description' => 'Paddle through calm waters in a kayak',
        'default_capacity' => 2,
        'price_per_person' => 1700
    ]
]);

// Weekly Booking Window Configuration - NEW
define('BOOKING_WINDOW_TYPE', 'weekly'); // 'weekly' = Monday to Monday, 'daily' = rolling 7 days
define('WEEK_START_DAY', 'monday'); // When does the booking week start

// Accommodation Capacity Limits
define('ACCOMMODATION_CAPACITY', [
    'tent' => [
        'max_people_per_unit' => 1, // 1 people per tent
        'total_units' => 100,       // 100 tents available
        'max_total_capacity' => 100 // 100 tents × 1 people each
    ],
    'dorm' => [
        'max_people_per_unit' => 1, // 1 people per dorm room
        'total_units' => 100,       // 100 dorm rooms
        'max_total_capacity' => 100 // 100 dorms × 1 people each
    ],
    'cottage' => [
        'max_people_per_unit' => 4, // 4 people per cottage
        'total_units' => 2,         // Only 2 cottages available
        'max_total_capacity' => 8   // 2 cottages × 4 people each
    ]
]);

// Pricing Configuration (in INR)
define('SURF_SUP_BASE_PRICE', 1700); // Base price for all activities
define('GST_RATE', 0.18); // 18%

// Package Base Prices (per person)
define('PACKAGE_PRICES', [
    '1_night_1_session' => [
        'tent' => 3000,
        'dorm' => 3250,
        'cottage_1' => 9000,
        'cottage_2' => 10500,
        'cottage_3' => 12750,
        'cottage_4' => 15000
    ],
    '1_night_2_sessions' => [
        'tent' => 5000,
        'dorm' => 5000,
        'cottage_1' => 10000,
        'cottage_2' => 14000,
        'cottage_3' => 18000,
        'cottage_4' => 22000
    ],
    '2_nights_3_sessions' => [
        'tent' => 8000,
        'dorm' => 8000,
        'cottage_1' => 18000,
        'cottage_2' => 24000,
        'cottage_3' => 30000,
        'cottage_4' => 36000
    ]
]);

// Stay-only Prices
define('STAY_PRICES', [
    'tent' => [
        'without_meals' => 1000,
        'with_meals' => 1500
    ],
    'dorm' => [
        'without_meals' => 1200,
        'with_meals' => 1700
    ],
    'cottage' => [
        'base_price' => 6000, // Per cottage base price
        'meal_price_per_person' => 500 // Additional 500 per person for meals
    ]
]);

// Extended Adventure (6N 7D)
define('EXTENDED_ADVENTURE', [
    'dorm' => [
        'without_meals' => 6000,
        'with_meals' => 11000
    ]
]);

// Payment Gateway (Razorpay)
// NOTE: Get these from your Razorpay dashboard
define('RAZORPAY_KEY_ID', 'rzp_test_your_key_here'); // Replace with actual key
define('RAZORPAY_SECRET', 'your_secret_here'); // Replace with actual secret
define('RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret_here'); // Replace with actual webhook secret

// Email Configuration
define('SMTP_HOST', 'mail.mumbaisurfclub.com'); // Your domain's SMTP
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'booking@mumbaisurfclub.com'); // Create this email
define('SMTP_PASSWORD', 'your_email_password'); // Replace with actual password
define('FROM_EMAIL', 'booking@mumbaisurfclub.com');
define('FROM_NAME', 'Mumbai Surf Club');

// File Upload Configuration
define('UPLOAD_DIR', 'uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// Security
define('ENCRYPTION_KEY', 'your-32-character-secret-key-here'); // Generate a random 32-character string
define('CSRF_TOKEN_NAME', 'csrf_token');

// API Rate Limiting
define('API_RATE_LIMIT', 100); // requests per hour
define('API_RATE_WINDOW', 3600); // 1 hour in seconds

// Helper function to calculate total amount with GST
function calculateTotalAmount($baseAmount) {
    $gstAmount = $baseAmount * GST_RATE;
    return [
        'base_amount' => $baseAmount,
        'gst_amount' => $gstAmount,
        'total_amount' => $baseAmount + $gstAmount
    ];
}

// Helper function to format currency
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

// Helper function to get current week dates (Monday to Sunday)
function getCurrentWeekDates() {
    $monday = date('Y-m-d', strtotime('monday this week'));
    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $dates[] = date('Y-m-d', strtotime($monday . ' +' . $i . ' days'));
    }
    return $dates;
}

// NEW: Get activity types
function getActivityTypes() {
    return ACTIVITY_TYPES;
}

// NEW: Get activity info by type
function getActivityInfo($activityType) {
    return ACTIVITY_TYPES[$activityType] ?? null;
}

// NEW: Weekly booking window helper
function getWeeklyBookingWindow() {
    $today = new DateTime();
    $currentDayOfWeek = (int)$today->format('N'); // 1=Monday, 7=Sunday
    
    // Find next Monday
    $daysUntilNextMonday = (8 - $currentDayOfWeek) % 7;
    if ($daysUntilNextMonday === 0) {
        // If today is Monday, next Monday is 7 days away
        $daysUntilNextMonday = 7;
    }
    
    $nextMonday = clone $today;
    $nextMonday->modify("+{$daysUntilNextMonday} days");
    
    // Get bookable dates (from today until next Monday)
    $dates = [];
    $current = clone $today;
    
    while ($current < $nextMonday) {
        $dates[] = [
            'date' => $current->format('Y-m-d'),
            'day_name' => $current->format('l'),
            'formatted_date' => $current->format('M d, Y'),
            'is_today' => $current->format('Y-m-d') === $today->format('Y-m-d'),
            'is_weekend' => in_array((int)$current->format('N'), [6, 7])
        ];
        $current->modify('+1 day');
    }
    
    return [
        'dates' => $dates,
        'window_end' => $nextMonday->format('Y-m-d'),
        'days_available' => count($dates)
    ];
}

// Enhanced accommodation requirements calculation
function calculateAccommodationRequirements($accommodationType, $peopleCount) {
    $capacity = ACCOMMODATION_CAPACITY[$accommodationType];
    
    // Check if total capacity exceeded
    if ($peopleCount > $capacity['max_total_capacity']) {
        throw new Exception("Cannot accommodate $peopleCount people in $accommodationType. Maximum capacity is {$capacity['max_total_capacity']} people.");
    }
    
    // Calculate units needed
    $unitsNeeded = ceil($peopleCount / $capacity['max_people_per_unit']);
    
    // Check if we have enough units
    if ($unitsNeeded > $capacity['total_units']) {
        throw new Exception("Need $unitsNeeded {$accommodationType}s but only {$capacity['total_units']} available.");
    }
    
    return [
        'accommodation_type' => $accommodationType,
        'people_count' => $peopleCount,
        'units_needed' => $unitsNeeded,
        'max_people_per_unit' => $capacity['max_people_per_unit'],
        'total_units_available' => $capacity['total_units'],
        'is_valid' => true
    ];
}

// Enhanced package pricing with capacity validation
function calculatePackagePriceWithCapacity($packageType, $accommodationType, $peopleCount) {
    // First validate capacity
    $requirements = calculateAccommodationRequirements($accommodationType, $peopleCount);
    
    $prices = PACKAGE_PRICES[$packageType];
    $baseAmount = 0;
    
    if ($accommodationType === 'cottage') {
        // For cottages: price based on number of cottages needed
        $cottagesNeeded = $requirements['units_needed'];
        
        if ($cottagesNeeded == 1) {
            // 1-4 people = 1 cottage, use people-based pricing
            $cottageKey = 'cottage_' . min($peopleCount, 4);
            $baseAmount = $prices[$cottageKey];
        } else {
            // 5-8 people = 2 cottages, use maximum price for cottages
            $baseAmount = $prices['cottage_4'] * $cottagesNeeded;
        }
    } else {
        // Tent/dorm: per person pricing
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

// Enhanced stay pricing with capacity validation
function calculateStayPriceWithCapacity($accommodationType, $peopleCount, $nights, $includesMeals) {
    // First validate capacity
    $requirements = calculateAccommodationRequirements($accommodationType, $peopleCount);
    
    $baseAmount = 0;
    
    if ($accommodationType === 'cottage') {
        $cottagesNeeded = $requirements['units_needed'];
        $basePrice = STAY_PRICES['cottage']['base_price'] * $cottagesNeeded * $nights;
        $baseAmount = $basePrice;
        
        if ($includesMeals) {
            $mealPrice = STAY_PRICES['cottage']['meal_price_per_person'] * $peopleCount * $nights;
            $baseAmount += $mealPrice;
        }
    } else {
        // Tent/dorm pricing per person per night
        $pricePerNight = $includesMeals ? 
            STAY_PRICES[$accommodationType]['with_meals'] : 
            STAY_PRICES[$accommodationType]['without_meals'];
        
        $baseAmount = $pricePerNight * $peopleCount * $nights;
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

// Legacy helper function - now uses weekly booking window
function getBookingWindowDates() {
    $window = getWeeklyBookingWindow();
    return array_column($window['dates'], 'date');
}
?>
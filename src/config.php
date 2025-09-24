<?php
/**
 * Mumbai Surf Club Booking System Configuration
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
define('DEFAULT_SLOT_CAPACITY', 40);

// Pricing Configuration (in INR)
define('SURF_SUP_BASE_PRICE', 1700);
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

// Helper function to calculate cottage stay pricing
function calculateCottageStayPrice($people_count, $with_meals = false, $nights = 1) {
    $base_price = STAY_PRICES['cottage']['base_price'] * $nights;
    
    if ($with_meals) {
        $meal_price = STAY_PRICES['cottage']['meal_price_per_person'] * $people_count * $nights;
        return $base_price + $meal_price;
    }
    
    return $base_price;
}

// Helper function to get booking window dates
function getBookingWindowDates() {
    $dates = [];
    for ($i = 0; $i < BOOKING_ADVANCE_DAYS; $i++) {
        $dates[] = date('Y-m-d', strtotime('+' . $i . ' days'));
    }
    return $dates;
}
?>
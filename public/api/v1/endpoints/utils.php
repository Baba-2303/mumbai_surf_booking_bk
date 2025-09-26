<?php
/**
 * Updated Utilities Endpoint Handler
 * Enhanced with activity-based system utilities
 */

function handleUtilsEndpoint($method, $resource, $id) {
    switch ($method) {
        case 'GET':
            if ($resource === 'activities') {
                handleGetActivityInfo();
            } elseif ($resource === 'booking-window') {
                handleGetBookingWindow();
            } else {
                sendError('Invalid utils GET endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        case 'POST':
            if ($resource === 'generate-sessions') {
                handleGeneratePackageSessions();
            } elseif ($resource === 'validate-booking') {
                handleValidateBookingData();
            } else {
                sendError('Invalid utils POST endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        default:
            sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
}

/**
 * NEW: GET /utils/activities
 * Get activity information with current availability stats
 */
function handleGetActivityInfo() {
    try {
        $activityTypes = getActivityTypes();
        $db = Database::getInstance();
        
        // Get current date for availability stats
        $today = date('Y-m-d');
        
        $activities = [];
        foreach ($activityTypes as $type => $info) {
            // Get today's availability stats for this activity
            $todaysStats = $db->fetch(
                "SELECT 
                    COUNT(sa.slot_id) as total_slots,
                    SUM(sa.max_capacity) as total_capacity,
                    SUM(COALESCE(saa.booked_count, 0)) as total_booked,
                    (SUM(sa.max_capacity) - SUM(COALESCE(saa.booked_count, 0))) as available_spots
                 FROM slot_activities sa
                 JOIN slots s ON sa.slot_id = s.id
                 LEFT JOIN slot_activity_availability saa ON sa.slot_id = saa.slot_id 
                     AND saa.booking_date = ? AND saa.activity_type = sa.activity_type
                 WHERE sa.activity_type = ? AND s.is_active = 1 
                   AND s.day_of_week = ?",
                [$today, $type, date('N')]
            );
            
            $activities[] = [
                'type' => $type,
                'name' => $info['name'],
                'description' => $info['description'],
                'default_capacity' => $info['default_capacity'],
                'price_per_person' => $info['price_per_person'],
                'formatted_price' => formatCurrency($info['price_per_person']),
                'availability_today' => [
                    'total_slots' => (int)($todaysStats['total_slots'] ?? 0),
                    'total_capacity' => (int)($todaysStats['total_capacity'] ?? 0),
                    'total_booked' => (int)($todaysStats['total_booked'] ?? 0),
                    'available_spots' => (int)($todaysStats['available_spots'] ?? 0),
                    'availability_status' => $todaysStats && $todaysStats['available_spots'] > 0 ? 'available' : 'full'
                ]
            ];
        }
        
        sendResponse(true, [
            'activities' => $activities,
            'current_date' => $today,
            'total_activity_types' => count($activities)
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve activity information: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: GET /utils/booking-window
 * Get current booking window information
 */
function handleGetBookingWindow() {
    try {
        $bookingWindow = getWeeklyBookingWindow();
        
        // Add additional context
        $windowInfo = [
            'type' => BOOKING_WINDOW_TYPE,
            'description' => 'Weekly booking window (Monday to Monday)',
            'current_window' => $bookingWindow,
            'rules' => [
                'advance_booking' => 'Bookings can be made up to next Monday',
                'same_day_booking' => 'Same day bookings allowed until slot starts',
                'cancellation_policy' => 'Cancellations allowed up to 24 hours before'
            ],
            'timezone' => 'Asia/Kolkata'
        ];
        
        sendResponse(true, $windowInfo);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve booking window: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /utils/generate-sessions
 * Generate session dates for package bookings
 */
function handleGeneratePackageSessions() {
    $data = getRequestBody();
    
    validateRequired($data, ['package_type', 'check_in_date']);
    
    $packageType = $data['package_type'];
    $checkInDate = $data['check_in_date'];
    
    // Validate package type
    if (!in_array($packageType, ['1_night_1_session', '1_night_2_sessions', '2_nights_3_sessions'])) {
        sendError('Invalid package type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkInDate) || !strtotime($checkInDate)) {
        sendError('Invalid check-in date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // Check if check-in date is within booking window
    $bookingWindow = getWeeklyBookingWindow();
    $validDates = array_column($bookingWindow['dates'], 'date');
    
    if (!in_array($checkInDate, $validDates)) {
        sendError('Check-in date is outside booking window', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $booking = new Booking();
        $sessions = $booking->generatePackageSessionDates($packageType, $checkInDate);
        
        // Calculate check-out date
        $nights = (strpos($packageType, '2_nights') !== false) ? 2 : 1;
        $checkOutDate = date('Y-m-d', strtotime($checkInDate . ' +' . $nights . ' days'));
        
        // Get available slots for each session date (using legacy method for packages)
        $slot = new Slot();
        foreach ($sessions as &$session) {
            $availableSlots = $slot->getAvailableSlots($session['session_date']);
            
            // Format slots for better response
            $formattedSlots = [];
            foreach ($availableSlots as $slotData) {
                $formattedSlots[] = [
                    'slot_id' => $slotData['id'],
                    'start_time' => $slotData['start_time'],
                    'end_time' => $slotData['end_time'],
                    'formatted_time' => $slotData['formatted_time']
                ];
            }
            
            $session['available_slots'] = $formattedSlots;
            $session['slots_count'] = count($formattedSlots);
            
            // Suggest first available slot
            $nextAvailable = $slot->getNextAvailableSlot($session['session_date'], 1);
            $session['suggested_slot'] = $nextAvailable ? [
                'slot_id' => $nextAvailable['id'],
                'formatted_time' => $slot->formatSlotTime($nextAvailable['start_time'], $nextAvailable['end_time'])
            ] : null;
        }
        
        $response = [
            'package_type' => $packageType,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'nights_count' => $nights,
            'sessions_count' => count($sessions),
            'sessions' => $sessions,
            'booking_summary' => [
                'total_nights' => $nights,
                'total_sessions' => count($sessions),
                'session_dates' => array_column($sessions, 'session_date')
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to generate session dates: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: POST /utils/validate-booking
 * Validate booking data before submission
 */
function handleValidateBookingData() {
    $data = getRequestBody();
    
    validateRequired($data, ['booking_type']);
    
    $bookingType = $data['booking_type'];
    $errors = [];
    $warnings = [];
    
    try {
        switch ($bookingType) {
            case 'activity':
                $errors = validateActivityBookingData($data);
                break;
                
            case 'package':
                $errors = validatePackageBookingData($data);
                break;
                
            case 'stay':
                $errors = validateStayBookingData($data);
                break;
                
            default:
                $errors[] = 'Invalid booking type';
        }
        
        // Check availability warnings
        if (empty($errors) && in_array($bookingType, ['activity', 'package'])) {
            $warnings = checkAvailabilityWarnings($data, $bookingType);
        }
        
        $response = [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'validation_timestamp' => date('c')
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to validate booking data: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * Helper function to validate activity booking data
 */
function validateActivityBookingData($data) {
    $errors = [];
    
    // Required fields
    $requiredFields = ['customer_name', 'customer_email', 'customer_phone', 'activity_type', 'session_date', 'slot_id', 'people'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $errors[] = "Missing required field: $field";
        }
    }
    
    if (!empty($errors)) return $errors; // Return early if missing required fields
    
    // Validate activity type
    $validActivities = array_keys(getActivityTypes());
    if (!in_array($data['activity_type'], $validActivities)) {
        $errors[] = 'Invalid activity type';
    }
    
    // Validate email
    if (!filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    // Validate date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['session_date']) || !strtotime($data['session_date'])) {
        $errors[] = 'Invalid session date format';
    }
    
    // Validate people array
    if (!is_array($data['people']) || empty($data['people'])) {
        $errors[] = 'At least one person is required';
    } else {
        foreach ($data['people'] as $i => $person) {
            if (empty($person['name']) || empty($person['age'])) {
                $errors[] = "Missing name or age for person " . ($i + 1);
            }
            if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
                $errors[] = "Invalid age for person " . ($i + 1);
            }
        }
    }
    
    return $errors;
}

/**
 * Helper function to validate package booking data
 */
function validatePackageBookingData($data) {
    $errors = [];
    
    $requiredFields = ['customer_name', 'customer_email', 'customer_phone', 'package_type', 'accommodation_type', 'service_type', 'check_in_date', 'people', 'sessions'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $errors[] = "Missing required field: $field";
        }
    }
    
    if (!empty($errors)) return $errors;
    
    // Validate package type
    if (!in_array($data['package_type'], ['1_night_1_session', '1_night_2_sessions', '2_nights_3_sessions'])) {
        $errors[] = 'Invalid package type';
    }
    
    // Validate accommodation type
    if (!in_array($data['accommodation_type'], ['tent', 'dorm', 'cottage'])) {
        $errors[] = 'Invalid accommodation type';
    }
    
    // Additional package-specific validations...
    
    return $errors;
}

/**
 * Helper function to validate stay booking data
 */
function validateStayBookingData($data) {
    $errors = [];
    
    $requiredFields = ['customer_name', 'customer_email', 'customer_phone', 'accommodation_type', 'check_in_date', 'check_out_date', 'people'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $errors[] = "Missing required field: $field";
        }
    }
    
    // Additional stay-specific validations...
    
    return $errors;
}

/**
 * Helper function to check availability warnings
 */
function checkAvailabilityWarnings($data, $bookingType) {
    $warnings = [];
    
    try {
        $slot = new Slot();
        
        if ($bookingType === 'activity') {
            $peopleCount = count($data['people']);
            if (!$slot->hasActivityAvailability($data['slot_id'], $data['session_date'], $data['activity_type'], $peopleCount)) {
                $warnings[] = 'Selected slot may no longer be available';
            }
        }
        // Add package warnings if needed...
        
    } catch (Exception $e) {
        $warnings[] = 'Could not verify current availability';
    }
    
    return $warnings;
}
?>
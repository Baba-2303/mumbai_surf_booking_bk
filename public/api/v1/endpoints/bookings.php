<?php
/**
 * Updated Bookings Endpoint Handler
 * Now supports activity-based booking system
 */

function handleBookingsEndpoint($method, $resource, $id) {
    $booking = new Booking();
    
    switch ($method) {
        case 'GET':
            if ($id) {
                handleGetBooking($booking, $id);
            } else {
                sendError('Booking ID required', 'VALIDATION_ERROR', 422);
            }
            break;
            
        case 'POST':
            if ($resource === 'activity') {
                handleCreateActivityBooking($booking);
            } elseif ($resource === 'surf-sup') {
                // Legacy endpoint - redirect to activity booking
                handleCreateSurfSupBooking($booking);
            } elseif ($resource === 'package') {
                handleCreatePackageBooking($booking);
            } elseif ($resource === 'stay') {
                handleCreateStayBooking($booking);
            } else {
                sendError('Invalid booking type', 'NOT_FOUND', 404);
            }
            break;
            
        default:
            sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
}

/**
 * GET /bookings/{id}
 */
function handleGetBooking($booking, $id) {
    if (!is_numeric($id)) {
        sendError('Invalid booking ID', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $bookingData = $booking->getById($id);
        
        if (!$bookingData) {
            sendError('Booking not found', 'NOT_FOUND', 404);
        }
        
        sendResponse(true, $bookingData);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: POST /bookings/activity
 * Create activity booking (surf/sup/kayak)
 */
function handleCreateActivityBooking($booking) {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'activity_type', 'session_date', 'slot_id', 'people'
    ]);
    
    // Validate activity type
    $validActivities = array_keys(getActivityTypes());
    if (!in_array($data['activity_type'], $validActivities)) {
        sendError('Invalid activity type. Valid options: ' . implode(', ', $validActivities), 'VALIDATION_ERROR', 422);
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['session_date']) || !strtotime($data['session_date'])) {
        sendError('Invalid session date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // Validate people array
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    // Get activity capacity limit
    $activityInfo = getActivityInfo($data['activity_type']);
    $maxCapacity = $activityInfo['default_capacity'];
    
    if (count($data['people']) > $maxCapacity) {
        sendError("Maximum $maxCapacity people allowed for {$activityInfo['name']}", 'VALIDATION_ERROR', 422);
    }
    
    // Validate each person
    foreach ($data['people'] as $index => $person) {
        if (empty($person['name']) || empty($person['age'])) {
            sendError("Name and age required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
            sendError("Age must be between 5 and 100 for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    // Check if date is within booking window
    $slot = new Slot();
    $bookableDates = $slot->getBookableDates();
    $validDates = array_column($bookableDates, 'date');
    
    if (!in_array($data['session_date'], $validDates)) {
        sendError('Session date is outside booking window', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Check activity slot availability before creating booking
        if (!$slot->hasActivityAvailability($data['slot_id'], $data['session_date'], $data['activity_type'], count($data['people']))) {
            sendError('Selected activity slot is no longer available', 'SLOT_UNAVAILABLE', 409);
        }
        
        $bookingId = $booking->createActivityBooking($data);
        
        // Get the created booking with all details
        $bookingData = $booking->getById($bookingId);
        
        // Add booking reference
        $bookingData['booking_reference'] = $booking->generateBookingReference(
            $bookingId, 
            $data['session_date'], 
            $data['activity_type']
        );
        
        sendResponse(true, $bookingData, 'Activity booking created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * LEGACY: POST /bookings/surf-sup
 * Maintains backward compatibility - converts to activity booking
 */
function handleCreateSurfSupBooking($booking) {
    $data = getRequestBody();
    
    // Validate required fields (legacy format)
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'service_type', 'session_date', 'slot_id', 'people'
    ]);
    
    // Convert service_type to activity_type for backward compatibility
    if (!isset($data['activity_type']) && isset($data['service_type'])) {
        $data['activity_type'] = $data['service_type'];
    }
    
    // Validate service/activity type
    if (!in_array($data['service_type'], ['surf', 'sup'])) {
        sendError('Service type must be either "surf" or "sup"', 'VALIDATION_ERROR', 422);
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['session_date']) || !strtotime($data['session_date'])) {
        sendError('Invalid session date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // Validate people array
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    if (count($data['people']) > 40) {
        sendError('Maximum 40 people allowed per booking', 'VALIDATION_ERROR', 422);
    }
    
    // Validate each person
    foreach ($data['people'] as $index => $person) {
        if (empty($person['name']) || empty($person['age'])) {
            sendError("Name and age required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
            sendError("Age must be between 5 and 100 for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    // Check if date is within booking window
    $slot = new Slot();
    $bookableDates = $slot->getBookableDates();
    $validDates = array_column($bookableDates, 'date');
    
    if (!in_array($data['session_date'], $validDates)) {
        sendError('Session date is outside booking window', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Use legacy availability check for backward compatibility
        if (!$slot->hasAvailability($data['slot_id'], $data['session_date'], count($data['people']))) {
            sendError('Selected slot is no longer available', 'SLOT_UNAVAILABLE', 409);
        }
        
        // Call legacy method that redirects to activity booking
        $bookingId = $booking->createSurfSupBooking($data);
        
        // Get the created booking with all details
        $bookingData = $booking->getById($bookingId);
        
        sendResponse(true, $bookingData, 'Surf/SUP booking created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /bookings/package
 */
function handleCreatePackageBooking($booking) {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'package_type', 'accommodation_type', 'service_type',
        'check_in_date', 'people', 'sessions'
    ]);
    
    // Validate package type
    if (!in_array($data['package_type'], ['1_night_1_session', '1_night_2_sessions', '2_nights_3_sessions'])) {
        sendError('Invalid package type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate accommodation type
    if (!in_array($data['accommodation_type'], ['tent', 'dorm', 'cottage'])) {
        sendError('Invalid accommodation type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate service type (packages still use surf/sup only)
    if (!in_array($data['service_type'], ['surf', 'sup'])) {
        sendError('Service type must be either "surf" or "sup"', 'VALIDATION_ERROR', 422);
    }
    
    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['check_in_date']) || !strtotime($data['check_in_date'])) {
        sendError('Invalid check-in date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // Calculate check-out date based on package type
    $nights = (strpos($data['package_type'], '2_nights') !== false) ? 2 : 1;
    $checkOutDate = date('Y-m-d', strtotime($data['check_in_date'] . ' +' . $nights . ' days'));
    $data['check_out_date'] = $checkOutDate;
    
    // Validate people
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    if (count($data['people']) > 40) {
        sendError('Maximum 40 people allowed per booking', 'VALIDATION_ERROR', 422);
    }
    
    // Validate each person
    foreach ($data['people'] as $index => $person) {
        if (empty($person['name']) || empty($person['age'])) {
            sendError("Name and age required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
            sendError("Age must be between 5 and 100 for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    // Validate sessions
    if (!is_array($data['sessions']) || empty($data['sessions'])) {
        sendError('Session information is required', 'VALIDATION_ERROR', 422);
    }
    
    foreach ($data['sessions'] as $index => $session) {
        if (empty($session['session_date']) || !strtotime($session['session_date'])) {
            sendError("Valid session date is required for session " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        if (empty($session['slot_id']) || !is_numeric($session['slot_id'])) {
            sendError("Valid slot selection is required for session " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    try {
        // Check all session slots availability (using legacy method for packages)
        $slot = new Slot();
        foreach ($data['sessions'] as $session) {
            if (!$slot->hasAvailability($session['slot_id'], $session['session_date'], count($data['people']))) {
                sendError('Session slot on ' . $session['session_date'] . ' is no longer available', 'SLOT_UNAVAILABLE', 409);
            }
        }
        
        $bookingId = $booking->createPackageBooking($data);
        
        // Get the created booking with all details
        $bookingData = $booking->getById($bookingId);
        
        // Add booking reference
        $bookingData['booking_reference'] = $booking->generateBookingReference(
            $bookingId, 
            $data['check_in_date'], 
            'package'
        );
        
        sendResponse(true, $bookingData, 'Package booking created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create package booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /bookings/stay
 */
function handleCreateStayBooking($booking) {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'accommodation_type', 'check_in_date', 'check_out_date',
        'people'
    ]);
    
    // Validate accommodation type
    if (!in_array($data['accommodation_type'], ['tent', 'dorm', 'cottage'])) {
        sendError('Invalid accommodation type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['check_in_date']) || !strtotime($data['check_in_date'])) {
        sendError('Invalid check-in date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['check_out_date']) || !strtotime($data['check_out_date'])) {
        sendError('Invalid check-out date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // Validate date order
    if (strtotime($data['check_out_date']) <= strtotime($data['check_in_date'])) {
        sendError('Check-out date must be after check-in date', 'VALIDATION_ERROR', 422);
    }
    
    // Calculate nights
    $checkIn = new DateTime($data['check_in_date']);
    $checkOut = new DateTime($data['check_out_date']);
    $data['nights_count'] = $checkOut->diff($checkIn)->days;
    
    // Set meals flag
    $data['includes_meals'] = isset($data['includes_meals']) ? (bool)$data['includes_meals'] : false;
    
    // Validate people
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    if (count($data['people']) > 40) {
        sendError('Maximum 40 people allowed per booking', 'VALIDATION_ERROR', 422);
    }
    
    // Validate each person
    foreach ($data['people'] as $index => $person) {
        if (empty($person['name']) || empty($person['age'])) {
            sendError("Name and age required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
            sendError("Age must be between 5 and 100 for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    try {
        $bookingId = $booking->createStayBooking($data);
        
        // Get the created booking with all details
        $bookingData = $booking->getById($bookingId);
        
        // Add booking reference
        $bookingData['booking_reference'] = $booking->generateBookingReference(
            $bookingId, 
            $data['check_in_date'], 
            'stay'
        );
        
        sendResponse(true, $bookingData, 'Stay booking created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create stay booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
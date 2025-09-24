<?php
/**
 * Bookings Endpoint Handler
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
            if ($resource === 'surf-sup') {
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
 * POST /bookings/surf-sup
 */
function handleCreateSurfSupBooking($booking) {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'service_type', 'session_date', 'slot_id', 'people'
    ]);
    
    // Validate service type
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
        // Check slot availability before creating booking
        if (!$slot->hasAvailability($data['slot_id'], $data['session_date'], count($data['people']))) {
            sendError('Selected slot is no longer available', 'SLOT_UNAVAILABLE', 409);
        }
        
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
    
    // Validate service type
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
    
    // Validate sessions
    if (!is_array($data['sessions']) || empty($data['sessions'])) {
        sendError('Session information is required', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Check all session slots availability
        $slot = new Slot();
        foreach ($data['sessions'] as $session) {
            if (!$slot->hasAvailability($session['slot_id'], $session['session_date'], count($data['people']))) {
                sendError('Session slot on ' . $session['session_date'] . ' is no longer available', 'SLOT_UNAVAILABLE', 409);
            }
        }
        
        $bookingId = $booking->createPackageBooking($data);
        
        // Get the created booking with all details
        $bookingData = $booking->getById($bookingId);
        
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
    
    try {
        $bookingId = $booking->createStayBooking($data);
        
        // Get the created booking with all details
        $bookingData = $booking->getById($bookingId);
        
        sendResponse(true, $bookingData, 'Stay booking created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create stay booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
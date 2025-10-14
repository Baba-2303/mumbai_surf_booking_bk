<?php
/**
 * ENHANCED Bookings Endpoint Handler
 * All critical fixes applied + optimizations
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
 * ENHANCED: POST /bookings/activity
 * ✅ Now supports multi-activity bookings (different activity per person)
 * ✅ Validates capacity for each activity separately
 * ✅ Supports both single-activity (legacy) and multi-activity (new) formats
 */
function handleCreateActivityBooking($booking) {
    $data = getRequestBody();
    
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'session_date', 'slot_id', 'people'
    ]);
    
    // ✅ VALIDATION: Date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['session_date']) || !strtotime($data['session_date'])) {
        sendError('Invalid session date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // ✅ VALIDATION: Booking window
    $bookingWindow = getWeeklyBookingWindow();
    $validDates = array_column($bookingWindow['dates'], 'date');
    
    if (!in_array($data['session_date'], $validDates)) {
        sendError('Session date is outside booking window', 'INVALID_DATE', 422);
    }
    
    // ✅ VALIDATION: Prevent past dates
    if (strtotime($data['session_date']) < strtotime(date('Y-m-d'))) {
        sendError('Cannot book for past dates', 'INVALID_DATE', 422);
    }
    
    // ✅ VALIDATION: People array
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    // ✅ VALIDATION: Max 40 people
    if (count($data['people']) > 40) {
        sendError('Maximum 40 people allowed per booking', 'VALIDATION_ERROR', 422);
    }
    
    // ✅ VALIDATION: Each person must have activity_type
    $validActivities = ['surf', 'sup', 'kayak'];
    foreach ($data['people'] as $index => $person) {
        if (empty($person['name']) || empty($person['age'])) {
            sendError("Name and age required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
            sendError("Age must be between 5 and 100 for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        // ✅ NEW: Validate activity_type for each person
        if (empty($person['activity_type']) || !in_array($person['activity_type'], $validActivities)) {
            sendError("Valid activity selection is required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    try {
        $slot = new Slot();
        
        // ✅ CRITICAL: Validate slot exists for this date
        $slotValidation = $slot->getById($data['slot_id']);
        if (!$slotValidation) {
            sendError('Invalid slot selection', 'INVALID_SLOT', 422);
        }
        
        // ✅ NEW: Group people by activity and validate capacity for EACH activity
        $activityGroups = [];
        foreach ($data['people'] as $person) {
            $activityType = $person['activity_type'];
            if (!isset($activityGroups[$activityType])) {
                $activityGroups[$activityType] = [];
            }
            $activityGroups[$activityType][] = $person;
        }
        
        // ✅ NEW: Check capacity for each activity
        foreach ($activityGroups as $activityType => $people) {
            $count = count($people);
            
            // Get activity info for capacity limits
            $activityInfo = getActivityInfo($activityType);
            if ($count > $activityInfo['default_capacity']) {
                sendError(
                    "Cannot book $count people for $activityType. Maximum capacity is {$activityInfo['default_capacity']} per slot.",
                    'CAPACITY_EXCEEDED',
                    422
                );
            }
            
            // Check actual slot availability
            if (!$slot->hasActivityAvailability($data['slot_id'], $data['session_date'], $activityType, $count)) {
                sendError(
                    "Selected slot doesn't have enough capacity for $count people doing $activityType",
                    'SLOT_UNAVAILABLE',
                    409
                );
            }
        }
        
        // ✅ All validations passed - create booking
        $bookingId = $booking->createActivityBooking($data);
        $bookingData = $booking->getById($bookingId);
        
        // Generate booking reference
        $bookingData['booking_reference'] = $booking->generateBookingReference(
            $bookingId, 
            $data['session_date'], 
            'activity'
        );
        
        sendResponse(true, $bookingData, 'Activity booking created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * ENHANCED: POST /bookings/package
 * ✅ FIX: Added session date validation
 * ✅ FIX: Added duplicate session detection
 * ✅ FIX: Added booking window validation
 */
function handleCreatePackageBooking($booking) {
    $data = getRequestBody();
    
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'package_type', 'accommodation_type', 'service_type',
        'check_in_date', 'people', 'sessions'
    ]);
    
    if (!in_array($data['package_type'], ['1_night_1_session', '1_night_2_sessions', '2_nights_3_sessions'])) {
        sendError('Invalid package type', 'VALIDATION_ERROR', 422);
    }
    
    if (!in_array($data['accommodation_type'], ['tent', 'dorm', 'cottage'])) {
        sendError('Invalid accommodation type', 'VALIDATION_ERROR', 422);
    }
    
    if (!in_array($data['service_type'], ['surf', 'sup'])) {
        sendError('Service type must be either "surf" or "sup"', 'VALIDATION_ERROR', 422);
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['check_in_date']) || !strtotime($data['check_in_date'])) {
        sendError('Invalid check-in date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // ✅ NEW: Validate booking window for check-in
    $bookingWindow = getWeeklyBookingWindow();
    $validDates = array_column($bookingWindow['dates'], 'date');
    
    if (!in_array($data['check_in_date'], $validDates)) {
        sendError('Check-in date is outside booking window', 'INVALID_DATE', 422);
    }
    
    // ✅ NEW: Prevent past dates
    if (strtotime($data['check_in_date']) < strtotime(date('Y-m-d'))) {
        sendError('Cannot book for past dates', 'INVALID_DATE', 422);
    }
    
    $nights = (strpos($data['package_type'], '2_nights') !== false) ? 2 : 1;
    $checkOutDate = date('Y-m-d', strtotime($data['check_in_date'] . ' +' . $nights . ' days'));
    $data['check_out_date'] = $checkOutDate;
    
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    if (count($data['people']) > 40) {
        sendError('Maximum 40 people allowed per booking', 'VALIDATION_ERROR', 422);
    }
    
    foreach ($data['people'] as $index => $person) {
        if (empty($person['name']) || empty($person['age'])) {
            sendError("Name and age required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
            sendError("Age must be between 5 and 100 for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    if (!is_array($data['sessions']) || empty($data['sessions'])) {
        sendError('Session information is required', 'VALIDATION_ERROR', 422);
    }
    
    foreach ($data['sessions'] as $index => $session) {
        if (empty($session['session_date']) || !strtotime($session['session_date'])) {
            sendError("Valid session date is required for session " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        // ✅ NEW: Validate each session date is in booking window
        if (!in_array($session['session_date'], $validDates)) {
            sendError(
                "Session " . ($index + 1) . " date ({$session['session_date']}) is outside booking window",
                'INVALID_DATE',
                422
            );
        }
        
        // ✅ NEW: Prevent past session dates
        if (strtotime($session['session_date']) < strtotime(date('Y-m-d'))) {
            sendError("Cannot book sessions for past dates (session " . ($index + 1) . ")", 'INVALID_DATE', 422);
        }
        
        if (empty($session['slot_id']) || !is_numeric($session['slot_id'])) {
            sendError("Valid slot selection is required for session " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    // ✅ CRITICAL FIX: Validate session dates match package schedule
    $expectedSessionDates = [];
    $checkInDate = new DateTime($data['check_in_date']);
    
    if ($data['package_type'] === '1_night_1_session') {
        $sessionDate = clone $checkInDate;
        $sessionDate->modify('+1 day');
        $expectedSessionDates[] = $sessionDate->format('Y-m-d');
        
    } elseif ($data['package_type'] === '1_night_2_sessions') {
        $expectedSessionDates[] = $checkInDate->format('Y-m-d');
        $sessionDate = clone $checkInDate;
        $sessionDate->modify('+1 day');
        $expectedSessionDates[] = $sessionDate->format('Y-m-d');
        
    } elseif ($data['package_type'] === '2_nights_3_sessions') {
        $expectedSessionDates[] = $checkInDate->format('Y-m-d');
        $sessionDate = clone $checkInDate;
        $sessionDate->modify('+1 day');
        $expectedSessionDates[] = $sessionDate->format('Y-m-d');
        $sessionDate->modify('+1 day');
        $expectedSessionDates[] = $sessionDate->format('Y-m-d');
    }
    
    $actualSessionDates = array_column($data['sessions'], 'session_date');
    
    // ✅ NEW: Check for duplicate session dates
    if (count($actualSessionDates) !== count(array_unique($actualSessionDates))) {
        sendError(
            'Cannot book multiple sessions on the same date',
            'DUPLICATE_SESSION_DATE',
            422
        );
    }
    
    $sortedActualDates = $actualSessionDates;
    sort($sortedActualDates);
    
    if ($sortedActualDates !== $expectedSessionDates) {
        sendError(
            'Session dates must match package schedule. Expected: ' . implode(' → ', $expectedSessionDates),
            'INVALID_SESSION_SCHEDULE',
            422
        );
    }
    
    try {
        $slot = new Slot();
        
        // Validate all session slots
        foreach ($data['sessions'] as $session) {
            // ✅ FIX: Validate slot exists for date
            $slotValidation = $slot->validateSlotForDate($session['slot_id'], $session['session_date'], $data['service_type']);
            if (!$slotValidation) {
                sendError(
                    "Invalid slot {$session['slot_id']} for session on {$session['session_date']}",
                    'INVALID_SLOT',
                    422
                );
            }
            
            if (!$slot->hasAvailability($session['slot_id'], $session['session_date'], count($data['people']))) {
                sendError('Session slot on ' . $session['session_date'] . ' is no longer available', 'SLOT_UNAVAILABLE', 409);
            }
        }
        
        $bookingId = $booking->createPackageBooking($data);
        $bookingData = $booking->getById($bookingId);
        
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
 * ENHANCED: POST /bookings/stay
 * ✅ FIX: Added nights calculation
 * ✅ FIX: Added booking window validation
 */
function handleCreateStayBooking($booking) {
    $data = getRequestBody();
    
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'accommodation_type', 'check_in_date', 'check_out_date',
        'people'
    ]);
    
    // ✅ FIX: Validate dates FIRST before everything else
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['check_in_date']) || !strtotime($data['check_in_date'])) {
        sendError('Invalid check-in date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['check_out_date']) || !strtotime($data['check_out_date'])) {
        sendError('Invalid check-out date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    if (strtotime($data['check_out_date']) <= strtotime($data['check_in_date'])) {
        sendError('Check-out date must be after check-in date', 'VALIDATION_ERROR', 422);
    }
    
    // ✅ CRITICAL FIX: Calculate nights FIRST
    $checkIn = new DateTime($data['check_in_date']);
    $checkOut = new DateTime($data['check_out_date']);
    $nightsCount = $checkOut->diff($checkIn)->days;
    $data['nights_count'] = $nightsCount;
    
    // ✅ NEW: Validate booking window
    $bookingWindow = getWeeklyBookingWindow();
    $validDates = array_column($bookingWindow['dates'], 'date');
    
    if (!in_array($data['check_in_date'], $validDates)) {
        sendError('Check-in date is outside booking window', 'INVALID_DATE', 422);
    }
    
    // ✅ NEW: Prevent past dates
    if (strtotime($data['check_in_date']) < strtotime(date('Y-m-d'))) {
        sendError('Cannot book for past dates', 'INVALID_DATE', 422);
    }
    
    // ✅ FIX: NOW validate accommodation (needs nights_count)
    if (!in_array($data['accommodation_type'], ['tent', 'dorm', 'cottage'])) {
        sendError('Invalid accommodation type', 'VALIDATION_ERROR', 422);
    }
    
    if ($nightsCount === 6 && $data['accommodation_type'] !== 'dorm') {
        sendError('Extended stay (6 nights) is only available for dorm accommodation', 'VALIDATION_ERROR', 422);
    }
    
    if ($nightsCount === 6) {
        $data['accommodation_type'] = 'dorm';
    }
    
    $data['includes_meals'] = isset($data['includes_meals']) ? (bool)$data['includes_meals'] : false;
    
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    if (count($data['people']) > 40) {
        sendError('Maximum 40 people allowed per booking', 'VALIDATION_ERROR', 422);
    }
    
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
        $bookingData = $booking->getById($bookingId);
        
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

/**
 * LEGACY: POST /bookings/surf-sup (unchanged - backward compatibility)
 */
function handleCreateSurfSupBooking($booking) {
    $data = getRequestBody();
    
    validateRequired($data, [
        'customer_name', 'customer_email', 'customer_phone',
        'service_type', 'session_date', 'slot_id', 'people'
    ]);
    
    if (!isset($data['activity_type']) && isset($data['service_type'])) {
        $data['activity_type'] = $data['service_type'];
    }
    
    if (!in_array($data['service_type'], ['surf', 'sup'])) {
        sendError('Service type must be either "surf" or "sup"', 'VALIDATION_ERROR', 422);
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['session_date']) || !strtotime($data['session_date'])) {
        sendError('Invalid session date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    if (!is_array($data['people']) || empty($data['people'])) {
        sendError('At least one person is required', 'VALIDATION_ERROR', 422);
    }
    
    if (count($data['people']) > 40) {
        sendError('Maximum 40 people allowed per booking', 'VALIDATION_ERROR', 422);
    }
    
    foreach ($data['people'] as $index => $person) {
        if (empty($person['name']) || empty($person['age'])) {
            sendError("Name and age required for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
        
        if (!is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
            sendError("Age must be between 5 and 100 for person " . ($index + 1), 'VALIDATION_ERROR', 422);
        }
    }
    
    $slot = new Slot();
    $bookableDates = $slot->getBookableDates();
    $validDates = array_column($bookableDates, 'date');
    
    if (!in_array($data['session_date'], $validDates)) {
        sendError('Session date is outside booking window', 'VALIDATION_ERROR', 422);
    }
    
    try {
        if (!$slot->hasAvailability($data['slot_id'], $data['session_date'], count($data['people']))) {
            sendError('Selected slot is no longer available', 'SLOT_UNAVAILABLE', 409);
        }
        
        $bookingId = $booking->createSurfSupBooking($data);
        $bookingData = $booking->getById($bookingId);
        
        sendResponse(true, $bookingData, 'Surf/SUP booking created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
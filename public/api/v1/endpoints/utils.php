<?php
/**
 * Utilities Endpoint Handler
 */

function handleUtilsEndpoint($method, $resource, $id) {
    if ($method !== 'POST') {
        sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
    
    switch ($resource) {
        case 'generate-sessions':
            handleGeneratePackageSessions();
            break;
            
        default:
            sendError('Invalid utils endpoint', 'NOT_FOUND', 404);
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
    
    try {
        $booking = new Booking();
        $sessions = $booking->generatePackageSessionDates($packageType, $checkInDate);
        
        // Calculate check-out date
        $nights = (strpos($packageType, '2_nights') !== false) ? 2 : 1;
        $checkOutDate = date('Y-m-d', strtotime($checkInDate . ' +' . $nights . ' days'));
        
        // Get available slots for each session date
        $slot = new Slot();
        foreach ($sessions as &$session) {
            $availableSlots = $slot->getAvailableSlots($session['session_date']);
            $session['available_slots'] = $availableSlots;
            
            // Suggest first available slot
            $nextAvailable = $slot->getNextAvailableSlot($session['session_date'], 1);
            $session['suggested_slot'] = $nextAvailable;
        }
        
        $response = [
            'package_type' => $packageType,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'nights_count' => $nights,
            'sessions' => $sessions
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to generate session dates: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
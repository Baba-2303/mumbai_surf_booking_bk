<?php
/**
 * Slots Endpoint Handler
 */

function handleSlotsEndpoint($method, $resource, $id) {
    $slot = new Slot();
    
    switch ($method) {
        case 'GET':
            if ($resource === 'dates') {
                handleGetBookableDates($slot);
            } elseif (empty($resource)) {
                handleGetAvailableSlots($slot);
            } else {
                sendError('Invalid slots endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        default:
            sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
}

/**
 * GET /slots?date=2025-09-25&people=2
 */
function handleGetAvailableSlots($slot) {
    $date = $_GET['date'] ?? date('Y-m-d');
    $people = (int)($_GET['people'] ?? 1);
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        sendError('Invalid date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // Check if date is within booking window
    $bookableDates = $slot->getBookableDates();
    $validDates = array_column($bookableDates, 'date');
    
    if (!in_array($date, $validDates)) {
        sendError('Date is outside booking window', 'VALIDATION_ERROR', 422);
    }
    
    // Validate people count
    if ($people < 1 || $people > 40) {
        sendError('People count must be between 1 and 40', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $slots = $slot->getAvailableSlots($date);
        
        // Filter slots with enough capacity
        $availableSlots = array_filter($slots, function($slot) use ($people) {
            return $slot['available_spots'] >= $people;
        });
        
        // Format slot times
        foreach ($availableSlots as &$slotData) {
            $slotData['formatted_time'] = $slot->formatSlotTime(
                $slotData['start_time'], 
                $slotData['end_time']
            );
        }
        
        sendResponse(true, [
            'date' => $date,
            'day_name' => date('l', strtotime($date)),
            'people_count' => $people,
            'available_slots' => array_values($availableSlots),
            'total_available' => count($availableSlots)
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve slots: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /slots/dates
 */
function handleGetBookableDates($slot) {
    try {
        $dates = $slot->getBookableDates();
        
        sendResponse(true, [
            'bookable_dates' => $dates,
            'booking_window_days' => BOOKING_ADVANCE_DAYS
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve bookable dates: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
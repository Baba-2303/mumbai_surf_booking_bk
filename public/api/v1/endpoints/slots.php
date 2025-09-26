<?php
/**
 * Updated Slots Endpoint Handler
 * Now supports activity-based slot system
 */

function handleSlotsEndpoint($method, $resource, $id) {
    $slot = new Slot();
    
    switch ($method) {
        case 'GET':
            if ($resource === 'dates') {
                handleGetBookableDates($slot);
            } elseif ($resource === 'activities') {
                handleGetActivitySlots($slot);
            } elseif (empty($resource)) {
                // Legacy endpoint - maintain backward compatibility
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
 * NEW: GET /slots/activities?date=2025-09-28&activity=surf&people=3
 * Get activity-specific slot availability
 */
function handleGetActivitySlots($slot) {
    $date = $_GET['date'] ?? '';
    $activityType = $_GET['activity'] ?? '';
    $people = (int)($_GET['people'] ?? 1);
    
    // Validate required parameters
    if (empty($date)) {
        sendError('Date parameter is required', 'VALIDATION_ERROR', 422);
    }
    
    if (empty($activityType)) {
        sendError('Activity parameter is required', 'VALIDATION_ERROR', 422);
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        sendError('Invalid date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // Validate activity type
    $validActivities = array_keys(getActivityTypes());
    if (!in_array($activityType, $validActivities)) {
        sendError('Invalid activity type. Valid options: ' . implode(', ', $validActivities), 'VALIDATION_ERROR', 422);
    }
    
    // Get activity info for capacity validation
    $activityInfo = getActivityInfo($activityType);
    
    // Validate people count against activity capacity
    if ($people < 1 || $people > $activityInfo['default_capacity']) {
        sendError("People count must be between 1 and {$activityInfo['default_capacity']} for {$activityInfo['name']}", 'VALIDATION_ERROR', 422);
    }
    
    // Check if date is within booking window
    $bookableDates = $slot->getBookableDates();
    $validDates = array_column($bookableDates, 'date');
    
    if (!in_array($date, $validDates)) {
        sendError('Date is outside booking window', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Get activity-specific slots
        $activitySlots = $slot->getActivitySlots($date, $activityType);
        
        // Filter and format slots that can accommodate the requested people
        $availableSlots = [];
        foreach ($activitySlots as $slotData) {
            if ($slotData['available_spots'] >= $people) {
                $availableSlots[] = [
                    'slot_id' => $slotData['id'],
                    'start_time' => $slotData['start_time'],
                    'end_time' => $slotData['end_time'],
                    'formatted_time' => $slotData['formatted_time'],
                    'activity_type' => $slotData['activity_type'],
                    'max_capacity' => $slotData['max_capacity'],
                    'booked_count' => $slotData['booked_count'],
                    'available_spots' => $slotData['available_spots'],
                    'can_book' => true,
                    'utilization_percent' => round(($slotData['booked_count'] / $slotData['max_capacity']) * 100, 1),
                    'availability_status' => $slotData['available_spots'] > ($slotData['max_capacity'] * 0.5) ? 'good' : 
                                            ($slotData['available_spots'] > 0 ? 'limited' : 'full')
                ];
            }
        }
        
        // Sort slots by start time
        usort($availableSlots, function($a, $b) {
            return strtotime($a['start_time']) - strtotime($b['start_time']);
        });
        
        $response = [
            'date' => $date,
            'day_name' => date('l', strtotime($date)),
            'formatted_date' => date('M d, Y', strtotime($date)),
            'activity' => [
                'type' => $activityType,
                'name' => $activityInfo['name'],
                'description' => $activityInfo['description'],
                'max_capacity' => $activityInfo['default_capacity']
            ],
            'people_count' => $people,
            'available_slots' => $availableSlots,
            'total_slots_available' => count($availableSlots),
            'booking_info' => [
                'is_today' => $date === date('Y-m-d'),
                'is_weekend' => in_array(date('N', strtotime($date)), [6, 7]),
                'days_from_now' => (strtotime($date) - strtotime(date('Y-m-d'))) / 86400
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve activity slots: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * LEGACY: GET /slots?date=2025-09-25&people=2
 * Maintains backward compatibility for package bookings
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
    
    // Validate people count (legacy limits)
    if ($people < 1 || $people > 40) {
        sendError('People count must be between 1 and 40', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Get basic slot information (for package bookings)
        $slots = $slot->getAvailableSlots($date);
        
        // For each slot, check if it has availability across any activity
        $availableSlots = [];
        foreach ($slots as $slotData) {
            if ($slot->hasAvailability($slotData['id'], $date, $people)) {
                $availableSlots[] = [
                    'slot_id' => $slotData['id'],
                    'start_time' => $slotData['start_time'],
                    'end_time' => $slotData['end_time'],
                    'formatted_time' => $slotData['formatted_time'],
                    'can_book' => true
                ];
            }
        }
        
        // Sort slots by start time
        usort($availableSlots, function($a, $b) {
            return strtotime($a['start_time']) - strtotime($b['start_time']);
        });
        
        sendResponse(true, [
            'date' => $date,
            'day_name' => date('l', strtotime($date)),
            'formatted_date' => date('M d, Y', strtotime($date)),
            'people_count' => $people,
            'available_slots' => $availableSlots,
            'total_available' => count($availableSlots),
            'note' => 'Legacy endpoint - use /slots/activities for activity-specific availability'
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve slots: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /slots/dates
 * Get bookable dates within the booking window
 */
function handleGetBookableDates($slot) {
    try {
        // Use the updated weekly booking window function
        $bookingWindow = getWeeklyBookingWindow();
        
        // Enhanced date information
        $enhancedDates = [];
        foreach ($bookingWindow['dates'] as $dateInfo) {
            $enhancedDates[] = [
                'date' => $dateInfo['date'],
                'day_name' => $dateInfo['day_name'],
                'formatted_date' => $dateInfo['formatted_date'],
                'is_today' => $dateInfo['is_today'],
                'is_weekend' => $dateInfo['is_weekend'],
                'is_bookable' => true, // All dates in window are bookable
                'days_from_now' => (strtotime($dateInfo['date']) - strtotime(date('Y-m-d'))) / 86400
            ];
        }
        
        sendResponse(true, [
            'bookable_dates' => $enhancedDates,
            'booking_window' => [
                'type' => BOOKING_WINDOW_TYPE,
                'days_available' => $bookingWindow['days_available'],
                'window_end' => $bookingWindow['window_end'],
                'description' => 'Bookings available from today until next Monday'
            ],
            'current_date' => date('Y-m-d'),
            'total_dates_available' => count($enhancedDates)
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve bookable dates: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
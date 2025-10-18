<?php
/**
 * Activities Endpoint Handler
 * NEW - Handles activity types and information
 */

function handleActivitiesEndpoint($method, $resource, $id) {
    switch ($method) {
        case 'GET':
            if (empty($resource)) {
                handleGetActivityTypes();
            } elseif ($resource === 'slots') {
                handleGetActivitySlots2();
            } else {
                sendError('Invalid activities endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        default:
            sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
}

/**
 * GET /activities
 * Get all available activity types with their information
 */
function handleGetActivityTypes() {
    try {
        $activityTypes = getActivityTypes();
        
        // Convert to array format for response
        $activities = [];
        foreach ($activityTypes as $type => $info) {
            $activities[] = [
                'type' => $type,
                'name' => $info['name'],
                'description' => $info['description'],
                'default_capacity' => $info['default_capacity'],
                'price_per_person' => $info['price_per_person'],
                'formatted_price' => formatCurrency($info['price_per_person'])
            ];
        }
        
        sendResponse(true, [
            'activities' => $activities,
            'total_count' => count($activities)
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve activity types: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /activities/slots?date=2025-09-28&activity=surf&people=3
 * Get activity-specific slot availability
 */
function handleGetActivitySlots2() {
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
    
    // Validate people count
    if ($people < 1 || $people > 100) {
        sendError('People count must be between 1 and 100', 'VALIDATION_ERROR', 422);
    }
    
    // Check if date is within booking window
    $slot = new Slot();
    $bookableDates = $slot->getBookableDates();
    $validDates = array_column($bookableDates, 'date');
    
    if (!in_array($date, $validDates)) {
        sendError('Date is outside booking window', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Get activity-specific slots
        $slots = $slot->getActivitySlots($date, $activityType);
        
        // Filter slots that can accommodate the requested number of people
        $availableSlots = [];
        foreach ($slots as $slotData) {
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
                    'utilization_percent' => round(($slotData['booked_count'] / $slotData['max_capacity']) * 100, 1)
                ];
            }
        }
        
        // Get activity information
        $activityInfo = getActivityInfo($activityType);
        
        $response = [
            'date' => $date,
            'day_name' => date('l', strtotime($date)),
            'formatted_date' => date('M d, Y', strtotime($date)),
            'activity' => [
                'type' => $activityType,
                'name' => $activityInfo['name'],
                'description' => $activityInfo['description'],
                'default_capacity' => $activityInfo['default_capacity']
            ],
            'people_count' => $people,
            'available_slots' => $availableSlots,
            'total_slots_available' => count($availableSlots),
            'booking_window_info' => [
                'is_today' => $date === date('Y-m-d'),
                'is_weekend' => in_array(date('N', strtotime($date)), [6, 7])
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve activity slots: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
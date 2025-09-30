<?php
/**
 * Enhanced Utilities Endpoint Handler
 * Enhanced with activity-based system utilities and package flow improvements
 */

function handleUtilsEndpoint($method, $resource, $id) {
    switch ($method) {
        case 'GET':
            if ($resource === 'activities') {
                handleGetActivityInfo();
            } elseif ($resource === 'booking-window') {
                handleGetBookingWindow();
            } elseif ($resource === 'package-info') {
                handleGetPackageInfo();
            } elseif ($resource === 'stay-info') {  // ADD THIS
                handleGetStayInfo();
            } else {
                sendError('Invalid utils GET endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        case 'POST':
            if ($resource === 'generate-sessions') {
                handleGeneratePackageSessions();
            } elseif ($resource === 'validate-booking') {
                handleValidateBookingData();
            } elseif ($resource === 'validate-package-sessions') {
                handleValidatePackageSessions();
            } else {
                sendError('Invalid utils POST endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        default:
            sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
}

/**
 * NEW: GET /utils/package-info
 * Get package information with descriptions and pricing
 */
function handleGetPackageInfo() {
    try {
        $packages = [
            '1_night_1_session' => [
                'name' => '1 Night 1 Surf Session',
                'short_name' => '1N1S',
                'description' => 'Overnight stay with morning surf session on checkout day',
                'nights' => 1,
                'sessions' => 1,
                'session_schedule' => [
                    ['day' => 2, 'time' => 'morning', 'description' => 'Morning session on checkout day']
                ],
                'includes' => ['Accommodation', '1 Surf/SUP session', 'All meals', 'Equipment']
            ],
            '1_night_2_sessions' => [
                'name' => '1 Night 2 Surf Sessions',
                'short_name' => '1N2S', 
                'description' => 'Overnight stay with surf sessions on both days',
                'nights' => 1,
                'sessions' => 2,
                'session_schedule' => [
                    ['day' => 1, 'time' => 'afternoon', 'description' => 'Afternoon session on check-in day'],
                    ['day' => 2, 'time' => 'morning', 'description' => 'Morning session on checkout day']
                ],
                'includes' => ['Accommodation', '2 Surf/SUP sessions', 'All meals', 'Equipment']
            ],
            '2_nights_3_sessions' => [
                'name' => '2 Nights 3 Surf Sessions',
                'short_name' => '2N3S',
                'description' => 'Two night stay with surf sessions on all three days', 
                'nights' => 2,
                'sessions' => 3,
                'session_schedule' => [
                    ['day' => 1, 'time' => 'afternoon', 'description' => 'Afternoon session on check-in day'],
                    ['day' => 2, 'time' => 'morning', 'description' => 'Session on day 2'],
                    ['day' => 3, 'time' => 'morning', 'description' => 'Morning session on checkout day']
                ],
                'includes' => ['Accommodation', '3 Surf/SUP sessions', 'All meals', 'Equipment']
            ]
        ];
        
        // Add pricing info for each package
        foreach ($packages as $type => &$package) {
            $package['pricing'] = [
                'tent' => PACKAGE_PRICES[$type]['tent'],
                'dorm' => PACKAGE_PRICES[$type]['dorm'], 
                'cottage_base' => PACKAGE_PRICES[$type]['cottage_1'],
                'pricing_note' => 'Cottage pricing varies by occupancy (1-4 people)'
            ];
            $package['formatted_pricing'] = [
                'tent' => formatCurrency(PACKAGE_PRICES[$type]['tent']),
                'dorm' => formatCurrency(PACKAGE_PRICES[$type]['dorm']),
                'cottage_base' => formatCurrency(PACKAGE_PRICES[$type]['cottage_1'])
            ];
        }
        
        sendResponse(true, [
            'packages' => $packages,
            'available_activities' => ['surf', 'sup'],
            'accommodation_types' => [
                'tent' => 'Tent accommodation - Basic outdoor experience',
                'dorm' => 'Hostel dorm - Shared accommodation with modern amenities', 
                'cottage' => 'Private cottage - Exclusive accommodation for groups (1-4 people)'
            ],
            'general_inclusions' => [
                'All equipment provided',
                'Professional instruction',
                'Safety briefing',
                'Campus facilities access'
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get package info: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: GET /utils/stay-info
 * Get stay accommodation information and pricing
 */
function handleGetStayInfo() {
    try {
        $stayOptions = [
            'tent' => [
                'name' => 'Tent Accommodation',
                'description' => 'Basic outdoor camping experience. Sleeping bags provided.',
                'capacity_per_unit' => ACCOMMODATION_CAPACITY['tent']['max_people_per_unit'],
                'total_units' => ACCOMMODATION_CAPACITY['tent']['total_units'],
                'max_capacity' => ACCOMMODATION_CAPACITY['tent']['max_total_capacity'],
                'features' => ['Sleeping bags', 'Common washrooms', 'Shared facilities'],
                'pricing' => [
                    'without_meals' => STAY_PRICES['tent']['without_meals'],
                    'with_meals' => STAY_PRICES['tent']['with_meals']
                ],
                'formatted_pricing' => [
                    'without_meals' => formatCurrency(STAY_PRICES['tent']['without_meals']) . ' per person per night',
                    'with_meals' => formatCurrency(STAY_PRICES['tent']['with_meals']) . ' per person per night (includes breakfast & dinner)'
                ]
            ],
            'dorm' => [
                'name' => 'Hostel Dorm',
                'description' => 'Shared accommodation with modern amenities. Air conditioned.',
                'capacity_per_unit' => ACCOMMODATION_CAPACITY['dorm']['max_people_per_unit'],
                'total_units' => ACCOMMODATION_CAPACITY['dorm']['total_units'],
                'max_capacity' => ACCOMMODATION_CAPACITY['dorm']['max_total_capacity'],
                'features' => ['Air conditioning', 'Fresh bedding', 'Common washroom', 'Co-ed sleeping'],
                'pricing' => [
                    'without_meals' => STAY_PRICES['dorm']['without_meals'],
                    'with_meals' => STAY_PRICES['dorm']['with_meals'],
                    'extended_stay_without_meals' => EXTENDED_ADVENTURE['dorm']['without_meals'],
                    'extended_stay_with_meals' => EXTENDED_ADVENTURE['dorm']['with_meals']
                ],
                'formatted_pricing' => [
                    'without_meals' => formatCurrency(STAY_PRICES['dorm']['without_meals']) . ' per person per night',
                    'with_meals' => formatCurrency(STAY_PRICES['dorm']['with_meals']) . ' per person per night (includes breakfast & dinner)',
                    'extended_stay' => formatCurrency(EXTENDED_ADVENTURE['dorm']['with_meals']) . ' per person for 6 nights/7 days (all meals included)'
                ],
                'extended_stay_available' => true
            ],
            'cottage' => [
                'name' => 'Private Cottage',
                'description' => 'Exclusive private accommodation for groups. Pet friendly.',
                'capacity_per_unit' => ACCOMMODATION_CAPACITY['cottage']['max_people_per_unit'],
                'total_units' => ACCOMMODATION_CAPACITY['cottage']['total_units'],
                'max_capacity' => ACCOMMODATION_CAPACITY['cottage']['max_total_capacity'],
                'features' => ['2 double beds', 'Hot water', 'Air conditioning', 'Attached washroom', 'Pet friendly', 'Private space'],
                'pricing' => [
                    'base_per_cottage' => STAY_PRICES['cottage']['base_price'],
                    'meal_per_person' => STAY_PRICES['cottage']['meal_price_per_person']
                ],
                'formatted_pricing' => [
                    'base' => formatCurrency(STAY_PRICES['cottage']['base_price']) . ' per cottage per night (1-4 people)',
                    'meals' => '+ ' . formatCurrency(STAY_PRICES['cottage']['meal_price_per_person']) . ' per person for meals (breakfast & dinner)'
                ],
                'pricing_note' => 'Cottage price is flat per night. Meals are optional and charged per person.'
            ]
        ];
        
        sendResponse(true, [
            'accommodation_types' => $stayOptions,
            'extended_stay_info' => [
                'duration' => '6 nights / 7 days',
                'available_for' => ['dorm'],
                'description' => 'Extended adventure package available only for dorm accommodation',
                'includes' => '6 nights accommodation + 6 breakfasts, lunches, and dinners'
            ],
            'check_in_out' => [
                'check_in_time' => '12:00 PM',
                'check_out_time' => '10:00 AM'
            ],
            'policies' => [
                'pet_policy' => 'Pets allowed only in cottages',
                'meal_times' => 'Breakfast after activities, Dinner at 8 PM',
                'campus_access' => 'Full access to campus facilities and activities'
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get stay info: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /utils/activities
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
 * GET /utils/booking-window
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
 * ENHANCED: POST /utils/generate-sessions
 * Generate session dates for package bookings with auto-allocation
 */
function handleGeneratePackageSessions() {
    $data = getRequestBody();
    
    validateRequired($data, ['package_type', 'check_in_date']);
    
    $packageType = $data['package_type'];
    $checkInDate = $data['check_in_date'];
    $activityType = $data['activity_type'] ?? 'surf'; // Default to surf
    $peopleCount = $data['people_count'] ?? 1;
    
    // Validate package type
    if (!in_array($packageType, ['1_night_1_session', '1_night_2_sessions', '2_nights_3_sessions'])) {
        sendError('Invalid package type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate activity type (packages support surf/sup only)
    if (!in_array($activityType, ['surf', 'sup'])) {
        sendError('Invalid activity type for packages. Use surf or sup.', 'VALIDATION_ERROR', 422);
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkInDate) || !strtotime($checkInDate)) {
        sendError('Invalid check-in date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    // IMPORTANT: Check if check-in date is within booking window
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
        
        // ENHANCED: Get available slots with auto-allocation logic
        $slot = new Slot();
        $autoAllocatedCount = 0;
        $failedAllocations = [];
        
        foreach ($sessions as &$session) {
            $availableSlots = $slot->getAvailableSlots($session['session_date']);
            
            // Format slots for better response with capacity check
            $formattedSlots = [];
            $autoAllocatedSlot = null;
            
            foreach ($availableSlots as $slotData) {
                $hasCapacity = $slot->hasAvailability($slotData['id'], $session['session_date'], $peopleCount);
                
                $formattedSlot = [
                    'slot_id' => $slotData['id'],
                    'start_time' => $slotData['start_time'],
                    'end_time' => $slotData['end_time'],
                    'formatted_time' => $slotData['formatted_time'],
                    'can_book' => $hasCapacity,
                    'is_auto_allocated' => false
                ];
                
                // Auto-allocate first available slot if none allocated yet
                if ($hasCapacity && !$autoAllocatedSlot) {
                    $autoAllocatedSlot = $formattedSlot;
                    $autoAllocatedSlot['is_auto_allocated'] = true;
                    $autoAllocatedCount++;
                }
                
                $formattedSlots[] = $formattedSlot;
            }
            
            $session['available_slots'] = $formattedSlots;
            $session['slots_count'] = count($formattedSlots);
            $session['auto_allocated_slot'] = $autoAllocatedSlot;
            
            // Mark allocation status
            if ($autoAllocatedSlot) {
                $session['allocation_status'] = 'allocated';
                $session['recommended_slot_id'] = $autoAllocatedSlot['slot_id'];
            } else {
                $session['allocation_status'] = 'no_capacity';
                $failedAllocations[] = [
                    'date' => $session['session_date'],
                    'reason' => 'No slots available with capacity for ' . $peopleCount . ' people'
                ];
            }
            
            // Add day context for better UX
            $session['day_context'] = [
                'is_checkin_day' => $session['session_date'] === $checkInDate,
                'is_checkout_day' => $session['session_date'] === $checkOutDate,
                'days_from_checkin' => (strtotime($session['session_date']) - strtotime($checkInDate)) / 86400
            ];
        }
        $canProceedWithBooking = empty($failedAllocations);

        $response = [
            'package_type' => $packageType,
            'activity_type' => $activityType,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'nights_count' => $nights,
            'sessions_count' => count($sessions),
            'sessions' => $sessions,
            'auto_allocation_summary' => [
                'total_sessions' => count($sessions),
                'auto_allocated' => $autoAllocatedCount,
                'failed_allocations' => count($failedAllocations),
                'can_proceed' => $canProceedWithBooking,
                'allocation_success_rate' => count($sessions) > 0 ? 
                    round(($autoAllocatedCount / count($sessions)) * 100, 1) : 0,
                'message' => $canProceedWithBooking ? 
                    'All sessions can be auto-allocated. Proceed with booking.' : 
                    'Some sessions have no availability. Booking will proceed with accommodation only.'
            ],
            'failed_allocations' => $failedAllocations,
            'booking_policy' => [
                'accommodation_guaranteed' => true,
                'session_allocation' => $canProceedWithBooking ? 'automatic' : 'manual_by_staff',
                'note' => !$canProceedWithBooking ? 'Sessions will be scheduled by our staff based on availability during your stay' : null
            ],
            'booking_summary' => [
                'total_nights' => $nights,
                'total_sessions' => count($sessions),
                'session_dates' => array_column($sessions, 'session_date'),
                'activity_type' => $activityType
            ],
            'recommendations' => $failedAllocations > 0 ? [
                'Consider selecting different dates with better availability',
                'Reduce group size if possible',
                'Contact us for alternative arrangements'
            ] : [
                'All sessions can be automatically allocated',
                'You can change slot times if needed',
                'Proceed to booking confirmation'
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to generate session dates: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: POST /utils/validate-package-sessions
 * Validate package session selections with auto-allocation fallback
 */
function handleValidatePackageSessions() {
    $data = getRequestBody();
    
    validateRequired($data, ['package_type', 'sessions', 'people_count']);
    
    $packageType = $data['package_type'];
    $sessions = $data['sessions'];
    $peopleCount = (int)$data['people_count'];
    
    $errors = [];
    $autoAllocations = [];
    $warnings = [];
    
    try {
        $slot = new Slot();
        
        // Check expected session count
        $expectedSessions = 1;
        if (strpos($packageType, '2_sessions') !== false) $expectedSessions = 2;
        if (strpos($packageType, '3_sessions') !== false) $expectedSessions = 3;
        
        if (count($sessions) !== $expectedSessions) {
            $errors[] = "Package requires exactly $expectedSessions sessions, got " . count($sessions);
        }
        
        // Validate each session
        foreach ($sessions as $index => $session) {
            $sessionNum = $index + 1;
            
            // Check if session date is provided
            if (empty($session['session_date'])) {
                $errors[] = "Session date required for session $sessionNum";
                continue;
            }
            
            // Validate date is within booking window
            $bookingWindow = getWeeklyBookingWindow();
            $validDates = array_column($bookingWindow['dates'], 'date');
            
            if (!in_array($session['session_date'], $validDates)) {
                $errors[] = "Session $sessionNum date is outside booking window";
                continue;
            }
            
            if (empty($session['slot_id'])) {
                // Auto-allocate if slot_id is missing
                $autoSlot = $slot->getNextAvailableSlot($session['session_date'], $peopleCount);
                if ($autoSlot) {
                    $autoAllocations[] = [
                        'session_number' => $sessionNum,
                        'session_date' => $session['session_date'],
                        'allocated_slot_id' => $autoSlot['id'],
                        'formatted_time' => $slot->formatSlotTime($autoSlot['start_time'], $autoSlot['end_time']),
                        'reason' => 'No slot selected - auto-allocated first available'
                    ];
                } else {
                    $errors[] = "No available slots for session $sessionNum on {$session['session_date']}";
                }
            } else {
                // Validate provided slot
                if (!$slot->hasAvailability($session['slot_id'], $session['session_date'], $peopleCount)) {
                    // Try to find alternative
                    $alternativeSlot = $slot->getNextAvailableSlot($session['session_date'], $peopleCount);
                    if ($alternativeSlot) {
                        $warnings[] = "Selected slot for session $sessionNum is full. Alternative slot available: " . 
                                     $slot->formatSlotTime($alternativeSlot['start_time'], $alternativeSlot['end_time']);
                        $autoAllocations[] = [
                            'session_number' => $sessionNum,
                            'session_date' => $session['session_date'],
                            'allocated_slot_id' => $alternativeSlot['id'],
                            'formatted_time' => $slot->formatSlotTime($alternativeSlot['start_time'], $alternativeSlot['end_time']),
                            'reason' => 'Selected slot full - auto-allocated alternative'
                        ];
                    } else {
                        $errors[] = "Selected slot for session $sessionNum is no longer available and no alternatives found";
                    }
                }
            }
        }
        
        $response = [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'auto_allocations' => $autoAllocations,
            'validation_summary' => [
                'expected_sessions' => $expectedSessions,
                'provided_sessions' => count($sessions),
                'auto_allocated_count' => count($autoAllocations),
                'error_count' => count($errors),
                'warning_count' => count($warnings)
            ],
            'next_steps' => empty($errors) ? [
                'Validation successful',
                count($autoAllocations) > 0 ? 'Some slots were auto-allocated' : 'All selected slots are available',
                'Proceed to create package booking'
            ] : [
                'Fix validation errors before proceeding',
                'Consider selecting different dates or reducing group size'
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to validate package sessions: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /utils/validate-booking
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
    
    // Check booking window for activity bookings
    if (!empty($data['session_date'])) {
        $bookingWindow = getWeeklyBookingWindow();
        $validDates = array_column($bookingWindow['dates'], 'date');
        
        if (!in_array($data['session_date'], $validDates)) {
            $errors[] = 'Session date is outside booking window';
        }
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
    
    // Check booking window for check-in date
    if (!empty($data['check_in_date'])) {
        $bookingWindow = getWeeklyBookingWindow();
        $validDates = array_column($bookingWindow['dates'], 'date');
        
        if (!in_array($data['check_in_date'], $validDates)) {
            $errors[] = 'Check-in date is outside booking window';
        }
    }
    
    // Validate session count matches package type
    if (!empty($data['sessions']) && !empty($data['package_type'])) {
        $expectedSessions = 1;
        if (strpos($data['package_type'], '2_sessions') !== false) $expectedSessions = 2;
        if (strpos($data['package_type'], '3_sessions') !== false) $expectedSessions = 3;
        
        if (count($data['sessions']) !== $expectedSessions) {
            $errors[] = "Package requires exactly $expectedSessions sessions";
        }
    }
    
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
    
    // Check booking window for check-in date
    if (!empty($data['check_in_date'])) {
        $bookingWindow = getWeeklyBookingWindow();
        $validDates = array_column($bookingWindow['dates'], 'date');
        
        if (!in_array($data['check_in_date'], $validDates)) {
            $errors[] = 'Check-in date is outside booking window';
        }
    }
    
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
        } elseif ($bookingType === 'package') {
            $peopleCount = count($data['people']);
            foreach ($data['sessions'] as $session) {
                if (!empty($session['slot_id']) && !$slot->hasAvailability($session['slot_id'], $session['session_date'], $peopleCount)) {
                    $warnings[] = "Session slot on {$session['session_date']} may no longer be available";
                }
            }
        }
        
    } catch (Exception $e) {
        $warnings[] = 'Could not verify current availability';
    }
    
    return $warnings;
}
?>
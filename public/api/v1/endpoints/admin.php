<?php
/**
 * Updated Admin Endpoint Handler
 * Enhanced for activity-based booking system
 */

function handleAdminEndpoint($method, $resource, $id, $action) {
    // Handle login separately (no auth required)
    if ($resource === 'login' && $method === 'POST') {
        handleAdminLogin();
        return;
    }
    
    // All other admin endpoints require authentication
    requireAuth();
    
    switch ($method) {
        case 'GET':
            if ($resource === 'dashboard') {
                handleGetDashboard();
            } elseif ($resource === 'bookings') {
                handleGetAllBookings();
            } elseif ($resource === 'customers') {
                if ($id) {
                    handleGetCustomerDetails($id);
                } else {
                    handleGetAllCustomers();
                }
            } elseif ($resource === 'slots') {
                if (isset($_GET['report']) && $_GET['report'] === 'availability') {
                    handleGetAvailabilityReport();
                } elseif (isset($_GET['report']) && $_GET['report'] === 'utilization') {
                    handleGetUtilizationReport();
                } else {
                    handleGetWeeklySchedule();
                }
            } elseif ($resource === 'activities') {
                if ($id) {
                    handleGetActivityDetails($id);
                } else {
                    handleGetActivityOverview();
                }
            } else {
                sendError('Invalid admin endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        case 'POST':
            if ($resource === 'slots') {
                handleCreateSlot();
            } elseif ($resource === 'activities' && $action === 'capacity') {
                handleSetActivityCapacity();
            } else {
                sendError('Invalid admin endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        case 'PUT':
            if ($resource === 'bookings' && $id) {
                if ($action === 'payment') {
                    handleUpdatePaymentStatus($id);
                } elseif ($action === 'cancel') {
                    handleCancelBooking($id);
                } else {
                    sendError('Invalid booking action', 'VALIDATION_ERROR', 422);
                }
            } elseif ($resource === 'slots' && $id) {
                if ($action === 'capacity') {
                    handleUpdateSlotCapacity($id);
                } else {
                    handleUpdateSlot($id);
                }
            } else {
                sendError('Invalid admin endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        case 'DELETE':
            if ($resource === 'slots' && $id) {
                handleDeactivateSlot($id);
            } else {
                sendError('Invalid admin endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        default:
            sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
}

/**
 * POST /admin/login
 */
function handleAdminLogin() {
    $data = getRequestBody();
    
    validateRequired($data, ['username', 'password']);

    // ADD RATE LIMITING HERE
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkLoginRateLimit($ip)) {
        sendError('Too many login attempts. Please try again in 15 minutes.', 'RATE_LIMITED', 429);
    }
    
    try {
        $loginResult = adminLogin($data['username'], $data['password']);
        
        if (!$loginResult) {
            // Log failed attempt
            logFailedLogin($ip, $data['username']);
            sendError('Invalid username or password', 'UNAUTHORIZED', 401);
        }
        
        sendResponse(true, $loginResult, 'Login successful');
        
    } catch (Exception $e) {
        sendError('Login failed: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

function checkLoginRateLimit($identifier, $maxAttempts = 5, $window = 900) {
    $cacheDir = dirname(__DIR__, 3) . '/logs/rate_limits';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/login_' . md5($identifier) . '.json';
    
    $attempts = [];
    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $attempts = json_decode($content, true) ?: [];
    }
    
    $now = time();
    $attempts = array_filter($attempts, function($timestamp) use ($now, $window) {
        return ($now - $timestamp) < $window;
    });
    
    if (count($attempts) >= $maxAttempts) {
        return false;
    }
    
    $attempts[] = $now;
    file_put_contents($cacheFile, json_encode($attempts));
    
    return true;
}

function logFailedLogin($ip, $username) {
    $logDir = dirname(__DIR__, 3) . '/logs';
    $logFile = $logDir . '/failed_logins.log';
    
    $logEntry = date('Y-m-d H:i:s') . " - IP: $ip - Username: $username\n";
    error_log($logEntry, 3, $logFile);
}

/**
 * GET /admin/dashboard
 * Enhanced with activity-specific metrics
 */
function handleGetDashboard() {
    try {
        $booking = new Booking();
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $stats = $booking->getStats($dateFrom, $dateTo);
        
        // Get today's sessions with activity breakdown
        $db = Database::getInstance();
        $todaysSessions = $db->fetchAll(
            "SELECT b.id, b.total_people, c.name as customer_name, 
                    ab.activity_type, ab.session_date, s.start_time, s.end_time
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             JOIN activity_bookings ab ON b.id = ab.booking_id
             JOIN slots s ON ab.slot_id = s.id
             WHERE ab.session_date = CURDATE() AND b.booking_status = 'confirmed'
             
             UNION ALL
             
             SELECT b.id, b.total_people, c.name as customer_name,
                    pb.service_type as activity_type, ps.session_date, s.start_time, s.end_time
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             JOIN package_bookings pb ON b.id = pb.booking_id
             JOIN package_sessions ps ON pb.id = ps.package_booking_id
             JOIN slots s ON ps.slot_id = s.id
             WHERE ps.session_date = CURDATE() AND b.booking_status = 'confirmed'
             
             ORDER BY start_time"
        );
        
        // Get activity breakdown for today
        $activityBreakdown = $db->fetchAll(
            "SELECT 
                COALESCE(ab.activity_type, pb.service_type) as activity_type,
                COUNT(*) as booking_count,
                SUM(b.total_people) as total_people
             FROM bookings b
             LEFT JOIN activity_bookings ab ON b.id = ab.booking_id AND ab.session_date = CURDATE()
             LEFT JOIN package_bookings pb ON b.id = pb.booking_id
             LEFT JOIN package_sessions ps ON pb.id = ps.package_booking_id AND ps.session_date = CURDATE()
             WHERE (ab.session_date = CURDATE() OR ps.session_date = CURDATE()) 
               AND b.booking_status = 'confirmed'
             GROUP BY COALESCE(ab.activity_type, pb.service_type)"
        );
        
        // Get slot utilization for today
        $slot = new Slot();
        $utilizationStats = $slot->getActivityUtilizationStats(date('Y-m-d'));
        
        // Get recent bookings
        $recentBookings = $db->fetchAll(
            "SELECT b.*, c.name as customer_name, c.email as customer_email,
                    CASE 
                        WHEN b.booking_type = 'activity' THEN ab.activity_type
                        WHEN b.booking_type = 'package' THEN pb.service_type
                        ELSE 'stay_only'
                    END as activity_type
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             LEFT JOIN activity_bookings ab ON b.id = ab.booking_id
             LEFT JOIN package_bookings pb ON b.id = pb.booking_id
             ORDER BY b.created_at DESC
             LIMIT 10"
        );
        
        $dashboardData = [
            'statistics' => $stats,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo
            ],
            'todays_overview' => [
                'sessions' => $todaysSessions,
                'total_sessions' => count($todaysSessions),
                'activity_breakdown' => $activityBreakdown,
                'utilization_stats' => $utilizationStats
            ],
            'recent_bookings' => $recentBookings,
            'quick_stats' => [
                'total_customers_today' => count(array_unique(array_column($todaysSessions, 'customer_name'))),
                'total_people_today' => array_sum(array_column($todaysSessions, 'total_people')),
                'peak_activity' => !empty($activityBreakdown) ? $activityBreakdown[0]['activity_type'] : 'none'
            ]
        ];
        
        sendResponse(true, $dashboardData);
        
    } catch (Exception $e) {
        sendError('Failed to load dashboard: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: GET /admin/activities
 * Get activity overview with capacity and bookings
 */
function handleGetActivityOverview() {
    try {
        $db = Database::getInstance();
        $activityTypes = getActivityTypes();
        
        $activities = [];
        foreach ($activityTypes as $type => $info) {
            // Get total configured slots for this activity
            $slotStats = $db->fetch(
                "SELECT 
                    COUNT(*) as configured_slots,
                    SUM(max_capacity) as total_capacity
                 FROM slot_activities sa
                 JOIN slots s ON sa.slot_id = s.id
                 WHERE sa.activity_type = ? AND s.is_active = 1",
                [$type]
            );
            
            // Get booking stats for last 30 days
            $bookingStats = $db->fetch(
                "SELECT 
                    COUNT(*) as total_bookings,
                    SUM(b.total_people) as total_people,
                    AVG(b.total_people) as avg_group_size
                 FROM bookings b
                 JOIN activity_bookings ab ON b.id = ab.booking_id
                 WHERE ab.activity_type = ? 
                   AND b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   AND b.booking_status = 'confirmed'",
                [$type]
            );
            
            $activities[] = [
                'type' => $type,
                'name' => $info['name'],
                'description' => $info['description'],
                'default_capacity' => $info['default_capacity'],
                'price_per_person' => $info['price_per_person'],
                'slot_configuration' => [
                    'configured_slots' => (int)($slotStats['configured_slots'] ?? 0),
                    'total_capacity' => (int)($slotStats['total_capacity'] ?? 0),
                    'average_capacity_per_slot' => $slotStats['configured_slots'] > 0 ? 
                        round($slotStats['total_capacity'] / $slotStats['configured_slots'], 1) : 0
                ],
                'booking_stats_30_days' => [
                    'total_bookings' => (int)($bookingStats['total_bookings'] ?? 0),
                    'total_people' => (int)($bookingStats['total_people'] ?? 0),
                    'average_group_size' => round($bookingStats['avg_group_size'] ?? 0, 1)
                ]
            ];
        }
        
        sendResponse(true, [
            'activities' => $activities,
            'total_activity_types' => count($activities)
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to load activity overview: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: GET /admin/slots?report=utilization&date=2025-09-28
 * Get detailed utilization report for specific date
 */
function handleGetUtilizationReport() {
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        sendError('Invalid date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $slot = new Slot();
        $utilizationStats = $slot->getActivityUtilizationStats($date);
        
        // Group by slot and format data
        $slotUtilization = [];
        $activityTotals = [];
        
        foreach ($utilizationStats as $stat) {
            $slotKey = $stat['id'] . '_' . $stat['start_time'];
            
            if (!isset($slotUtilization[$slotKey])) {
                $slotUtilization[$slotKey] = [
                    'slot_id' => $stat['id'],
                    'start_time' => $stat['start_time'],
                    'end_time' => $stat['end_time'],
                    'formatted_time' => $slot->formatSlotTime($stat['start_time'], $stat['end_time']),
                    'activities' => []
                ];
            }
            
            $slotUtilization[$slotKey]['activities'][] = [
                'activity_type' => $stat['activity_type'],
                'max_capacity' => $stat['max_capacity'],
                'booked_count' => $stat['booked_count'],
                'available_spots' => $stat['max_capacity'] - $stat['booked_count'],
                'utilization_percent' => $stat['utilization_percent']
            ];
            
            // Track activity totals
            if (!isset($activityTotals[$stat['activity_type']])) {
                $activityTotals[$stat['activity_type']] = [
                    'total_capacity' => 0,
                    'total_booked' => 0
                ];
            }
            $activityTotals[$stat['activity_type']]['total_capacity'] += $stat['max_capacity'];
            $activityTotals[$stat['activity_type']]['total_booked'] += $stat['booked_count'];
        }
        
        // Calculate overall utilization
        foreach ($activityTotals as $type => &$totals) {
            $totals['utilization_percent'] = $totals['total_capacity'] > 0 ? 
                round(($totals['total_booked'] / $totals['total_capacity']) * 100, 1) : 0;
        }
        
        sendResponse(true, [
            'date' => $date,
            'day_name' => date('l', strtotime($date)),
            'slot_utilization' => array_values($slotUtilization),
            'activity_totals' => $activityTotals,
            'overall_stats' => [
                'total_slots' => count($slotUtilization),
                'total_activities' => count($activityTotals),
                'average_utilization' => count($activityTotals) > 0 ? 
                    round(array_sum(array_column($activityTotals, 'utilization_percent')) / count($activityTotals), 1) : 0
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to generate utilization report: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * NEW: POST /admin/activities/capacity
 * Set activity capacity for specific slots
 */
function handleSetActivityCapacity() {
    $data = getRequestBody();
    
    validateRequired($data, ['slot_id', 'activity_capacities']);
    
    $slotId = (int)$data['slot_id'];
    $capacities = $data['activity_capacities'];
    
    if (!is_array($capacities)) {
        sendError('Activity capacities must be an array', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $slot = new Slot();
        
        // Verify slot exists
        if (!$slot->getById($slotId)) {
            sendError('Slot not found', 'NOT_FOUND', 404);
        }
        
        $db = Database::getInstance();
        $db->beginTransaction();
        
        foreach ($capacities as $activityType => $capacity) {
            // Validate activity type
            $validActivities = array_keys(getActivityTypes());
            if (!in_array($activityType, $validActivities)) {
                throw new Exception("Invalid activity type: $activityType");
            }
            
            // Validate capacity
            if (!is_numeric($capacity) || $capacity < 0 || $capacity > 100) {
                throw new Exception("Invalid capacity for $activityType. Must be between 0 and 100.");
            }
            
            // Update or insert capacity
            $slot->updateActivityCapacity($slotId, $activityType, (int)$capacity);
        }
        
        $db->commit();
        
        // Return updated slot with activities
        $updatedSlot = $slot->getSlotWithActivities($slotId);
        
        sendResponse(true, $updatedSlot, 'Activity capacities updated successfully');
        
    } catch (Exception $e) {
        if ($db->hasActiveTransaction()) {
            $db->rollback();
        }
        sendError('Failed to update activity capacities: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * Enhanced GET /admin/bookings with activity filtering
 */
function handleGetAllBookings() {
    try {
        $booking = new Booking();
        
        // Enhanced filter parameters
        $filters = [
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'booking_type' => $_GET['booking_type'] ?? '',
            'activity_type' => $_GET['activity_type'] ?? '', // NEW
            'payment_status' => $_GET['payment_status'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];
        
        $bookings = $booking->getAll($filters);
        
        // Add activity type information to each booking
        $db = Database::getInstance();
        foreach ($bookings as &$bookingData) {
            if ($bookingData['booking_type'] === 'activity') {
                $activityInfo = $db->fetch(
                    "SELECT activity_type FROM activity_bookings WHERE booking_id = ?",
                    [$bookingData['id']]
                );
                $bookingData['activity_type'] = $activityInfo['activity_type'] ?? null;
            }
        }
        
        sendResponse(true, [
            'bookings' => $bookings,
            'filters_applied' => array_filter($filters),
            'total_count' => count($bookings),
            'available_filters' => [
                'booking_types' => ['activity', 'package', 'stay_only'],
                'activity_types' => array_keys(getActivityTypes()),
                'payment_statuses' => ['pending', 'completed', 'failed']
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve bookings: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

// Keep all existing methods with minimal changes:
// - handleGetAllCustomers()
// - handleGetCustomerDetails()
// - handleGetWeeklySchedule()
// - handleGetAvailabilityReport()
// - handleCreateSlot()
// - handleUpdateSlot()
// - handleDeactivateSlot()
// - handleUpdatePaymentStatus()
// - handleCancelBooking()

/**
 * GET /admin/customers
 */
function handleGetAllCustomers() {
    try {
        $customer = new Customer();
        
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        $search = $_GET['search'] ?? '';
        
        if ($limit > 200) {
            $limit = 200;
        }
        
        $customers = $customer->getAll($limit, $offset, $search);
        $totalCount = $customer->countAll($search);
        
        sendResponse(true, [
            'customers' => $customers,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total_count' => $totalCount,
                'has_more' => ($offset + $limit) < $totalCount
            ],
            'search_term' => $search
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve customers: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /admin/customers/{id}
 */
function handleGetCustomerDetails($id) {
    if (!is_numeric($id)) {
        sendError('Invalid customer ID', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $customer = new Customer();
        
        $customerData = $customer->getById($id);
        if (!$customerData) {
            sendError('Customer not found', 'NOT_FOUND', 404);
        }
        
        $bookingHistory = $customer->getBookingHistory($id);
        $stats = $customer->getStats($id);
        $activityPreferences = $customer->getActivityPreferences($id);
        $upcomingSessions = $customer->getUpcomingSessions($id);
        
        sendResponse(true, [
            'customer' => $customerData,
            'booking_history' => $bookingHistory,
            'statistics' => $stats,
            'activity_preferences' => $activityPreferences,
            'upcoming_sessions' => $upcomingSessions
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve customer details: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /admin/slots
 */
function handleGetWeeklySchedule() {
    try {
        $slot = new Slot();
        
        $slots = $slot->getWeeklySchedule();
        
        // Parse activity capacities and group by day
        $schedule = [];
        foreach ($slots as $slotData) {
            $dayName = date('l', strtotime('Monday +' . ($slotData['day_of_week'] - 1) . ' days'));
            
            if (!isset($schedule[$dayName])) {
                $schedule[$dayName] = [
                    'day_of_week' => $slotData['day_of_week'],
                    'day_name' => $dayName,
                    'slots' => []
                ];
            }
            
            // Parse activity capacities from GROUP_CONCAT
            $activities = [];
            if ($slotData['activity_capacities']) {
                $activityPairs = explode(',', $slotData['activity_capacities']);
                foreach ($activityPairs as $pair) {
                    list($type, $capacity) = explode(':', $pair);
                    $activities[] = [
                        'type' => $type,
                        'capacity' => (int)$capacity
                    ];
                }
            }
            
            $slotData['formatted_time'] = $slot->formatSlotTime($slotData['start_time'], $slotData['end_time']);
            $slotData['activities'] = $activities;
            unset($slotData['activity_capacities']); // Remove raw data
            
            $schedule[$dayName]['slots'][] = $slotData;
        }
        
        sendResponse(true, [
            'weekly_schedule' => array_values($schedule)
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve weekly schedule: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /admin/slots
 * Enhanced to create slots with default activity capacities
 */
function handleCreateSlot() {
    $data = getRequestBody();
    
    validateRequired($data, ['day_of_week', 'start_time', 'end_time']);
    
    $dayOfWeek = (int)$data['day_of_week'];
    $startTime = $data['start_time'];
    $endTime = $data['end_time'];
    
    // Validate inputs
    if ($dayOfWeek < 1 || $dayOfWeek > 7) {
        sendError('Day of week must be between 1 (Monday) and 7 (Sunday)', 'VALIDATION_ERROR', 422);
    }
    
    $slot = new Slot();
    if (!$slot->validateTime($startTime) || !$slot->validateTime($endTime)) {
        sendError('Invalid time format. Use HH:MM:SS', 'VALIDATION_ERROR', 422);
    }
    
    if (strtotime($endTime) <= strtotime($startTime)) {
        sendError('End time must be after start time', 'VALIDATION_ERROR', 422);
    }
    
    try {
        if ($slot->hasOverlappingSlots($dayOfWeek, $startTime, $endTime)) {
            sendError('This slot overlaps with an existing slot', 'VALIDATION_ERROR', 422);
        }
        
        // Create slot with default activity capacities
        $slotId = $slot->create($dayOfWeek, $startTime, $endTime);
        $newSlot = $slot->getSlotWithActivities($slotId);
        
        sendResponse(true, $newSlot, 'Slot created successfully with default activity capacities', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create slot: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

// Include other existing methods (handleUpdatePaymentStatus, handleCancelBooking, etc.)
// with minimal changes to maintain backward compatibility...

/**
 * PUT /admin/bookings/{id}?action=payment
 */
function handleUpdatePaymentStatus($id) {
    if (!is_numeric($id)) {
        sendError('Invalid booking ID', 'VALIDATION_ERROR', 422);
    }
    
    $data = getRequestBody();
    validateRequired($data, ['payment_status']);
    
    $paymentStatus = $data['payment_status'];
    $paymentId = $data['payment_id'] ?? null;
    $razorpayOrderId = $data['razorpay_order_id'] ?? null;
    
    if (!in_array($paymentStatus, ['pending', 'completed', 'failed'])) {
        sendError('Invalid payment status', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $booking = new Booking();
        
        if (!$booking->getById($id)) {
            sendError('Booking not found', 'NOT_FOUND', 404);
        }
        
        $booking->updatePaymentStatus($id, $paymentStatus, $paymentId, $razorpayOrderId);
        $updatedBooking = $booking->getById($id);
        
        sendResponse(true, $updatedBooking, 'Payment status updated successfully');
        
    } catch (Exception $e) {
        sendError('Failed to update payment status: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * PUT /admin/bookings/{id}?action=cancel
 */
function handleCancelBooking($id) {
    if (!is_numeric($id)) {
        sendError('Invalid booking ID', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $booking = new Booking();
        
        if (!$booking->getById($id)) {
            sendError('Booking not found', 'NOT_FOUND', 404);
        }
        
        $booking->cancel($id);
        $cancelledBooking = $booking->getById($id);
        
        sendResponse(true, $cancelledBooking, 'Booking cancelled successfully');
        
    } catch (Exception $e) {
        sendError('Failed to cancel booking: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
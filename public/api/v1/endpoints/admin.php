<?php
/**
 * Admin Endpoint Handler
 */

function handleAdminEndpoint($method, $resource, $id) {
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
                } else {
                    handleGetWeeklySchedule();
                }
            } else {
                sendError('Invalid admin endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        case 'POST':
            if ($resource === 'slots') {
                handleCreateSlot();
            } else {
                sendError('Invalid admin endpoint', 'NOT_FOUND', 404);
            }
            break;
            
        case 'PUT':
            if ($resource === 'bookings' && $id) {
                $action = $_GET['action'] ?? '';
                if ($action === 'payment') {
                    handleUpdatePaymentStatus($id);
                } elseif ($action === 'cancel') {
                    handleCancelBooking($id);
                } else {
                    sendError('Invalid booking action', 'VALIDATION_ERROR', 422);
                }
            } elseif ($resource === 'slots' && $id) {
                handleUpdateSlot($id);
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
    
    try {
        $loginResult = adminLogin($data['username'], $data['password']);
        
        if (!$loginResult) {
            sendError('Invalid username or password', 'UNAUTHORIZED', 401);
        }
        
        sendResponse(true, $loginResult, 'Login successful');
        
    } catch (Exception $e) {
        sendError('Login failed: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /admin/dashboard
 */
function handleGetDashboard() {
    try {
        $booking = new Booking();
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // Start of current month
        $dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today
        
        $stats = $booking->getStats($dateFrom, $dateTo);
        
        // Get today's sessions
        $db = Database::getInstance();
        $todaysSessions = $db->fetchAll(
            "SELECT b.id, b.total_people, c.name as customer_name, 
                    ssb.service_type, ssb.session_date, s.start_time, s.end_time
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             LEFT JOIN surf_sup_bookings ssb ON b.id = ssb.booking_id
             LEFT JOIN slots s ON ssb.slot_id = s.id
             WHERE ssb.session_date = CURDATE() AND b.booking_status = 'confirmed'
             ORDER BY s.start_time
             
             UNION ALL
             
             SELECT b.id, b.total_people, c.name as customer_name,
                    pb.service_type, ps.session_date, s.start_time, s.end_time
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             LEFT JOIN package_bookings pb ON b.id = pb.booking_id
             LEFT JOIN package_sessions ps ON pb.id = ps.package_booking_id
             LEFT JOIN slots s ON ps.slot_id = s.id
             WHERE ps.session_date = CURDATE() AND b.booking_status = 'confirmed'
             ORDER BY s.start_time"
        );
        
        // Get recent bookings
        $recentBookings = $db->fetchAll(
            "SELECT b.*, c.name as customer_name, c.email as customer_email
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             ORDER BY b.created_at DESC
             LIMIT 10"
        );
        
        $dashboardData = [
            'statistics' => $stats,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo
            ],
            'todays_sessions' => $todaysSessions,
            'recent_bookings' => $recentBookings
        ];
        
        sendResponse(true, $dashboardData);
        
    } catch (Exception $e) {
        sendError('Failed to load dashboard: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /admin/bookings
 */
function handleGetAllBookings() {
    try {
        $booking = new Booking();
        
        // Get filter parameters
        $filters = [
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'booking_type' => $_GET['booking_type'] ?? '',
            'payment_status' => $_GET['payment_status'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];
        
        $bookings = $booking->getAll($filters);
        
        sendResponse(true, [
            'bookings' => $bookings,
            'filters_applied' => array_filter($filters),
            'total_count' => count($bookings)
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to retrieve bookings: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * GET /admin/customers
 */
function handleGetAllCustomers() {
    try {
        $customer = new Customer();
        
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        $search = $_GET['search'] ?? '';
        
        // Validate limit
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
        
        sendResponse(true, [
            'customer' => $customerData,
            'booking_history' => $bookingHistory,
            'statistics' => $stats
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
        
        // Group by day of week
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
            
            $slotData['formatted_time'] = $slot->formatSlotTime($slotData['start_time'], $slotData['end_time']);
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
 * GET /admin/slots?report=availability&start_date=2025-09-25&end_date=2025-10-01
 */
function handleGetAvailabilityReport() {
    $startDate = $_GET['start_date'] ?? date('Y-m-d');
    $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !strtotime($startDate)) {
        sendError('Invalid start_date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || !strtotime($endDate)) {
        sendError('Invalid end_date format. Use YYYY-MM-DD', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $slot = new Slot();
        $report = $slot->getAvailabilityReport($startDate, $endDate);
        
        sendResponse(true, [
            'availability_report' => $report,
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to generate availability report: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /admin/slots
 */
function handleCreateSlot() {
    $data = getRequestBody();
    
    validateRequired($data, ['day_of_week', 'start_time', 'end_time']);
    
    $dayOfWeek = (int)$data['day_of_week'];
    $startTime = $data['start_time'];
    $endTime = $data['end_time'];
    $capacity = (int)($data['capacity'] ?? DEFAULT_SLOT_CAPACITY);
    
    // Validate day of week
    if ($dayOfWeek < 1 || $dayOfWeek > 7) {
        sendError('Day of week must be between 1 (Monday) and 7 (Sunday)', 'VALIDATION_ERROR', 422);
    }
    
    // Validate time format
    $slot = new Slot();
    if (!$slot->validateTime($startTime) || !$slot->validateTime($endTime)) {
        sendError('Invalid time format. Use HH:MM:SS', 'VALIDATION_ERROR', 422);
    }
    
    // Validate time order
    if (strtotime($endTime) <= strtotime($startTime)) {
        sendError('End time must be after start time', 'VALIDATION_ERROR', 422);
    }
    
    // Validate capacity
    if ($capacity < 1 || $capacity > 100) {
        sendError('Capacity must be between 1 and 100', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Check for overlapping slots
        if ($slot->hasOverlappingSlots($dayOfWeek, $startTime, $endTime)) {
            sendError('This slot overlaps with an existing slot', 'VALIDATION_ERROR', 422);
        }
        
        $slotId = $slot->create($dayOfWeek, $startTime, $endTime, $capacity);
        $newSlot = $slot->getById($slotId);
        
        sendResponse(true, $newSlot, 'Slot created successfully', 201);
        
    } catch (Exception $e) {
        sendError('Failed to create slot: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * PUT /admin/slots/{id}
 */
function handleUpdateSlot($id) {
    if (!is_numeric($id)) {
        sendError('Invalid slot ID', 'VALIDATION_ERROR', 422);
    }
    
    $data = getRequestBody();
    
    validateRequired($data, ['start_time', 'end_time', 'capacity']);
    
    $startTime = $data['start_time'];
    $endTime = $data['end_time'];
    $capacity = (int)$data['capacity'];
    $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
    
    try {
        $slot = new Slot();
        
        // Check if slot exists
        $existingSlot = $slot->getById($id);
        if (!$existingSlot) {
            sendError('Slot not found', 'NOT_FOUND', 404);
        }
        
        // Validate time format
        if (!$slot->validateTime($startTime) || !$slot->validateTime($endTime)) {
            sendError('Invalid time format. Use HH:MM:SS', 'VALIDATION_ERROR', 422);
        }
        
        // Validate time order
        if (strtotime($endTime) <= strtotime($startTime)) {
            sendError('End time must be after start time', 'VALIDATION_ERROR', 422);
        }
        
        // Validate capacity
        if ($capacity < 1 || $capacity > 100) {
            sendError('Capacity must be between 1 and 100', 'VALIDATION_ERROR', 422);
        }
        
        // Check for overlapping slots (excluding current slot)
        if ($slot->hasOverlappingSlots($existingSlot['day_of_week'], $startTime, $endTime, $id)) {
            sendError('This slot would overlap with an existing slot', 'VALIDATION_ERROR', 422);
        }
        
        $slot->update($id, $startTime, $endTime, $capacity, $isActive);
        $updatedSlot = $slot->getById($id);
        
        sendResponse(true, $updatedSlot, 'Slot updated successfully');
        
    } catch (Exception $e) {
        sendError('Failed to update slot: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * DELETE /admin/slots/{id}
 */
function handleDeactivateSlot($id) {
    if (!is_numeric($id)) {
        sendError('Invalid slot ID', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $slot = new Slot();
        
        // Check if slot exists
        if (!$slot->getById($id)) {
            sendError('Slot not found', 'NOT_FOUND', 404);
        }
        
        $slot->deactivate($id);
        
        sendResponse(true, null, 'Slot deactivated successfully');
        
    } catch (Exception $e) {
        sendError('Failed to deactivate slot: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

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
    
    // Validate payment status
    if (!in_array($paymentStatus, ['pending', 'completed', 'failed'])) {
        sendError('Invalid payment status', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $booking = new Booking();
        
        // Check if booking exists
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
        
        // Check if booking exists
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
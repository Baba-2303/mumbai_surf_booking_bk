<?php
/**
 * Booking Management Class
 * Handles all booking operations: Surf/SUP, Packages, and Stay-only
 */

class Booking {
    private $db;
    private $customer;
    private $slot;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->customer = new Customer();
        $this->slot = new Slot();
    }
    
    /**
     * Create Surf/SUP Booking
     */
    public function createSurfSupBooking($bookingData) {
        $this->db->beginTransaction();
        
        try {
            // Validate booking data
            $errors = $this->validateSurfSupBooking($bookingData);
            if (!empty($errors)) {
                throw new Exception('Validation failed: ' . implode(', ', $errors));
            }
            
            // Create or get customer
            $customerId = $this->customer->createOrGet(
                $bookingData['customer_name'],
                $bookingData['customer_email'],
                $bookingData['customer_phone']
            );
            
            // Calculate pricing
            $peopleCount = count($bookingData['people']);
            $pricing = calculateTotalAmount(SURF_SUP_BASE_PRICE * $peopleCount);
            
            // Check slot availability
            if (!$this->slot->hasAvailability(
                $bookingData['slot_id'],
                $bookingData['session_date'],
                $peopleCount
            )) {
                throw new Exception('Selected slot is no longer available');
            }
            
            // Create main booking record
            $bookingId = $this->db->insert(
                "INSERT INTO bookings (customer_id, booking_type, total_people, base_amount, gst_amount, total_amount)
                 VALUES (?, 'surf_sup', ?, ?, ?, ?)",
                [
                    $customerId,
                    $peopleCount,
                    $pricing['base_amount'],
                    $pricing['gst_amount'],
                    $pricing['total_amount']
                ]
            );
            
            // Create surf/sup specific record
            $this->db->insert(
                "INSERT INTO surf_sup_bookings (booking_id, service_type, session_date, slot_id)
                 VALUES (?, ?, ?, ?)",
                [
                    $bookingId,
                    $bookingData['service_type'],
                    $bookingData['session_date'],
                    $bookingData['slot_id']
                ]
            );
            
            // Add people to booking
            foreach ($bookingData['people'] as $person) {
                $this->db->insert(
                    "INSERT INTO booking_people (booking_id, name, age) VALUES (?, ?, ?)",
                    [$bookingId, $person['name'], $person['age']]
                );
            }
            
            // Reserve the slot
            $this->slot->reserveSlots([
                ['slot_id' => $bookingData['slot_id'], 'date' => $bookingData['session_date']]
            ], $peopleCount);
            
            $this->db->commit();
            return $bookingId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Create Package Booking
     */
    public function createPackageBooking($bookingData) {
        $this->db->beginTransaction();
        
        try {
            // Validate booking data
            $errors = $this->validatePackageBooking($bookingData);
            if (!empty($errors)) {
                throw new Exception('Validation failed: ' . implode(', ', $errors));
            }
            
            // Create or get customer
            $customerId = $this->customer->createOrGet(
                $bookingData['customer_name'],
                $bookingData['customer_email'],
                $bookingData['customer_phone']
            );
            
            // Calculate pricing
            $peopleCount = count($bookingData['people']);
            $packagePrice = $this->calculatePackagePrice(
                $bookingData['package_type'],
                $bookingData['accommodation_type'],
                $peopleCount
            );
            $pricing = calculateTotalAmount($packagePrice);
            
            // Check all session slots availability
            foreach ($bookingData['sessions'] as $session) {
                if (!$this->slot->hasAvailability(
                    $session['slot_id'],
                    $session['session_date'],
                    $peopleCount
                )) {
                    throw new Exception('Session slot on ' . $session['session_date'] . ' is no longer available');
                }
            }
            
            // Create main booking record
            $bookingId = $this->db->insert(
                "INSERT INTO bookings (customer_id, booking_type, total_people, base_amount, gst_amount, total_amount)
                 VALUES (?, 'package', ?, ?, ?, ?)",
                [
                    $customerId,
                    $peopleCount,
                    $pricing['base_amount'],
                    $pricing['gst_amount'],
                    $pricing['total_amount']
                ]
            );
            
            // Create package specific record
            $packageBookingId = $this->db->insert(
                "INSERT INTO package_bookings (booking_id, package_type, accommodation_type, service_type, check_in_date, check_out_date)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $bookingId,
                    $bookingData['package_type'],
                    $bookingData['accommodation_type'],
                    $bookingData['service_type'],
                    $bookingData['check_in_date'],
                    $bookingData['check_out_date']
                ]
            );
            
            // Add session records
            foreach ($bookingData['sessions'] as $index => $session) {
                $this->db->insert(
                    "INSERT INTO package_sessions (package_booking_id, session_date, slot_id, session_number)
                     VALUES (?, ?, ?, ?)",
                    [$packageBookingId, $session['session_date'], $session['slot_id'], $index + 1]
                );
            }
            
            // Add people to booking
            foreach ($bookingData['people'] as $person) {
                $this->db->insert(
                    "INSERT INTO booking_people (booking_id, name, age) VALUES (?, ?, ?)",
                    [$bookingId, $person['name'], $person['age']]
                );
            }
            
            // Reserve all session slots
            $slots = [];
            foreach ($bookingData['sessions'] as $session) {
                $slots[] = ['slot_id' => $session['slot_id'], 'date' => $session['session_date']];
            }
            $this->slot->reserveSlots($slots, $peopleCount);
            
            $this->db->commit();
            return $bookingId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Create Stay-only Booking
     */
    public function createStayBooking($bookingData) {
        $this->db->beginTransaction();
        
        try {
            // Validate booking data
            $errors = $this->validateStayBooking($bookingData);
            if (!empty($errors)) {
                throw new Exception('Validation failed: ' . implode(', ', $errors));
            }
            
            // Create or get customer
            $customerId = $this->customer->createOrGet(
                $bookingData['customer_name'],
                $bookingData['customer_email'],
                $bookingData['customer_phone']
            );
            
            // Calculate pricing
            $peopleCount = count($bookingData['people']);
            $stayPrice = $this->calculateStayPrice(
                $bookingData['accommodation_type'],
                $peopleCount,
                $bookingData['nights_count'],
                $bookingData['includes_meals']
            );
            $pricing = calculateTotalAmount($stayPrice);
            
            // Create main booking record
            $bookingId = $this->db->insert(
                "INSERT INTO bookings (customer_id, booking_type, total_people, base_amount, gst_amount, total_amount)
                 VALUES (?, 'stay_only', ?, ?, ?, ?)",
                [
                    $customerId,
                    $peopleCount,
                    $pricing['base_amount'],
                    $pricing['gst_amount'],
                    $pricing['total_amount']
                ]
            );
            
            // Create stay specific record
            $this->db->insert(
                "INSERT INTO stay_bookings (booking_id, accommodation_type, check_in_date, check_out_date, includes_dinner, includes_breakfast, nights_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $bookingId,
                    $bookingData['accommodation_type'],
                    $bookingData['check_in_date'],
                    $bookingData['check_out_date'],
                    $bookingData['includes_meals'],
                    $bookingData['includes_meals'],
                    $bookingData['nights_count']
                ]
            );
            
            // Add people to booking
            foreach ($bookingData['people'] as $person) {
                $this->db->insert(
                    "INSERT INTO booking_people (booking_id, name, age) VALUES (?, ?, ?)",
                    [$bookingId, $person['name'], $person['age']]
                );
            }
            
            $this->db->commit();
            return $bookingId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get booking by ID with all related data
     */
    public function getById($id) {
        $booking = $this->db->fetch(
            "SELECT b.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             WHERE b.id = ?",
            [$id]
        );
        
        if (!$booking) {
            return null;
        }
        
        // Get people
        $booking['people'] = $this->db->fetchAll(
            "SELECT name, age FROM booking_people WHERE booking_id = ?",
            [$id]
        );
        
        // Get type-specific data
        switch ($booking['booking_type']) {
            case 'surf_sup':
                $specific = $this->db->fetch(
                    "SELECT ssb.*, s.start_time, s.end_time
                     FROM surf_sup_bookings ssb
                     JOIN slots s ON ssb.slot_id = s.id
                     WHERE ssb.booking_id = ?",
                    [$id]
                );
                $booking['surf_sup_details'] = $specific;
                break;
                
            case 'package':
                $specific = $this->db->fetch(
                    "SELECT * FROM package_bookings WHERE booking_id = ?",
                    [$id]
                );
                $sessions = $this->db->fetchAll(
                    "SELECT ps.*, s.start_time, s.end_time
                     FROM package_sessions ps
                     JOIN package_bookings pb ON ps.package_booking_id = pb.id
                     JOIN slots s ON ps.slot_id = s.id
                     WHERE pb.booking_id = ?
                     ORDER BY ps.session_number",
                    [$id]
                );
                $booking['package_details'] = $specific;
                $booking['sessions'] = $sessions;
                break;
                
            case 'stay_only':
                $specific = $this->db->fetch(
                    "SELECT * FROM stay_bookings WHERE booking_id = ?",
                    [$id]
                );
                $booking['stay_details'] = $specific;
                break;
        }
        
        return $booking;
    }
    
    /**
     * Get all bookings for admin with filters
     */
    public function getAll($filters = []) {
        $conditions = [];
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $conditions[] = "DATE(b.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $conditions[] = "DATE(b.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['booking_type'])) {
            $conditions[] = "b.booking_type = ?";
            $params[] = $filters['booking_type'];
        }
        
        if (!empty($filters['payment_status'])) {
            $conditions[] = "b.payment_status = ?";
            $params[] = $filters['payment_status'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $conditions[] = "(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
        
        return $this->db->fetchAll(
            "SELECT b.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             $whereClause
             ORDER BY b.created_at DESC
             LIMIT 100",
            $params
        );
    }
    
    /**
     * Update payment status
     */
    public function updatePaymentStatus($bookingId, $status, $paymentId = null, $razorpayOrderId = null) {
        return $this->db->execute(
            "UPDATE bookings 
             SET payment_status = ?, payment_id = ?, razorpay_order_id = ?, updated_at = NOW()
             WHERE id = ?",
            [$status, $paymentId, $razorpayOrderId, $bookingId]
        );
    }
    
    /**
     * Cancel booking
     */
    public function cancel($bookingId) {
        $this->db->beginTransaction();
        
        try {
            $booking = $this->getById($bookingId);
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            // Release slots for surf/sup and package bookings
            if ($booking['booking_type'] === 'surf_sup') {
                $this->slot->releaseSlots([
                    ['slot_id' => $booking['surf_sup_details']['slot_id'], 
                     'date' => $booking['surf_sup_details']['session_date']]
                ], $booking['total_people']);
            } elseif ($booking['booking_type'] === 'package') {
                $slots = [];
                foreach ($booking['sessions'] as $session) {
                    $slots[] = ['slot_id' => $session['slot_id'], 'date' => $session['session_date']];
                }
                $this->slot->releaseSlots($slots, $booking['total_people']);
            }
            
            // Update booking status
            $this->db->execute(
                "UPDATE bookings SET booking_status = 'cancelled', updated_at = NOW() WHERE id = ?",
                [$bookingId]
            );
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Calculate package pricing
     */
    public function calculatePackagePrice($packageType, $accommodationType, $peopleCount) {
        $prices = PACKAGE_PRICES[$packageType];
        
        if ($accommodationType === 'cottage') {
            // Cottage pricing based on number of people (1-4)
            $cottageKey = 'cottage_' . min($peopleCount, 4);
            return $prices[$cottageKey];
        } else {
            // Tent/dorm pricing per person
            return $prices[$accommodationType] * $peopleCount;
        }
    }
    
    /**
     * Calculate stay-only pricing
     */
    public function calculateStayPrice($accommodationType, $peopleCount, $nights, $includesMeals) {
        if ($accommodationType === 'cottage') {
            $basePrice = STAY_PRICES['cottage']['base_price'] * $nights;
            if ($includesMeals) {
                $mealPrice = STAY_PRICES['cottage']['meal_price_per_person'] * $peopleCount * $nights;
                return $basePrice + $mealPrice;
            }
            return $basePrice;
        } else {
            // Tent/dorm pricing per person per night
            $pricePerNight = $includesMeals ? 
                STAY_PRICES[$accommodationType]['with_meals'] : 
                STAY_PRICES[$accommodationType]['without_meals'];
            
            return $pricePerNight * $peopleCount * $nights;
        }
    }
    
    /**
     * Generate session dates for package bookings
     */
    public function generatePackageSessionDates($packageType, $checkInDate) {
        $sessions = [];
        $checkIn = new DateTime($checkInDate);
        
        switch ($packageType) {
            case '1_night_1_session':
                // Session on checkout day (day 2)
                $sessionDate = clone $checkIn;
                $sessionDate->modify('+1 day');
                $sessions[] = [
                    'session_number' => 1,
                    'session_date' => $sessionDate->format('Y-m-d'),
                    'description' => 'Morning session on checkout day'
                ];
                break;
                
            case '1_night_2_sessions':
                // Session 1: Day 1 (check-in day afternoon)
                $sessions[] = [
                    'session_number' => 1,
                    'session_date' => $checkIn->format('Y-m-d'),
                    'description' => 'Afternoon session on check-in day'
                ];
                // Session 2: Day 2 (checkout day morning)
                $sessionDate = clone $checkIn;
                $sessionDate->modify('+1 day');
                $sessions[] = [
                    'session_number' => 2,
                    'session_date' => $sessionDate->format('Y-m-d'),
                    'description' => 'Morning session on checkout day'
                ];
                break;
                
            case '2_nights_3_sessions':
                // Session 1: Day 1 (check-in day afternoon)
                $sessions[] = [
                    'session_number' => 1,
                    'session_date' => $checkIn->format('Y-m-d'),
                    'description' => 'Afternoon session on check-in day'
                ];
                // Session 2: Day 2 (full day)
                $sessionDate = clone $checkIn;
                $sessionDate->modify('+1 day');
                $sessions[] = [
                    'session_number' => 2,
                    'session_date' => $sessionDate->format('Y-m-d'),
                    'description' => 'Session on day 2'
                ];
                // Session 3: Day 3 (checkout day morning)
                $sessionDate->modify('+1 day');
                $sessions[] = [
                    'session_number' => 3,
                    'session_date' => $sessionDate->format('Y-m-d'),
                    'description' => 'Morning session on checkout day'
                ];
                break;
        }
        
        return $sessions;
    }
    
    /**
     * Validation methods
     */
    private function validateSurfSupBooking($data) {
        $errors = [];
        
        if (empty($data['customer_name'])) {
            $errors[] = 'Customer name is required';
        }
        
        if (empty($data['customer_email']) || !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        
        if (empty($data['customer_phone'])) {
            $errors[] = 'Phone number is required';
        }
        
        if (empty($data['service_type']) || !in_array($data['service_type'], ['surf', 'sup'])) {
            $errors[] = 'Valid service type is required';
        }
        
        if (empty($data['session_date']) || !strtotime($data['session_date'])) {
            $errors[] = 'Valid session date is required';
        }
        
        if (empty($data['slot_id']) || !is_numeric($data['slot_id'])) {
            $errors[] = 'Valid slot selection is required';
        }
        
        if (empty($data['people']) || !is_array($data['people'])) {
            $errors[] = 'At least one person is required';
        }
        
        // Validate each person
        foreach ($data['people'] as $i => $person) {
            if (empty($person['name'])) {
                $errors[] = "Name is required for person " . ($i + 1);
            }
            if (empty($person['age']) || !is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
                $errors[] = "Valid age (5-100) is required for person " . ($i + 1);
            }
        }
        
        return $errors;
    }
    
    private function validatePackageBooking($data) {
        $errors = $this->validateSurfSupBooking($data);
        
        if (empty($data['package_type']) || !in_array($data['package_type'], 
            ['1_night_1_session', '1_night_2_sessions', '2_nights_3_sessions'])) {
            $errors[] = 'Valid package type is required';
        }
        
        if (empty($data['accommodation_type']) || !in_array($data['accommodation_type'], 
            ['tent', 'dorm', 'cottage'])) {
            $errors[] = 'Valid accommodation type is required';
        }
        
        if (empty($data['check_in_date']) || !strtotime($data['check_in_date'])) {
            $errors[] = 'Valid check-in date is required';
        }
        
        if (empty($data['check_out_date']) || !strtotime($data['check_out_date'])) {
            $errors[] = 'Valid check-out date is required';
        }
        
        if (empty($data['sessions']) || !is_array($data['sessions'])) {
            $errors[] = 'Session information is required';
        }
        
        return $errors;
    }
    
    private function validateStayBooking($data) {
        $errors = [];
        
        if (empty($data['customer_name'])) {
            $errors[] = 'Customer name is required';
        }
        
        if (empty($data['customer_email']) || !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        
        if (empty($data['customer_phone'])) {
            $errors[] = 'Phone number is required';
        }
        
        if (empty($data['accommodation_type']) || !in_array($data['accommodation_type'], 
            ['tent', 'dorm', 'cottage'])) {
            $errors[] = 'Valid accommodation type is required';
        }
        
        if (empty($data['check_in_date']) || !strtotime($data['check_in_date'])) {
            $errors[] = 'Valid check-in date is required';
        }
        
        if (empty($data['check_out_date']) || !strtotime($data['check_out_date'])) {
            $errors[] = 'Valid check-out date is required';
        }
        
        if (empty($data['people']) || !is_array($data['people'])) {
            $errors[] = 'At least one person is required';
        }
        
        return $errors;
    }
    
    /**
     * Get booking statistics for dashboard
     */
    public function getStats($dateFrom = null, $dateTo = null) {
        $dateFrom = $dateFrom ?: date('Y-m-01'); // Start of current month
        $dateTo = $dateTo ?: date('Y-m-d'); // Today
        
        return [
            'total_bookings' => $this->db->count(
                "SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN ? AND ?",
                [$dateFrom, $dateTo]
            ),
            'total_revenue' => $this->db->fetch(
                "SELECT COALESCE(SUM(total_amount), 0) as revenue FROM bookings 
                 WHERE DATE(created_at) BETWEEN ? AND ? AND payment_status = 'completed'",
                [$dateFrom, $dateTo]
            )['revenue'],
            'pending_payments' => $this->db->count(
                "SELECT COUNT(*) FROM bookings WHERE payment_status = 'pending'"
            ),
            'todays_sessions' => $this->db->count(
                "SELECT COUNT(*) FROM surf_sup_bookings ssb
                 JOIN bookings b ON ssb.booking_id = b.id
                 WHERE ssb.session_date = CURDATE() AND b.booking_status = 'confirmed'
                 UNION ALL
                 SELECT COUNT(*) FROM package_sessions ps
                 JOIN package_bookings pb ON ps.package_booking_id = pb.id
                 JOIN bookings b ON pb.booking_id = b.id
                 WHERE ps.session_date = CURDATE() AND b.booking_status = 'confirmed'"
            )
        ];
    }
}
?>
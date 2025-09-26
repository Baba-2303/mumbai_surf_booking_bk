<?php
/**
 * Activity Management Class
 * Handles activity types, capacity, and availability
 */
class Activity {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get all available activity types
     */
    public function getActivityTypes() {
        return [
            [
                'type' => 'surf',
                'name' => 'Surfing',
                'description' => 'Learn to ride the waves on our surfboards',
                'default_capacity' => 40,
                'price_per_person' => SURF_SUP_BASE_PRICE
            ],
            [
                'type' => 'sup',
                'name' => 'Stand Up Paddling',
                'description' => 'Balance and paddle on a stand-up paddleboard',
                'default_capacity' => 12,
                'price_per_person' => SURF_SUP_BASE_PRICE
            ],
            [
                'type' => 'kayak',
                'name' => 'Kayaking',
                'description' => 'Paddle through calm waters in a kayak',
                'default_capacity' => 2,
                'price_per_person' => SURF_SUP_BASE_PRICE
            ]
        ];
    }
    
    /**
     * Get activity-specific slot availability
     */
    public function getSlotAvailability($date, $activityType, $peopleCount) {
        $dayOfWeek = date('N', strtotime($date));
        
        $slots = $this->db->fetchAll(
            "SELECT s.id, s.start_time, s.end_time,
                    sa.activity_type, sa.max_capacity,
                    COALESCE(saa.booked_count, 0) as booked_count,
                    (sa.max_capacity - COALESCE(saa.booked_count, 0)) as available_spots
             FROM slots s
             JOIN slot_activities sa ON s.id = sa.slot_id
             LEFT JOIN slot_activity_availability saa ON s.id = saa.slot_id 
                 AND saa.booking_date = ? AND saa.activity_type = ?
             WHERE s.day_of_week = ? AND s.is_active = 1 AND sa.activity_type = ?
             ORDER BY s.start_time",
            [$date, $activityType, $dayOfWeek, $activityType]
        );
        
        // Add formatted time and availability check
        foreach ($slots as &$slot) {
            $slot['formatted_time'] = $this->formatSlotTime($slot['start_time'], $slot['end_time']);
            $slot['can_book'] = $slot['available_spots'] >= $peopleCount;
        }
        
        return $slots;
    }
    
    /**
     * Check if activity slot has availability
     */
    public function hasSlotAvailability($slotId, $date, $activityType, $peopleCount) {
        $availability = $this->db->fetch(
            "SELECT sa.max_capacity, COALESCE(saa.booked_count, 0) as booked_count
             FROM slot_activities sa
             LEFT JOIN slot_activity_availability saa ON sa.slot_id = saa.slot_id 
                 AND saa.booking_date = ? AND saa.activity_type = ?
             WHERE sa.slot_id = ? AND sa.activity_type = ?",
            [$date, $activityType, $slotId, $activityType]
        );
        
        if (!$availability) {
            return false;
        }
        
        $availableSpots = $availability['max_capacity'] - $availability['booked_count'];
        return $availableSpots >= $peopleCount;
    }
    
    /**
     * Reserve activity slot capacity
     */
    public function reserveActivitySlot($slotId, $date, $activityType, $peopleCount) {
        // Get max capacity for this slot+activity
        $maxCapacity = $this->db->fetch(
            "SELECT max_capacity FROM slot_activities WHERE slot_id = ? AND activity_type = ?",
            [$slotId, $activityType]
        );
        
        if (!$maxCapacity) {
            throw new Exception("Activity $activityType not available for this slot");
        }
        
        // Insert or update availability record
        $this->db->query(
            "INSERT INTO slot_activity_availability (slot_id, booking_date, activity_type, booked_count, max_capacity)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE booked_count = booked_count + ?",
            [$slotId, $date, $activityType, $peopleCount, $maxCapacity['max_capacity'], $peopleCount]
        );
    }
    
    /**
     * Release activity slot capacity (for cancellations)
     */
    public function releaseActivitySlot($slotId, $date, $activityType, $peopleCount) {
        $this->db->execute(
            "UPDATE slot_activity_availability 
             SET booked_count = GREATEST(0, booked_count - ?)
             WHERE slot_id = ? AND booking_date = ? AND activity_type = ?",
            [$peopleCount, $slotId, $date, $activityType]
        );
    }
    
    /**
     * Admin: Set activity capacity for a slot
     */
    public function setSlotActivityCapacity($slotId, $activityType, $capacity) {
        return $this->db->query(
            "INSERT INTO slot_activities (slot_id, activity_type, max_capacity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE max_capacity = ?",
            [$slotId, $activityType, $capacity, $capacity]
        );
    }
    
    /**
     * Admin: Get slot activity configurations
     */
    public function getSlotActivityConfig($slotId) {
        return $this->db->fetchAll(
            "SELECT sa.*, s.start_time, s.end_time, s.day_of_week
             FROM slot_activities sa
             JOIN slots s ON sa.slot_id = s.id
             WHERE sa.slot_id = ?
             ORDER BY sa.activity_type",
            [$slotId]
        );
    }
    
    private function formatSlotTime($startTime, $endTime) {
        return date('g:i A', strtotime($startTime)) . ' - ' . date('g:i A', strtotime($endTime));
    }
}

/**
 * Updated Booking Class for Activity Bookings
 */
class ActivityBooking {
    private $db;
    private $customer;
    private $activity;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->customer = new Customer();
        $this->activity = new Activity();
    }
    
    /**
     * Create Activity Booking (Surf/SUP/Kayak)
     */
    public function createActivityBooking($bookingData) {
        if ($this->db->hasActiveTransaction()) {
            $this->db->forceRollback();
        }
        $this->db->beginTransaction();
        
        try {
            // Validate booking data
            $errors = $this->validateActivityBooking($bookingData);
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
            
            // Check activity slot availability
            if (!$this->activity->hasSlotAvailability(
                $bookingData['slot_id'],
                $bookingData['session_date'],
                $bookingData['activity_type'],
                $peopleCount
            )) {
                throw new Exception('Selected activity slot is no longer available');
            }
            
            // Create main booking record
            $bookingId = $this->db->insert(
                "INSERT INTO bookings (customer_id, booking_type, total_people, base_amount, gst_amount, total_amount)
                 VALUES (?, 'activity', ?, ?, ?, ?)",
                [
                    $customerId,
                    $peopleCount,
                    $pricing['base_amount'],
                    $pricing['gst_amount'],
                    $pricing['total_amount']
                ]
            );
            
            // Create activity specific record
            $this->db->insert(
                "INSERT INTO activity_bookings (booking_id, service_type, activity_type, session_date, slot_id)
                 VALUES (?, ?, ?, ?, ?)",
                [
                    $bookingId,
                    $bookingData['activity_type'], // service_type for backward compatibility
                    $bookingData['activity_type'],
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
            
            // Reserve the activity slot
            $this->activity->reserveActivitySlot(
                $bookingData['slot_id'],
                $bookingData['session_date'],
                $bookingData['activity_type'],
                $peopleCount
            );
            
            $this->db->commit();
            return $bookingId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Generate booking reference
     */
    public function generateBookingReference($bookingId, $date, $activityType) {
        $activityCode = strtoupper(substr($activityType, 0, 3));
        $dateCode = date('ymd', strtotime($date));
        return "MSC-{$activityCode}-{$bookingId}-{$dateCode}";
    }
    
    private function validateActivityBooking($data) {
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
        
        if (empty($data['activity_type']) || !in_array($data['activity_type'], ['surf', 'sup', 'kayak'])) {
            $errors[] = 'Valid activity type is required';
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
        
        return $errors;
    }
}
?>
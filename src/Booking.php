<?php
/**
 * Enhanced Booking Management Class
 * Handles all booking operations: Activity, Packages, and Stay-only
 * Now supports activity-based capacity system with auto-allocation
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
            
            // ✅ NEW: Group people by activity to check capacity for each
            $activityGroups = [];
            foreach ($bookingData['people'] as $person) {
                $activity = $person['activity_type'];
                if (!isset($activityGroups[$activity])) {
                    $activityGroups[$activity] = [];
                }
                $activityGroups[$activity][] = $person;
            }
            
            // ✅ NEW: Validate capacity for EACH activity
            foreach ($activityGroups as $activityType => $people) {
                $count = count($people);
                if (!$this->slot->hasActivityAvailability(
                    $bookingData['slot_id'],
                    $bookingData['session_date'],
                    $activityType,
                    $count
                )) {
                    throw new Exception("Selected slot doesn't have capacity for $count people doing $activityType");
                }
            }
            
            // Calculate pricing (same base price for all activities)
            $peopleCount = count($bookingData['people']);
            $pricing = calculateTotalAmount(SURF_SUP_BASE_PRICE * $peopleCount);
            
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
            
            // ✅ MODIFIED: Determine primary activity (most common in group)
            $activityCounts = array_map('count', $activityGroups);
            arsort($activityCounts);
            $primaryActivity = key($activityCounts);
            
            $this->db->insert(
                "INSERT INTO activity_bookings (booking_id, activity_type, session_date, slot_id)
                VALUES (?, ?, ?, ?)",
                [
                    $bookingId,
                    $primaryActivity,
                    $bookingData['session_date'],
                    $bookingData['slot_id']
                ]
            );
            
            // ✅ MODIFIED: Add people WITH activity_type
            foreach ($bookingData['people'] as $person) {
                $this->db->insert(
                    "INSERT INTO booking_people (booking_id, name, age, activity_type) 
                    VALUES (?, ?, ?, ?)",
                    [
                        $bookingId, 
                        $person['name'], 
                        $person['age'],
                        $person['activity_type']  // ✅ NEW FIELD
                    ]
                );
            }
            
            // ✅ MODIFIED: Reserve capacity for EACH activity
            foreach ($activityGroups as $activityType => $people) {
                $this->slot->reserveActivitySlot(
                    $bookingData['slot_id'],
                    $bookingData['session_date'],
                    $activityType,
                    count($people)
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
     * LEGACY METHOD: Create Surf/SUP Booking (kept for backward compatibility)
     */
    public function createSurfSupBooking($bookingData) {
        // Convert to new activity booking format
        $bookingData['activity_type'] = $bookingData['service_type'] ?? 'surf';
        return $this->createActivityBooking($bookingData);
    }
    
    /**
     * ENHANCED: Create Package Booking
     * ✅ Supports per-person per-session activity selection
     * ✅ Maintains auto-allocation for missing slot IDs
     * ✅ Reserves capacity for EACH activity in EACH session
     */
    public function createPackageBooking($bookingData) {
        if ($this->db->hasActiveTransaction()) {
            $this->db->forceRollback();
        }
        $this->db->beginTransaction();
        
        try {
            // Validate booking data
            $errors = $this->validatePackageBooking($bookingData);
            if (!empty($errors)) {
                throw new Exception('Validation failed: ' . implode(', ', $errors));
            }
            
            // Calculate check-out date based on package type
            $nights = (strpos($bookingData['package_type'], '2_nights') !== false) ? 2 : 1;
            $bookingData['check_out_date'] = date('Y-m-d', strtotime($bookingData['check_in_date'] . ' +' . $nights . ' days'));
            
            // ✅ ENHANCED: Auto-allocation logic for missing slot IDs
            $autoAllocatedSessions = [];
            foreach ($bookingData['sessions'] as &$session) {
                if (empty($session['slot_id'])) {
                    $autoSlot = $this->slot->getNextAvailableSlot(
                        $session['session_date'], 
                        count($bookingData['people'])
                    );
                    
                    if (!$autoSlot) {
                        throw new Exception('No available slots for session on ' . $session['session_date']);
                    }
                    
                    $session['slot_id'] = $autoSlot['id'];
                    $session['auto_allocated'] = true;
                    $autoAllocatedSessions[] = [
                        'date' => $session['session_date'],
                        'slot_id' => $autoSlot['id'],
                        'time' => $this->slot->formatSlotTime($autoSlot['start_time'], $autoSlot['end_time'])
                    ];
                }
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
            
            // ✅ NEW: Validate capacity for ALL person-session-activity combinations
            $this->validatePackageCapacity($bookingData['sessions'], $peopleCount);
            
            // Create main booking record
            $bookingId = $this->db->insert(
                "INSERT INTO bookings (customer_id, booking_type, total_people, base_amount, gst_amount, total_amount, notes)
                VALUES (?, 'package', ?, ?, ?, ?, ?)",
                [
                    $customerId,
                    $peopleCount,
                    $pricing['base_amount'],
                    $pricing['gst_amount'],
                    $pricing['total_amount'],
                    !empty($autoAllocatedSessions) ? 
                        'Auto-allocated sessions: ' . json_encode($autoAllocatedSessions) : null
                ]
            );
            
            // ✅ NEW: Determine primary activity (most common across all sessions)
            $allActivities = [];
            foreach ($bookingData['sessions'] as $session) {
                if (isset($session['people_activities'])) {
                    foreach ($session['people_activities'] as $pa) {
                        $allActivities[] = $pa['activity_type'];
                    }
                }
            }
            $activityCounts = array_count_values($allActivities);
            arsort($activityCounts);
            $primaryActivity = key($activityCounts) ?: 'surf';
            
            // Create package specific record
            $packageBookingId = $this->db->insert(
                "INSERT INTO package_bookings (booking_id, package_type, accommodation_type, service_type, check_in_date, check_out_date)
                VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $bookingId,
                    $bookingData['package_type'],
                    $bookingData['accommodation_type'],
                    $primaryActivity, // Primary activity for backward compatibility
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
            
            // ✅ NEW: Add people to booking WITH activity_type
            $bookingPeopleIds = [];
            foreach ($bookingData['people'] as $index => $person) {
                $personId = $this->db->insert(
                    "INSERT INTO booking_people (booking_id, name, age, activity_type) VALUES (?, ?, ?, ?)",
                    [$bookingId, $person['name'], $person['age'], $person['activity_type']]
                );
                $bookingPeopleIds[$index] = $personId;
            }
            
            // ✅ NEW: Add package_person_sessions for each person-session-activity combination
            foreach ($bookingData['sessions'] as $sessionIndex => $session) {
                $sessionNumber = $sessionIndex + 1;
                
                if (!isset($session['people_activities']) || empty($session['people_activities'])) {
                    throw new Exception("Missing people_activities for session $sessionNumber");
                }
                
                foreach ($session['people_activities'] as $personActivity) {
                    $personIndex = $personActivity['person_index'];
                    $activityType = $personActivity['activity_type'];
                    
                    if (!isset($bookingPeopleIds[$personIndex])) {
                        throw new Exception("Invalid person_index: $personIndex");
                    }
                    
                    $this->db->insert(
                        "INSERT INTO package_person_sessions 
                        (package_booking_id, booking_person_id, session_number, session_date, slot_id, activity_type)
                        VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            $packageBookingId,
                            $bookingPeopleIds[$personIndex],
                            $sessionNumber,
                            $session['session_date'],
                            $session['slot_id'],
                            $activityType
                        ]
                    );
                }
            }
            
            // ✅ NEW: Reserve capacity for EACH activity in EACH session
            $this->reservePackageCapacity($bookingData['sessions']);
            
            $this->db->commit();
            return $bookingId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * ✅ NEW: Validate package capacity for all sessions
     */
    private function validatePackageCapacity($sessions, $peopleCount) {
        foreach ($sessions as $sessionIndex => $session) {
            $sessionNumber = $sessionIndex + 1;
            
            if (!isset($session['people_activities']) || empty($session['people_activities'])) {
                throw new Exception("Missing people_activities for session $sessionNumber");
            }
            
            // Count people per activity for this session
            $activityCounts = [];
            foreach ($session['people_activities'] as $pa) {
                $activity = $pa['activity_type'];
                if (!isset($activityCounts[$activity])) {
                    $activityCounts[$activity] = 0;
                }
                $activityCounts[$activity]++;
            }
            
            // Validate total people count matches
            if (array_sum($activityCounts) !== $peopleCount) {
                throw new Exception("Session $sessionNumber: people_activities count doesn't match total people");
            }
            
            // Check capacity for EACH activity
            foreach ($activityCounts as $activityType => $count) {
                if (!$this->slot->hasActivityAvailability(
                    $session['slot_id'],
                    $session['session_date'],
                    $activityType,
                    $count
                )) {
                    throw new Exception(
                        "Session $sessionNumber: Insufficient capacity for $count people doing $activityType on {$session['session_date']}"
                    );
                }
            }
        }
    }

    /**
     * ✅ NEW: Reserve capacity for package sessions
     */
    private function reservePackageCapacity($sessions) {
        foreach ($sessions as $session) {
            if (!isset($session['people_activities']) || empty($session['people_activities'])) {
                continue;
            }
            
            // Count people per activity for this session
            $activityCounts = [];
            foreach ($session['people_activities'] as $pa) {
                $activity = $pa['activity_type'];
                if (!isset($activityCounts[$activity])) {
                    $activityCounts[$activity] = 0;
                }
                $activityCounts[$activity]++;
            }
            
            // Reserve capacity for EACH activity
            foreach ($activityCounts as $activityType => $count) {
                $this->slot->reserveActivitySlot(
                    $session['slot_id'],
                    $session['session_date'],
                    $activityType,
                    $count
                );
            }
        }
    }
    
    /**
     * Reserve package slots within transaction (uses first available activity)
     */
    // private function reservePackageSlotsWithinTransaction($sessions, $peopleCount) {
    //     foreach ($sessions as $session) {
    //         $slotId = $session['slot_id'];
    //         $date = $session['session_date'];
            
    //         // Find the first available activity with enough capacity
    //         $availableActivity = $this->db->fetch(
    //             "SELECT sa.activity_type, sa.max_capacity, COALESCE(saa.booked_count, 0) as booked_count,
    //                     (sa.max_capacity - COALESCE(saa.booked_count, 0)) as available_spots
    //              FROM slot_activities sa
    //              LEFT JOIN slot_activity_availability saa ON sa.slot_id = saa.slot_id 
    //                  AND saa.booking_date = ? AND saa.activity_type = sa.activity_type
    //              WHERE sa.slot_id = ?
    //              HAVING available_spots >= ?
    //              ORDER BY available_spots DESC
    //              LIMIT 1",
    //             [$date, $slotId, $peopleCount]
    //         );
            
    //         if (!$availableActivity) {
    //             throw new Exception("No activity available for slot on $date");
    //         }
            
    //         // Reserve using the available activity
    //         $this->slot->reserveActivitySlot(
    //             $slotId,
    //             $date,
    //             $availableActivity['activity_type'],
    //             $peopleCount
    //         );
    //     }
    // }
    
    /**
     * Create Stay-only Booking - UNCHANGED
     */
    public function createStayBooking($bookingData) {
        if ($this->db->hasActiveTransaction()) {
            $this->db->forceRollback();
        }
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
     * Get booking by ID with all related data - UPDATED
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
        
        // ✅ MODIFIED: Get people WITH activity_type
        $booking['people'] = $this->db->fetchAll(
            "SELECT name, age, activity_type FROM booking_people WHERE booking_id = ?",
            [$id]
        );
        
        // ✅ NEW: Add activity breakdown
        if ($booking['booking_type'] === 'activity') {
            $activityBreakdown = $this->db->fetchAll(
                "SELECT activity_type, COUNT(*) as count 
                FROM booking_people 
                WHERE booking_id = ? 
                GROUP BY activity_type",
                [$id]
            );
            $booking['activity_breakdown'] = $activityBreakdown;
        }
        
        // Get type-specific data
        switch ($booking['booking_type']) {
            case 'activity': 
                $specific = $this->db->fetch(
                    "SELECT ab.id, ab.booking_id, ab.activity_type, ab.session_date, ab.slot_id,
                            s.start_time, s.end_time
                    FROM activity_bookings ab
                    JOIN slots s ON ab.slot_id = s.id
                    WHERE ab.booking_id = ?",
                    [$id]
                );
                $booking['activity_details'] = $specific;
                break;
                
            case 'surf_sup': // backward compatibility
                $specific = $this->db->fetch(
                    "SELECT ab.*, s.start_time, s.end_time
                     FROM activity_bookings ab
                     JOIN slots s ON ab.slot_id = s.id
                     WHERE ab.booking_id = ?",
                    [$id]
                );
                $booking['activity_details'] = $specific;
                // Also set surf_sup_details for backward compatibility
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
                
                // ✅ NEW: Get per-person per-session activity breakdown
                $personSessionActivities = $this->db->fetchAll(
                    "SELECT pps.*, bp.name, bp.age, s.start_time, s.end_time
                    FROM package_person_sessions pps
                    JOIN booking_people bp ON pps.booking_person_id = bp.id
                    JOIN slots s ON pps.slot_id = s.id
                    WHERE pps.package_booking_id = ?
                    ORDER BY pps.session_number, bp.id",
                    [$specific['id']]
                );
                $booking['person_session_activities'] = $personSessionActivities;
                
                // ✅ NEW: Get activity breakdown per session
                $sessionActivityBreakdown = $this->db->fetchAll(
                    "SELECT 
                        session_number,
                        session_date,
                        activity_type,
                        COUNT(*) as count
                    FROM package_person_sessions
                    WHERE package_booking_id = ?
                    GROUP BY session_number, session_date, activity_type
                    ORDER BY session_number, activity_type",
                    [$specific['id']]
                );
                $booking['session_activity_breakdown'] = $sessionActivityBreakdown;
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
     * ENHANCED: Cancel booking - Updated for package bookings
     */
    public function cancel($bookingId) {
        $this->db->beginTransaction();
        
        try {
            $booking = $this->getById($bookingId);
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            // Release capacity based on booking type
            if ($booking['booking_type'] === 'activity') {
                // ✅ Existing activity booking cancellation
                $activityGroups = [];
                foreach ($booking['people'] as $person) {
                    $activity = $person['activity_type'];
                    if (!isset($activityGroups[$activity])) {
                        $activityGroups[$activity] = 0;
                    }
                    $activityGroups[$activity]++;
                }
                
                foreach ($activityGroups as $activityType => $count) {
                    $this->slot->releaseActivitySlot(
                        $booking['activity_details']['slot_id'],
                        $booking['activity_details']['session_date'],
                        $activityType,
                        $count
                    );
                }
                
            } elseif ($booking['booking_type'] === 'package') {
                // ✅ NEW: Package booking cancellation - release per-session per-activity capacity
                if (isset($booking['person_session_activities'])) {
                    // Group by session, slot, activity
                    $sessionActivities = [];
                    foreach ($booking['person_session_activities'] as $psa) {
                        $key = $psa['session_date'] . '|' . $psa['slot_id'] . '|' . $psa['activity_type'];
                        if (!isset($sessionActivities[$key])) {
                            $sessionActivities[$key] = [
                                'session_date' => $psa['session_date'],
                                'slot_id' => $psa['slot_id'],
                                'activity_type' => $psa['activity_type'],
                                'count' => 0
                            ];
                        }
                        $sessionActivities[$key]['count']++;
                    }
                    
                    // Release capacity for each session-activity combination
                    foreach ($sessionActivities as $sa) {
                        $this->slot->releaseActivitySlot(
                            $sa['slot_id'],
                            $sa['session_date'],
                            $sa['activity_type'],
                            $sa['count']
                        );
                    }
                } else {
                    // ✅ FALLBACK: Use old method if person_session_activities not available
                    $slots = [];
                    foreach ($booking['sessions'] as $session) {
                        $slots[] = ['slot_id' => $session['slot_id'], 'date' => $session['session_date']];
                    }
                    $this->slot->releaseSlots($slots, $booking['total_people']);
                }
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
     * Generate booking reference
     */
    public function generateBookingReference($bookingId, $date, $type) {
        $typeCode = strtoupper(substr($type, 0, 3));
        $dateCode = date('ymd', strtotime($date));
        return "MSC-{$typeCode}-{$bookingId}-{$dateCode}";
    }
    
    /**
     * Validation for activity booking
     */
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
        $validActivities = ['surf', 'sup', 'kayak'];
        foreach ($data['people'] as $i => $person) {
            if (empty($person['name'])) {
                $errors[] = "Name is required for person " . ($i + 1);
            }
            if (empty($person['age']) || !is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
                $errors[] = "Valid age (5-100) is required for person " . ($i + 1);
            }
            // ✅ NEW: Validate activity_type for each person
            if (empty($person['activity_type']) || !in_array($person['activity_type'], $validActivities)) {
                $errors[] = "Valid activity selection is required for person " . ($i + 1);
            }
        }
        
        return $errors;
    }
    
    /**
     * LEGACY METHOD: validateSurfSupBooking - now redirects to activity validation
     */
    private function validateSurfSupBooking($data) {
        // Convert service_type to activity_type for validation
        if (isset($data['service_type']) && !isset($data['activity_type'])) {
            $data['activity_type'] = $data['service_type'];
        }
        return $this->validateActivityBooking($data);
    }
    
    /**
     * ENHANCED: Validation for package booking
     * ✅ Validates per-person per-session activity selections
     * ✅ Maintains all existing validation logic
     */
    private function validatePackageBooking($data) {
        $errors = [];
        
        // Basic customer validation
        if (empty($data['customer_name'])) {
            $errors[] = 'Customer name is required';
        }
        
        if (empty($data['customer_email']) || !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        
        if (empty($data['customer_phone'])) {
            $errors[] = 'Phone number is required';
        }
        
        // ✅ REMOVED: service_type validation (now per-person)
        // Package bookings now track activity per person, not at group level
        
        if (empty($data['people']) || !is_array($data['people'])) {
            $errors[] = 'At least one person is required';
        }
        
        // ✅ ENHANCED: Validate each person WITH activity_type
        if (!empty($data['people'])) {
            foreach ($data['people'] as $i => $person) {
                if (empty($person['name'])) {
                    $errors[] = "Name is required for person " . ($i + 1);
                }
                if (empty($person['age']) || !is_numeric($person['age']) || $person['age'] < 5 || $person['age'] > 100) {
                    $errors[] = "Valid age (5-100) is required for person " . ($i + 1);
                }
                // ✅ NEW: Validate activity_type for each person
                if (empty($person['activity_type']) || !in_array($person['activity_type'], ['surf', 'sup'])) {
                    $errors[] = "Valid activity selection (surf or sup) is required for person " . ($i + 1);
                }
            }
        }
        
        // Package-specific validation
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
        
        // ✅ ENHANCED: Validate sessions array with people_activities
        if (empty($data['sessions']) || !is_array($data['sessions'])) {
            $errors[] = 'Session information is required';
        } else {
            // Check session count matches package type
            $expectedSessions = 1;
            if (strpos($data['package_type'], '2_sessions') !== false) $expectedSessions = 2;
            if (strpos($data['package_type'], '3_sessions') !== false) $expectedSessions = 3;
            
            if (count($data['sessions']) !== $expectedSessions) {
                $errors[] = "Package requires exactly $expectedSessions sessions, got " . count($data['sessions']);
            }
            
            $peopleCount = count($data['people']);
            
            foreach ($data['sessions'] as $i => $session) {
                $sessionNum = $i + 1;
                
                // Validate session date
                if (empty($session['session_date']) || !strtotime($session['session_date'])) {
                    $errors[] = "Valid session date is required for session $sessionNum";
                }
                
                // Validate slot_id (can be empty for auto-allocation)
                if (!empty($session['slot_id']) && !is_numeric($session['slot_id'])) {
                    $errors[] = "Invalid slot selection for session $sessionNum";
                }
                
                // ✅ NEW: Validate people_activities array
                if (empty($session['people_activities']) || !is_array($session['people_activities'])) {
                    $errors[] = "Missing people_activities for session $sessionNum";
                    continue; // Skip further validation for this session
                }
                
                // Validate people_activities count matches total people
                if (count($session['people_activities']) !== $peopleCount) {
                    $errors[] = "Session $sessionNum: people_activities count (" . count($session['people_activities']) . ") doesn't match total people ($peopleCount)";
                }
                
                // Validate each person_activity
                $seenPersonIndices = [];
                foreach ($session['people_activities'] as $j => $pa) {
                    // Validate person_index
                    if (!isset($pa['person_index']) || !is_numeric($pa['person_index'])) {
                        $errors[] = "Session $sessionNum: Missing or invalid person_index for activity " . ($j + 1);
                        continue;
                    }
                    
                    $personIndex = (int)$pa['person_index'];
                    
                    // Check person_index is within valid range
                    if ($personIndex < 0 || $personIndex >= $peopleCount) {
                        $errors[] = "Session $sessionNum: Invalid person_index $personIndex (must be 0-" . ($peopleCount - 1) . ")";
                    }
                    
                    // Check for duplicate person_index in same session
                    if (in_array($personIndex, $seenPersonIndices)) {
                        $errors[] = "Session $sessionNum: Duplicate person_index $personIndex";
                    }
                    $seenPersonIndices[] = $personIndex;
                    
                    // Validate activity_type
                    if (empty($pa['activity_type']) || !in_array($pa['activity_type'], ['surf', 'sup'])) {
                        $errors[] = "Session $sessionNum: Invalid activity_type for person " . ($personIndex + 1);
                    }
                }
                
                // Ensure all people are accounted for in this session
                if (count($seenPersonIndices) === $peopleCount) {
                    $expectedIndices = range(0, $peopleCount - 1);
                    $missingIndices = array_diff($expectedIndices, $seenPersonIndices);
                    if (!empty($missingIndices)) {
                        $errors[] = "Session $sessionNum: Missing activity selection for person(s): " . implode(', ', array_map(function($i) { return $i + 1; }, $missingIndices));
                    }
                }
            }
        }
        
        // Validate accommodation capacity
        if (!empty($data['people']) && !empty($data['accommodation_type'])) {
            try {
                calculateAccommodationRequirements($data['accommodation_type'], count($data['people']));
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        
        return $errors;
    }

    /**
     * Enhanced validation for stay booking
     */
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
        
        // Validate accommodation capacity
        if (!empty($data['people']) && !empty($data['accommodation_type'])) {
            try {
                calculateAccommodationRequirements($data['accommodation_type'], count($data['people']));
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        
        return $errors;
    }
    
    /**
     * Calculate package pricing
     */
    public function calculatePackagePrice($packageType, $accommodationType, $peopleCount) {
        try {
            $packagePricing = calculatePackagePriceWithCapacity($packageType, $accommodationType, $peopleCount);
            return $packagePricing['base_amount'];
        } catch (Exception $e) {
            throw new Exception("Package booking error: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate stay-only pricing
     */
    public function calculateStayPrice($accommodationType, $peopleCount, $nights, $includesMeals) {
        try {
            $stayPricing = calculateStayPriceWithCapacity($accommodationType, $peopleCount, $nights, $includesMeals);
            return $stayPricing['base_amount'];
        } catch (Exception $e) {
            throw new Exception("Stay booking error: " . $e->getMessage());
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
                "SELECT COUNT(*) FROM activity_bookings ab
                 JOIN bookings b ON ab.booking_id = b.id
                 WHERE ab.session_date = CURDATE() AND b.booking_status = 'confirmed'
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
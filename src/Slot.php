<?php
/**
 * Updated Slot Management Class
 * Handles slot availability, booking, and weekly schedule management
 * Now supports activity-based capacity system
 */

class Slot {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get available slots for a specific date (LEGACY METHOD - for backward compatibility)
     * This method is kept for package bookings that still use the old system
     */
    public function getAvailableSlots($date) {
        $dayOfWeek = date('N', strtotime($date)); // 1=Monday, 7=Sunday
        
        // For backward compatibility, we'll return slots with basic info
        // Package bookings can still use this
        $slots = $this->db->fetchAll(
            "SELECT s.id, s.day_of_week, s.start_time, s.end_time, s.is_active, s.created_at, s.updated_at
             FROM slots s
             WHERE s.day_of_week = ? AND s.is_active = 1
             ORDER BY s.start_time",
            [$dayOfWeek]
        );
        
        // Add formatted time for each slot
        foreach ($slots as &$slot) {
            $slot['formatted_time'] = $this->formatSlotTime($slot['start_time'], $slot['end_time']);
        }
        
        return $slots;
    }
    
    /**
     * Get activity-specific slot availability for a date
     */
    public function getActivitySlots($date, $activityType = null) {
        $dayOfWeek = date('N', strtotime($date)); // 1=Monday, 7=Sunday
        
        $sql = "SELECT s.id, s.day_of_week, s.start_time, s.end_time, s.is_active,
                       sa.activity_type, sa.max_capacity,
                       COALESCE(saa.booked_count, 0) as booked_count,
                       (sa.max_capacity - COALESCE(saa.booked_count, 0)) as available_spots
                FROM slots s
                JOIN slot_activities sa ON s.id = sa.slot_id
                LEFT JOIN slot_activity_availability saa ON s.id = saa.slot_id 
                    AND saa.booking_date = ? AND saa.activity_type = sa.activity_type
                WHERE s.day_of_week = ? AND s.is_active = 1";
        
        $params = [$date, $dayOfWeek];
        
        if ($activityType) {
            $sql .= " AND sa.activity_type = ?";
            $params[] = $activityType;
        }
        
        $sql .= " ORDER BY s.start_time, sa.activity_type";
        
        $slots = $this->db->fetchAll($sql, $params);
        
        // Add formatted time and availability flags
        foreach ($slots as &$slot) {
            $slot['formatted_time'] = $this->formatSlotTime($slot['start_time'], $slot['end_time']);
            $slot['can_book'] = $slot['available_spots'] > 0;
        }
        
        return $slots;
    }
    
    /**
     * Get all slots for admin (weekly schedule) with activity capacities
     */
    public function getWeeklySchedule() {
        return $this->db->fetchAll(
            "SELECT s.*, 
                    GROUP_CONCAT(CONCAT(sa.activity_type, ':', sa.max_capacity) SEPARATOR ',') as activity_capacities
             FROM slots s
             LEFT JOIN slot_activities sa ON s.id = sa.slot_id
             GROUP BY s.id
             ORDER BY s.day_of_week, s.start_time"
        );
    }
    
    /**
     * Check if activity slot has availability
     */
    public function hasActivityAvailability($slotId, $date, $activityType, $peopleCount) {
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
     * LEGACY METHOD: Check if slot has availability (for package bookings)
     * This uses a simple total capacity check across all activities
     */
    public function hasAvailability($slotId, $date, $peopleCount) {
        // For backward compatibility with package bookings
        // We'll check if ANY activity has enough spots for the people count
        $activities = $this->db->fetchAll(
            "SELECT sa.activity_type, sa.max_capacity, COALESCE(saa.booked_count, 0) as booked_count,
                    (sa.max_capacity - COALESCE(saa.booked_count, 0)) as available_spots
             FROM slot_activities sa
             LEFT JOIN slot_activity_availability saa ON sa.slot_id = saa.slot_id 
                 AND saa.booking_date = ? AND saa.activity_type = sa.activity_type
             WHERE sa.slot_id = ?
             ORDER BY available_spots DESC",
            [$date, $slotId]
        );
        
        if (empty($activities)) {
            return false;
        }
        
        // Check if the activity with most availability can accommodate the group
        return $activities[0]['available_spots'] >= $peopleCount;
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
     * LEGACY METHOD: Release slots (for package booking cancellations)
     */
    public function releaseSlots($slots, $peopleCount) {
        $this->db->beginTransaction();
        
        try {
            foreach ($slots as $slotData) {
                $slotId = $slotData['slot_id'];
                $date = $slotData['date'];
                
                // For backward compatibility, we'll release from the first available activity
                // This is a fallback for package bookings
                $firstActivity = $this->db->fetch(
                    "SELECT activity_type FROM slot_activities WHERE slot_id = ? LIMIT 1",
                    [$slotId]
                );
                
                if ($firstActivity) {
                    $this->releaseActivitySlot($slotId, $date, $firstActivity['activity_type'], $peopleCount);
                }
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get bookable dates (next 7 days)
     */
    public function getBookableDates() {
        $dates = [];
        for ($i = 0; $i < BOOKING_ADVANCE_DAYS; $i++) {
            $date = date('Y-m-d', strtotime('+' . $i . ' days'));
            $dayName = date('l', strtotime($date));
            
            $dates[] = [
                'date' => $date,
                'day_name' => $dayName,
                'formatted_date' => date('M d, Y', strtotime($date)),
                'is_today' => ($i === 0),
                'is_weekend' => in_array(date('N', strtotime($date)), [6, 7])
            ];
        }
        
        return $dates;
    }
    
    /**
     * Get slot by ID
     */
    public function getById($id) {
        return $this->db->fetch(
            "SELECT * FROM slots WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * Get slot with activity capacities
     */
    public function getSlotWithActivities($id) {
        $slot = $this->getById($id);
        if ($slot) {
            $slot['activities'] = $this->db->fetchAll(
                "SELECT activity_type, max_capacity FROM slot_activities WHERE slot_id = ?",
                [$id]
            );
        }
        return $slot;
    }
    
    /**
     * Create new slot (admin) - now creates with default activity capacities
     */
    public function create($dayOfWeek, $startTime, $endTime) {
        $this->db->beginTransaction();
        try {
            // Create the slot
            $slotId = $this->db->insert(
                "INSERT INTO slots (day_of_week, start_time, end_time) VALUES (?, ?, ?)",
                [$dayOfWeek, $startTime, $endTime]
            );
            
            // Add default activity capacities
            $defaultActivities = [
                ['surf', 40],
                ['sup', 12], 
                ['kayak', 2]
            ];
            
            foreach ($defaultActivities as $activity) {
                $this->db->insert(
                    "INSERT INTO slot_activities (slot_id, activity_type, max_capacity) VALUES (?, ?, ?)",
                    [$slotId, $activity[0], $activity[1]]
                );
            }
            
            $this->db->commit();
            return $slotId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Update slot (admin)
     */
    public function update($id, $startTime, $endTime, $isActive = 1) {
        $this->db->beginTransaction();
        try {
            $affectedRows = $this->db->execute(
                "UPDATE slots SET start_time = ?, end_time = ?, is_active = ?, updated_at = NOW() 
                 WHERE id = ?",
                [$startTime, $endTime, $isActive, $id]
            );
            $this->db->commit();
            return $affectedRows;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Update activity capacity for a slot
     */
    public function updateActivityCapacity($slotId, $activityType, $capacity) {
        return $this->db->query(
            "INSERT INTO slot_activities (slot_id, activity_type, max_capacity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE max_capacity = ?",
            [$slotId, $activityType, $capacity, $capacity]
        );
    }
    
    /**
     * Deactivate slot (soft delete)
     */
    public function deactivate($id) {
        $this->db->beginTransaction();
        try {
            $affectedRows = $this->db->execute(
                "UPDATE slots SET is_active = 0, updated_at = NOW() WHERE id = ?",
                [$id]
            );
            $this->db->commit();
            return $affectedRows;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get capacity utilization statistics for activities
     */
    public function getActivityUtilizationStats($date) {
        return $this->db->fetchAll(
            "SELECT s.*, sa.activity_type, sa.max_capacity,
                    COALESCE(saa.booked_count, 0) as booked_count,
                    ROUND((COALESCE(saa.booked_count, 0) / sa.max_capacity) * 100, 2) as utilization_percent
             FROM slots s
             JOIN slot_activities sa ON s.id = sa.slot_id
             LEFT JOIN slot_activity_availability saa ON s.id = saa.slot_id 
                 AND saa.booking_date = ? AND saa.activity_type = sa.activity_type
             WHERE s.is_active = 1
             ORDER BY s.start_time, sa.activity_type",
            [$date]
        );
    }
    
    /**
     * Get next available slot for auto-selection (activity-based)
     */
    public function getNextAvailableActivitySlot($date, $activityType, $peopleCount) {
        $slots = $this->getActivitySlots($date, $activityType);
        
        foreach ($slots as $slot) {
            if ($slot['available_spots'] >= $peopleCount) {
                return $slot;
            }
        }
        
        return null;
    }
    
    /**
     * LEGACY METHOD: Get next available slot (for package bookings)
     */
    public function getNextAvailableSlot($date, $peopleCount) {
        $slots = $this->getAvailableSlots($date);
        
        foreach ($slots as $slot) {
            if ($this->hasAvailability($slot['id'], $date, $peopleCount)) {
                return $slot;
            }
        }
        
        return null;
    }
    
    /**
     * Validate slot time format
     */
    public function validateTime($time) {
        return (bool) strtotime($time);
    }
    
    /**
     * Format slot time for display
     */
    public function formatSlotTime($startTime, $endTime) {
        return date('g:i A', strtotime($startTime)) . ' - ' . date('g:i A', strtotime($endTime));
    }
    
    /**
     * Check for overlapping slots
     */
    public function hasOverlappingSlots($dayOfWeek, $startTime, $endTime, $excludeId = null) {
        $sql = "SELECT COUNT(*) FROM slots 
                WHERE day_of_week = ? AND is_active = 1 
                AND ((start_time <= ? AND end_time > ?) OR (start_time < ? AND end_time >= ?))";
        $params = [$dayOfWeek, $startTime, $startTime, $endTime, $endTime];
        
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        
        return $this->db->count($sql, $params) > 0;
    }
}
?>
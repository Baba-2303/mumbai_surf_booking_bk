<?php
/**
 * Slot Management Class
 * Handles slot availability, booking, and weekly schedule management
 */

class Slot {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get available slots for a specific date
     */
    public function getAvailableSlots($date) {
        $dayOfWeek = date('N', strtotime($date)); // 1=Monday, 7=Sunday
        
        $slots = $this->db->fetchAll(
            "SELECT s.*, 
                    COALESCE(sa.booked_count, 0) as booked_count,
                    (s.capacity - COALESCE(sa.booked_count, 0)) as available_spots
             FROM slots s
             LEFT JOIN slot_availability sa ON s.id = sa.slot_id AND sa.booking_date = ?
             WHERE s.day_of_week = ? AND s.is_active = 1
             ORDER BY s.start_time",
            [$date, $dayOfWeek]
        );
        
        return $slots;
    }
    
    /**
     * Get all slots for admin (weekly schedule)
     */
    public function getWeeklySchedule() {
        return $this->db->fetchAll(
            "SELECT * FROM slots ORDER BY day_of_week, start_time"
        );
    }
    
    /**
     * Check if slot has availability for specific date and number of people
     */
    public function hasAvailability($slotId, $date, $peopleCount) {
        $slot = $this->db->fetch(
            "SELECT s.capacity, COALESCE(sa.booked_count, 0) as booked_count
             FROM slots s
             LEFT JOIN slot_availability sa ON s.id = sa.slot_id AND sa.booking_date = ?
             WHERE s.id = ? AND s.is_active = 1",
            [$date, $slotId]
        );
        
        if (!$slot) {
            return false;
        }
        
        $availableSpots = $slot['capacity'] - $slot['booked_count'];
        return $availableSpots >= $peopleCount;
    }
    
    /**
     * Reserve slots (used during booking process)
     */
    public function reserveSlots($slots, $peopleCount) {
        $this->db->beginTransaction();
        
        try {
            foreach ($slots as $slotData) {
                $slotId = $slotData['slot_id'];
                $date = $slotData['date'];
                
                // Check availability again (double-check)
                if (!$this->hasAvailability($slotId, $date, $peopleCount)) {
                    throw new Exception("Slot no longer available: $date");
                }
                
                // Create or update slot availability record
                $this->db->query(
                    "INSERT INTO slot_availability (slot_id, booking_date, booked_count, max_capacity) 
                     VALUES (?, ?, ?, (SELECT capacity FROM slots WHERE id = ?))
                     ON DUPLICATE KEY UPDATE booked_count = booked_count + ?",
                    [$slotId, $date, $peopleCount, $slotId, $peopleCount]
                );
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Release slots (used when booking is cancelled)
     */
    public function releaseSlots($slots, $peopleCount) {
        $this->db->beginTransaction();
        
        try {
            foreach ($slots as $slotData) {
                $slotId = $slotData['slot_id'];
                $date = $slotData['date'];
                
                $this->db->execute(
                    "UPDATE slot_availability 
                     SET booked_count = GREATEST(0, booked_count - ?) 
                     WHERE slot_id = ? AND booking_date = ?",
                    [$peopleCount, $slotId, $date]
                );
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
     * Get slot availability for a specific date range (admin view)
     */
    public function getAvailabilityReport($startDate, $endDate) {
        return $this->db->fetchAll(
            "SELECT s.*, sa.booking_date, sa.booked_count, sa.max_capacity,
                    CASE WHEN sa.booking_date IS NOT NULL 
                         THEN (sa.max_capacity - sa.booked_count) 
                         ELSE s.capacity 
                    END as available_spots
             FROM slots s
             LEFT JOIN slot_availability sa ON s.id = sa.slot_id 
                 AND sa.booking_date BETWEEN ? AND ?
             WHERE s.is_active = 1
             ORDER BY sa.booking_date, s.day_of_week, s.start_time",
            [$startDate, $endDate]
        );
    }
    
    /**
     * Create new slot (admin)
     */
    public function create($dayOfWeek, $startTime, $endTime, $capacity = 40) {
        return $this->db->insert(
            "INSERT INTO slots (day_of_week, start_time, end_time, capacity) VALUES (?, ?, ?, ?)",
            [$dayOfWeek, $startTime, $endTime, $capacity]
        );
    }
    
    /**
     * Update slot (admin)
     */
    public function update($id, $startTime, $endTime, $capacity, $isActive = 1) {
        return $this->db->execute(
            "UPDATE slots SET start_time = ?, end_time = ?, capacity = ?, is_active = ?, updated_at = NOW() 
             WHERE id = ?",
            [$startTime, $endTime, $capacity, $isActive, $id]
        );
    }
    
    /**
     * Deactivate slot (soft delete)
     */
    public function deactivate($id) {
        return $this->db->execute(
            "UPDATE slots SET is_active = 0, updated_at = NOW() WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * Get capacity utilization statistics
     */
    public function getUtilizationStats($date) {
        return $this->db->fetchAll(
            "SELECT s.*, 
                    COALESCE(sa.booked_count, 0) as booked_count,
                    ROUND((COALESCE(sa.booked_count, 0) / s.capacity) * 100, 2) as utilization_percent
             FROM slots s
             LEFT JOIN slot_availability sa ON s.id = sa.slot_id AND sa.booking_date = ?
             WHERE s.is_active = 1
             ORDER BY utilization_percent DESC",
            [$date]
        );
    }
    
    /**
     * Get next available slot for auto-selection
     */
    public function getNextAvailableSlot($date, $peopleCount) {
        $slots = $this->getAvailableSlots($date);
        
        foreach ($slots as $slot) {
            if ($slot['available_spots'] >= $peopleCount) {
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
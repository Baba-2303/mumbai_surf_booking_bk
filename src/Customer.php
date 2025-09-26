<?php
/**
 * Updated Customer Management Class
 * Now supports activity-based booking system
 */

class Customer {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Create or get existing customer - FIXED VERSION
     * No transaction management - let the caller handle it
     */
    public function createOrGet($name, $email, $phone) {
        // Check if customer already exists
        $existing = $this->db->fetch(
            "SELECT * FROM customers WHERE email = ?",
            [$email]
        );
        
        if ($existing) {
            // Update phone number if different
            if ($existing['phone'] !== $phone || $existing['name'] !== $name) {
                $this->db->execute(
                    "UPDATE customers SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?",
                    [$name, $phone, $existing['id']]
                );
            }
            return $existing['id'];
        }
        
        // Create new customer
        return $this->db->insert(
            "INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)",
            [$name, $email, $phone]
        );
    }
    
    /**
     * Create or get existing customer with transaction - NEW METHOD
     * Use this when you need transaction management
     */
    public function createOrGetWithTransaction($name, $email, $phone) {
        $this->db->beginTransaction();
        try {
            $customerId = $this->createOrGet($name, $email, $phone);
            $this->db->commit();
            return $customerId;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Get customer by ID
     */
    public function getById($id) {
        return $this->db->fetch(
            "SELECT * FROM customers WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * Get customer by email
     */
    public function getByEmail($email) {
        return $this->db->fetch(
            "SELECT * FROM customers WHERE email = ?",
            [$email]
        );
    }
    
    /**
     * Get all customers for admin
     */
    public function getAll($limit = 100, $offset = 0, $search = '') {
        if ($search) {
            $searchTerm = '%' . $this->db->escapeLike($search) . '%';
            return $this->db->fetchAll(
                "SELECT * FROM customers 
                 WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?
                 ORDER BY created_at DESC 
                 LIMIT ? OFFSET ?",
                [$searchTerm, $searchTerm, $searchTerm, $limit, $offset]
            );
        }
        
        return $this->db->fetchAll(
            "SELECT * FROM customers ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }
    
    /**
     * Get customer booking history - UPDATED for activity system
     */
    public function getBookingHistory($customerId) {
        return $this->db->fetchAll(
            "SELECT b.*, 
                    CASE 
                        WHEN b.booking_type = 'activity' THEN ab.activity_type
                        WHEN b.booking_type = 'surf_sup' THEN ab.service_type
                        WHEN b.booking_type = 'package' THEN pb.service_type
                        ELSE 'N/A'
                    END as service_type,
                    CASE 
                        WHEN b.booking_type IN ('activity', 'surf_sup') THEN ab.session_date
                        WHEN b.booking_type = 'package' THEN pb.check_in_date
                        WHEN b.booking_type = 'stay_only' THEN sb.check_in_date
                    END as booking_date
             FROM bookings b
             LEFT JOIN activity_bookings ab ON b.id = ab.booking_id
             LEFT JOIN package_bookings pb ON b.id = pb.booking_id
             LEFT JOIN stay_bookings sb ON b.id = sb.booking_id
             WHERE b.customer_id = ?
             ORDER BY b.created_at DESC",
            [$customerId]
        );
    }
    
    /**
     * Get customer statistics
     */
    public function getStats($customerId) {
        $stats = $this->db->fetch(
            "SELECT 
                COUNT(*) as total_bookings,
                SUM(total_amount) as total_spent,
                MAX(created_at) as last_booking_date,
                MIN(created_at) as first_booking_date
             FROM bookings 
             WHERE customer_id = ?",
            [$customerId]
        );
        
        return $stats ?: [
            'total_bookings' => 0,
            'total_spent' => 0,
            'last_booking_date' => null,
            'first_booking_date' => null
        ];
    }
    
    /**
     * Get customer activity preferences - NEW METHOD
     */
    public function getActivityPreferences($customerId) {
        return $this->db->fetchAll(
            "SELECT ab.activity_type, COUNT(*) as booking_count, 
                    MAX(ab.session_date) as last_session_date
             FROM activity_bookings ab
             JOIN bookings b ON ab.booking_id = b.id
             WHERE b.customer_id = ? AND b.booking_status != 'cancelled'
             GROUP BY ab.activity_type
             ORDER BY booking_count DESC",
            [$customerId]
        );
    }
    
    /**
     * Get customer's upcoming sessions - NEW METHOD
     */
    public function getUpcomingSessions($customerId) {
        return $this->db->fetchAll(
            "SELECT b.id as booking_id, ab.activity_type, ab.session_date, 
                    s.start_time, s.end_time, b.total_people, b.payment_status
             FROM bookings b
             JOIN activity_bookings ab ON b.id = ab.booking_id
             JOIN slots s ON ab.slot_id = s.id
             WHERE b.customer_id = ? 
               AND ab.session_date >= CURDATE() 
               AND b.booking_status = 'confirmed'
             ORDER BY ab.session_date, s.start_time",
            [$customerId]
        );
    }
    
    /**
     * Count total customers
     */
    public function countAll($search = '') {
        if ($search) {
            $searchTerm = '%' . $this->db->escapeLike($search) . '%';
            return $this->db->count(
                "SELECT COUNT(*) FROM customers 
                 WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?",
                [$searchTerm, $searchTerm, $searchTerm]
            );
        }
        
        return $this->db->count("SELECT COUNT(*) FROM customers");
    }
    
    /**
     * Validate customer data
     */
    public function validate($data) {
        $errors = [];
        
        if (empty($data['name']) || strlen(trim($data['name'])) < 2) {
            $errors['name'] = 'Name must be at least 2 characters long';
        }
        
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email address is required';
        }
        
        if (empty($data['phone']) || !preg_match('/^[+]?[\d\s\-\(\)]{10,15}$/', $data['phone'])) {
            $errors['phone'] = 'Valid phone number is required (10-15 digits)';
        }
        
        return $errors;
    }
    
    /**
     * Update customer information - NO TRANSACTION
     */
    public function update($id, $name, $phone) {
        return $this->db->execute(
            "UPDATE customers SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?",
            [$name, $phone, $id]
        );
    }
    
    /**
     * Update customer with transaction
     */
    public function updateWithTransaction($id, $name, $phone) {
        $this->db->beginTransaction();
        try {
            $affectedRows = $this->update($id, $name, $phone);
            $this->db->commit();
            return $affectedRows;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Soft delete customer (for GDPR compliance)
     */
    public function anonymize($id) {
        $this->db->beginTransaction();
        try {
            $affectedRows = $this->db->execute(
                "UPDATE customers 
                 SET name = 'Deleted User', 
                     email = CONCAT('deleted_', id, '@mumbaisurfclub.com'),
                     phone = 'DELETED',
                     updated_at = NOW() 
                 WHERE id = ?",
                [$id]
            );
            $this->db->commit();
            return $affectedRows;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
?>
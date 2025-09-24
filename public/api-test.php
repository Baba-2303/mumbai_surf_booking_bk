<?php
/**
 * API Testing Interface
 * Test the core classes and functionality
 */

require_once '../src/config.php';
require_once '../src/Database.php';
require_once '../src/Customer.php';
require_once '../src/Slot.php';
require_once '../src/Booking.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'test';

try {
    switch ($action) {
        case 'slots':
            testSlots();
            break;
        case 'customer':
            testCustomer();
            break;
        case 'booking-surf':
            testSurfSupBooking();
            break;
        case 'booking-package':
            testPackageBooking();
            break;
        case 'available-slots':
            getAvailableSlots();
            break;
        case 'bookable-dates':
            getBookableDates();
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

function testSlots() {
    $slot = new Slot();
    
    // Get available slots for today
    $today = date('Y-m-d');
    $slots = $slot->getAvailableSlots($today);
    
    echo json_encode([
        'success' => true,
        'date' => $today,
        'available_slots' => $slots,
        'total_slots' => count($slots)
    ]);
}

function testCustomer() {
    $customer = new Customer();
    
    // Test customer creation
    $customerId = $customer->createOrGet(
        'Test User',
        'test@example.com',
        '+91-9876543210'
    );
    
    // Get customer details
    $customerData = $customer->getById($customerId);
    
    echo json_encode([
        'success' => true,
        'customer_id' => $customerId,
        'customer_data' => $customerData
    ]);
}

function testSurfSupBooking() {
    $booking = new Booking();
    $slot = new Slot();
    
    // Get next available slot for tomorrow
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $availableSlot = $slot->getNextAvailableSlot($tomorrow, 2);
    
    if (!$availableSlot) {
        echo json_encode(['error' => 'No available slots for tomorrow']);
        return;
    }
    
    // Create test booking data
    $bookingData = [
        'customer_name' => 'John Doe',
        'customer_email' => 'john.doe@example.com',
        'customer_phone' => '+91-9876543210',
        'service_type' => 'surf',
        'session_date' => $tomorrow,
        'slot_id' => $availableSlot['id'],
        'people' => [
            ['name' => 'John Doe', 'age' => 30],
            ['name' => 'Jane Doe', 'age' => 28]
        ]
    ];
    
    try {
        $bookingId = $booking->createSurfSupBooking($bookingData);
        $bookingDetails = $booking->getById($bookingId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Surf booking created successfully',
            'booking_id' => $bookingId,
            'booking_details' => $bookingDetails
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Booking failed: ' . $e->getMessage()]);
    }
}

function testPackageBooking() {
    $booking = new Booking();
    $slot = new Slot();
    
    // Generate dates for 1 night 2 sessions package
    $checkInDate = date('Y-m-d', strtotime('+2 days'));
    $sessions = $booking->generatePackageSessionDates('1_night_2_sessions', $checkInDate);
    
    // Find available slots for each session
    foreach ($sessions as &$session) {
        $availableSlot = $slot->getNextAvailableSlot($session['session_date'], 2);
        if (!$availableSlot) {
            echo json_encode(['error' => 'No available slots for session on ' . $session['session_date']]);
            return;
        }
        $session['slot_id'] = $availableSlot['id'];
        $session['slot_time'] = $slot->formatSlotTime($availableSlot['start_time'], $availableSlot['end_time']);
    }
    
    // Create test package booking
    $bookingData = [
        'customer_name' => 'Alice Smith',
        'customer_email' => 'alice.smith@example.com',
        'customer_phone' => '+91-9876543211',
        'package_type' => '1_night_2_sessions',
        'accommodation_type' => 'tent',
        'service_type' => 'sup',
        'check_in_date' => $checkInDate,
        'check_out_date' => date('Y-m-d', strtotime($checkInDate . ' +1 day')),
        'sessions' => $sessions,
        'people' => [
            ['name' => 'Alice Smith', 'age' => 25],
            ['name' => 'Bob Smith', 'age' => 27]
        ]
    ];
    
    try {
        $bookingId = $booking->createPackageBooking($bookingData);
        $bookingDetails = $booking->getById($bookingId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Package booking created successfully',
            'booking_id' => $bookingId,
            'booking_details' => $bookingDetails,
            'generated_sessions' => $sessions
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Package booking failed: ' . $e->getMessage()]);
    }
}

function getAvailableSlots() {
    $date = $_GET['date'] ?? date('Y-m-d');
    $people = (int)($_GET['people'] ?? 1);
    
    $slot = new Slot();
    $slots = $slot->getAvailableSlots($date);
    
    // Filter by availability
    $availableSlots = array_filter($slots, function($slot) use ($people) {
        return $slot['available_spots'] >= $people;
    });
    
    echo json_encode([
        'success' => true,
        'date' => $date,
        'people_count' => $people,
        'available_slots' => array_values($availableSlots),
        'total_available' => count($availableSlots)
    ]);
}

function getBookableDates() {
    $slot = new Slot();
    $dates = $slot->getBookableDates();
    
    echo json_encode([
        'success' => true,
        'bookable_dates' => $dates,
        'booking_window_days' => BOOKING_ADVANCE_DAYS
    ]);
}
?>
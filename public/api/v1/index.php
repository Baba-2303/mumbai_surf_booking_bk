<?php
/**
 * Mumbai Surf Club API Router v1
 */

// Define a constant for the project's root directory
define('PROJECT_ROOT', dirname(__DIR__, 3));

// Use the constant to build absolute paths to your source files
require_once PROJECT_ROOT . '/src/config.php';
require_once PROJECT_ROOT . '/src/Database.php';
require_once PROJECT_ROOT . '/src/Customer.php';
require_once PROJECT_ROOT . '/src/Slot.php';
require_once PROJECT_ROOT . '/src/Booking.php';

// Use __DIR__ for files relative to the current file
require_once __DIR__ . '/middleware/cors.php';
require_once __DIR__ . '/middleware/auth.php';

// Set JSON content type
header('Content-Type: application/json');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    handleCORS();
    http_response_code(200);
    exit;
}

// Apply CORS headers
handleCORS();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/v1', '', $path);
$pathParts = array_values(array_filter(explode('/', $path)));

try {
    // Route the request
    if (empty($pathParts)) {
        sendResponse(true, ['message' => 'Mumbai Surf Club API v1.0', 'endpoints' => getAvailableEndpoints()]);
    }
    
    $endpoint = $pathParts[0] ?? '';
    $resource =  '';
    $id =  '';
    $action =  '';

    // Check if the second part is numeric (an ID) or a string (a resource)
    if (isset($pathParts[1])) {
        if (is_numeric($pathParts[1])) {
            $id = $pathParts[1];
            // Potentially handle actions like /bookings/5/cancel
            $action = $pathParts[2] ?? ''; 
        } else {
            $resource = $pathParts[1];
            // Potentially handle IDs after a resource, e.g., /admin/users/5
            $id = $pathParts[2] ?? '';
            $action = $pathParts[3] ?? '';
        }
    }
    
    switch ($endpoint) {
        case 'health':
            handleHealthCheck();
            break;
            
        case 'slots':
            require_once 'endpoints/slots.php';
            handleSlotsEndpoint($method, $resource, $id);
            break;
            
        case 'bookings':
            require_once 'endpoints/bookings.php';
            handleBookingsEndpoint($method, $resource, $id);
            break;
            
        case 'pricing':
            require_once 'endpoints/pricing.php';
            handlePricingEndpoint($method, $resource, $id ?? '');
            break;
            
        case 'utils':
            require_once 'endpoints/utils.php';
            handleUtilsEndpoint($method, $resource, $id ?? '');
            break;
            
        case 'admin':
            // Check authentication for admin endpoints
            if ($resource !== 'login') {
                requireAuth();
            }
            require_once 'endpoints/admin.php';
            handleAdminEndpoint($method, $resource, $id, $action);
            break;
            
        default:
            sendError('Endpoint not found', 'NOT_FOUND', 404);
    }
    
} catch (Exception $e) {
    if (ENVIRONMENT === 'development') {
        sendError($e->getMessage(), 'INTERNAL_ERROR', 500, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        sendError('Internal server error', 'INTERNAL_ERROR', 500);
    }
}

/**
 * Send JSON response
 */
function sendResponse($success, $data = null, $message = null, $code = 200) {
    http_response_code($code);
    
    $response = ['success' => $success];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($message !== null) {
        $response['message'] = $message;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 */
function sendError($message, $errorCode = 'ERROR', $httpCode = 400, $details = null) {
    http_response_code($httpCode);
    
    $response = [
        'success' => false,
        'error' => $message,
        'code' => $errorCode
    ];
    
    if ($details !== null) {
        $response['details'] = $details;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Get request body as JSON
 */
function getRequestBody() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

/**
 * Validate required fields
 */
function validateRequired($data, $requiredFields) {
    $missing = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        sendError('Missing required fields: ' . implode(', ', $missing), 'VALIDATION_ERROR', 422);
    }
}

/**
 * Health check endpoint
 */
function handleHealthCheck() {
    try {
        $db = Database::getInstance();
        $db->query("SELECT 1");
        
        sendResponse(true, [
            'status' => 'healthy',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'database' => 'connected'
        ]);
    } catch (Exception $e) {
        sendError('Health check failed', 'HEALTH_CHECK_FAILED', 503);
    }
}

/**
 * Get available endpoints
 */
function getAvailableEndpoints() {
    return [
        'public' => [
            'GET /health' => 'Health check',
            'GET /slots' => 'Get available slots',
            'GET /slots/dates' => 'Get bookable dates',
            'POST /bookings/surf-sup' => 'Create surf/SUP booking',
            'POST /bookings/package' => 'Create package booking',
            'POST /bookings/stay' => 'Create stay booking',
            'GET /bookings/{id}' => 'Get booking details',
            'POST /pricing/surf-sup' => 'Calculate surf/SUP price',
            'POST /pricing/package' => 'Calculate package price',
            'POST /pricing/stay' => 'Calculate stay price',
            'POST /utils/generate-sessions' => 'Generate package sessions'
        ],
        'admin' => [
            'POST /admin/login' => 'Admin login',
            'GET /admin/dashboard' => 'Dashboard stats',
            'GET /admin/bookings' => 'Get all bookings',
            'PUT /admin/bookings/{id}/payment' => 'Update payment status',
            'PUT /admin/bookings/{id}/cancel' => 'Cancel booking',
            'GET /admin/customers' => 'Get all customers',
            'GET /admin/slots' => 'Get slot schedule',
            'POST /admin/slots' => 'Create slot',
            'PUT /admin/slots/{id}' => 'Update slot'
        ]
    ];
}
?>
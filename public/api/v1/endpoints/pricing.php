<?php
/**
 * Updated Pricing Endpoint Handler
 * Now supports activity-based pricing system
 */

function handlePricingEndpoint($method, $resource, $id) {
    if ($method !== 'POST') {
        sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
    
    switch ($resource) {
        case 'activity':
            handleCalculateActivityPrice();
            break;
            
        case 'surf-sup':
            // Legacy endpoint - redirect to activity pricing
            handleCalculateSurfSupPrice();
            break;
            
        case 'package':
            handleCalculatePackagePrice();
            break;
            
        case 'stay':
            handleCalculateStayPrice();
            break;
            
        default:
            sendError('Invalid pricing endpoint', 'NOT_FOUND', 404);
    }
}

/**
 * NEW: POST /pricing/activity
 * Calculate pricing for activity bookings (surf/sup/kayak)
 */
function handleCalculateActivityPrice() {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, ['activity_type', 'people_count']);
    
    $activityType = $data['activity_type'];
    $peopleCount = (int)$data['people_count'];
    
    // Validate activity type
    $validActivities = array_keys(getActivityTypes());
    if (!in_array($activityType, $validActivities)) {
        sendError('Invalid activity type. Valid options: ' . implode(', ', $validActivities), 'VALIDATION_ERROR', 422);
    }
    
    // Get activity information
    $activityInfo = getActivityInfo($activityType);
    if (!$activityInfo) {
        sendError('Activity information not found', 'INTERNAL_ERROR', 500);
    }
    
    // Validate people count against activity capacity
    if ($peopleCount < 1 || $peopleCount > $activityInfo['default_capacity']) {
        sendError("People count must be between 1 and {$activityInfo['default_capacity']} for {$activityInfo['name']}", 'VALIDATION_ERROR', 422);
    }
    
    try {
        $baseAmount = $activityInfo['price_per_person'] * $peopleCount;
        $pricing = calculateTotalAmount($baseAmount);
        
        $response = [
            'booking_type' => 'activity',
            'activity' => [
                'type' => $activityType,
                'name' => $activityInfo['name'],
                'description' => $activityInfo['description'],
                'max_capacity' => $activityInfo['default_capacity']
            ],
            'people_count' => $peopleCount,
            'price_per_person' => $activityInfo['price_per_person'],
            'base_amount' => $pricing['base_amount'],
            'gst_rate' => GST_RATE,
            'gst_amount' => $pricing['gst_amount'],
            'total_amount' => $pricing['total_amount'],
            'formatted_amounts' => [
                'price_per_person' => formatCurrency($activityInfo['price_per_person']),
                'base_amount' => formatCurrency($pricing['base_amount']),
                'gst_amount' => formatCurrency($pricing['gst_amount']),
                'total_amount' => formatCurrency($pricing['total_amount'])
            ],
            'pricing_breakdown' => [
                'calculation' => "{$peopleCount} people × " . formatCurrency($activityInfo['price_per_person']) . " = " . formatCurrency($pricing['base_amount']),
                'gst_calculation' => formatCurrency($pricing['base_amount']) . " × " . (GST_RATE * 100) . "% = " . formatCurrency($pricing['gst_amount'])
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to calculate activity pricing: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * LEGACY: POST /pricing/surf-sup
 * Maintains backward compatibility
 */
function handleCalculateSurfSupPrice() {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, ['people_count']);
    
    $peopleCount = (int)$data['people_count'];
    
    // Validate people count (legacy limits)
    if ($peopleCount < 1 || $peopleCount > 40) {
        sendError('People count must be between 1 and 40', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $baseAmount = SURF_SUP_BASE_PRICE * $peopleCount;
        $pricing = calculateTotalAmount($baseAmount);
        
        $response = [
            'booking_type' => 'surf_sup',
            'people_count' => $peopleCount,
            'price_per_person' => SURF_SUP_BASE_PRICE,
            'base_amount' => $pricing['base_amount'],
            'gst_rate' => GST_RATE,
            'gst_amount' => $pricing['gst_amount'],
            'total_amount' => $pricing['total_amount'],
            'formatted_amounts' => [
                'base_amount' => formatCurrency($pricing['base_amount']),
                'gst_amount' => formatCurrency($pricing['gst_amount']),
                'total_amount' => formatCurrency($pricing['total_amount'])
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError('Failed to calculate pricing: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /pricing/package - ENHANCED VERSION
 */
function handleCalculatePackagePrice() {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, ['package_type', 'accommodation_type', 'people_count']);
    
    $packageType = $data['package_type'];
    $accommodationType = $data['accommodation_type'];
    $peopleCount = (int)$data['people_count'];
    
    // Validate package type
    if (!in_array($packageType, ['1_night_1_session', '1_night_2_sessions', '2_nights_3_sessions'])) {
        sendError('Invalid package type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate accommodation type
    if (!in_array($accommodationType, ['tent', 'dorm', 'cottage'])) {
        sendError('Invalid accommodation type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate people count
    if ($peopleCount < 1 || $peopleCount > 100) {
        sendError('People count must be between 1 and 100', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Calculate with capacity validation
        $packagePricing = calculatePackagePriceWithCapacity($packageType, $accommodationType, $peopleCount);
        $pricing = calculateTotalAmount($packagePricing['base_amount']);
        
        // Calculate nights and sessions
        $nights = (strpos($packageType, '2_nights') !== false) ? 2 : 1;
        $sessions = 1;
        if (strpos($packageType, '2_sessions') !== false) {
            $sessions = 2;
        } elseif (strpos($packageType, '3_sessions') !== false) {
            $sessions = 3;
        }
        
        // Get package details for description
        $packageDescriptions = [
            '1_night_1_session' => '1 Night Stay + 1 Activity Session',
            '1_night_2_sessions' => '1 Night Stay + 2 Activity Sessions', 
            '2_nights_3_sessions' => '2 Nights Stay + 3 Activity Sessions'
        ];
        
        $response = [
            'booking_type' => 'package',
            'package_info' => [
                'type' => $packageType,
                'description' => $packageDescriptions[$packageType] ?? 'Package Deal',
                'nights_count' => $nights,
                'sessions_count' => $sessions
            ],
            'accommodation_info' => [
                'type' => $accommodationType,
                'requirements' => $packagePricing['accommodation_requirements']
            ],
            'people_count' => $peopleCount,
            'pricing_breakdown' => [
                'pricing_type' => $accommodationType === 'cottage' ? 'per_cottage' : 'per_person',
                'units_needed' => $packagePricing['accommodation_requirements']['units_needed'],
                'price_breakdown' => $packagePricing['pricing_details']
            ],
            'base_amount' => $pricing['base_amount'],
            'gst_rate' => GST_RATE,
            'gst_amount' => $pricing['gst_amount'],
            'total_amount' => $pricing['total_amount'],
            'formatted_amounts' => [
                'base_amount' => formatCurrency($pricing['base_amount']),
                'gst_amount' => formatCurrency($pricing['gst_amount']),
                'total_amount' => formatCurrency($pricing['total_amount'])
            ],
            'inclusions' => [
                'accommodation' => "Stay in {$accommodationType}",
                'meals' => 'All meals included',
                'activities' => "{$sessions} activity session(s)",
                'equipment' => 'All equipment provided'
            ]
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError($e->getMessage(), 'CAPACITY_ERROR', 422);
    }
}

/**
 * POST /pricing/stay - ENHANCED VERSION
 */
function handleCalculateStayPrice() {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, ['accommodation_type', 'people_count', 'nights_count']);
    
    $accommodationType = $data['accommodation_type'];
    $peopleCount = (int)$data['people_count'];
    $nightsCount = (int)$data['nights_count'];
    $includesMeals = isset($data['includes_meals']) ? (bool)$data['includes_meals'] : false;
    
    // Validate accommodation type
    if (!in_array($accommodationType, ['tent', 'dorm', 'cottage'])) {
        sendError('Invalid accommodation type', 'VALIDATION_ERROR', 422);
    }
    
    // Validate people count
    if ($peopleCount < 1 || $peopleCount > 100) {
        sendError('People count must be between 1 and 100', 'VALIDATION_ERROR', 422);
    }
    
    // Validate nights count
    if ($nightsCount < 1 || $nightsCount > 30) {
        sendError('Nights count must be between 1 and 30', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Calculate with capacity validation
        $stayPricing = calculateStayPriceWithCapacity($accommodationType, $peopleCount, $nightsCount, $includesMeals);
        $pricing = calculateTotalAmount($stayPricing['base_amount']);
        
        // Get accommodation descriptions
        $accommodationDescriptions = [
            'tent' => 'Tent Accommodation - Basic outdoor experience',
            'dorm' => 'Hostel Dorm - Shared accommodation with modern amenities',
            'cottage' => 'Private Cottage - Exclusive accommodation for groups'
        ];
        
        $response = [
            'booking_type' => 'stay_only',
            'accommodation_info' => [
                'type' => $accommodationType,
                'description' => $accommodationDescriptions[$accommodationType] ?? 'Accommodation',
                'requirements' => $stayPricing['accommodation_requirements']
            ],
            'stay_details' => [
                'people_count' => $peopleCount,
                'nights_count' => $nightsCount,
                'includes_meals' => $includesMeals,
                'meal_info' => $includesMeals ? 'Includes breakfast and dinner' : 'Meals not included'
            ],
            'pricing_breakdown' => [
                'pricing_type' => $accommodationType === 'cottage' ? 'per_cottage' : 'per_person_per_night',
                'units_needed' => $stayPricing['accommodation_requirements']['units_needed'],
                'price_breakdown' => $stayPricing['pricing_details']
            ],
            'base_amount' => $pricing['base_amount'],
            'gst_rate' => GST_RATE,
            'gst_amount' => $pricing['gst_amount'],
            'total_amount' => $pricing['total_amount'],
            'formatted_amounts' => [
                'base_amount' => formatCurrency($pricing['base_amount']),
                'gst_amount' => formatCurrency($pricing['gst_amount']),
                'total_amount' => formatCurrency($pricing['total_amount'])
            ],
            'inclusions' => array_filter([
                'accommodation' => "Stay in {$accommodationType}",
                'meals' => $includesMeals ? 'Breakfast and dinner included' : null,
                'facilities' => 'Access to all campus facilities'
            ])
        ];
        
        sendResponse(true, $response);
        
    } catch (Exception $e) {
        sendError($e->getMessage(), 'CAPACITY_ERROR', 422);
    }
}
?>
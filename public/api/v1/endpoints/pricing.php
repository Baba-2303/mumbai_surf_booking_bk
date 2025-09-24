<?php
/**
 * Pricing Endpoint Handler
 */

function handlePricingEndpoint($method, $resource) {
    if ($method !== 'POST') {
        sendError('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }
    
    switch ($resource) {
        case 'surf-sup':
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
 * POST /pricing/surf-sup
 */
function handleCalculateSurfSupPrice() {
    $data = getRequestBody();
    
    // Validate required fields
    validateRequired($data, ['people_count']);
    
    $peopleCount = (int)$data['people_count'];
    
    // Validate people count
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
 * POST /pricing/package
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
    if ($peopleCount < 1 || $peopleCount > 40) {
        sendError('People count must be between 1 and 40', 'VALIDATION_ERROR', 422);
    }
    
    try {
        // Calculate package price
        $packagePrices = PACKAGE_PRICES[$packageType];
        $baseAmount = 0;
        $priceBreakdown = [];
        
        if ($accommodationType === 'cottage') {
            // Cottage pricing based on number of people (1-4)
            $cottageKey = 'cottage_' . min($peopleCount, 4);
            $baseAmount = $packagePrices[$cottageKey];
            
            $priceBreakdown = [
                'pricing_type' => 'per_cottage',
                'cottage_price' => $baseAmount,
                'people_count' => $peopleCount,
                'max_cottage_capacity' => 4
            ];
        } else {
            // Tent/dorm pricing per person
            $baseAmount = $packagePrices[$accommodationType] * $peopleCount;
            
            $priceBreakdown = [
                'pricing_type' => 'per_person',
                'price_per_person' => $packagePrices[$accommodationType],
                'people_count' => $peopleCount
            ];
        }
        
        $pricing = calculateTotalAmount($baseAmount);
        
        // Calculate nights
        $nights = (strpos($packageType, '2_nights') !== false) ? 2 : 1;
        
        $response = [
            'booking_type' => 'package',
            'package_type' => $packageType,
            'accommodation_type' => $accommodationType,
            'people_count' => $peopleCount,
            'nights_count' => $nights,
            'pricing_breakdown' => $priceBreakdown,
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
        sendError('Failed to calculate package pricing: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}

/**
 * POST /pricing/stay
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
    if ($peopleCount < 1 || $peopleCount > 40) {
        sendError('People count must be between 1 and 40', 'VALIDATION_ERROR', 422);
    }
    
    // Validate nights count
    if ($nightsCount < 1 || $nightsCount > 30) {
        sendError('Nights count must be between 1 and 30', 'VALIDATION_ERROR', 422);
    }
    
    try {
        $baseAmount = 0;
        $priceBreakdown = [];
        
        if ($accommodationType === 'cottage') {
            $basePrice = STAY_PRICES['cottage']['base_price'] * $nightsCount;
            $baseAmount = $basePrice;
            
            $priceBreakdown = [
                'pricing_type' => 'per_cottage',
                'base_price_per_night' => STAY_PRICES['cottage']['base_price'],
                'nights_count' => $nightsCount,
                'cottage_total' => $basePrice,
                'people_count' => $peopleCount,
                'includes_meals' => $includesMeals
            ];
            
            if ($includesMeals) {
                $mealPrice = STAY_PRICES['cottage']['meal_price_per_person'] * $peopleCount * $nightsCount;
                $baseAmount += $mealPrice;
                $priceBreakdown['meal_price_per_person_per_night'] = STAY_PRICES['cottage']['meal_price_per_person'];
                $priceBreakdown['meal_total'] = $mealPrice;
            }
        } else {
            $pricePerNight = $includesMeals ? 
                STAY_PRICES[$accommodationType]['with_meals'] : 
                STAY_PRICES[$accommodationType]['without_meals'];
            
            $baseAmount = $pricePerNight * $peopleCount * $nightsCount;
            
            $priceBreakdown = [
                'pricing_type' => 'per_person_per_night',
                'price_per_person_per_night' => $pricePerNight,
                'people_count' => $peopleCount,
                'nights_count' => $nightsCount,
                'includes_meals' => $includesMeals
            ];
        }
        
        $pricing = calculateTotalAmount($baseAmount);
        
        $response = [
            'booking_type' => 'stay_only',
            'accommodation_type' => $accommodationType,
            'people_count' => $peopleCount,
            'nights_count' => $nightsCount,
            'includes_meals' => $includesMeals,
            'pricing_breakdown' => $priceBreakdown,
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
        sendError('Failed to calculate stay pricing: ' . $e->getMessage(), 'INTERNAL_ERROR', 500);
    }
}
?>
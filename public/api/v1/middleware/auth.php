<?php
/**
 * ENHANCED Authentication Middleware
 * ✅ Fixed transaction management
 * ✅ Added rate limiting
 * ✅ Added login attempt logging
 */

/**
 * Require authentication for protected endpoints
 */
function requireAuth() {
    $token = getBearerToken();
    
    if (!$token) {
        sendError('Authentication required', 'UNAUTHORIZED', 401);
    }
    
    $adminData = validateToken($token);
    if (!$adminData) {
        sendError('Invalid or expired token', 'UNAUTHORIZED', 401);
    }
    
    // Store admin data in global for use in endpoints
    $GLOBALS['current_admin'] = $adminData;
    
    return $adminData;
}

/**
 * Get bearer token from Authorization header
 */
function getBearerToken() {
    $headers = getAuthHeaders();
    
    if (!empty($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Get authorization headers
 */
function getAuthHeaders() {
    $headers = array();
    
    if (isset($_SERVER['Authorization'])) {
        $headers['Authorization'] = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers['Authorization'] = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers['Authorization'] = trim($requestHeaders['Authorization']);
        }
    }
    
    return $headers;
}

/**
 * Create JWT token
 */
function createToken($adminData) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    
    $payload = json_encode([
        'admin_id' => $adminData['id'],
        'username' => $adminData['username'],
        'iat' => time(),
        'exp' => time() + SESSION_TIMEOUT
    ]);
    
    $base64Header = base64url_encode($header);
    $base64Payload = base64url_encode($payload);
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, ENCRYPTION_KEY, true);
    $base64Signature = base64url_encode($signature);
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

/**
 * Validate JWT token
 */
function validateToken($token) {
    $tokenParts = explode('.', $token);
    
    if (count($tokenParts) !== 3) {
        return false;
    }
    
    $header = base64url_decode($tokenParts[0]);
    $payload = base64url_decode($tokenParts[1]);
    $signatureProvided = $tokenParts[2];
    
    // Verify signature
    $base64Header = base64url_encode($header);
    $base64Payload = base64url_encode($payload);
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, ENCRYPTION_KEY, true);
    $base64Signature = base64url_encode($signature);
    
    if (!hash_equals($base64Signature, $signatureProvided)) {
        return false;
    }
    
    $payloadData = json_decode($payload, true);
    
    // Check expiration
    if ($payloadData['exp'] < time()) {
        return false;
    }
    
    // Get admin data from database
    $db = Database::getInstance();
    $admin = $db->fetch(
        "SELECT * FROM admin_users WHERE id = ? AND is_active = 1",
        [$payloadData['admin_id']]
    );
    
    return $admin;
}

/**
 * Base64 URL encode
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL decode
 */
function base64url_decode($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

/**
 * ✅ FIXED: Admin login function - removed transaction management
 * Transactions should be managed at endpoint level, not here
 */
function adminLogin($username, $password) {
    $db = Database::getInstance();
    
    try {
        $admin = $db->fetch(
            "SELECT * FROM admin_users WHERE username = ? AND is_active = 1",
            [$username]
        );
        
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return false;
        }
        
        // Update last login (simple query, no transaction needed)
        $db->execute(
            "UPDATE admin_users SET last_login = NOW() WHERE id = ?",
            [$admin['id']]
        );
        
        // Create token
        $token = createToken($admin);
        
        return [
            'token' => $token,
            'admin' => [
                'id' => $admin['id'],
                'username' => $admin['username'],
                'email' => $admin['email'],
                'full_name' => $admin['full_name']
            ],
            'expires_in' => SESSION_TIMEOUT
        ];
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * ✅ NEW: Rate limiting for login attempts
 * Prevents brute force attacks
 */
function checkLoginRateLimit($identifier, $maxAttempts = 5, $window = 900) {
    $cacheDir = dirname(__DIR__, 3) . '/logs/rate_limits';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/login_' . md5($identifier) . '.json';
    
    $attempts = [];
    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $attempts = json_decode($content, true) ?: [];
    }
    
    // Remove old attempts outside the time window
    $now = time();
    $attempts = array_filter($attempts, function($timestamp) use ($now, $window) {
        return ($now - $timestamp) < $window;
    });
    
    // Check if limit exceeded
    if (count($attempts) >= $maxAttempts) {
        return false; // Rate limited!
    }
    
    // Add current attempt
    $attempts[] = $now;
    file_put_contents($cacheFile, json_encode($attempts));
    
    return true; // Allowed
}

/**
 * ✅ NEW: Log failed login attempts
 * Helps track security issues
 */
function logFailedLogin($ip, $username) {
    $logDir = dirname(__DIR__, 3) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/failed_logins.log';
    
    $logEntry = date('Y-m-d H:i:s') . " - IP: $ip - Username: $username\n";
    error_log($logEntry, 3, $logFile);
}

/**
 * ✅ NEW: Check API rate limit (for booking endpoints)
 * Prevents API abuse
 */
function checkApiRateLimit($identifier, $maxRequests = 100, $window = 3600) {
    $cacheDir = dirname(__DIR__, 3) . '/logs/rate_limits';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/api_' . md5($identifier) . '.json';
    
    $requests = [];
    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $requests = json_decode($content, true) ?: [];
    }
    
    $now = time();
    $requests = array_filter($requests, function($timestamp) use ($now, $window) {
        return ($now - $timestamp) < $window;
    });
    
    if (count($requests) >= $maxRequests) {
        return false;
    }
    
    $requests[] = $now;
    file_put_contents($cacheFile, json_encode($requests));
    
    return true;
}

/**
 * ✅ NEW: Create initial admin user (run this once)
 * Usage: Call this function from a setup script
 */
function createAdminUser($username, $password, $email, $fullName) {
    $db = Database::getInstance();
    
    // Check if admin already exists
    $existing = $db->fetch(
        "SELECT id FROM admin_users WHERE username = ?",
        [$username]
    );
    
    if ($existing) {
        throw new Exception("Admin user already exists");
    }
    
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    
    return $db->insert(
        "INSERT INTO admin_users (username, password_hash, email, full_name) VALUES (?, ?, ?, ?)",
        [$username, $passwordHash, $email, $fullName]
    );
}
?>
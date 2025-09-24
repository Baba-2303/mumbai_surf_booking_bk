<?php
// Get the requested path from the server
$path = $_SERVER["REQUEST_URI"];

// If the request is for an actual file or directory, serve it directly.
// This is important for assets like CSS, JS, and images.
if (is_file(__DIR__ . $path) || is_dir(__DIR__ . $path)) {
    return false; // Let the server handle the request as-is
}

// If the request is for our API, route it to the API's index.php file.
if (strpos($path, '/api/v1') === 0) {
    require_once __DIR__ . '/api/v1/index.php';
} else {
    // Handle other routes or serve a 404 for non-API requests if needed.
    // For now, we can just let it fall through or show a generic error.
    http_response_code(404);
    echo "Page not found.";
}
?>
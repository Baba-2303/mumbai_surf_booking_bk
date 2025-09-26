<?php
/**
 * Mumbai Surf Club Booking System - Test Page
 * Updated for Activity-Based System
 */

// Include configuration and classes
require_once '../src/config.php';
require_once '../src/Database.php';
require_once '../src/Customer.php';
require_once '../src/Slot.php';
require_once '../src/Booking.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Booking System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            text-align: center;
        }
        .status {
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        pre {
            background-color: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
        }
        .button:hover {
            background-color: #0056b3;
        }
        .activity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .activity-card {
            background: #fff;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        .activity-card.available {
            border-color: #28a745;
        }
        .activity-card.limited {
            border-color: #ffc107;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?php echo SITE_NAME; ?> - Activity Booking System</h1>
        
        <div class="info">
            <strong>Environment:</strong> <?php echo ENVIRONMENT; ?><br>
            <strong>PHP Version:</strong> <?php echo PHP_VERSION; ?><br>
            <strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
            <strong>Booking Window:</strong> <?php echo BOOKING_WINDOW_TYPE; ?> (<?php echo BOOKING_ADVANCE_DAYS; ?> days)
        </div>

        <!-- Configuration Test -->
        <div class="section">
            <h3>📋 Configuration Status</h3>
            <?php
            $configOk = true;
            $configIssues = [];
            
            // Check required constants
            $requiredConstants = [
                'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
                'SITE_NAME', 'SITE_URL', 'GST_RATE', 'ACTIVITY_TYPES'
            ];
            
            foreach ($requiredConstants as $constant) {
                if (!defined($constant)) {
                    $configIssues[] = "Missing constant: $constant";
                    $configOk = false;
                }
            }
            
            if ($configOk) {
                echo '<div class="success">✅ All configuration constants are defined</div>';
            } else {
                echo '<div class="error">❌ Configuration issues found:<br>' . implode('<br>', $configIssues) . '</div>';
            }
            ?>
        </div>

        <!-- Database Connection Test -->
        <div class="section">
            <h3>🔌 Database Connection</h3>
            <?php
            try {
                $db = Database::getInstance();
                echo '<div class="success">✅ Database connection successful</div>';
                
                // Test basic query
                $result = $db->query("SELECT 1 as test");
                if ($result) {
                    echo '<div class="success">✅ Database query test passed</div>';
                } else {
                    echo '<div class="error">❌ Database query test failed</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="error">❌ Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
                echo '<div class="info">
                    <strong>Troubleshooting:</strong><br>
                    1. Make sure you have created the database in cPanel<br>
                    2. Update the DB_* constants in src/config.php with your actual database details<br>
                    3. Ensure the database user has proper permissions
                </div>';
            }
            ?>
        </div>

        <!-- Table Check -->
        <div class="section">
            <h3>🗄️ Database Tables</h3>
            <?php
            try {
                $db = Database::getInstance();
                
                // Updated table list for activity system
                $tables = [
                    'customers', 'slots', 'bookings', 'booking_people',
                    'activity_bookings', 'package_bookings', 'package_sessions',
                    'stay_bookings', 'slot_activities', 'slot_activity_availability', 'admin_users'
                ];
                
                $existingTables = $db->fetchAll("SHOW TABLES");
                $existingTableNames = array_column($existingTables, 'Tables_in_' . DB_NAME);
                
                $missingTables = array_diff($tables, $existingTableNames);
                
                if (empty($missingTables)) {
                    echo '<div class="success">✅ All required tables exist (' . count($tables) . ' tables)</div>';
                    
                    // Show table row counts
                    echo '<div class="info"><strong>Table Statistics:</strong><br>';
                    foreach ($tables as $table) {
                        try {
                            $count = $db->count("SELECT COUNT(*) FROM $table");
                            echo "$table: $count records<br>";
                        } catch (Exception $e) {
                            echo "$table: Error counting records<br>";
                        }
                    }
                    echo '</div>';
                    
                } else {
                    echo '<div class="error">❌ Missing tables: ' . implode(', ', $missingTables) . '</div>';
                    echo '<div class="info">Please run the updated database setup SQL script in phpMyAdmin</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="error">❌ Could not check tables: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Activity System Test -->
        <div class="section">
            <h3>🏄 Activity System Test</h3>
            <?php
            try {
                $activityTypes = getActivityTypes();
                echo '<div class="success">✅ Activity types loaded: ' . count($activityTypes) . '</div>';
                
                echo '<div class="activity-grid">';
                foreach ($activityTypes as $type => $info) {
                    $cardClass = $info['default_capacity'] >= 10 ? 'available' : 'limited';
                    echo '<div class="activity-card ' . $cardClass . '">';
                    echo '<h4>' . htmlspecialchars($info['name']) . '</h4>';
                    echo '<p>Capacity: ' . $info['default_capacity'] . '</p>';
                    echo '<p>Price: ' . formatCurrency($info['price_per_person']) . '</p>';
                    echo '<small>' . htmlspecialchars($info['description']) . '</small>';
                    echo '</div>';
                }
                echo '</div>';
                
                // Test booking window
                $bookingWindow = getWeeklyBookingWindow();
                echo '<div class="info">
                    <strong>Booking Window Test:</strong><br>
                    Available dates: ' . $bookingWindow['days_available'] . ' days<br>
                    Window ends: ' . $bookingWindow['window_end'] . '
                </div>';
                
            } catch (Exception $e) {
                echo '<div class="error">❌ Activity system test failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Sample Data Test -->
        <div class="section">
            <h3>📊 Sample Data & Activity Setup</h3>
            <?php
            try {
                $db = Database::getInstance();
                
                // Check admin users
                $adminCount = $db->count("SELECT COUNT(*) FROM admin_users WHERE is_active = 1");
                if ($adminCount > 0) {
                    echo '<div class="success">✅ Admin users found: ' . $adminCount . '</div>';
                } else {
                    echo '<div class="error">❌ No admin users found</div>';
                }
                
                // Check slots
                $slotCount = $db->count("SELECT COUNT(*) FROM slots WHERE is_active = 1");
                if ($slotCount > 0) {
                    echo '<div class="success">✅ Slots created: ' . $slotCount . '</div>';
                } else {
                    echo '<div class="error">❌ No slots found</div>';
                }
                
                // Check slot activities
                $activitySlotCount = $db->count("SELECT COUNT(*) FROM slot_activities");
                if ($activitySlotCount > 0) {
                    echo '<div class="success">✅ Slot activities configured: ' . $activitySlotCount . '</div>';
                    
                    // Show sample activity slots
                    $sampleActivities = $db->fetchAll(
                        "SELECT s.day_of_week, s.start_time, s.end_time, sa.activity_type, sa.max_capacity
                         FROM slot_activities sa
                         JOIN slots s ON sa.slot_id = s.id
                         WHERE s.is_active = 1
                         ORDER BY s.day_of_week, s.start_time, sa.activity_type
                         LIMIT 10"
                    );
                    
                    echo '<div class="info"><strong>Sample Activity Slots:</strong><br>';
                    foreach ($sampleActivities as $activity) {
                        $dayName = date('l', strtotime('Monday +' . ($activity['day_of_week'] - 1) . ' days'));
                        echo $dayName . ': ' . 
                             date('g:i A', strtotime($activity['start_time'])) . '-' . 
                             date('g:i A', strtotime($activity['end_time'])) . 
                             ' (' . ucfirst($activity['activity_type']) . ': ' . $activity['max_capacity'] . ')<br>';
                    }
                    echo '</div>';
                    
                } else {
                    echo '<div class="error">❌ No slot activities found - need to configure activity capacities</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="error">❌ Could not check sample data: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Helper Functions Test -->
        <div class="section">
            <h3>🛠️ Helper Functions Test</h3>
            <?php
            // Test pricing calculation
            $testAmount = 1700;
            $pricing = calculateTotalAmount($testAmount);
            echo '<div class="info">
                <strong>Pricing Test (₹1700 base):</strong><br>
                Base Amount: ' . formatCurrency($pricing['base_amount']) . '<br>
                GST (18%): ' . formatCurrency($pricing['gst_amount']) . '<br>
                Total: ' . formatCurrency($pricing['total_amount']) . '
            </div>';
            
            // Test new booking window function
            $bookingWindow = getWeeklyBookingWindow();
            echo '<div class="info">
                <strong>Weekly Booking Window:</strong><br>
                Days available: ' . $bookingWindow['days_available'] . '<br>
                First date: ' . $bookingWindow['dates'][0]['formatted_date'] . '<br>
                Last date: ' . end($bookingWindow['dates'])['formatted_date'] . '<br>
                Window ends: ' . $bookingWindow['window_end'] . '
            </div>';
            
            // Test activity info function
            $surfInfo = getActivityInfo('surf');
            if ($surfInfo) {
                echo '<div class="info">
                    <strong>Activity Info Test (Surf):</strong><br>
                    Name: ' . $surfInfo['name'] . '<br>
                    Default Capacity: ' . $surfInfo['default_capacity'] . '<br>
                    Price: ' . formatCurrency($surfInfo['price_per_person']) . '
                </div>';
            }
            ?>
        </div>

        <!-- API Endpoints Test -->
        <div class="section">
            <h3>🔗 API Endpoints</h3>
            <div class="info">
                <strong>Updated API endpoints for activity system:</strong><br>
                • GET /api/v1/activities - Get activity types<br>
                • GET /api/v1/slots/activities?date=&activity= - Get activity slots<br>
                • POST /api/v1/bookings/activity - Create activity booking<br>
                • GET /api/v1/pricing/activity - Calculate activity pricing<br>
                <br>
                <strong>Legacy endpoints (still supported):</strong><br>
                • POST /api/v1/bookings/surf-sup - Redirects to activity booking<br>
                • All package and stay booking endpoints unchanged
            </div>
        </div>

        <!-- Quick Setup Actions -->
        <div class="section">
            <h3>⚡ Quick Setup</h3>
            <?php
            try {
                $db = Database::getInstance();
                $slotsExist = $db->count("SELECT COUNT(*) FROM slots") > 0;
                $activitiesConfigured = $db->count("SELECT COUNT(*) FROM slot_activities") > 0;
                
                if (!$slotsExist) {
                    echo '<div class="error">❌ No slots configured. Please create slots first.</div>';
                } elseif (!$activitiesConfigured) {
                    echo '<div class="error">❌ Activity capacities not set. Configure slot activities.</div>';
                } else {
                    echo '<div class="success">✅ System ready for bookings!</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="error">❌ Setup check failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
            
            <p><strong>Quick Actions:</strong></p>
            <a href="/api/v1/health" class="button" target="_blank">Test API Health</a>
            <a href="/api/v1/activities" class="button" target="_blank">View Activities</a>
            <a href="#" class="button" onclick="window.location.reload()">Refresh Tests</a>
        </div>

        <!-- Debug Information -->
        <div class="section">
            <h3>🐛 Debug Information</h3>
            <details>
                <summary>Click to show debug info</summary>
                <pre><?php
                echo "Configuration Constants:\n";
                echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'Not defined') . "\n";
                echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'Not defined') . "\n";
                echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'Not defined') . "\n";
                echo "ENVIRONMENT: " . (defined('ENVIRONMENT') ? ENVIRONMENT : 'Not defined') . "\n";
                echo "BOOKING_WINDOW_TYPE: " . (defined('BOOKING_WINDOW_TYPE') ? BOOKING_WINDOW_TYPE : 'Not defined') . "\n";
                echo "\nActivity Types:\n";
                if (defined('ACTIVITY_TYPES')) {
                    foreach (ACTIVITY_TYPES as $type => $info) {
                        echo "$type: {$info['name']} (Capacity: {$info['default_capacity']})\n";
                    }
                }
                echo "\nPHP Extensions:\n";
                echo "PDO: " . (extension_loaded('pdo') ? 'Available' : 'Missing') . "\n";
                echo "PDO MySQL: " . (extension_loaded('pdo_mysql') ? 'Available' : 'Missing') . "\n";
                echo "OpenSSL: " . (extension_loaded('openssl') ? 'Available' : 'Missing') . "\n";
                echo "cURL: " . (extension_loaded('curl') ? 'Available' : 'Missing') . "\n";
                echo "JSON: " . (extension_loaded('json') ? 'Available' : 'Missing') . "\n";
                ?></pre>
            </details>
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds when in development
        <?php if (ENVIRONMENT === 'development'): ?>
        setTimeout(() => {
            const refreshBtn = document.createElement('div');
            refreshBtn.style.cssText = 'position: fixed; top: 10px; right: 10px; background: #28a745; color: white; padding: 8px 12px; border-radius: 5px; cursor: pointer; font-size: 12px;';
            refreshBtn.textContent = 'Auto-refresh in 30s';
            document.body.appendChild(refreshBtn);
            
            let countdown = 30;
            const interval = setInterval(() => {
                countdown--;
                refreshBtn.textContent = `Auto-refresh in ${countdown}s`;
                if (countdown <= 0) {
                    window.location.reload();
                }
            }, 1000);
        }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>
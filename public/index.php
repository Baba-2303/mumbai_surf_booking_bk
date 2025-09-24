<?php
/**
 * Mumbai Surf Club Booking System - Test Page
 */

// Include configuration
require_once '../src/config.php';
require_once '../src/Database.php';

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
    </style>
</head>
<body>
    <div class="container">
        <h1><?php echo SITE_NAME; ?> - Booking System Test</h1>
        
        <div class="info">
            <strong>Environment:</strong> <?php echo ENVIRONMENT; ?><br>
            <strong>PHP Version:</strong> <?php echo PHP_VERSION; ?><br>
            <strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s'); ?>
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
                'SITE_NAME', 'SITE_URL', 'GST_RATE'
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
                
                // Check if tables exist
                $tables = [
                    'customers', 'slots', 'bookings', 'booking_people',
                    'surf_sup_bookings', 'package_bookings', 'package_sessions',
                    'stay_bookings', 'slot_availability', 'admin_users'
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
                            $count = $db->count("SELECT COUNT(*) FROM `$table`");
                            echo "$table: $count records<br>";
                        } catch (Exception $e) {
                            echo "$table: Error counting records<br>";
                        }
                    }
                    echo '</div>';
                    
                } else {
                    echo '<div class="error">❌ Missing tables: ' . implode(', ', $missingTables) . '</div>';
                    echo '<div class="info">Please run the database setup SQL script in phpMyAdmin</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="error">❌ Could not check tables: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Sample Data Test -->
        <div class="section">
            <h3>📊 Sample Data</h3>
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
                    echo '<div class="success">✅ Default slots created: ' . $slotCount . '</div>';
                    
                    // Show sample slots
                    $sampleSlots = $db->fetchAll("SELECT day_of_week, start_time, end_time, capacity FROM slots WHERE is_active = 1 ORDER BY day_of_week, start_time LIMIT 5");
                    echo '<div class="info"><strong>Sample slots:</strong><br>';
                    foreach ($sampleSlots as $slot) {
                        $dayName = date('l', strtotime('Monday +' . ($slot['day_of_week'] - 1) . ' days'));
                        echo $dayName . ': ' . date('g:i A', strtotime($slot['start_time'])) . ' - ' . date('g:i A', strtotime($slot['end_time'])) . ' (Cap: ' . $slot['capacity'] . ')<br>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="error">❌ No slots found</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="error">❌ Could not check sample data: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Helper Functions Test -->
        <div class="section">
            <h3>🛠️ Helper Functions</h3>
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
            
            // Test date functions
            $weekDates = getCurrentWeekDates();
            $bookingDates = getBookingWindowDates();
            
            echo '<div class="info">
                <strong>Date Functions:</strong><br>
                Current Week: ' . $weekDates[0] . ' to ' . $weekDates[6] . '<br>
                Booking Window: ' . $bookingDates[0] . ' to ' . end($bookingDates) . ' (' . count($bookingDates) . ' days)
            </div>';
            ?>
        </div>

        <!-- Next Steps -->
        <div class="section">
            <h3>🎯 Next Steps</h3>
            <div class="info">
                <strong>To continue development:</strong><br>
                1. Copy the SQL script to phpMyAdmin and run it<br>
                2. Update your database credentials in src/config.php<br>
                3. Set up your Razorpay account and update payment keys<br>
                4. Create the booking interfaces<br>
                5. Build the admin panel
            </div>
            
            <p><strong>Quick Actions:</strong></p>
            <a href="#" class="button" onclick="alert('Coming soon!')">Test Booking Flow</a>
            <a href="#" class="button" onclick="alert('Coming soon!')">Admin Panel</a>
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
                echo "SITE_URL: " . (defined('SITE_URL') ? SITE_URL : 'Not defined') . "\n";
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
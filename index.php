<?php
/**
 * Plugin Name: Bot Logger
 * Description: A no-frills log viewer for Technical SEO. Tracks Googlebot and Bingbot by default with custom user agent support.
 * Version: 1.6
 * Author: Mike Ciffone
 * Author URI: https://ciffonedigital.com
 * Plugin URI: https://ciffonedigital.com/bot-logger
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin initialization
 */

// Include Composer's autoload file
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

include plugin_dir_path(__FILE__) . 'validation.php';
include plugin_dir_path(__FILE__) . 'cloudflare.php';

// Set the custom user agent limit
$custom_user_agent_limit = 3;

// Create or update the table to store the logs upon plugin activation
register_activation_hook(__FILE__, 'bl_plugin_activate');

function bl_plugin_activate() {
    bl_create_table();
    add_option('bl_show_import_prompt', true); // Flag to show import prompt
}

function bl_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bot_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_agent varchar(255) NOT NULL,
        bot_name varchar(255) NOT NULL,
        log_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        resource text NOT NULL,
        status_code int(3) NOT NULL,
        ip_address varchar(100) NOT NULL,
        is_valid varchar(10) DEFAULT 'unknown' NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Update existing table to add new columns if they don't exist
    if (!$wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'bot_name'")) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN bot_name VARCHAR(255) NOT NULL");
    }
    if (!$wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'resource'")) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN resource TEXT NOT NULL");
    }
    if (!$wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'ip_address'")) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN ip_address VARCHAR(100) NOT NULL");
    }
    if (!$wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'status_code'")) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN status_code INT(3) NOT NULL");
    }
    if (!$wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'is_valid'")) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN is_valid VARCHAR(10) DEFAULT 'unknown' NOT NULL");
    }
}

// Enqueue styles
add_action('admin_enqueue_scripts', 'bl_enqueue_styles', 100);

function bl_enqueue_styles() {
    wp_enqueue_style('bl_custom_styles', plugin_dir_url(__FILE__) . 'custom-styles.css');
}

// Enqueue scripts
 
add_action('admin_enqueue_scripts', 'bl_enqueue_scripts');

function bl_enqueue_scripts() {
    wp_enqueue_script('bot_logger_scripts', plugin_dir_url(__FILE__) . 'botLogger.js', array('jquery'), '1.0', true);
    wp_localize_script('bot_logger_scripts', 'bot_logger_ajax_obj', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('bot_logger_ajax_nonce')
    ));
}

/**
 * Import existing access.log data
 * To do: Node.js support, include unconventional formats
 */

// Display the import prompt on initial install
add_action('admin_notices', 'bl_import_prompt');

function bl_import_prompt() {
    if (get_option('bl_show_import_prompt')) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p>Do you want to import existing bot log data from the server\'s access log? <a href="' . esc_url(admin_url('admin.php?page=bot-logs&import=1')) . '">Yes, import now</a></p>';
        echo '</div>';
        update_option('bl_show_import_prompt', false); // don't show it thereafter
    }
}

// Handle the import action
add_action('admin_init', 'bl_handle_import');

function bl_handle_import() {
    if (isset($_GET['import']) && $_GET['import'] == 1) {
        $message = bl_import_access_logs();
        add_action('admin_notices', function() use ($message) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p>' . esc_html($message) . '</p>';
            echo '</div>';
        });
    }
}

function bl_get_log_path() {
    $server_software = $_SERVER['SERVER_SOFTWARE'];
    error_log("Server software: $server_software");

    if (stripos($server_software, 'Apache') !== false) {
        error_log("Detected Apache server. Using log path: /var/log/apache2/access.log");
        // Default Apache log path
        return '/var/log/apache2/access.log';
    } elseif (stripos($server_software, 'nginx') !== false) {
        error_log("Detected Nginx server. Using log path: /var/log/nginx/access.log");
        // Default Nginx log path
        return '/var/log/nginx/access.log';
    } elseif (stripos($server_software, 'LiteSpeed') !== false) {
        error_log("Detected LiteSpeed server. Using log path: /usr/local/lsws/logs/access.log");
        // Default LiteSpeed log path
        return '/usr/local/lsws/logs/access.log';
    } else {
        return ''; // Unknown server type
    }
}

function bl_import_access_logs($custom_path = null) {
    $access_log_path = $custom_path ? $custom_path : bl_get_log_path();
    error_log("Access log path: $access_log_path");

    if (empty($access_log_path) || !file_exists($access_log_path)) {
        error_log("Access log file not found.");
        return 'Access log file not found.';
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bot_logs';
    $file_handle = fopen($access_log_path, 'r');
    $inserted_count = 0;

    if ($file_handle) {
        while (($line = fgets($file_handle)) !== false) {
            error_log("Processing log line: $line");
            // Log format: 127.0.0.1 - - [10/May/2024:10:30:21 +0000] "GET /example-page HTTP/1.1" 200 2326 "-" "Googlebot/2.1 (+http://www.google.com/bot.html)"
            if (preg_match('/Googlebot|bingbot|Googlebot-Image|Googlebot-News|Googlebot-Video|Storebot-Google|Google-InspectionTool|GoogleOther|GoogleOther-Image|GoogleOther-Video|Google-Extended|APIs-Google|AdsBot-Google-Mobile|AdsBot-Google|Mediapartners-Google|Google-Safety/', $line)) {
                preg_match('/^([\d.]+) - - \[([^\]]+)\] "([^"]+)" (\d+) \d+ "[^"]*" "([^"]+)"/', $line, $matches);
                if (count($matches) === 6) {
                    $ip_address = $matches[1];
                    $log_date = date('Y-m-d H:i:s', strtotime($matches[2]));
                    $request_parts = explode(' ', $matches[3]);
                    $resource = $request_parts[1];
                    $status_code = intval($matches[4]);
                    $user_agent = $matches[5];
                    $bot_name = bl_get_bot_name($user_agent);

                    error_log("Logging bot request: $bot_name, $log_date, $resource, $status_code, $ip_address, $user_agent");

                    $wpdb->insert(
                        $table_name,
                        array(
                            'user_agent' => $user_agent,
                            'bot_name' => $bot_name,
                            'log_date' => $log_date,
                            'resource' => $resource,
                            'status_code' => $status_code,
                            'ip_address' => $ip_address,
                            'is_valid' => 'unknown'
                        )
                    );

                    $inserted_count++;
                } else {
                    error_log("Log line does not match expected format: $line");
                }
            }
        }
        fclose($file_handle);
    } else {
        error_log("Error opening the access log file.");
        return 'Error opening the access log file.';
    }
    error_log("Number of entries imported: $inserted_count");
    return "$inserted_count log entries imported.";
}

function bl_import_logs_page() {
    echo '<div class="wrap">';
    echo '<h1>Import Data From Access Logs</h1>';
    echo "Bot Logger will scan your server, detect the OS, and look for access log files in the server's standard directory.";
    echo '
    <table class="log-file-chart">
        <thead>
            <tr>
                <th>Server Type</th>
                <th>Standard Access Log Directory</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Apache</strong></td>
                <td>/var/log/apache2/access.log</td>
            </tr>
            <tr>
                <td><strong>Nginx</strong></td>
                <td>/var/log/nginx/access.log</td>
            </tr>
            <tr>
                <td><strong>LiteSpeed</strong></td>
                <td>/usr/local/lsws/logs/access.log</td>
            </tr>
        </tbody>
    </table>
    ';
    echo '<form method="post" action="">';
    echo '
    <fieldset>
    <legend>Options:</legend>
        <div class="checkbox-container">
            <div class="checkbox-item">
                <input type="checkbox" id="has_custom_log_path" name="has_custom_log_path" />
                <label for="has_custom_log_path">Custom access log path</label>
            </div>
            <div class="custom-log-path">
                <label for="custom_log_path_value">Custom path:</label>
                <input type="text" id="custom_log_path_value" name="custom_log_path_value" />
            </div>
        </div>
    </fieldset>
    ';
    echo '<p><input type="submit" name="bl_import_logs" class="button-primary" value="Import Logs" /></p>';
    echo '</form>';
    echo '</div>';

    if (isset($_POST['bl_import_logs'])) {
        // Handle custom log path option
        if (isset($_POST['has_custom_log_path']) && !empty($_POST['custom_log_path_value'])) {
            $custom_log_path = sanitize_text_field($_POST['custom_log_path_value']);
            update_option('bl_custom_log_path', $custom_log_path);
            $message = bl_import_access_logs($custom_log_path);
        } else {
            // Clear the custom log path option if the checkbox is not selected
            delete_option('bl_custom_log_path');
            $message = bl_import_access_logs();
        }

        if (strpos($message, 'Error') !== false) {
            echo '<div class="notice notice-error is-dismissible">';
        } else {
            echo '<div class="notice notice-success is-dismissible">';
        }
        echo '<p>' . esc_html($message) . '</p>';
        echo '</div>';
    }
}

/**
 * Log incoming requests
 */

add_action('template_redirect', 'bl_log_bot_request');

function bl_log_bot_request() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $custom_bots = get_option('bl_custom_bots', array());

    error_log("Received request with user agent: $user_agent");

    if (preg_match('/Googlebot|bingbot|Googlebot-Image|Googlebot-News|Googlebot-Video|Storebot-Google|Google-InspectionTool|GoogleOther|GoogleOther-Image|GoogleOther-Video|Google-Extended|APIs-Google|AdsBot-Google-Mobile|AdsBot-Google|Mediapartners-Google|Google-Safety/', $user_agent) || bl_is_custom_bot($user_agent, $custom_bots)) {
        global $wpdb;
        $resource = $_SERVER['REQUEST_URI'];
        $status_code = http_response_code(); 
        $ip_address = $_SERVER['REMOTE_ADDR'];

        // Check for the X-Forwarded-For header and use it if available (reverse proxy configs)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_address = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }

        $bot_name = '';
        if (strpos($user_agent, 'Googlebot') !== false) {
            $bot_name = bl_extract_bot_name($user_agent, 'Googlebot');
        } elseif (strpos($user_agent, 'bingbot') !== false) {
            $bot_name = bl_extract_bot_name($user_agent, 'bingbot');
        } else {
            foreach ($custom_bots as $bot) {
                if (strpos($user_agent, $bot['user_agent']) !== false) {
                    $bot_name = $bot['simplified_bot_name'];
                    break;
                }
            }
        }

        error_log("Logging bot request: Bot Name - $bot_name, Resource - $resource, Status Code - $status_code, IP Address - $ip_address");

        $table_name = $wpdb->prefix . 'bot_logs';
        $inserted = $wpdb->insert(
            $table_name,
            array(
                'user_agent' => $user_agent,
                'bot_name' => $bot_name,
                'log_date' => current_time('mysql'),
                'resource' => $resource,
                'status_code' => $status_code,
                'ip_address' => $ip_address,
                'is_valid' => 'unknown'
            )
        );

        if ($inserted === false) {
            error_log("Failed to log bot request: " . $wpdb->last_error);
        } else {
            error_log("Bot request logged successfully with ID: " . $wpdb->insert_id);
        }
    } else {
        error_log("User agent does not match any bot patterns or custom bots.");
    }
}

/**
 * Check if bot is a custom bot and get its name
 */

function bl_is_custom_bot($user_agent, $custom_bots) {
    foreach ($custom_bots as $bot) {
        if (strpos($user_agent, $bot['user_agent']) !== false) {
            error_log("Custom bot matched: " . $bot['user_agent']);
            return true;
        }
    }
    error_log("No custom bot matched for user agent: $user_agent");
    return false;
}

function bl_get_bot_name($user_agent, $custom_bots = array()) {
    if (strpos($user_agent, 'Googlebot') !== false) {
        return bl_extract_bot_name($user_agent, 'Googlebot');
    } elseif (strpos($user_agent, 'bingbot') !== false) {
        return bl_extract_bot_name($user_agent, 'bingbot');
    } else {
        foreach ($custom_bots as $bot) {
            if (strpos($user_agent, $bot['user_agent']) !== false) {
                return $bot['simplified_bot_name'];
            }
        }
    }
    error_log("No bot name found for user agent: $user_agent");
    return $user_agent; // Return full user agent if no match is found
}

function bl_extract_bot_name($user_agent, $token) {
    // Check for Googlebot or Bingbot specifically
    if (strpos($user_agent, 'Googlebot') !== false) {
        if (preg_match('/Googlebot\/[\d\.]+/', $user_agent, $matches)) {
            return $matches[0];
        }
    } elseif (strpos($user_agent, 'bingbot') !== false) {
        if (preg_match('/bingbot\/[\d\.]+/', $user_agent, $matches)) {
            return $matches[0];
        }
    } else {
        // General extraction for custom bots
        if (preg_match('/(?:;|\s|^)([a-zA-Z\-]+bot[a-zA-Z\-]*)\/([\d\.]+)/i', $user_agent, $matches)) {
            return $matches[1] . '/' . $matches[2];  // forcefully recreate the string
        } elseif (preg_match('/\b([a-zA-Z\-]+[Bb]ot[a-zA-Z\-]*)\/(\d+\.\d+)/', $user_agent, $matches)) {
            return $matches[0];
        } else {
            error_log("Unmatched User Agent: " . $user_agent);
            return $token;
        }
    }
}

/**
 * Admin Menu
 */

add_action('admin_menu', 'bl_admin_menu');

function bl_admin_menu() {
    $svg_icon = 'data:image/svg+xml;base64,' . base64_encode('
    <svg width="16pt" height="16pt" version="1.1" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
     <g fill="currentColor" fill-rule="evenodd">
      <path d="m35 62c1.1055 0 2-0.89453 2-2s-0.89453-2-2-2-2 0.89453-2 2 0.89453 2 2 2zm0 4c-3.3125 0-6-2.6875-6-6s2.6875-6 6-6 6 2.6875 6 6-2.6875 6-6 6zm30-4c1.1055 0 2-0.89453 2-2s-0.89453-2-2-2-2 0.89453-2 2 0.89453 2 2 2zm0 4c-3.3125 0-6-2.6875-6-6s2.6875-6 6-6 6 2.6875 6 6-2.6875 6-6 6z"/>
      <path d="m50 20c21.434 0 31.383 8.6133 36 16.609v-26.609h4v60s0 20-40 20-40-20-40-20v-60h4v26.609c4.6172-7.9961 14.566-16.609 36-16.609zm-30 40c0-8.2852 6.707-15 15.004-15h29.992c8.2852 0 15.004 6.7148 15.004 15s-6.707 15-15.004 15h-29.992c-8.2852 0-15.004-6.7148-15.004-15zm-6-50c0-1.1055-0.89453-2-2-2s-2 0.89453-2 2zm76 0c0-1.1055-0.89453-2-2-2s-2 0.89453-2 2z"/>
     </g>
    </svg>');

    // Main page: Viewing logs
    add_menu_page('Bot Logger', 'Bot Logger', 'manage_options', 'bot-logs', 'bl_display_logs', $svg_icon, 20);

    // Submenu: Import logs
    add_submenu_page('bot-logs', 'Import Logs', 'Import Logs', 'manage_options', 'bot-logs-import', 'bl_import_logs_page');

    // Submenu: Settings
    add_submenu_page('bot-logs', 'Bot Logger Settings', 'Settings', 'manage_options', 'bot-log-settings', 'bl_settings_page');
}

function bl_display_log_table($logs) {
    echo '<table class="widefat fixed wp-list-table" cellspacing="0">';
    echo '<thead><tr><th class="column-user_agent">User Agent</th><th class="column-date">Date</th><th class="column-status_code">HTTP Status</th><th class="column-resource">Resource</th><th class="column-ip_address">IP Address</th><th class="column-is_valid">IP Status</th></tr></thead>';
    echo '<tbody>';
    foreach ($logs as $log) {
        $validation_class = ($log->is_valid === 'valid') ? 'valid' : (($log->is_valid === 'invalid') ? 'invalid' : 'unknown');
        $status_class = $log->status_code == 404 ? 'status-404' : '';

        echo '<tr>';
        echo '<td class="column-user_agent"> ' . esc_html($log->bot_name) . '</td>';
        echo '<td class="column-date"> ' . esc_html($log->log_date) . '</td>';
        echo '<td class="column-status_code ' . esc_attr($status_class) . '">' . esc_html($log->status_code) . '</td>';
        echo '<td>' . esc_html($log->resource) . '</td>';
        echo '<td class="column-ip_address"> ' . esc_html($log->ip_address) . '</td>';
        echo '<td class="column-is_valid ' . $validation_class . '">' . esc_html($log->is_valid) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
}

/**
 * Main log page display (todo: tidy up)
 */

function bl_display_logs() {
    global $wpdb;
    global $custom_user_agent_limit;

    $table_name = $wpdb->prefix . 'bot_logs';

    // Bandaid to redirect to first tab if no custom tabs are present
    $custom_bots = get_option('bl_custom_bots', array());
    if (empty($custom_bots) && isset($_GET['tab']) && strpos($_GET['tab'], 'custombot_') === 0) {
        wp_redirect(admin_url('admin.php?page=bot-logs&tab=googlebot'));
        exit;
    }

    $google_logs = $wpdb->get_results("SELECT * FROM $table_name WHERE user_agent LIKE '%Googlebot%' ORDER BY log_date DESC");
    $bing_logs = $wpdb->get_results("SELECT * FROM $table_name WHERE user_agent LIKE '%bingbot%' ORDER BY log_date DESC");
    $custom_bots = get_option('bl_custom_bots', array());

    echo '<div class="wrap">';
    echo '<img src="' . plugin_dir_url(__FILE__) . 'bot-logger.webp" alt="Bot Logger Logo" class="bl-plugin-logo">';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=bot-logs&tab=googlebot" class="nav-tab' . (isset($_GET['tab']) && $_GET['tab'] == 'googlebot' ? ' nav-tab-active' : '') . '">Googlebot</a>';
    echo '<a href="?page=bot-logs&tab=bingbot" class="nav-tab' . (isset($_GET['tab']) && $_GET['tab'] == 'bingbot' ? ' nav-tab-active' : '') . '">Bingbot</a>';

    foreach ($custom_bots as $index => $bot) {
        echo '<a href="?page=bot-logs&tab=custombot_' . $index . '" class="nav-tab' . (isset($_GET['tab']) && $_GET['tab'] == 'custombot_' . $index ? ' nav-tab-active' : '') . '">' . esc_html($bot['tab_name']) . '</a>';
    }

    if (count($custom_bots) < $custom_user_agent_limit) {
        echo '<a href="?page=bot-logs&tab=addcustombot" class="nav-tab' . (isset($_GET['tab']) && $_GET['tab'] == 'addcustombot' ? ' nav-tab-active' : '') . '">+</a>';
    }

    echo '<span class="nav-tab">Custom User Agents: ' . count($custom_bots) . '/' . $custom_user_agent_limit . '</span>';
    echo '</h2>';

    // Validate IP Button
    $tab = isset($_GET['tab']) ? esc_attr($_GET['tab']) : '';
    echo '<a href="#" class="button button-primary validation-button" style="margin: 10px; float: right;" data-tab="' . $tab . '">Validate IPs</a>';

    //Export button
    echo '<a href="' . esc_url(admin_url('admin.php?page=bot-logs&export_excel=1')) . '" class="button button-secondary export-button" style="margin: 10px; float: right;">Export Data</a>';

    if (!isset($_GET['tab']) || $_GET['tab'] == 'googlebot') {
        echo '<h2>Googlebot Logs</h2>';
        bl_display_log_table($google_logs);
    } elseif ($_GET['tab'] == 'bingbot') {
        echo '<h2>Bingbot Logs</h2>';
        bl_display_log_table($bing_logs);
    } elseif (strpos($_GET['tab'], 'custombot_') === 0) {
        $index = str_replace('custombot_', '', $_GET['tab']);
        if (isset($custom_bots[$index])) {
            $bot = $custom_bots[$index];
            $custom_logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_agent LIKE %s ORDER BY log_date DESC", '%' . $bot['user_agent'] . '%'));
            echo '<h2>' . esc_html($bot['tab_name']) . ' Logs</h2>';
            bl_display_log_table($custom_logs);
            echo '<form method="post" action="">';
            echo '<input type="hidden" name="custom_bot_index" value="' . $index . '" />';
            echo '<input type="submit" name="bl_remove_custom_bot" class="button-secondary" style="margin: 10px;" value="Remove Custom User Agent" onclick="return confirm(\'Are you sure you want to remove ' . esc_html($bot['tab_name']) . '?\');" />';
            echo '</form>';
        }
    } elseif ($_GET['tab'] == 'addcustombot') {
        echo '<h2>Add Custom User Agent</h2>';
        echo '<div class="form-container">';
        echo '<form method="post" action="">';
        echo '<label for="custom_bot_name">Label:</label> ';
        echo '<input type="text" id="custom_bot_name" name="custom_bot_name" value="" required />';
        echo '<label for="custom_bot_user_agent">User Agent String:</label> ';
        echo '<input type="text" id="custom_bot_user_agent" name="custom_bot_user_agent" value="" required />';
        echo '<label for="custom_bot_ip_addresses">Valid IP Addresses (comma-separated):</label> ';
        echo '<textarea id="custom_bot_ip_addresses" name="custom_bot_ip_addresses" rows="4" cols="50"></textarea>';
        echo '<input type="submit" name="bl_save_custom_bot" class="button-primary" value="Save" style="margin: 10px;" />';
        echo '</form>';
        echo '</div>';
    }

    echo '</div>';
}

/**
 * Save Custom Bots
 */

add_action('admin_init', 'bl_save_custom_bot');

function bl_save_custom_bot() {
    if (isset($_POST['bl_save_custom_bot'])) {
        $custom_bots = get_option('bl_custom_bots', array());
        if (count($custom_bots) < $GLOBALS['custom_user_agent_limit']) {
            $user_agent = sanitize_text_field($_POST['custom_bot_user_agent']);
            $simplified_bot_name = bl_extract_bot_name($user_agent, $user_agent);

            $custom_bots[] = array(
                'tab_name' => sanitize_text_field($_POST['custom_bot_name']),
                'user_agent' => $user_agent,
                'simplified_bot_name' => $simplified_bot_name,
                'ip_addresses' => sanitize_textarea_field($_POST['custom_bot_ip_addresses'])
            );
            update_option('bl_custom_bots', $custom_bots);

            // Handle Cloudflare Cache-ruleset so custom bots don't hit cache
            $api_token = get_option('bl_cloudflare_api_token');
            $zone_id = get_option('bl_cloudflare_zone_id');

            if (!empty($api_token) && !empty($zone_id)) {
                $ruleset_id = get_or_create_cloudflare_ruleset($api_token, $zone_id);
                if ($ruleset_id) {
                    update_cloudflare_ruleset($api_token, $zone_id, $ruleset_id, array_column($custom_bots, 'user_agent'));
                }
            }

            echo '<div class="updated"><p>Custom bot user agent saved.</p></div>';
        } else {
            echo '<div class="error"><p>Custom user agent limit reached.</p></div>';
        }
    }
}

/**
 * Custom bot removal
 */

add_action('admin_init', 'bl_remove_custom_bot');

function bl_remove_custom_bot() {
    if (isset($_POST['bl_remove_custom_bot'])) {
        $custom_bots = get_option('bl_custom_bots', array());
        $index = intval($_POST['custom_bot_index']);
        if (isset($custom_bots[$index])) {
            $user_agent = $custom_bots[$index]['user_agent'];
            unset($custom_bots[$index]);
            update_option('bl_custom_bots', array_values($custom_bots));
            bl_remove_custom_bot_logs($user_agent);
            echo '<div class="updated"><p>Custom bot user agent removed.</p></div>';
        }
    }
}

function bl_remove_custom_bot_logs($user_agent) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bot_logs';
    
    $result = $wpdb->delete($table_name, array('user_agent' => $user_agent), array('%s'));
    
    if ($result === false) {
        error_log("Failed to delete logs for user agent: $user_agent. Error: " . $wpdb->last_error);
    } else {
        error_log("Successfully deleted logs for user agent: $user_agent. Rows affected: $result");
    }
}

/**
 * Settings and routines
 */

function bl_settings_page() {
    if (isset($_POST['bl_save_settings'])) {
        update_option('bl_retention_days', intval($_POST['retention_days']));
        update_option('bl_validation_frequency', intval($_POST['validation_frequency']));
        update_option('bl_delete_data_on_uninstall', isset($_POST['delete_data_on_uninstall']) ? 1 : 0);
        update_option('bl_cloudflare_api_token', sanitize_text_field($_POST['cloudflare_api_token']));
        update_option('bl_cloudflare_zone_id', sanitize_text_field($_POST['cloudflare_zone_id']));
        bl_clear_old_logs();
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    if (isset($_POST['bl_clear_logs'])) {
        bl_clear_logs();
        echo '<div class="updated"><p>All logs cleared.</p></div>';
    }

    $retention_days = get_option('bl_retention_days', 30);
    $validation_frequency = get_option('bl_validation_frequency', 1);
    $delete_data_on_uninstall = get_option('bl_delete_data_on_uninstall', 0);
    $cloudflare_api_token = get_option('bl_cloudflare_api_token', '');
    $cloudflare_zone_id = get_option('bl_cloudflare_zone_id', '');

    echo '<div class="wrap">';
    echo '<h1>Bot Log Settings</h1>';
    echo '<form method="post" action="">';
    echo '<table class="form-table">';
    echo '<tr valign="top">';
    echo '<th scope="row">Log Retention (days)</th>';
    echo '<td><input type="number" name="retention_days" value="' . esc_attr($retention_days) . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">Bot Validation Frequency (hours)</th>';
    echo '<td><input type="number" name="validation_frequency" value="' . esc_attr($validation_frequency) . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">Cloudflare API Token</th>';
    echo '<td><input type="text" name="cloudflare_api_token" value="' . esc_attr($cloudflare_api_token) . '" /></td>'; // Cloudflare here btw
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">Cloudflare Zone ID</th>';
    echo '<td><input type="text" name="cloudflare_zone_id" value="' . esc_attr($cloudflare_zone_id) . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">Remove Database Table on Uninstall?</th>';
    echo '<td><input type="checkbox" name="delete_data_on_uninstall" value="1"' . checked(1, $delete_data_on_uninstall, false) . ' /> Check to delete data and database table upon plugin deletion</td>';
    echo '</tr>';
    echo '</table>';

    echo '<p class="submit"><input type="submit" name="bl_save_settings" class="button-primary" value="Save Changes" /></p>';
    echo '</form>';

    echo '<form method="post" action="">';
    echo '<p class="submit"><input type="submit" name="bl_clear_logs" class="button-secondary" value="Clear All Logs" /></p>';
    echo '</form>';
    echo '</div>';
}

// Clear logs older than retention period
function bl_clear_old_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bot_logs';
    $retention_days = get_option('bl_retention_days', 30);
    $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE log_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $retention_days));
}

// Clear all logs
function bl_clear_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bot_logs';
    $wpdb->query("TRUNCATE TABLE $table_name");
}

// Schedule daily log cleanup
if (!wp_next_scheduled('bl_daily_cleanup')) {
    wp_schedule_event(time(), 'daily', 'bl_daily_cleanup');
}

add_action('bl_daily_cleanup', 'bl_clear_old_logs');

// Schedule IP validation
if (!wp_next_scheduled('validate_bot_ips_event')) {
    wp_schedule_event(time(), 'hourly', 'validate_bot_ips_event');
}

add_action('validate_bot_ips_event', 'validate_bot_ips');

// Unschedule validation cron upon deactivation
register_deactivation_hook(__FILE__, 'bl_deactivate');

function bl_deactivate() {
    wp_clear_scheduled_hook('bl_daily_cleanup');
}

// Delete data upon deletion
register_uninstall_hook(__FILE__, 'bl_uninstall');


// Uninstall handler
function bl_uninstall() {
    if (get_option('bl_delete_data_on_uninstall')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bot_logs';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        delete_option('bl_retention_days');
        delete_option('bl_custom_bots');
        delete_option('bl_show_import_prompt');
        delete_option('bl_delete_data_on_uninstall');
        delete_option('bl_cloudflare_api_token');
        delete_option('bl_cloudflare_zone_id');
    }
}

/**
 * Export to Excel function
 */

add_action('admin_init', 'bl_export_excel');

function bl_export_excel() {
    if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bot_logs';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Googlebot Sheet
        $googlebotSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Googlebot Logs');
        $spreadsheet->addSheet($googlebotSheet, 0);
        $googlebotData = $wpdb->get_results("SELECT log_date, ip_address, status_code, resource, user_agent, is_valid FROM $table_name WHERE user_agent LIKE '%Googlebot%' ORDER BY log_date DESC", ARRAY_A);
        if (!empty($googlebotData)) {
            $googlebotSheet->fromArray(array_keys($googlebotData[0]), NULL, 'A1');
            $googlebotSheet->fromArray($googlebotData, NULL, 'A2');
        }

        // Bingbot Sheet
        $bingbotSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Bingbot Logs');
        $spreadsheet->addSheet($bingbotSheet, 1);
        $bingbotData = $wpdb->get_results("SELECT log_date, ip_address, status_code, resource, user_agent, is_valid FROM $table_name WHERE user_agent LIKE '%bingbot%' ORDER BY log_date DESC", ARRAY_A);
        if (!empty($bingbotData)) {
            $bingbotSheet->fromArray(array_keys($bingbotData[0]), NULL, 'A1');
            $bingbotSheet->fromArray($bingbotData, NULL, 'A2');
        }

        // Custom Bot Sheet(s)
        $custom_bots = get_option('bl_custom_bots', array());
        foreach ($custom_bots as $index => $bot) {
            $customBotSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $bot['tab_name'] . ' Logs');
            $spreadsheet->addSheet($customBotSheet, $index + 2);
            $customBotData = $wpdb->get_results($wpdb->prepare("SELECT log_date, ip_address, status_code, resource, user_agent, is_valid FROM $table_name WHERE user_agent LIKE %s ORDER BY log_date DESC", '%' . $bot['user_agent'] . '%'), ARRAY_A);
            if (!empty($customBotData)) {
                $customBotSheet->fromArray(array_keys($customBotData[0]), NULL, 'A1');
                $customBotSheet->fromArray($customBotData, NULL, 'A2');
            }
        }

        // Remove default blank Worksheet
        $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet')));

        // Set active sheet to the first one
        $spreadsheet->setActiveSheetIndex(0);

        // Generate file
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        $site_url = site_url();
        $filename = parse_url($site_url, PHP_URL_HOST) . '_bot_logs_' . date('Y-m-d_H-i-s') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');

        exit;
    }
}

/**
 * Handle IP validation request
 */

add_action('wp_ajax_bl_validate_ips', 'bl_validate_ips');

function bl_validate_ips() {
    check_ajax_referer('bot_logger_ajax_nonce', 'nonce');

    $result = validate_bot_ips();

    if ($result) {
        wp_send_json_success(array('message' => 'IP validation successful.'));
    } else {
        wp_send_json_error(array('message' => 'IP validation failed.'));
    }
}

/**
 * Plugins page customizations
 */

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bl_add_plugin_action_links');
add_filter('plugin_row_meta', 'bl_add_plugin_row_meta', 10, 2);

function bl_add_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=bot-log-settings') . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

function bl_add_plugin_row_meta($links, $file) {
    if ($file == plugin_basename(__FILE__)) {
        $website_link = '<a href="https://ciffonedigital.com" target="_blank">' . __('Website') . '</a>';
        $documentation_link = '<a href="https://ciffonedigital.com/bot-logger/" target="_blank">' . __('Documentation') . '</a>';
        $links[] = $website_link;
        $links[] = $documentation_link;
    }
    return $links;
}
?>
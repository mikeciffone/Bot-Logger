<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/*function validate_bot_ips() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bot_logs';

    // Fetch IP ranges from Google and Bing
    $googlebot_ips = fetch_googlebot_ips();
    $bingbot_ips = fetch_bingbot_ips();

    // Get all bot logs
    $logs = $wpdb->get_results("SELECT id, user_agent, ip_address FROM $table_name");

    foreach ($logs as $log) {
        $is_valid = false;
        if (strpos($log->user_agent, 'Googlebot') !== false) {
            $is_valid = in_array($log->ip_address, $googlebot_ips);
        } elseif (strpos($log->user_agent, 'bingbot') !== false) {
            $is_valid = in_array($log->ip_address, $bingbot_ips);
        } else {
            $custom_bots = get_option('bl_custom_bots', array());
            foreach ($custom_bots as $bot) {
                if (strpos($log->user_agent, $bot['user_agent']) !== false) {
                    $custom_ips = explode(',', $bot['ip_addresses']);
                    $is_valid = in_array(trim($log->ip_address), $custom_ips);
                    break;
                }
            }
        }

        // Update the log with validation result
        $wpdb->update(
            $table_name,
            array('is_valid' => $is_valid ? 'valid' : 'invalid'),
            array('id' => $log->id)
        );
    }
}*/

function validate_bot_ips() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bot_logs';

    // Fetch IP ranges from Google and Bing
    $googlebot_ips = fetch_googlebot_ips();
    $bingbot_ips = fetch_bingbot_ips();

    // Get all bot logs
    $logs = $wpdb->get_results("SELECT id, user_agent, ip_address FROM $table_name");

    $all_successful = true; // Flag to track overall success

    foreach ($logs as $log) {
        $is_valid = false;
        if (strpos($log->user_agent, 'Googlebot') !== false) {
            $is_valid = in_array($log->ip_address, $googlebot_ips);
        } elseif (strpos($log->user_agent, 'bingbot') !== false) {
            $is_valid = in_array($log->ip_address, $bingbot_ips);
        } else {
            $custom_bots = get_option('bl_custom_bots', array());
            foreach ($custom_bots as $bot) {
                if (strpos($log->user_agent, $bot['user_agent']) !== false) {
                    $custom_ips = explode(',', $bot['ip_addresses']);
                    $is_valid = in_array(trim($log->ip_address), $custom_ips);
                    break;
                }
            }
        }

        // Update the log with validation result
        $updated = $wpdb->update(
            $table_name,
            array('is_valid' => $is_valid ? 'valid' : 'invalid'),
            array('id' => $log->id)
        );

        // Check if the update was successful
        if ($updated === false) {
            $all_successful = false;
        }
    }

    return $all_successful;
}



// Fetch Googlebot IP ranges
function fetch_googlebot_ips() {
    $urls = [
        'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
        'https://developers.google.com/static/search/apis/ipranges/special-crawlers.json',
        'https://developers.google.com/static/search/apis/ipranges/user-triggered-fetchers.json',
    ];

    $ips = [];
    foreach ($urls as $url) {
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            continue;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['prefixes'])) {
            foreach ($data['prefixes'] as $prefix) {
                if (isset($prefix['ipv4Prefix'])) {
                    $ips = array_merge($ips, get_ips_from_prefix($prefix['ipv4Prefix']));
                }
            }
        }
    }
    return $ips;
}

// Fetch Bingbot IP ranges
function fetch_bingbot_ips() {
    $url = 'https://www.bing.com/toolbox/bingbot.json';

    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return [];
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);

    $ips = [];
    if (isset($data['prefixes'])) {
        foreach ($data['prefixes'] as $prefix) {
            if (isset($prefix['ipv4Prefix'])) {
                $ips = array_merge($ips, get_ips_from_prefix($prefix['ipv4Prefix']));
            }
        }
    }
    return $ips;
}

function get_ips_from_prefix($prefix) {
    list($base, $bits) = explode('/', $prefix);
    $start = ip2long($base) & ((-1 << (32 - $bits)));
    $end = ip2long($base) + pow(2, (32 - $bits)) - 1;

    $ips = [];
    for ($i = $start; $i <= $end; $i++) {
        $ips[] = long2ip($i);
    }
    return $ips;
}

// Register the validation function to be called manually or via a scheduled event
if (!wp_next_scheduled('validate_bot_ips_event')) {
    wp_schedule_event(time(), 'hourly', 'validate_bot_ips_event');
}

add_action('validate_bot_ips_event', 'validate_bot_ips');

function handle_manual_validation() {
    if (isset($_GET['validate_ips']) && $_GET['validate_ips'] == 1) {
        validate_bot_ips();
        wp_redirect(admin_url('admin.php?page=bot-logs'));
        exit;
    }
}
add_action('admin_init', 'handle_manual_validation');
?>

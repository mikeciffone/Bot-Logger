<?php
function get_or_create_cloudflare_ruleset($api_token, $zone_id) {
    // List existing rulesets
    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets?phase=http_request_cache_settings";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$api_token}",
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode >= 200 && $httpcode < 300) {
        $result = json_decode($response, true);

        if (!empty($result['result'])) {
            // Return the first ruleset id that matches the cache ruleset
            foreach ($result['result'] as $ruleset) {
                if ($ruleset['phase'] == 'http_request_cache_settings') {
                    return $ruleset['id'];
                }
            }
        }
    }

    // Create ruleset if none exists
    return create_cloudflare_ruleset($api_token, $zone_id);
}

function create_cloudflare_ruleset($api_token, $zone_id) {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets";

    $data = [
        "name" => "Bot Logger Cache Bypass Ruleset",
        "kind" => "zone",
        "phase" => "http_request_cache_settings",
        "rules" => []
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$api_token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode >= 200 && $httpcode < 300) {
        $result = json_decode($response, true);
        return $result['result']['id'];
    }

    return null;
}

function update_cloudflare_ruleset($api_token, $zone_id, $ruleset_id, $custom_bot_user_agents) {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets/{$ruleset_id}";

    $rules = [];
    foreach ($custom_bot_user_agents as $user_agent) {
        $rules[] = [
            "expression" => "(http.user_agent eq \"{$user_agent}\")",
            "description" => "Bypass cache for custom bot user agent: {$user_agent}",
            "action" => "set_cache_settings",
            "action_parameters" => [
                "cache" => true,
                "edge_ttl" => [
                    "mode" => "bypass_by_default"
                ]
            ]
        ];
    }

    $data = [
        "description" => "Ruleset to bypass cache for custom bot user agents",
        "rules" => $rules
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$api_token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode >= 200 && $httpcode < 300) {
        error_log("Cloudflare ruleset updated successfully.");
    } else {
        error_log("Failed to update Cloudflare ruleset: " . $response);
    }

    return [
        'response' => $response,
        'http_code' => $httpcode
    ];
}
?>
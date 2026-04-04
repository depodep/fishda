<?php
// Test Arduino POST request format
$postData = [
    'action' => 'poll_session_status',
    'access_code' => 'APS-ESP-2026',
    'model_unit' => 'Fishda',
    'model_code' => 'FD2026',
    'device_unique_code' => 'ESP8266-UNIT-001'
];

$options = [
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($postData)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents('http://localhost/fishda/api/session_api.php', false, $context);

echo "Arduino Request Test:\n";
echo "URL: http://localhost/fishda/api/session_api.php\n";
echo "POST Data: " . http_build_query($postData) . "\n";
echo "Response: $result\n";
?>
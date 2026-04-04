<?php
// Test Sensor API
$postData = [
    'temp' => '25.5',
    'humidity' => '60.2'
];

$options = [
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($postData)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents('http://localhost/fishda/api/sensor_api.php', false, $context);

echo "Sensor API Test:\n";
echo "POST Data: " . http_build_query($postData) . "\n";
echo "Response: $result\n";
?>
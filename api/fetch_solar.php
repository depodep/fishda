<?php
// Mock solar and environmental data endpoint
declare(strict_types=1);
header('Content-Type: application/json');

// In a real IoT integration, fetch from sensors or another API.
// Here we simulate values safely.
$temperature = round(mt_rand(380, 450) / 10, 1); // 38.0 - 45.0 °C
$humidity    = round(mt_rand(450, 600) / 10, 1); // 45.0 - 60.0 %

echo json_encode([
    'status' => 'success',
    'message' => 'Solar data fetched',
    'data' => [
        'temperature' => $temperature,
        'humidity'    => $humidity,
        'solarPowerW' => mt_rand(200, 800), // mock watts
        'timestamp'   => date('Y-m-d H:i:s')
    ]
]);

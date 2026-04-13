<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'error',
    'message' => 'delete_record.php is deprecated. Completed session data is sourced from drying_sessions.'
]);
?>
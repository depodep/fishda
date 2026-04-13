<?php
header('Content-Type: application/json');
include '../database/dbcon.php';

echo json_encode([
    'status' => 'error',
    'message' => 'save_record.php is deprecated. Use api/session_api.php stop_session flow; completed records are derived from drying_sessions.'
]);
?>
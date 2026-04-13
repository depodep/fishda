<?php
// user_management_api.php — Updated for Prototype System
ini_set('display_errors', 0);
error_reporting(E_NONE);
if (ob_get_level()) ob_clean();
header('Content-Type: application/json');

include('../database/dbcon.php');

function sendResponse($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

function normalizePrototypeStatus($raw) {
    if (is_string($raw)) {
        $v = strtolower(trim($raw));
        if ($v === 'active' || $v === 'enabled' || $v === 'enable' || $v === '1' || $v === 'true') return 1;
        if ($v === 'disabled' || $v === 'disable' || $v === 'inactive' || $v === '0' || $v === 'false') return 0;
    }
    return intval($raw) === 1 ? 1 : 0;
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
if (!$action) sendResponse('error', 'No action specified.');

switch ($action) {

    // Fetch all registered prototypes
    case 'fetch_prototypes':
        try {
            $stmt = $dbh->prepare("SELECT id, model_name, given_code, owner_name, status, created_at FROM tbl_prototypes ORDER BY id DESC");
            $stmt->execute();
            sendResponse('success', 'Prototypes fetched.', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            sendResponse('error', 'Failed to fetch prototypes.');
        }
        break;

    // Register a new prototype
    case 'register_prototype':
        $model_name = htmlspecialchars(trim($_POST['model_name'] ?? ''));
        $given_code = strtoupper(trim($_POST['given_code'] ?? ''));
        $owner_name = htmlspecialchars(trim($_POST['owner_name'] ?? ''));
        $description = htmlspecialchars(trim($_POST['description'] ?? ''));

        if (empty($model_name) || empty($given_code)) {
            sendResponse('error', 'Model name and code are required.');
        }

        try {
            $chk = $dbh->prepare("SELECT id FROM tbl_prototypes WHERE model_name=:m AND given_code=:c LIMIT 1");
            $chk->execute([':m' => $model_name, ':c' => $given_code]);
            if ($chk->fetch()) sendResponse('error', 'This model name and code combination already exists.');

            $ins = $dbh->prepare("INSERT INTO tbl_prototypes (model_name, given_code, owner_name, description, status) VALUES (:m,:c,:o,:d,1)");
            $ins->execute([':m'=>$model_name,':c'=>$given_code,':o'=>$owner_name,':d'=>$description]);
            sendResponse('success', 'Prototype registered successfully.');
        } catch (Exception $e) {
            sendResponse('error', 'Database error during registration.');
        }
        break;

    // Update prototype status (activate/restrict)
    case 'update_status':
        $id     = filter_var($_POST['id'] ?? $_POST['proto_id'] ?? 0, FILTER_VALIDATE_INT);
        $status = normalizePrototypeStatus($_POST['status'] ?? 1);
        if (!$id) sendResponse('error', 'Invalid prototype ID.');
        try {
            $upd = $dbh->prepare("UPDATE tbl_prototypes SET status=:s WHERE id=:id");
            $upd->execute([':s'=>$status,':id'=>$id]);
            if ($upd->rowCount() < 1) {
                $chk = $dbh->prepare("SELECT id FROM tbl_prototypes WHERE id=:id LIMIT 1");
                $chk->execute([':id' => $id]);
                if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                    sendResponse('error', 'Prototype not found.');
                }
            }
            sendResponse('success', 'Status updated.', ['new_status' => $status]);
        } catch (Exception $e) {
            sendResponse('error', 'Failed to update status.');
        }
        break;

    // Delete a prototype
    case 'delete_prototype':
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$id) sendResponse('error', 'Invalid prototype ID.');
        try {
            $q = $dbh->prepare("DELETE FROM tbl_prototypes WHERE id=:id");
            $q->execute([':id' => $id]);
            sendResponse($q->rowCount() ? 'success' : 'error', $q->rowCount() ? 'Prototype deleted.' : 'Not found.');
        } catch (Exception $e) {
            sendResponse('error', 'Database error during deletion.');
        }
        break;

    default:
        sendResponse('error', 'Unknown action: ' . htmlspecialchars($action));
}
?>

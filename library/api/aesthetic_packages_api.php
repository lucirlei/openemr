<?php
/**
 * API for managing patient aesthetic treatment packages.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

use OpenEMR\Common\Csrf\CsrfUtils;

require_once(__DIR__ . '/../globals.php');
require_once($GLOBALS['srcdir'] . '/api.inc');
require_once($GLOBALS['srcdir'] . '/formdata.inc.php');

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower($_REQUEST['action'] ?? ($method === 'GET' ? 'list' : 'assign'));

try {
    switch ($action) {
        case 'list':
            echo json_encode(listAssignments());
            break;
        case 'assign':
            ensurePost();
            verifyToken();
            echo json_encode(assignPackage());
            break;
        case 'log-session':
            ensurePost();
            verifyToken();
            echo json_encode(logSession());
            break;
        case 'update-status':
            ensurePost();
            verifyToken();
            echo json_encode(updateStatus());
            break;
        default:
            throw new RuntimeException(xlt('Unsupported action.'));
    }
} catch (Throwable $throwable) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $throwable->getMessage(),
    ]);
}

function ensurePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException(xlt('Invalid request method.'));
    }
}

function verifyToken(): void
{
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', 'aesthetic_packages_patient')) {
        CsrfUtils::csrfNotVerified();
    }
}

function listAssignments(): array
{
    $pid = (int)($_GET['pid'] ?? $_SESSION['pid'] ?? 0);
    if (empty($pid)) {
        throw new RuntimeException(xlt('Patient not selected.'));
    }
    $sql = 'SELECT ppt.*, ap.name, ap.session_count, ap.price FROM patient_package_tracking AS ppt '
        . 'JOIN aesthetic_packages AS ap ON ap.id = ppt.package_id '
        . 'WHERE ppt.pid = ? ORDER BY ppt.created_at DESC';
    $res = sqlStatement($sql, [$pid]);
    $records = [];
    while ($row = sqlFetchArray($res)) {
        $log = [];
        if (!empty($row['session_log'])) {
            $decoded = json_decode($row['session_log'], true);
            if (is_array($decoded)) {
                $log = $decoded;
            }
        }
        $row['session_log'] = $log;
        $row['remaining_sessions'] = max(0, ((int)$row['session_count']) - ((int)$row['sessions_completed']));
        $records[] = $row;
    }
    return [
        'success' => true,
        'data' => $records,
    ];
}

function assignPackage(): array
{
    $pid = (int)($_POST['pid'] ?? $_SESSION['pid'] ?? 0);
    $packageId = (int)($_POST['package_id'] ?? 0);
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $notes = $_POST['notes'] ?? null;

    if (empty($pid) || empty($packageId)) {
        throw new RuntimeException(xlt('Missing required parameters.'));
    }

    $userId = (int)($_SESSION['authUserID'] ?? 0);

    $data = [
        'pid' => $pid,
        'package_id' => $packageId,
        'start_date' => $startDate,
        'notes' => $notes,
        'assigned_by' => $userId ?: null,
    ];
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    $id = sqlInsert(
        'INSERT INTO patient_package_tracking (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')',
        array_values($data)
    );
    return [
        'success' => true,
        'id' => $id,
    ];
}

function logSession(): array
{
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $pid = (int)($_POST['pid'] ?? $_SESSION['pid'] ?? 0);
    $note = $_POST['session_note'] ?? null;
    $sessionDate = $_POST['session_date'] ?? date('Y-m-d');

    if (empty($trackingId) || empty($pid)) {
        throw new RuntimeException(xlt('Missing required parameters.'));
    }

    $row = sqlQuery('SELECT sessions_completed, session_log FROM patient_package_tracking WHERE id = ? AND pid = ?', [$trackingId, $pid]);
    if (empty($row)) {
        throw new RuntimeException(xlt('Tracking record not found.'));
    }

    $sessionsCompleted = (int)($row['sessions_completed'] ?? 0) + 1;
    $log = [];
    if (!empty($row['session_log'])) {
        $decoded = json_decode($row['session_log'], true);
        if (is_array($decoded)) {
            $log = $decoded;
        }
    }
    $log[] = [
        'number' => $sessionsCompleted,
        'date' => $sessionDate,
        'note' => $note,
        'recorded_by' => $_SESSION['authUser'] ?? null,
    ];
    sqlStatement(
        'UPDATE patient_package_tracking SET sessions_completed = ?, session_log = ? WHERE id = ? AND pid = ?',
        [$sessionsCompleted, json_encode($log), $trackingId, $pid]
    );

    return [
        'success' => true,
        'sessions_completed' => $sessionsCompleted,
        'session_log' => $log,
    ];
}

function updateStatus(): array
{
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $pid = (int)($_POST['pid'] ?? $_SESSION['pid'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $notes = $_POST['notes'] ?? null;
    if (empty($trackingId) || empty($pid)) {
        throw new RuntimeException(xlt('Missing required parameters.'));
    }
    sqlStatement(
        'UPDATE patient_package_tracking SET status = ?, notes = ? WHERE id = ? AND pid = ?',
        [$status, $notes, $trackingId, $pid]
    );
    return [
        'success' => true,
    ];
}

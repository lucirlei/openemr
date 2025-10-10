<?php
/**
 * Save handler for aesthetic packages.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(xlt('Invalid request.'));
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', 'aesthetic_packages')) {
    CsrfUtils::csrfNotVerified();
}

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$description = $_POST['description'] ?? null;
$sessionCount = max(1, (int)($_POST['session_count'] ?? 1));
$price = $_POST['price'] !== '' ? (float)$_POST['price'] : null;
$procedures = $_POST['procedures'] ?? null;
$isActive = (int)($_POST['is_active'] ?? 1);
$userId = (int)($_SESSION['authUserID'] ?? 0);

if (empty($name)) {
    die(xlt('Package name is required.'));
}

$data = [
    'name' => $name,
    'description' => $description,
    'session_count' => $sessionCount,
    'price' => $price,
    'procedures' => $procedures,
    'is_active' => $isActive,
    'updated_by' => $userId ?: null,
];

if (!empty($id)) {
    $setParts = [];
    $values = [];
    foreach ($data as $column => $value) {
        $setParts[] = "`$column` = ?";
        $values[] = $value;
    }
    $values[] = $id;
    sqlStatement('UPDATE aesthetic_packages SET ' . implode(', ', $setParts) . ' WHERE id = ?', $values);
} else {
    $data['created_by'] = $userId ?: null;
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    sqlInsert(
        'INSERT INTO aesthetic_packages (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')',
        array_values($data)
    );
}
?>
<!DOCTYPE html>
<html>
<head>
    <script>
        if (window.top) {
            window.top.dlgclose();
            if (window.top.location) {
                window.top.location.reload();
            }
        }
    </script>
</head>
<body>
</body>
</html>

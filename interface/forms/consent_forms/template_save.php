<?php
/**
 * Save handler for consent templates.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(xlt('Invalid request.'));
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', 'consent_forms')) {
    CsrfUtils::csrfNotVerified();
}

$id = (int)($_POST['id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$description = $_POST['description'] ?? null;
$bodyHtml = $_POST['body_html'] ?? '';
$isActive = (int)($_POST['is_active'] ?? 1);
$userId = (int)($_SESSION['authUserID'] ?? 0);

if (empty($title) || empty($bodyHtml)) {
    die(xlt('Title and body are required.'));
}


$data = [
    'title' => $title,
    'description' => $description,
    'body_html' => $bodyHtml,
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
    sqlStatement('UPDATE consent_templates SET ' . implode(', ', $setParts) . ' WHERE id = ?', $values);
} else {
    $data['created_by'] = $userId ?: null;
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    sqlInsert(
        'INSERT INTO consent_templates (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')',
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

<?php
/**
 * Persist signed consent.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');
require_once($GLOBALS['srcdir'] . '/classes/Document.class.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\DocumentService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(xlt('Invalid request.'));
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', 'consent_forms_sign')) {
    CsrfUtils::csrfNotVerified();
}

$pid = (int)($_POST['pid'] ?? 0);
$templateId = (int)($_POST['template_id'] ?? 0);
$encounterId = $_POST['encounter_id'] !== '' ? (int)$_POST['encounter_id'] : null;
$renderedHtml = $_POST['rendered_html'] ?? '';
$notes = $_POST['notes'] ?? null;

if (empty($pid) || empty($templateId) || empty($renderedHtml)) {
    die(xlt('Missing required information.'));
}

if (empty($_FILES['signature']) || ($_FILES['signature']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    die(xlt('Signature image is required.'));
}

$documentService = new DocumentService();
$categoryId = null;
$paths = [
    'Patient Documents/Consent Forms',
    'Patient Documents/Medical Record',
    'Patient Documents'
];
foreach ($paths as $path) {
    if ($documentService->isValidPath($path)) {
        $categoryId = $documentService->getLastIdOfPath($path);
        break;
    }
}
if (empty($categoryId)) {
    die(xlt('Unable to locate document category.'));
}

$signatureTmp = $_FILES['signature']['tmp_name'];
$signatureName = $_FILES['signature']['name'];
if (!is_uploaded_file($signatureTmp)) {
    die(xlt('Invalid signature upload.'));
}

$signatureData = file_get_contents($signatureTmp);
$document = new Document();
$document->set_foreign_id($pid);
$document->set_docdate(date('Y-m-d'));
$docError = $document->createDocument(
    $pid,
    $categoryId,
    $signatureName,
    mime_content_type($signatureTmp) ?: 'image/png',
    $signatureData,
    '',
    1,
    $_SESSION['authUserID'] ?? 0,
    $signatureTmp
);
if (!empty($docError)) {
    die(text($docError));
}
$signatureDocumentId = $document->get_id();
$docHashRow = sqlQuery('SELECT hash FROM documents WHERE id = ?', [$signatureDocumentId]);

$htmlDocument = new Document();
$htmlDocument->set_foreign_id($pid);
$htmlDocument->set_docdate(date('Y-m-d'));
$htmlError = $htmlDocument->createDocument(
    $pid,
    $categoryId,
    'Signed Consent - ' . date('Ymd_His') . '.html',
    'text/html',
    $renderedHtml
);
if (!empty($htmlError)) {
    die(text($htmlError));
}
$htmlDocumentId = $htmlDocument->get_id();

$formData = [
    'html_document_id' => $htmlDocumentId,
    'signature_document_id' => $signatureDocumentId,
    'notes' => $notes,
    'html_content' => $renderedHtml,
];

sqlInsert(
    'INSERT INTO patient_signed_consents (pid, encounter_id, template_id, signature_path, signature_hash, form_data, signed_by, notes) '
    . 'VALUES (?,?,?,?,?,?,?,?)',
    [
        $pid,
        $encounterId,
        $templateId,
        'document_id:' . $signatureDocumentId,
        $docHashRow['hash'] ?? null,
        json_encode($formData),
        $_SESSION['authUserID'] ?? null,
        $notes,
    ]
);
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

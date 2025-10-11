<?php
/**
 * View a signed consent document.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Core\Header;
use OpenEMR\Services\DocumentService;

$id = (int)($_GET['id'] ?? 0);
if (empty($id)) {
    die(xlt('Consent not found.'));
}

$row = sqlQuery(
    'SELECT sc.*, ct.title FROM patient_signed_consents AS sc '
    . 'JOIN consent_templates AS ct ON ct.id = sc.template_id WHERE sc.id = ?',
    [$id]
);
if (empty($row)) {
    die(xlt('Consent not found.'));
}

$patient = sqlQuery('SELECT fname, lname, DOB FROM patient_data WHERE pid = ?', [$row['pid']]);
$patientName = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
$patientDob = !empty($patient['DOB']) ? oeFormatShortDate($patient['DOB']) : '';

$formData = [];
if (!empty($row['form_data'])) {
    $decoded = json_decode($row['form_data'], true);
    if (is_array($decoded)) {
        $formData = $decoded;
    }
}

$signatureLink = null;
$documentService = new DocumentService();
if (!empty($formData['signature_document_id'])) {
    $signatureLink = $documentService->getDownloadLink($formData['signature_document_id'], $row['pid']);
}

$consentHtml = $formData['html_content'] ?? '';
if (empty($consentHtml) && !empty($formData['html_document_id'])) {
    $docRow = sqlQuery('SELECT url FROM documents WHERE id = ?', [$formData['html_document_id']]);
    if (!empty($docRow['url']) && file_exists($docRow['url'])) {
        $consentHtml = file_get_contents($docRow['url']);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo text($row['title']); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body>
<div class="container-fluid my-3">
    <div class="mb-3">
        <h3><?php echo text($row['title']); ?></h3>
        <p class="text-muted mb-1">
            <?php echo xlt('Patient'); ?>: <?php echo text($patientName); ?>
            <?php if ($patientDob) : ?> · <?php echo xlt('DOB'); ?>: <?php echo text($patientDob); ?><?php endif; ?>
        </p>
        <p class="text-muted mb-1"><?php echo xlt('Signed'); ?>: <?php echo text(oeFormatDateTime($row['signed_at'])); ?></p>
        <?php if (!empty($row['encounter_id'])) : ?>
            <p class="text-muted mb-1"><?php echo xlt('Encounter ID'); ?>: <?php echo text($row['encounter_id']); ?></p>
        <?php endif; ?>
        <?php if (!empty($row['notes'])) : ?>
            <p class="text-muted mb-1"><?php echo xlt('Notes'); ?>: <?php echo nl2br(text($row['notes'])); ?></p>
        <?php endif; ?>
        <?php if ($signatureLink) : ?>
            <p class="mb-1"><a class="btn btn-outline-primary btn-sm" href="<?php echo attr($signatureLink); ?>" target="_blank"><?php echo xlt('View Signature'); ?></a></p>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-body">
            <?php if (!empty($consentHtml)) : ?>
                <?php echo $consentHtml; ?>
            <?php else : ?>
                <p class="text-muted"><?php echo xlt('Consent text unavailable.'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

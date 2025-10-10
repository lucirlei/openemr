<?php
/**
 * Capture patient consent signature.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$pid = (int)($_GET['pid'] ?? $_SESSION['pid'] ?? 0);
if (empty($pid)) {
    die(xlt('Patient not selected.'));
}

$patient = sqlQuery('SELECT fname, lname, DOB FROM patient_data WHERE pid = ?', [$pid]);
$patientName = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
$patientDob = !empty($patient['DOB']) ? oeFormatShortDate($patient['DOB']) : '';

$templates = [];
$templateRes = sqlStatement('SELECT id, title, body_html FROM consent_templates WHERE is_active = 1 ORDER BY title');
while ($row = sqlFetchArray($templateRes)) {
    $templates[] = $row;
}

if (empty($templates)) {
    die(xlt('No consent templates available.'));
}

$csrfToken = CsrfUtils::collectCsrfToken('consent_forms_sign');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Capture Consent'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        #rendered_html {
            min-height: 260px;
        }
    </style>
</head>
<body>
<div class="container-fluid my-3">
    <form id="consent-sign-form" method="post" action="sign_save.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>" />
        <input type="hidden" name="pid" value="<?php echo attr($pid); ?>" />
        <div class="mb-3">
            <label for="template_id" class="form-label"><?php echo xlt('Template'); ?></label>
            <select id="template_id" name="template_id" class="form-select" required>
                <option value=""><?php echo xlt('Select Template'); ?></option>
                <?php foreach ($templates as $template) : ?>
                    <option value="<?php echo attr($template['id']); ?>"><?php echo text($template['title']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="encounter_id" class="form-label"><?php echo xlt('Encounter ID (optional)'); ?></label>
                <input type="number" class="form-control" id="encounter_id" name="encounter_id" />
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo xlt('Patient'); ?></label>
                <p class="form-control-plaintext"><?php echo text($patientName); ?><?php if ($patientDob) : ?> · <?php echo xlt('DOB'); ?>: <?php echo text($patientDob); ?><?php endif; ?></p>
            </div>
        </div>
        <div class="mb-3">
            <label for="rendered_html" class="form-label"><?php echo xlt('Consent Text'); ?></label>
            <textarea class="form-control" id="rendered_html" name="rendered_html" required></textarea>
            <small class="form-text text-muted"><?php echo xlt('You may customize the consent text before capturing a signature.'); ?></small>
        </div>
        <div class="mb-3">
            <label for="notes" class="form-label"><?php echo xlt('Notes'); ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
        </div>
        <div class="mb-3">
            <label for="signature" class="form-label"><?php echo xlt('Signature Image'); ?></label>
            <input type="file" class="form-control" id="signature" name="signature" accept="image/*" required />
        </div>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" onclick="dlgclose();"><?php echo xlt('Cancel'); ?></button>
            <button type="submit" class="btn btn-success"><?php echo xlt('Save Consent'); ?></button>
        </div>
    </form>
</div>
<script>
    const templates = <?php echo json_encode($templates); ?>;
    const replacements = {
        '{{patient_name}}': <?php echo json_encode($patientName); ?>,
        '{{patient_dob}}': <?php echo json_encode($patientDob); ?>,
        '{{today_date}}': <?php echo json_encode(oeFormatShortDate(date('Y-m-d'))); ?>,
        '{{provider_name}}': <?php echo json_encode($_SESSION['authUser'] ?? ''); ?>
    };

    document.getElementById('template_id').addEventListener('change', function (event) {
        const selectedId = event.target.value;
        const template = templates.find(function (item) { return item.id === selectedId; })
            || templates.find(function (item) { return String(item.id) === String(selectedId); });
        if (!template) {
            document.getElementById('rendered_html').value = '';
            return;
        }
        let body = template.body_html;
        Object.keys(replacements).forEach(function (key) {
            const value = replacements[key];
            body = body.split(key).join(value);
        });
        document.getElementById('rendered_html').value = body;
    });
</script>
</body>
</html>

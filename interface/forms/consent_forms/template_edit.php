<?php
/**
 * Consent template edit form.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$id = (int)($_GET['id'] ?? 0);
$template = null;
if (!empty($id)) {
    $template = sqlQuery('SELECT * FROM consent_templates WHERE id = ?', [$id]);
    if (empty($template)) {
        die(xlt('Template not found.'));
    }
}

$csrfToken = CsrfUtils::collectCsrfToken('consent_forms');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Consent Template'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        textarea#body_html {
            min-height: 320px;
            font-family: monospace;
        }
    </style>
</head>
<body>
<div class="container-fluid my-3">
    <form method="post" action="template_save.php">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>" />
        <input type="hidden" name="id" value="<?php echo attr($id); ?>" />
        <div class="mb-3">
            <label for="title" class="form-label"><?php echo xlt('Title'); ?></label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo attr($template['title'] ?? ''); ?>" required />
        </div>
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo xlt('Description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo text($template['description'] ?? ''); ?></textarea>
        </div>
        <div class="mb-3">
            <label for="body_html" class="form-label"><?php echo xlt('Template HTML'); ?></label>
            <textarea class="form-control" id="body_html" name="body_html" required><?php echo text($template['body_html'] ?? '<p>' . xlt('Describe the consent terms here.') . '</p>'); ?></textarea>
            <small class="form-text text-muted"><?php echo xlt('Use placeholders like {{patient_name}}, {{patient_dob}}, {{today_date}}, {{provider_name}}.'); ?></small>
        </div>
        <div class="mb-3">
            <label for="is_active" class="form-label"><?php echo xlt('Status'); ?></label>
            <select id="is_active" name="is_active" class="form-select">
                <option value="1"<?php echo (!isset($template['is_active']) || $template['is_active']) ? ' selected' : ''; ?>><?php echo xlt('Active'); ?></option>
                <option value="0"<?php echo (isset($template['is_active']) && !$template['is_active']) ? ' selected' : ''; ?>><?php echo xlt('Inactive'); ?></option>
            </select>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" onclick="dlgclose();"><?php echo xlt('Cancel'); ?></button>
            <button type="submit" class="btn btn-primary"><?php echo xlt('Save Template'); ?></button>
        </div>
    </form>
</div>
</body>
</html>

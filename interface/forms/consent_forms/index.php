<?php
/**
 * Consent form management dashboard.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$pid = (int)($_GET['pid'] ?? $_SESSION['pid'] ?? 0);

$templates = [];
$templateSql = 'SELECT * FROM consent_templates ORDER BY is_active DESC, title';
$templateRes = sqlStatement($templateSql);
while ($row = sqlFetchArray($templateRes)) {
    $templates[] = $row;
}

$signedConsents = [];
$params = [];
$signedSql = 'SELECT sc.*, ct.title FROM patient_signed_consents AS sc '
    . 'JOIN consent_templates AS ct ON ct.id = sc.template_id';
if (!empty($pid)) {
    $signedSql .= ' WHERE sc.pid = ?';
    $params[] = $pid;
}
$signedSql .= ' ORDER BY sc.signed_at DESC';
$signedRes = sqlStatement($signedSql, $params);
while ($row = sqlFetchArray($signedRes)) {
    $signedConsents[] = $row;
}

$csrfToken = CsrfUtils::collectCsrfToken('consent_forms');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Consent Forms'); ?></title>
    <?php Header::setupHeader(['datatables', 'datatables-bs']); ?>
</head>
<body>
<div class="container-fluid my-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><?php echo xlt('Consent Form Templates'); ?></h4>
        <button class="btn btn-primary btn-sm" onclick="openTemplateForm();">
            <i class="fa fa-plus"></i> <?php echo xlt('New Template'); ?>
        </button>
    </div>
    <table id="templates-table" class="table table-striped table-bordered">
        <thead>
        <tr>
            <th><?php echo xlt('Title'); ?></th>
            <th><?php echo xlt('Status'); ?></th>
            <th><?php echo xlt('Updated'); ?></th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($templates as $template) : ?>
            <tr>
                <td><?php echo text($template['title']); ?></td>
                <td>
                    <?php if (!empty($template['is_active'])) : ?>
                        <span class="badge bg-success"><?php echo xlt('Active'); ?></span>
                    <?php else : ?>
                        <span class="badge bg-secondary"><?php echo xlt('Inactive'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo text(oeFormatDateTime($template['updated_at'])); ?></td>
                <td>
                    <button class="btn btn-link btn-sm" onclick="openTemplateForm(<?php echo attr_js($template['id']); ?>);">
                        <?php echo xlt('Edit'); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="d-flex justify-content-between align-items-center mt-5 mb-3">
        <h4><?php echo xlt('Signed Consents'); ?></h4>
        <?php if (!empty($pid)) : ?>
            <button class="btn btn-success btn-sm" onclick="openSignForm();">
                <i class="fa fa-pencil"></i> <?php echo xlt('Capture Consent'); ?>
            </button>
        <?php endif; ?>
    </div>
    <table id="signed-table" class="table table-hover">
        <thead>
        <tr>
            <th><?php echo xlt('Patient ID'); ?></th>
            <th><?php echo xlt('Template'); ?></th>
            <th><?php echo xlt('Signed At'); ?></th>
            <th><?php echo xlt('Encounter'); ?></th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($signedConsents as $signed) : ?>
            <tr>
                <td><?php echo text($signed['pid']); ?></td>
                <td><?php echo text($signed['title']); ?></td>
                <td><?php echo text(oeFormatDateTime($signed['signed_at'])); ?></td>
                <td><?php echo text($signed['encounter_id']); ?></td>
                <td>
                    <a class="btn btn-link btn-sm" href="<?php echo attr($GLOBALS['webroot'] . '/interface/forms/consent_forms/view_signed.php?id=' . urlencode($signed['id'])); ?>" target="_blank"><?php echo xlt('View'); ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script>
    $(function () {
        $('#templates-table').DataTable();
        $('#signed-table').DataTable();
    });

    function openTemplateForm(id = '') {
        const url = top.webroot_url + '/interface/forms/consent_forms/template_edit.php?id=' + encodeURIComponent(id);
        dlgopen(url, '_blank', 900, 700, '', '', {allowResize: true, allowMaximize: true});
    }

    function openSignForm() {
        const url = top.webroot_url + '/interface/forms/consent_forms/sign.php?pid=<?php echo attr_js($pid); ?>';
        dlgopen(url, '_blank', 900, 750, '', '', {allowResize: true, allowMaximize: true});
    }
</script>
</body>
</html>

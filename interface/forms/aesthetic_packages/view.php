<?php
/**
 * Administrative listing of aesthetic treatment packages.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Core\Header;
use OpenEMR\Common\Csrf\CsrfUtils;

if (!empty($_REQUEST) && !CsrfUtils::verifyCsrfToken($_REQUEST['csrf_token_form'] ?? '', 'aesthetic_packages')) {
    CsrfUtils::csrfNotVerified();
}

$csrfToken = CsrfUtils::collectCsrfToken('aesthetic_packages');

$packages = [];
$sql = "SELECT * FROM aesthetic_packages ORDER BY is_active DESC, name";
$res = sqlStatement($sql);
while ($row = sqlFetchArray($res)) {
    $packages[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Aesthetic Packages'); ?></title>
    <?php Header::setupHeader(['datatables', 'datatables-bs']); ?>
</head>
<body>
<div class="container-fluid my-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><?php echo xlt('Aesthetic Treatment Packages'); ?></h4>
        <button class="btn btn-primary btn-sm" onclick="openPackageForm();">
            <i class="fa fa-plus"></i> <?php echo xlt('Create Package'); ?>
        </button>
    </div>
    <table id="packages-table" class="table table-striped table-bordered">
        <thead>
        <tr>
            <th><?php echo xlt('Name'); ?></th>
            <th><?php echo xlt('Description'); ?></th>
            <th><?php echo xlt('Sessions'); ?></th>
            <th><?php echo xlt('Price'); ?></th>
            <th><?php echo xlt('Procedures Included'); ?></th>
            <th><?php echo xlt('Status'); ?></th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($packages as $package) : ?>
            <tr>
                <td><?php echo text($package['name']); ?></td>
                <td><?php echo nl2br(text($package['description'])); ?></td>
                <td><?php echo text((int)$package['session_count']); ?></td>
                <td><?php echo text(oeFormatMoney($package['price'] ?? 0)); ?></td>
                <td><?php echo nl2br(text($package['procedures'] ?? '')); ?></td>
                <td>
                    <?php if (!empty($package['is_active'])) : ?>
                        <span class="badge bg-success"><?php echo xlt('Active'); ?></span>
                    <?php else : ?>
                        <span class="badge bg-secondary"><?php echo xlt('Inactive'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-link btn-sm" onclick="openPackageForm(<?php echo attr_js($package['id']); ?>);">
                        <?php echo xlt('Edit'); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script>
    $(function () {
        $('#packages-table').DataTable();
    });

    function openPackageForm(id = '') {
        const url = top.webroot_url + '/interface/forms/aesthetic_packages/edit.php?id=' + encodeURIComponent(id);
        dlgopen(url, '_blank', 800, 600, '', '', {allowResize: true, allowMaximize: true});
    }
</script>
</body>
</html>

<?php
/**
 * Edit form for aesthetic treatment packages.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$id = (int)($_GET['id'] ?? 0);
$package = null;
if (!empty($id)) {
    $package = sqlQuery('SELECT * FROM aesthetic_packages WHERE id = ?', [$id]);
    if (empty($package)) {
        die(xlt('Package not found.'));
    }
}

$csrfToken = CsrfUtils::collectCsrfToken('aesthetic_packages');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Aesthetic Package'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body>
<div class="container-fluid my-3">
    <form id="package-form" method="post" action="save.php">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>" />
        <input type="hidden" name="id" value="<?php echo attr($id); ?>" />
        <div class="mb-3">
            <label for="name" class="form-label"><?php echo xlt('Package Name'); ?></label>
            <input type="text" class="form-control" id="name" name="name" value="<?php echo attr($package['name'] ?? ''); ?>" required />
        </div>
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo xlt('Description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo text($package['description'] ?? ''); ?></textarea>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label for="session_count" class="form-label"><?php echo xlt('Number of Sessions'); ?></label>
                <input type="number" min="1" class="form-control" id="session_count" name="session_count" value="<?php echo attr($package['session_count'] ?? 1); ?>" required />
            </div>
            <div class="col-md-4 mb-3">
                <label for="price" class="form-label"><?php echo xlt('Price'); ?></label>
                <input type="number" step="0.01" class="form-control" id="price" name="price" value="<?php echo attr($package['price'] ?? ''); ?>" />
            </div>
            <div class="col-md-4 mb-3">
                <label for="is_active" class="form-label"><?php echo xlt('Status'); ?></label>
                <select id="is_active" name="is_active" class="form-select">
                    <option value="1"<?php echo (!isset($package['is_active']) || $package['is_active']) ? ' selected' : ''; ?>><?php echo xlt('Active'); ?></option>
                    <option value="0"<?php echo (isset($package['is_active']) && !$package['is_active']) ? ' selected' : ''; ?>><?php echo xlt('Inactive'); ?></option>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label for="procedures" class="form-label"><?php echo xlt('Procedures Included'); ?></label>
            <textarea class="form-control" id="procedures" name="procedures" rows="4"><?php echo text($package['procedures'] ?? ''); ?></textarea>
            <small class="form-text text-muted"><?php echo xlt('List each procedure or modality separated by line breaks.'); ?></small>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" onclick="dlgclose();"><?php echo xlt('Cancel'); ?></button>
            <button type="submit" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
        </div>
    </form>
</div>
</body>
</html>

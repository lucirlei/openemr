<?php
/**
 * Patient summary widget for aesthetic treatment package tracking.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$pid = (int)($_SESSION['pid'] ?? $_GET['pid'] ?? 0);
if (empty($pid)) {
    die(xlt('Patient not selected.'));
}

$availablePackages = [];
$pkgSql = 'SELECT id, name, session_count FROM aesthetic_packages WHERE is_active = 1 ORDER BY name';
$pkgRes = sqlStatement($pkgSql);
while ($row = sqlFetchArray($pkgRes)) {
    $availablePackages[] = $row;
}

$assignments = [];
$sql = 'SELECT ppt.*, ap.name, ap.session_count, ap.price FROM patient_package_tracking AS ppt '
    . 'JOIN aesthetic_packages AS ap ON ap.id = ppt.package_id WHERE ppt.pid = ? ORDER BY ppt.created_at DESC';
$res = sqlStatement($sql, [$pid]);
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
    $assignments[] = $row;
}

$csrfToken = CsrfUtils::collectCsrfToken('aesthetic_packages_patient');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Aesthetic Treatments'); ?></title>
    <?php Header::setupHeader(['moment']); ?>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center my-2">
        <h5><?php echo xlt('Aesthetic Treatment Packages'); ?></h5>
    </div>

    <div class="card mb-3">
        <div class="card-header"><?php echo xlt('Assign Package'); ?></div>
        <div class="card-body">
            <?php if (empty($availablePackages)) : ?>
                <p class="text-muted mb-0"><?php echo xlt('No active packages available. Create packages under Administration first.'); ?></p>
            <?php else : ?>
                <form id="assign-package-form" class="row gy-2 gx-3 align-items-end">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>" />
                    <input type="hidden" name="pid" value="<?php echo attr($pid); ?>" />
                    <div class="col-md-4">
                        <label for="package_id" class="form-label"><?php echo xlt('Package'); ?></label>
                        <select id="package_id" name="package_id" class="form-select" required>
                            <option value=""><?php echo xlt('Select'); ?></option>
                            <?php foreach ($availablePackages as $package) : ?>
                                <option value="<?php echo attr($package['id']); ?>">
                                    <?php echo text($package['name']); ?> (<?php echo xlt('Sessions'); ?>: <?php echo text($package['session_count']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="start_date" class="form-label"><?php echo xlt('Start Date'); ?></label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo attr(date('Y-m-d')); ?>" />
                    </div>
                    <div class="col-md-5">
                        <label for="notes" class="form-label"><?php echo xlt('Notes'); ?></label>
                        <input type="text" class="form-control" id="notes" name="notes" />
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary btn-sm"><?php echo xlt('Assign Package'); ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($assignments)) : ?>
        <div class="alert alert-info"><?php echo xlt('No treatment packages have been assigned to this patient.'); ?></div>
    <?php else : ?>
        <?php foreach ($assignments as $assignment) : ?>
            <div class="card mb-3" data-tracking-id="<?php echo attr($assignment['id']); ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?php echo text($assignment['name']); ?></strong>
                        <span class="ms-2 badge bg-secondary"><?php echo text(ucfirst($assignment['status'])); ?></span>
                    </div>
                    <div class="small text-muted">
                        <?php echo xlt('Assigned'); ?>: <?php echo text(oeFormatShortDate($assignment['start_date'] ?? '')); ?>
                        <?php if (!empty($assignment['price'])) : ?>
                            · <?php echo xlt('Price'); ?>: <?php echo text(oeFormatMoney($assignment['price'])); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <p class="mb-1"><strong><?php echo xlt('Sessions Completed'); ?>:</strong> <?php echo text($assignment['sessions_completed']); ?> / <?php echo text($assignment['session_count']); ?></p>
                            <p class="mb-1"><strong><?php echo xlt('Remaining'); ?>:</strong> <?php echo text($assignment['remaining_sessions']); ?></p>
                            <p class="mb-1"><strong><?php echo xlt('Notes'); ?>:</strong> <?php echo nl2br(text($assignment['notes'] ?? '')); ?></p>
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-success btn-sm" onclick="logSession(<?php echo attr_js($assignment['id']); ?>);">
                                    <i class="fa fa-check"></i> <?php echo xlt('Log Session'); ?>
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="updateStatus(<?php echo attr_js($assignment['id']); ?>, '<?php echo attr_js($assignment['status']); ?>');">
                                    <?php echo xlt('Change Status'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <h6><?php echo xlt('Session Log'); ?></h6>
                            <?php if (empty($assignment['session_log'])) : ?>
                                <p class="text-muted"><?php echo xlt('No sessions recorded yet.'); ?></p>
                            <?php else : ?>
                                <ul class="list-group">
                                    <?php foreach (array_reverse($assignment['session_log']) as $log) : ?>
                                        <li class="list-group-item">
                                            <strong><?php echo xlt('Session'); ?> <?php echo text($log['number']); ?></strong>
                                            · <?php echo text(oeFormatShortDate($log['date'] ?? '')); ?>
                                            <?php if (!empty($log['recorded_by'])) : ?>
                                                · <?php echo text($log['recorded_by']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($log['note'])) : ?>
                                                <div class="small text-muted"><?php echo nl2br(text($log['note'])); ?></div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
    const csrfToken = '<?php echo attr_js($csrfToken); ?>';
    const pid = '<?php echo attr_js($pid); ?>';

    document.getElementById('assign-package-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        formData.append('action', 'assign');
        submitPackageRequest(formData, '<?php echo xlt('Package assigned successfully.'); ?>');
    });

    function logSession(trackingId) {
        const note = prompt('<?php echo xlt('Enter optional session notes'); ?>');
        if (note === null) {
            return;
        }
        const formData = new FormData();
        formData.append('csrf_token_form', csrfToken);
        formData.append('pid', pid);
        formData.append('tracking_id', trackingId);
        formData.append('session_note', note);
        formData.append('session_date', new Date().toISOString().slice(0, 10));
        formData.append('action', 'log-session');
        submitPackageRequest(formData, '<?php echo xlt('Session logged.'); ?>');
    }

    function updateStatus(trackingId, currentStatus) {
        const status = prompt('<?php echo xlt('Enter new status (active, completed, cancelled, on_hold)'); ?>', currentStatus);
        if (!status) {
            return;
        }
        const notes = prompt('<?php echo xlt('Update notes (optional)'); ?>');
        const formData = new FormData();
        formData.append('csrf_token_form', csrfToken);
        formData.append('pid', pid);
        formData.append('tracking_id', trackingId);
        formData.append('status', status);
        formData.append('notes', notes || '');
        formData.append('action', 'update-status');
        submitPackageRequest(formData, '<?php echo xlt('Package updated.'); ?>');
    }

    function submitPackageRequest(formData, successMessage) {
        fetch(top.webroot_url + '/library/api/aesthetic_packages_api.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (payload.success) {
                alert(successMessage);
                if (top && top.location) {
                    top.location.reload();
                }
            } else {
                alert(payload.message || '<?php echo xlt('Unable to process request'); ?>');
            }
        }).catch(function () {
            alert('<?php echo xlt('Unexpected error.'); ?>');
        });
    }
</script>
</body>
</html>

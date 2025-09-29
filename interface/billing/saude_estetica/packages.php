<?php
/**
 * Administrative interface for building Saúde & Estética treatment packages.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../globals.php');
require_once($GLOBALS['srcdir'] . '/options.inc.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\Aesthetic\PackageService;

if (!AclMain::aclCheckCore('admin', 'super')) {
    die(xlt('Not authorized.'));
}

$service = new PackageService();
$service->ensureSchema();

$messages = [];
$errors = [];
$editPackageId = (int)($_GET['package_id'] ?? 0);
$packageToEdit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'save_package') {
        $payload = [
            'package_id' => $_POST['package_id'] ?? null,
            'package_code' => $_POST['package_code'] ?? null,
            'name' => $_POST['name'] ?? null,
            'description' => $_POST['description'] ?? null,
            'base_price' => $_POST['base_price'] ?? null,
            'promo_price' => $_POST['promo_price'] ?? null,
            'promo_start_date' => $_POST['promo_start_date'] ?? null,
            'promo_end_date' => $_POST['promo_end_date'] ?? null,
            'periodicity_unit' => $_POST['periodicity_unit'] ?? null,
            'periodicity_count' => $_POST['periodicity_count'] ?? null,
            'session_count' => $_POST['session_count'] ?? null,
            'installment_counts' => $_POST['installment_counts'] ?? [],
            'installment_amounts' => $_POST['installment_amounts'] ?? [],
            'installment_descriptions' => $_POST['installment_descriptions'] ?? [],
            'is_active' => !empty($_POST['is_active']),
        ];
        $result = $service->savePackage($payload);
        if ($result->hasErrors() || $result->hasValidationMessages()) {
            $errors = array_merge($errors, $result->getValidationMessages());
            $errors = array_merge($errors, $result->getInternalErrors());
            $packageToEdit = $payload;
        } else {
            $messages[] = xlt('Package saved successfully.');
            $data = $result->getData();
            if (!empty($data[0]['package_id'])) {
                $editPackageId = (int)$data[0]['package_id'];
            }
        }
    } elseif ($action === 'delete_package') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        if ($packageId > 0) {
            $service->deletePackage($packageId);
            $messages[] = xlt('Package removed.');
            if ($editPackageId === $packageId) {
                $editPackageId = 0;
            }
        }
    }
}

$packages = $service->listPackages(false);
if ($packageToEdit === null && $editPackageId > 0) {
    $packageToEdit = $service->getPackage($editPackageId);
}

$periodicityUnits = [
    'day' => xlt('Days'),
    'week' => xlt('Weeks'),
    'month' => xlt('Months'),
    'year' => xlt('Years'),
];

Header::setupHeader(['datetime-picker', 'jquery-ui']);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Saúde & Estética Packages'); ?></title>
    <style>
        .package-summary-table th,
        .package-summary-table td {
            vertical-align: top;
        }
        .installment-table th,
        .installment-table td {
            vertical-align: middle;
        }
        .installment-table input[type="number"] {
            width: 100px;
        }
        .installment-table input[type="text"] {
            width: 220px;
        }
    </style>
</head>
<body class="container-fluid">
    <div class="page-header">
        <h2 class="title"><?php echo xlt('Saúde & Estética Packages'); ?></h2>
    </div>

    <?php if (!empty($messages)) : ?>
        <div class="alert alert-success">
            <ul class="mb-0">
                <?php foreach ($messages as $message) : ?>
                    <li><?php echo text($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)) : ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $field => $error) : ?>
                    <li><?php echo text(is_numeric($field) ? $error : ($field . ': ' . $error)); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?php echo xlt('Configured Packages'); ?></span>
                    <a class="btn btn-sm btn-primary" href="<?php echo attr($_SERVER['PHP_SELF']); ?>">
                        <i class="fa fa-plus"></i> <?php echo xlt('New Package'); ?>
                    </a>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-striped table-hover package-summary-table mb-0">
                        <thead>
                            <tr>
                                <th><?php echo xlt('Name'); ?></th>
                                <th><?php echo xlt('Pricing'); ?></th>
                                <th><?php echo xlt('Periodicity'); ?></th>
                                <th><?php echo xlt('Sessions'); ?></th>
                                <th><?php echo xlt('Status'); ?></th>
                                <th class="text-end"><?php echo xlt('Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages)) : ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted"><?php echo xlt('No packages configured yet.'); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($packages as $package) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo text($package['name']); ?></strong><br />
                                        <small class="text-muted"><?php echo text($package['package_code']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo text(oeFormatMoney($package['base_price'])); ?><br />
                                        <?php if (!empty($package['promo_price'])) : ?>
                                            <span class="badge bg-success">
                                                <?php echo xlt('Promo:'); ?> <?php echo text(oeFormatMoney($package['promo_price'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo text($package['periodicity_count']); ?>
                                        <?php echo text($periodicityUnits[$package['periodicity_unit']] ?? $package['periodicity_unit']); ?>
                                    </td>
                                    <td><?php echo text($package['session_count'] ?? '-'); ?></td>
                                    <td>
                                        <?php if (!empty($package['is_active'])) : ?>
                                            <span class="badge bg-primary"><?php echo xlt('Active'); ?></span>
                                        <?php else : ?>
                                            <span class="badge bg-secondary"><?php echo xlt('Inactive'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr($_SERVER['PHP_SELF'] . '?package_id=' . urlencode($package['package_id'])); ?>">
                                            <i class="fa fa-edit"></i>
                                        </a>
                                        <form class="d-inline" method="post" onsubmit="return confirm(<?php echo xlj('Remove this package?'); ?>);">
                                            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                                            <input type="hidden" name="action" value="delete_package" />
                                            <input type="hidden" name="package_id" value="<?php echo attr($package['package_id']); ?>" />
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <?php echo $editPackageId > 0 ? xlt('Edit Package') : xlt('New Package'); ?>
                </div>
                <div class="card-body">
                    <form method="post" id="package-form" autocomplete="off">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input type="hidden" name="action" value="save_package" />
                        <input type="hidden" name="package_id" value="<?php echo attr($packageToEdit['package_id'] ?? ''); ?>" />
                        <div class="mb-3">
                            <label class="form-label" for="package_code"><?php echo xlt('Package Code'); ?></label>
                            <input type="text" class="form-control" id="package_code" name="package_code" value="<?php echo attr($packageToEdit['package_code'] ?? ''); ?>" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="name"><?php echo xlt('Name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required value="<?php echo attr($packageToEdit['name'] ?? ''); ?>" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="description"><?php echo xlt('Description'); ?></label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo text($packageToEdit['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="base_price"><?php echo xlt('Base Price'); ?> <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" id="base_price" name="base_price" required value="<?php echo attr($packageToEdit['base_price'] ?? ''); ?>" />
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="promo_price"><?php echo xlt('Promo Price'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="promo_price" name="promo_price" value="<?php echo attr($packageToEdit['promo_price'] ?? ''); ?>" />
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="session_count"><?php echo xlt('Sessions Included'); ?></label>
                                <input type="number" min="0" class="form-control" id="session_count" name="session_count" value="<?php echo attr($packageToEdit['session_count'] ?? ''); ?>" />
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="promo_start_date"><?php echo xlt('Promo Start'); ?></label>
                                <input type="text" class="form-control datepicker" id="promo_start_date" name="promo_start_date" value="<?php echo attr($packageToEdit['promo_start_date'] ?? ''); ?>" />
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="promo_end_date"><?php echo xlt('Promo End'); ?></label>
                                <input type="text" class="form-control datepicker" id="promo_end_date" name="promo_end_date" value="<?php echo attr($packageToEdit['promo_end_date'] ?? ''); ?>" />
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="periodicity_count"><?php echo xlt('Repeat Every'); ?></label>
                                <input type="number" min="1" class="form-control" id="periodicity_count" name="periodicity_count" value="<?php echo attr($packageToEdit['periodicity_count'] ?? 1); ?>" />
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="periodicity_unit"><?php echo xlt('Unit'); ?></label>
                                <select class="form-select" id="periodicity_unit" name="periodicity_unit">
                                    <?php foreach ($periodicityUnits as $unitKey => $label) : ?>
                                        <option value="<?php echo attr($unitKey); ?>" <?php echo (!empty($packageToEdit['periodicity_unit']) && $packageToEdit['periodicity_unit'] === $unitKey) ? 'selected' : ''; ?>>
                                            <?php echo text($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo (!empty($packageToEdit['is_active']) || $packageToEdit === null) ? 'checked' : ''; ?> />
                            <label class="form-check-label" for="is_active"><?php echo xlt('Package available for new subscriptions'); ?></label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('Installment Options'); ?></label>
                            <table class="table table-sm table-bordered installment-table" id="installment-table">
                                <thead>
                                    <tr>
                                        <th style="width: 15%;"><?php echo xlt('Installments'); ?></th>
                                        <th style="width: 20%;"><?php echo xlt('Amount'); ?></th>
                                        <th><?php echo xlt('Description'); ?></th>
                                        <th style="width: 5%;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $existingPlans = [];
                                    if (!empty($packageToEdit['installment_options'])) {
                                        $existingPlans = json_decode($packageToEdit['installment_options'], true) ?: [];
                                    }
                                    if (empty($existingPlans)) {
                                        $existingPlans = [[]];
                                    }
                                    foreach ($existingPlans as $plan) :
                                    ?>
                                        <tr>
                                            <td><input type="number" min="1" class="form-control" name="installment_counts[]" value="<?php echo attr($plan['installments'] ?? ''); ?>" /></td>
                                            <td><input type="number" min="0" step="0.01" class="form-control" name="installment_amounts[]" value="<?php echo attr($plan['amount'] ?? ''); ?>" /></td>
                                            <td><input type="text" class="form-control" name="installment_descriptions[]" value="<?php echo attr($plan['description'] ?? ''); ?>" /></td>
                                            <td class="text-center"><button type="button" class="btn btn-link text-danger remove-installment" title="<?php echo xla('Remove'); ?>"><i class="fa fa-times"></i></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="add-installment">
                                <i class="fa fa-plus"></i> <?php echo xlt('Add option'); ?>
                            </button>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> <?php echo xlt('Save Package'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(function () {
            $('.datepicker').datetimepicker({
                timepicker: false,
                format: 'Y-m-d'
            });

            $('#add-installment').on('click', function () {
                const row = `<tr>
                    <td><input type="number" min="1" class="form-control" name="installment_counts[]" /></td>
                    <td><input type="number" min="0" step="0.01" class="form-control" name="installment_amounts[]" /></td>
                    <td><input type="text" class="form-control" name="installment_descriptions[]" /></td>
                    <td class="text-center"><button type="button" class="btn btn-link text-danger remove-installment" title="<?php echo xla('Remove'); ?>"><i class="fa fa-times"></i></button></td>
                </tr>`;
                $('#installment-table tbody').append(row);
            });

            $('#installment-table').on('click', '.remove-installment', function () {
                if ($('#installment-table tbody tr').length <= 1) {
                    $(this).closest('tr').find('input').val('');
                    return;
                }
                $(this).closest('tr').remove();
            });
        });
    </script>
</body>
</html>

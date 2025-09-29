<?php
/**
 * Patient facing package tracking screen for Saúde & Estética flows.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../globals.php');
require_once($GLOBALS['srcdir'] . '/options.inc.php');
require_once($GLOBALS['srcdir'] . '/forms.inc.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\Aesthetic\PackageService;
use OpenEMR\Validators\ProcessingResult;

$pid = (int)($_GET['pid'] ?? 0);
if ($pid <= 0) {
    die(xlt('Patient context is required.'));
}

if (!AclMain::aclCheckCore('patients', 'med')) {
    die(xlt('Not authorized.'));
}

$service = new PackageService();
$service->ensureSchema();

$alerts = [];
$errors = [];

$packages = $service->listPackages(true);
$packageOptions = [];
foreach ($packages as $package) {
    $packageOptions[$package['package_id']] = $package;
}

$providerRows = QueryUtils::fetchRecords(
    'SELECT id, fname, lname FROM users WHERE active = 1 AND authorized = 1 ORDER BY lname, fname',
    [],
    true
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'create_subscription':
            $payload = [
                'patient_id' => $pid,
                'package_id' => $_POST['package_id'] ?? null,
                'start_date' => $_POST['start_date'] ?? null,
                'end_date' => $_POST['end_date'] ?? null,
                'status' => $_POST['status'] ?? 'active',
                'total_sessions' => $_POST['total_sessions'] ?? null,
                'installment_plan' => $_POST['installment_plan'] ?? null,
                'total_amount' => $_POST['total_amount'] ?? null,
                'promo_price' => $_POST['promo_price'] ?? null,
                'recurring_amount' => $_POST['recurring_amount'] ?? null,
                'next_session_date' => $_POST['next_session_date'] ?? null,
                'next_billing_date' => $_POST['next_billing_date'] ?? null,
                'billing_cycle_unit' => $_POST['billing_cycle_unit'] ?? null,
                'billing_cycle_count' => $_POST['billing_cycle_count'] ?? null,
                'auto_bill' => !empty($_POST['auto_bill']),
                'pos_customer_reference' => $_POST['pos_customer_reference'] ?? null,
                'pos_agreement_reference' => $_POST['pos_agreement_reference'] ?? null,
                'gateway_identifier' => $_POST['gateway_identifier'] ?? null,
                'receipt_email' => $_POST['receipt_email'] ?? null,
            ];
            $result = $service->createSubscription($payload);
            if ($result->hasErrors() || $result->hasValidationMessages()) {
                $errors = array_merge($errors, format_processing_errors($result));
            } else {
                $alerts[] = xlt('Subscription created successfully.');
            }
            break;
        case 'log_session':
            $payload = [
                'subscription_id' => $_POST['subscription_id'] ?? null,
                'log_type' => 'session',
                'session_date' => $_POST['session_date'] ?? null,
                'status' => $_POST['session_status'] ?? null,
                'provider_id' => $_POST['provider_id'] ?? null,
                'appointment_id' => $_POST['appointment_id'] ?? null,
                'duration_minutes' => $_POST['duration_minutes'] ?? null,
                'notes' => $_POST['notes'] ?? null,
            ];
            $result = $service->logSubscriptionEvent($payload);
            if ($result->hasErrors() || $result->hasValidationMessages()) {
                $errors = array_merge($errors, format_processing_errors($result));
            } else {
                $alerts[] = xlt('Session event recorded.');
            }
            break;
        case 'record_payment':
            $subscriptionId = (int)($_POST['payment_subscription_id'] ?? 0);
            $amount = $_POST['payment_amount'] ?? null;
            if ($subscriptionId <= 0 || $amount === null || $amount === '') {
                $errors[] = xlt('A subscription and payment amount are required.');
                break;
            }
            $metadata = [
                'payment_reference' => $_POST['payment_reference'] ?? null,
                'receipt_reference' => $_POST['receipt_reference'] ?? null,
                'notes' => $_POST['payment_notes'] ?? null,
                'next_billing_date' => $_POST['payment_next_billing'] ?? null,
            ];
            $service->registerRecurringPayment($subscriptionId, (float)$amount, $_POST['payment_date'] ?? null, $metadata);
            $alerts[] = xlt('Recurring payment registered.');
            break;
    }
}

$subscriptions = $service->getSubscriptionsForPatient($pid);
$subscriptionOptions = [];
foreach ($subscriptions as $subscription) {
    $subscriptionOptions[$subscription['subscription_id']] = $subscription;
}

Header::setupHeader(['datetime-picker', 'jquery-ui']);

function format_processing_errors(ProcessingResult $result): array
{
    $messages = [];
    foreach ((array)$result->getValidationMessages() as $field => $text) {
        if (is_array($text)) {
            foreach ($text as $item) {
                $messages[] = (is_numeric($field) ? '' : $field . ': ') . $item;
            }
        } else {
            $messages[] = (is_numeric($field) ? '' : $field . ': ') . $text;
        }
    }
    foreach ($result->getInternalErrors() as $error) {
        $messages[] = (string)$error;
    }
    return $messages;
}

$packageInstallments = [];
foreach ($packages as $package) {
    $packageInstallments[$package['package_id']] = [];
    if (!empty($package['installment_options'])) {
        $decoded = json_decode($package['installment_options'], true) ?: [];
        foreach ($decoded as $plan) {
            $packageInstallments[$package['package_id']][] = $plan;
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Treatment Packages'); ?></title>
    <style>
        .subscription-card + .subscription-card {
            margin-top: 1rem;
        }
        .session-log-table td,
        .session-log-table th {
            vertical-align: middle;
        }
    </style>
</head>
<body class="container-fluid">
    <div class="page-header">
        <h2 class="title"><?php echo xlt('Treatment Packages for Patient'); ?></h2>
    </div>

    <?php if (!empty($alerts)) : ?>
        <div class="alert alert-success">
            <ul class="mb-0">
                <?php foreach ($alerts as $message) : ?>
                    <li><?php echo text($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)) : ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error) : ?>
                    <li><?php echo text($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><?php echo xlt('Create Subscription'); ?></div>
                <div class="card-body">
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input type="hidden" name="action" value="create_subscription" />
                        <div class="mb-3">
                            <label class="form-label" for="package_id"><?php echo xlt('Package'); ?></label>
                            <select class="form-select" id="package_id" name="package_id" required>
                                <option value=""><?php echo xlt('Select'); ?></option>
                                <?php foreach ($packages as $package) : ?>
                                    <option value="<?php echo attr($package['package_id']); ?>">
                                        <?php echo text($package['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="start_date"><?php echo xlt('Start Date'); ?></label>
                                <input type="text" class="form-control datepicker" id="start_date" name="start_date" />
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="end_date"><?php echo xlt('End Date'); ?></label>
                                <input type="text" class="form-control datepicker" id="end_date" name="end_date" />
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="status"><?php echo xlt('Status'); ?></label>
                            <select class="form-select" id="status" name="status">
                                <option value="active"><?php echo xlt('Active'); ?></option>
                                <option value="paused"><?php echo xlt('Paused'); ?></option>
                                <option value="completed"><?php echo xlt('Completed'); ?></option>
                                <option value="cancelled"><?php echo xlt('Cancelled'); ?></option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="total_sessions"><?php echo xlt('Total Sessions'); ?></label>
                                <input type="number" min="0" class="form-control" id="total_sessions" name="total_sessions" />
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="installment_plan"><?php echo xlt('Installment Plan'); ?></label>
                                <select class="form-select" id="installment_plan" name="installment_plan">
                                    <option value=""><?php echo xlt('Select'); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="total_amount"><?php echo xlt('Total Amount'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="total_amount" name="total_amount" />
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="promo_price"><?php echo xlt('Promo Applied'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="promo_price" name="promo_price" />
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="recurring_amount"><?php echo xlt('Recurring Amount'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="recurring_amount" name="recurring_amount" />
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="next_billing_date"><?php echo xlt('Next Billing'); ?></label>
                                <input type="text" class="form-control datepicker" id="next_billing_date" name="next_billing_date" />
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="billing_cycle_count"><?php echo xlt('Billing Every'); ?></label>
                                <input type="number" min="1" class="form-control" id="billing_cycle_count" name="billing_cycle_count" />
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="billing_cycle_unit"><?php echo xlt('Cycle Unit'); ?></label>
                                <select class="form-select" id="billing_cycle_unit" name="billing_cycle_unit">
                                    <option value=""></option>
                                    <option value="day"><?php echo xlt('Days'); ?></option>
                                    <option value="week"><?php echo xlt('Weeks'); ?></option>
                                    <option value="month"><?php echo xlt('Months'); ?></option>
                                    <option value="year"><?php echo xlt('Years'); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="next_session_date"><?php echo xlt('Next Session Target'); ?></label>
                            <input type="text" class="form-control datepicker" id="next_session_date" name="next_session_date" />
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="auto_bill" name="auto_bill" value="1" />
                            <label class="form-check-label" for="auto_bill"><?php echo xlt('Enable automatic billing'); ?></label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pos_customer_reference"><?php echo xlt('POS Customer Reference'); ?></label>
                            <input type="text" class="form-control" id="pos_customer_reference" name="pos_customer_reference" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pos_agreement_reference"><?php echo xlt('POS Agreement'); ?></label>
                            <input type="text" class="form-control" id="pos_agreement_reference" name="pos_agreement_reference" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="gateway_identifier"><?php echo xlt('Gateway Subscription ID'); ?></label>
                            <input type="text" class="form-control" id="gateway_identifier" name="gateway_identifier" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="receipt_email"><?php echo xlt('Receipt Email'); ?></label>
                            <input type="email" class="form-control" id="receipt_email" name="receipt_email" />
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?php echo xlt('Create'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><?php echo xlt('Log Session'); ?></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input type="hidden" name="action" value="log_session" />
                        <div class="mb-3">
                            <label class="form-label" for="subscription_id"><?php echo xlt('Subscription'); ?></label>
                            <select class="form-select" id="subscription_id" name="subscription_id" required>
                                <option value=""><?php echo xlt('Select'); ?></option>
                                <?php foreach ($subscriptions as $subscription) : ?>
                                    <option value="<?php echo attr($subscription['subscription_id']); ?>"><?php echo text($subscription['package_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="session_date"><?php echo xlt('Session Date/Time'); ?></label>
                            <input type="text" class="form-control datetimepicker" id="session_date" name="session_date" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="session_status"><?php echo xlt('Status'); ?></label>
                            <select class="form-select" id="session_status" name="session_status">
                                <option value="completed"><?php echo xlt('Completed'); ?></option>
                                <option value="scheduled"><?php echo xlt('Scheduled'); ?></option>
                                <option value="cancelled"><?php echo xlt('Cancelled'); ?></option>
                                <option value="pending"><?php echo xlt('Pending'); ?></option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="provider_id"><?php echo xlt('Provider'); ?></label>
                                <select class="form-select" id="provider_id" name="provider_id">
                                    <option value=""><?php echo xlt('Select'); ?></option>
                                    <?php foreach ($providerRows as $provider) : ?>
                                        <option value="<?php echo attr($provider['id']); ?>"><?php echo text($provider['lname'] . ', ' . $provider['fname']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="duration_minutes"><?php echo xlt('Duration (min)'); ?></label>
                                <input type="number" min="0" class="form-control" id="duration_minutes" name="duration_minutes" />
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="appointment_id"><?php echo xlt('Appointment ID'); ?></label>
                            <input type="number" class="form-control" id="appointment_id" name="appointment_id" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="notes"><?php echo xlt('Notes'); ?></label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-secondary"><i class="fa fa-check"></i> <?php echo xlt('Log Session'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><?php echo xlt('Register Payment'); ?></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input type="hidden" name="action" value="record_payment" />
                        <div class="mb-3">
                            <label class="form-label" for="payment_subscription_id"><?php echo xlt('Subscription'); ?></label>
                            <select class="form-select" id="payment_subscription_id" name="payment_subscription_id" required>
                                <option value=""><?php echo xlt('Select'); ?></option>
                                <?php foreach ($subscriptions as $subscription) : ?>
                                    <option value="<?php echo attr($subscription['subscription_id']); ?>"><?php echo text($subscription['package_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="payment_amount"><?php echo xlt('Amount'); ?></label>
                                <input type="number" step="0.01" min="0" class="form-control" id="payment_amount" name="payment_amount" required />
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="payment_date"><?php echo xlt('Payment Date'); ?></label>
                                <input type="text" class="form-control datepicker" id="payment_date" name="payment_date" />
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="payment_reference"><?php echo xlt('Payment Reference'); ?></label>
                            <input type="text" class="form-control" id="payment_reference" name="payment_reference" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="receipt_reference"><?php echo xlt('Receipt'); ?></label>
                            <input type="text" class="form-control" id="receipt_reference" name="receipt_reference" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="payment_next_billing"><?php echo xlt('Next Billing Date'); ?></label>
                            <input type="text" class="form-control datepicker" id="payment_next_billing" name="payment_next_billing" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="payment_notes"><?php echo xlt('Notes'); ?></label>
                            <textarea class="form-control" id="payment_notes" name="payment_notes" rows="2"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-success"><i class="fa fa-credit-card"></i> <?php echo xlt('Register Payment'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <?php if (empty($subscriptions)) : ?>
                <div class="alert alert-info"><?php echo xlt('No subscriptions for this patient yet.'); ?></div>
            <?php endif; ?>
            <?php foreach ($subscriptions as $subscription) :
                $logs = $service->getSessionLogs((int)$subscription['subscription_id']);
                $recentLogs = array_slice($logs, 0, 5);
                ?>
                <div class="card subscription-card" id="subscription-<?php echo attr($subscription['subscription_id']); ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0"><?php echo text($subscription['package_name']); ?></h5>
                            <small class="text-muted"><?php echo xlt('Status'); ?>: <?php echo text(ucfirst($subscription['status'])); ?></small>
                        </div>
                        <div>
                            <?php if (!empty($subscription['next_session_suggestion'])) : ?>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-next-date="<?php echo attr($subscription['next_session_suggestion']); ?>" onclick="openScheduler(this)">
                                    <i class="fa fa-calendar-plus"></i> <?php echo xlt('Schedule Next'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-6"><?php echo xlt('Start Date'); ?></dt>
                                    <dd class="col-sm-6"><?php echo text($subscription['start_date']); ?></dd>
                                    <dt class="col-sm-6"><?php echo xlt('Next Session'); ?></dt>
                                    <dd class="col-sm-6"><?php echo text($subscription['next_session_suggestion'] ?? $subscription['next_session_date']); ?></dd>
                                    <dt class="col-sm-6"><?php echo xlt('Sessions Completed'); ?></dt>
                                    <dd class="col-sm-6"><?php echo text($subscription['usage']['completed']); ?> / <?php echo text($subscription['total_sessions'] ?? $subscription['session_count'] ?? '-'); ?></dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-6"><?php echo xlt('Recurring Amount'); ?></dt>
                                    <dd class="col-sm-6"><?php echo text(oeFormatMoney($subscription['recurring_amount'] ?? $subscription['total_amount'])); ?></dd>
                                    <dt class="col-sm-6"><?php echo xlt('Next Billing'); ?></dt>
                                    <dd class="col-sm-6"><?php echo text($subscription['next_billing_date'] ?: '-'); ?></dd>
                                    <dt class="col-sm-6"><?php echo xlt('Auto Bill'); ?></dt>
                                    <dd class="col-sm-6"><?php echo !empty($subscription['auto_bill']) ? xlt('Yes') : xlt('No'); ?></dd>
                                </dl>
                            </div>
                        </div>
                        <?php if (!empty($recentLogs)) : ?>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm session-log-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo xlt('Date'); ?></th>
                                            <th><?php echo xlt('Type'); ?></th>
                                            <th><?php echo xlt('Status'); ?></th>
                                            <th><?php echo xlt('Notes'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentLogs as $log) : ?>
                                            <tr>
                                                <td><?php echo text($log['session_date']); ?></td>
                                                <td><?php echo text(ucfirst($log['log_type'])); ?></td>
                                                <td><?php echo text($log['status']); ?></td>
                                                <td><?php echo text($log['notes'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else : ?>
                            <p class="text-muted mt-3"><?php echo xlt('No session history recorded yet.'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        const packageInstallments = <?php echo json_encode($packageInstallments); ?>;

        function updateInstallmentOptions(packageId) {
            const select = document.getElementById('installment_plan');
            while (select.firstChild) {
                select.removeChild(select.firstChild);
            }
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '<?php echo xlt('Select'); ?>';
            select.appendChild(placeholder);
            if (!packageId || !packageInstallments[packageId]) {
                return;
            }
            packageInstallments[packageId].forEach(function (plan) {
                const option = document.createElement('option');
                option.value = JSON.stringify(plan);
                const description = plan.description ? ' - ' + plan.description : '';
                option.textContent = plan.installments + 'x ' + plan.amount + description;
                select.appendChild(option);
            });
        }

        function openScheduler(button) {
            const date = button.getAttribute('data-next-date') || '';
            let url = '../../main/calendar/add_edit_event.php?pid=<?php echo attr_url($pid); ?>';
            if (date) {
                url += '&date=' + encodeURIComponent(date);
            }
            top.restoreSession();
            dlgopen(url, '_blank', 900, 600);
        }

        $(function () {
            $('.datepicker').datetimepicker({
                timepicker: false,
                format: 'Y-m-d'
            });
            $('.datetimepicker').datetimepicker({
                timepicker: true,
                step: 15,
                format: 'Y-m-d H:i'
            });

            $('#package_id').on('change', function () {
                updateInstallmentOptions(this.value);
            });

            updateInstallmentOptions($('#package_id').val());
        });
    </script>
</body>
</html>

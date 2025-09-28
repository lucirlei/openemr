<?php

/**
 * Cron entry point for CRM automations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$ignoreAuth = true;
require_once(dirname(__DIR__, 3) . '/interface/globals.php');

use OpenEMR\Services\Crm\CrmAutomationService;

$automationService = new CrmAutomationService();
$windowEnd = new DateTimeImmutable();
$windowStart = $windowEnd->sub(new DateInterval('PT1H'));

$result = $automationService->dispatchWindow($windowStart, $windowEnd);

if ($result->hasErrors()) {
    foreach ($result->getValidationMessages() as $message) {
        error_log('CRM automation validation error: ' . print_r($message, true));
    }
    foreach ($result->getInternalErrors() as $message) {
        error_log('CRM automation internal error: ' . $message);
    }
}

echo 'CRM automation run at ' . $windowEnd->format('c') . PHP_EOL;
$dispatched = $result->getData();
if (!empty($dispatched)) {
    foreach ($dispatched as $entry) {
        echo '- Lead ' . ($entry['lead_uuid'] ?? 'n/a') . ' via ' . ($entry['channel'] ?? 'n/a') . PHP_EOL;
    }
} else {
    echo "No automations queued in this window." . PHP_EOL;
}

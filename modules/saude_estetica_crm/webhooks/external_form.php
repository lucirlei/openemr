<?php

/**
 * External form webhook intake for CRM leads.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$ignoreAuth = true;
require_once(dirname(__DIR__, 3) . '/interface/globals.php');

use OpenEMR\Services\Crm\CrmIntegrationService;

header('Content-Type: application/json');

$expectedToken = getenv('OEMR_CRM_WEBHOOK_TOKEN') ?: ($GLOBALS['crm_webhook_token'] ?? '');
$providedToken = $_GET['token'] ?? '';

if (!empty($expectedToken) && (!hash_equals($expectedToken, $providedToken))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload)) {
    $payload = $_POST;
}

$integrationService = new CrmIntegrationService();
$result = $integrationService->handleInboundWebhook($payload, $_GET['source'] ?? 'external_form');

$response = [
    'success' => !$result->hasErrors(),
    'validationErrors' => $result->getValidationMessages(),
    'internalErrors' => $result->getInternalErrors(),
    'data' => $result->getData(),
];

http_response_code($result->hasErrors() ? 422 : 201);

echo json_encode($response);

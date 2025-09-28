<?php

/**
 * Dispatch queued CRM interactions to external marketing platforms.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$ignoreAuth = true;
require_once(dirname(__DIR__, 3) . '/interface/globals.php');

use OpenEMR\Common\Database\QueryUtils;

$endpoint = getenv('OEMR_CRM_MARKETING_URL');
$token = getenv('OEMR_CRM_MARKETING_TOKEN');

if (empty($endpoint)) {
    echo "Marketing dispatcher disabled. Set OEMR_CRM_MARKETING_URL." . PHP_EOL;
    return;
}

if (!function_exists('curl_init')) {
    echo "cURL extension is required for marketing dispatcher." . PHP_EOL;
    return;
}

$records = QueryUtils::fetchRecords(
    'SELECT id, payload FROM crm_interactions WHERE interaction_type = ? AND outcome = ? ORDER BY created_at ASC LIMIT 25',
    ['webhook', 'queued']
);

if (empty($records)) {
    echo "No queued marketing events." . PHP_EOL;
    return;
}

foreach ($records as $record) {
    $payload = [];
    if (!empty($record['payload'])) {
        $decoded = json_decode($record['payload'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $payload = $decoded;
        }
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
        'Content-Type: application/json',
    ], $token ? ['Authorization: Bearer ' . $token] : []));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || ($httpCode >= 300)) {
        sqlStatement('UPDATE crm_interactions SET outcome = ? WHERE id = ?', ['error', $record['id']]);
        error_log('CRM marketing dispatch failed for ID ' . $record['id'] . ': ' . ($error ?: $response));
    } else {
        sqlStatement('UPDATE crm_interactions SET outcome = ?, completed_at = NOW() WHERE id = ?', ['delivered', $record['id']]);
        echo 'Dispatched interaction ' . $record['id'] . PHP_EOL;
    }
}

<?php

use OpenEMR\Services\Checkout\PaymentGatewayManager;

require_once __DIR__ . '/../interface/globals.php';

$gateway = strtolower($_GET['gateway'] ?? '');
$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload ?: '[]', true);
if (!is_array($data)) {
    $data = array();
}

$manager = new PaymentGatewayManager();
if (!$manager->supports($gateway)) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => 'Unsupported gateway.'));
    return;
}

$response = $manager->handleWebhook($gateway, $data);
header('Content-Type: application/json');
echo json_encode(array('status' => 'ok', 'data' => $response));

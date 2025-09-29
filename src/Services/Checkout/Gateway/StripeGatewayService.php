<?php

namespace OpenEMR\Services\Checkout\Gateway;

class StripeGatewayService extends AbstractGatewayService
{
    public function dispatch(array $payload): array
    {
        $externalId = $payload['external_id'] ?? ('stripe_' . bin2hex(random_bytes(8)));
        $sessionId = $payload['session_id'] ?? null;
        $amount = $payload['amount'] ?? 0;
        $record = array(
            'session_id' => $sessionId,
            'amount' => $amount,
            'reference' => $payload['reference'] ?? '',
            'installments' => $payload['installments'] ?? 1,
            'metadata' => $payload
        );
        $this->persistTransaction('stripe', $sessionId, $externalId, 'pending', $record);
        return array(
            'status' => 'pending',
            'external_id' => $externalId
        );
    }

    public function handleWebhook(array $payload): array
    {
        $externalId = $payload['id'] ?? $payload['external_id'] ?? '';
        if ($externalId === '') {
            return array('status' => 'ignored');
        }
        $status = $payload['status'] ?? 'completed';
        $sessionId = $payload['session_id'] ?? null;
        $this->persistTransaction('stripe', $sessionId, $externalId, $status, $payload);
        return array('status' => $status);
    }
}


<?php

namespace OpenEMR\Services\Checkout;

use OpenEMR\Services\Checkout\Exception\PaymentProcessingException;
use OpenEMR\Services\Checkout\Gateway\AbstractGatewayService;
use OpenEMR\Services\Checkout\Gateway\PagSeguroGatewayService;
use OpenEMR\Services\Checkout\Gateway\StripeGatewayService;
use Psr\Log\LoggerInterface;

class PaymentGatewayManager
{
    /** @var array<string, AbstractGatewayService> */
    private array $gateways;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->gateways = array(
            'stripe' => new StripeGatewayService($logger),
            'pagseguro' => new PagSeguroGatewayService($logger)
        );
    }

    public function dispatchPayment(string $gatewayKey, array $payload): array
    {
        $gateway = $this->getGateway($gatewayKey);
        return $gateway->dispatch($payload);
    }

    public function handleWebhook(string $gatewayKey, array $payload): array
    {
        $gateway = $this->getGateway($gatewayKey);
        return $gateway->handleWebhook($payload);
    }

    public function supports(string $gatewayKey): bool
    {
        $normalized = strtolower($gatewayKey);
        return isset($this->gateways[$normalized]);
    }

    private function getGateway(string $gatewayKey): AbstractGatewayService
    {
        $normalized = strtolower($gatewayKey);
        if (!$this->supports($normalized)) {
            throw new PaymentProcessingException(sprintf('Unsupported gateway "%s"', $gatewayKey));
        }
        return $this->gateways[$normalized];
    }
}


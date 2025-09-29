<?php

namespace OpenEMR\Services\Checkout;

use OpenEMR\Services\Checkout\Exception\PaymentProcessingException;
use Psr\Log\LoggerInterface;

class CheckoutService
{
    public function __construct(
        private PaymentGatewayManager $gatewayManager,
        private PaymentLedgerService $ledgerService,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function processPayments(
        int $patientId,
        int $encounterId,
        array $payments,
        string $serviceDate,
        string $postTimestamp,
        array $context
    ): array {
        $results = array();
        foreach ($payments as $payment) {
            $amount = isset($payment['amount']) ? (float)$payment['amount'] : 0.0;
            if ($amount <= 0) {
                continue;
            }
            $method = $payment['method'] ?? 'cash';
            $gateway = $payment['gateway'] ?? null;
            $reference = $payment['reference'] ?? '';
            $installments = isset($payment['installments']) ? (int)$payment['installments'] : 1;
            $sessionId = $this->createSession(
                $patientId,
                $serviceDate,
                $amount,
                $reference,
                $method
            );
            $this->createActivity(
                $sessionId,
                $patientId,
                $encounterId,
                $amount,
                $context['code_type'] ?? '',
                $context['code'] ?? '',
                $context['modifier'] ?? ''
            );
            $metadata = array(
                'reference' => $reference,
                'installments' => $installments,
                'gateway' => $gateway,
                'session_id' => $sessionId,
                'method' => $method
            );
            if (!empty($gateway)) {
                try {
                    $gatewayResponse = $this->gatewayManager->dispatchPayment($gateway, array(
                        'session_id' => $sessionId,
                        'patient_id' => $patientId,
                        'encounter_id' => $encounterId,
                        'amount' => $amount,
                        'reference' => $reference,
                        'installments' => $installments
                    ));
                    $metadata['gateway_response'] = $gatewayResponse;
                } catch (\Exception $exception) {
                    if ($this->logger) {
                        $this->logger->error('Gateway dispatch failed', ['exception' => $exception]);
                    }
                    throw new PaymentProcessingException($exception->getMessage(), 0, $exception);
                }
            }
            $this->ledgerService->recordPayment(
                $sessionId,
                $patientId,
                $encounterId,
                $method,
                $gateway,
                $installments,
                $amount,
                $metadata,
                $postTimestamp
            );
            $results[] = array(
                'session_id' => $sessionId,
                'amount' => $amount,
                'method' => $method
            );
        }
        if (empty($results)) {
            throw new PaymentProcessingException('No valid payments were provided for processing.');
        }
        return $results;
    }

    private function createSession(
        int $patientId,
        string $serviceDate,
        float $amount,
        string $reference,
        string $method
    ): int {
        $sql = "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total,global_amount,payment_type,description,patient_id,payment_method,adjustment_code,post_to_date) "
            . " VALUES ('0',?,?,now(),?,?,'','patient','COPAY',?,?,'patient_payment',now())";
        return \sqlInsert($sql, array(
            $_SESSION['authUserID'],
            $reference,
            $serviceDate,
            $amount,
            $patientId,
            $method
        ));
    }

    private function createActivity(
        int $sessionId,
        int $patientId,
        int $encounterId,
        float $amount,
        string $codeType,
        string $code,
        string $modifier
    ): void {
        \sqlBeginTrans();
        $sequence = \sqlQuery(
            "SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?",
            array($patientId, $encounterId)
        );
        $sql = "INSERT INTO ar_activity (pid,encounter,sequence_no,code_type,code,modifier,payer_type,post_time,post_user,session_id,pay_amount,account_code)"
            . " VALUES (?,?,?,?,?,?,0,?,?,?,?,'PCP')";
        \sqlInsert(
            $sql,
            array(
                $patientId,
                $encounterId,
                $sequence['increment'],
                $codeType,
                $code,
                $modifier,
                date('Y-m-d H:i:s'),
                $_SESSION['authUserID'],
                $sessionId,
                $amount
            )
        );
        \sqlCommitTrans();
    }
}


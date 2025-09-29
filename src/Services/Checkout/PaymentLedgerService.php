<?php

namespace OpenEMR\Services\Checkout;

use DateTimeImmutable;
use OpenEMR\Common\Logging\SystemLogger;

class PaymentLedgerService
{
    private static bool $schemaInitialized = false;

    public function __construct(private ?SystemLogger $logger = null)
    {
        $this->initializeSchema();
    }

    public function recordPayment(
        ?int $sessionId,
        int $patientId,
        int $encounterId,
        string $method,
        ?string $gateway,
        int $installments,
        float $amount,
        array $metadata,
        string $timestamp
    ): void {
        $this->initializeSchema();
        $createdAt = new DateTimeImmutable($timestamp);
        $meta = json_encode($metadata);
        $sql = "INSERT INTO ar_payment_ledger (session_id, patient_id, encounter_id, payment_method, gateway, installments, amount, created_at, metadata) "
            . "VALUES (?,?,?,?,?,?,?,?,?)";
        try {
            \sqlStatement($sql, array(
                $sessionId,
                $patientId,
                $encounterId,
                $method,
                $gateway,
                max(1, $installments),
                $amount,
                $createdAt->format('Y-m-d H:i:s'),
                $meta
            ));
        } catch (\Exception $exception) {
            if ($this->logger) {
                $this->logger->error('Failed to record payment ledger entry', ['exception' => $exception]);
            }
            throw $exception;
        }
    }

    private function initializeSchema(): void
    {
        if (self::$schemaInitialized) {
            return;
        }
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ar_payment_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    encounter_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(64) NOT NULL,
    gateway VARCHAR(64) DEFAULT NULL,
    installments INT UNSIGNED DEFAULT 1,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL,
    metadata TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_patient (patient_id),
    KEY idx_encounter (encounter_id),
    KEY idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        \sqlStatement($sql);
        self::$schemaInitialized = true;
    }
}


<?php

namespace OpenEMR\Services\Checkout\Gateway;

abstract class AbstractGatewayService
{
    private static bool $schemaInitialized = false;

    public function __construct(protected ?\Psr\Log\LoggerInterface $logger = null)
    {
        $this->ensureSchema();
    }

    abstract public function dispatch(array $payload): array;

    abstract public function handleWebhook(array $payload): array;

    protected function ensureSchema(): void
    {
        if (self::$schemaInitialized) {
            return;
        }
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS payment_gateway_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NULL,
    gateway VARCHAR(64) NOT NULL,
    external_id VARCHAR(128) DEFAULT NULL,
    status VARCHAR(32) DEFAULT 'pending',
    payload TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_gateway (gateway),
    KEY idx_external (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        \sqlStatement($sql);
        self::$schemaInitialized = true;
    }

    protected function persistTransaction(string $gateway, ?int $sessionId, string $externalId, string $status, array $payload): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = \sqlQuery(
            "SELECT id FROM payment_gateway_transactions WHERE gateway = ? AND external_id = ? LIMIT 1",
            array($gateway, $externalId)
        );
        if (!empty($existing['id'])) {
            \sqlStatement(
                "UPDATE payment_gateway_transactions SET status = ?, payload = ?, updated_at = ? WHERE id = ?",
                array($status, json_encode($payload), $now, $existing['id'])
            );
        } else {
            \sqlStatement(
                "INSERT INTO payment_gateway_transactions (session_id, gateway, external_id, status, payload, created_at, updated_at) VALUES (?,?,?,?,?,?,?)",
                array($sessionId, $gateway, $externalId, $status, json_encode($payload), $now, $now)
            );
        }
    }
}


<?php

/**
 * PackageService provides helper methods to create, update and query treatment packages
 * for the Saúde & Estética workflows. The service owns the database schema lifecycle for
 * the package catalog, subscription tracking and session/payment logs so that other
 * presentation layers do not need to worry about schema initialization.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Aesthetic;

use DateInterval;
use DateTime;
use Exception;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Validators\ProcessingResult;

/**
 * Service facade responsible for all the persistence and orchestration logic that supports
 * selling treatment packages, tracking recurring payments and keeping a detailed log of the
 * sessions delivered to a patient.
 */
class PackageService
{
    private const TABLE_CATALOG = 'package_catalog';
    private const TABLE_SUBSCRIPTION = 'package_subscription';
    private const TABLE_SESSION_LOG = 'package_session_log';

    private const PERIODICITY_UNITS = ['day', 'week', 'month', 'year'];

    /**
     * Ensures that the storage tables exist. This method is idempotent and can safely be called
     * whenever the service is constructed.
     */
    public function ensureSchema(): void
    {
        $this->createCatalogTable();
        $this->createSubscriptionTable();
        $this->createSessionLogTable();
    }

    /**
     * Returns the catalog packages.
     *
     * @param bool $activeOnly Whether to limit the results to active packages.
     * @return array<int, array<string, mixed>>
     */
    public function listPackages(bool $activeOnly = false): array
    {
        $this->ensureSchema();
        $sql = 'SELECT pkg.* FROM `' . self::TABLE_CATALOG . '` pkg';
        $binds = [];
        if ($activeOnly) {
            $sql .= ' WHERE pkg.`is_active` = ?';
            $binds[] = 1;
        }
        $sql .= ' ORDER BY pkg.`updated_at` DESC';

        return QueryUtils::fetchRecords($sql, $binds, true);
    }

    /**
     * Returns a package row.
     */
    public function getPackage(int $packageId): ?array
    {
        $this->ensureSchema();
        $sql = 'SELECT * FROM `' . self::TABLE_CATALOG . '` WHERE `package_id` = ?';
        $record = QueryUtils::fetchRecords($sql, [$packageId], true);
        return $record[0] ?? null;
    }

    /**
     * Creates or updates a package catalog entry.
     */
    public function savePackage(array $payload): ProcessingResult
    {
        $this->ensureSchema();
        $result = new ProcessingResult();
        $now = date('Y-m-d H:i:s');
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            $result->setValidationMessages(['name' => xlt('A package name is required.')]);
            return $result;
        }

        $basePrice = $this->parseMoney($payload['base_price'] ?? null);
        if ($basePrice === null) {
            $result->setValidationMessages(['base_price' => xlt('A valid base price is required.')]);
            return $result;
        }

        $promoPrice = $this->parseMoney($payload['promo_price'] ?? null);
        $promoStart = $this->parseDate($payload['promo_start_date'] ?? null);
        $promoEnd = $this->parseDate($payload['promo_end_date'] ?? null);
        if ($promoPrice !== null && $promoStart === null) {
            $result->setValidationMessages(['promo_start_date' => xlt('A promotional price requires a start date.')]);
            return $result;
        }
        if ($promoPrice !== null && $promoEnd !== null && $promoStart !== null && $promoEnd < $promoStart) {
            $result->setValidationMessages(['promo_end_date' => xlt('Promotion end date must be after the start date.')]);
            return $result;
        }

        $periodicityUnit = strtolower(trim((string)($payload['periodicity_unit'] ?? 'month')));
        $periodicityCount = max(1, (int)($payload['periodicity_count'] ?? 1));
        if (!in_array($periodicityUnit, self::PERIODICITY_UNITS)) {
            $result->setValidationMessages(['periodicity_unit' => xlt('Invalid periodicity unit.')]);
            return $result;
        }

        $sessionCount = null;
        if (isset($payload['session_count']) && $payload['session_count'] !== '') {
            $sessionCount = max(0, (int)$payload['session_count']);
        }

        $installments = $this->normalizeInstallments($payload);
        if (!$installments['valid']) {
            $result->setValidationMessages($installments['errors']);
            return $result;
        }

        $record = [
            'package_code' => $this->nullIfEmpty($payload['package_code'] ?? null),
            'name' => $name,
            'description' => $this->nullIfEmpty($payload['description'] ?? null),
            'base_price' => $basePrice,
            'promo_price' => $promoPrice,
            'promo_start_date' => $promoStart ? $promoStart->format('Y-m-d') : null,
            'promo_end_date' => $promoEnd ? $promoEnd->format('Y-m-d') : null,
            'periodicity_unit' => $periodicityUnit,
            'periodicity_count' => $periodicityCount,
            'session_count' => $sessionCount,
            'installment_options' => !empty($installments['data']) ? json_encode($installments['data']) : null,
            'is_active' => !empty($payload['is_active']) ? 1 : 0,
            'metadata' => $this->encodeMetadata($payload['metadata'] ?? []),
            'updated_at' => $now,
        ];

        $packageId = isset($payload['package_id']) ? (int)$payload['package_id'] : 0;
        if ($packageId > 0) {
            $set = [];
            $binds = [];
            foreach ($record as $column => $value) {
                $set[] = "`$column` = ?";
                $binds[] = $value;
            }
            $binds[] = $packageId;
            QueryUtils::sqlStatementThrowException(
                'UPDATE `' . self::TABLE_CATALOG . '` SET ' . implode(', ', $set) . ' WHERE `package_id` = ?',
                $binds,
                true
            );
            $result->addData(array_merge(['package_id' => $packageId], $record));
        } else {
            $createdAt = $now;
            $values = [
                $record['package_code'],
                $record['name'],
                $record['description'],
                $record['base_price'],
                $record['promo_price'],
                $record['promo_start_date'],
                $record['promo_end_date'],
                $record['periodicity_unit'],
                $record['periodicity_count'],
                $record['session_count'],
                $record['installment_options'],
                $record['is_active'],
                $record['metadata'],
                $createdAt,
                $record['updated_at'],
            ];
            $newId = QueryUtils::sqlInsert(
                'INSERT INTO `' . self::TABLE_CATALOG . '` (`package_code`, `name`, `description`, `base_price`, `promo_price`, '
                . '`promo_start_date`, `promo_end_date`, `periodicity_unit`, `periodicity_count`, `session_count`, `installment_options`, '
                . '`is_active`, `metadata`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $values
            );
            $result->addData(array_merge(['package_id' => $newId], $record, ['created_at' => $createdAt]));
        }

        return $result;
    }

    /**
     * Deletes a package from the catalog.
     */
    public function deletePackage(int $packageId): void
    {
        $this->ensureSchema();
        QueryUtils::sqlStatementThrowException(
            'DELETE FROM `' . self::TABLE_CATALOG . '` WHERE `package_id` = ?',
            [$packageId],
            true
        );
    }

    /**
     * Creates a patient subscription to a catalog package.
     */
    public function createSubscription(array $payload): ProcessingResult
    {
        $this->ensureSchema();
        $result = new ProcessingResult();
        $now = date('Y-m-d H:i:s');

        $patientId = (int)($payload['patient_id'] ?? 0);
        $packageId = (int)($payload['package_id'] ?? 0);
        if ($patientId <= 0 || $packageId <= 0) {
            $result->setValidationMessages(['subscription' => xlt('A patient and a package are required.')]);
            return $result;
        }

        $package = $this->getPackage($packageId);
        if (empty($package)) {
            $result->setValidationMessages(['package_id' => xlt('Package not found.')]);
            return $result;
        }

        $startDate = $this->parseDate($payload['start_date'] ?? null) ?? new DateTime();
        $status = in_array(($payload['status'] ?? 'active'), ['active', 'paused', 'completed', 'cancelled'])
            ? $payload['status']
            : 'active';

        $installmentSelection = $payload['installment_plan'] ?? null;
        if (is_array($installmentSelection)) {
            $installmentSelection = json_encode($installmentSelection);
        }

        $totalSessions = $payload['total_sessions'] ?? $package['session_count'] ?? null;
        $totalSessions = $totalSessions !== null ? max(0, (int)$totalSessions) : null;

        $autoBill = !empty($payload['auto_bill']) ? 1 : 0;
        $billingUnit = $payload['billing_cycle_unit'] ?? $package['periodicity_unit'];
        $billingCount = $payload['billing_cycle_count'] ?? $package['periodicity_count'];
        if ($billingUnit && !in_array($billingUnit, self::PERIODICITY_UNITS)) {
            $billingUnit = $package['periodicity_unit'];
        }
        $billingCount = $billingCount ? max(1, (int)$billingCount) : $package['periodicity_count'];

        $nextSessionDate = $this->formatDate($payload['next_session_date'] ?? null);
        if ($nextSessionDate === null) {
            $nextSessionDate = $startDate->format('Y-m-d');
        }

        $record = [
            'package_id' => $packageId,
            'patient_id' => $patientId,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $this->formatDate($payload['end_date'] ?? null),
            'status' => $status,
            'total_sessions' => $totalSessions,
            'installment_selection' => $this->nullIfEmpty($installmentSelection),
            'total_amount' => $this->parseMoney($payload['total_amount'] ?? $package['base_price']),
            'promo_price' => $this->parseMoney($payload['promo_price'] ?? $package['promo_price']),
            'recurring_amount' => $this->parseMoney($payload['recurring_amount'] ?? null),
            'next_session_date' => $nextSessionDate,
            'next_billing_date' => $this->formatDate($payload['next_billing_date'] ?? null),
            'billing_cycle_unit' => $billingUnit,
            'billing_cycle_count' => $billingCount,
            'auto_bill' => $autoBill,
            'pos_customer_reference' => $this->nullIfEmpty($payload['pos_customer_reference'] ?? null),
            'pos_agreement_reference' => $this->nullIfEmpty($payload['pos_agreement_reference'] ?? null),
            'gateway_identifier' => $this->nullIfEmpty($payload['gateway_identifier'] ?? null),
            'receipt_email' => $this->nullIfEmpty($payload['receipt_email'] ?? null),
            'metadata' => $this->encodeMetadata($payload['metadata'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $insertId = QueryUtils::sqlInsert(
            'INSERT INTO `' . self::TABLE_SUBSCRIPTION . '` (`package_id`, `patient_id`, `start_date`, `end_date`, `status`, '
            . '`total_sessions`, `installment_selection`, `total_amount`, `promo_price`, `recurring_amount`, `next_session_date`, '
            . '`next_billing_date`, `billing_cycle_unit`, `billing_cycle_count`, `auto_bill`, `pos_customer_reference`, '
            . '`pos_agreement_reference`, `gateway_identifier`, `receipt_email`, `metadata`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($record)
        );

        $result->addData(array_merge(['subscription_id' => $insertId], $record));
        return $result;
    }

    /**
     * Updates subscription metadata such as the next billing date or status.
     */
    public function updateSubscription(int $subscriptionId, array $payload): void
    {
        $this->ensureSchema();
        $fields = [];
        $binds = [];
        $allowed = [
            'status', 'end_date', 'next_session_date', 'next_billing_date', 'billing_cycle_unit', 'billing_cycle_count',
            'auto_bill', 'recurring_amount', 'pos_customer_reference', 'pos_agreement_reference', 'gateway_identifier',
            'receipt_email', 'metadata'
        ];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }

            $value = $payload[$column];
            switch ($column) {
                case 'end_date':
                case 'next_session_date':
                case 'next_billing_date':
                    $value = $this->formatDate($value);
                    break;
                case 'billing_cycle_unit':
                    if ($value && !in_array($value, self::PERIODICITY_UNITS)) {
                        continue 2;
                    }
                    break;
                case 'billing_cycle_count':
                    $value = $value !== null ? max(1, (int)$value) : null;
                    break;
                case 'auto_bill':
                    $value = !empty($value) ? 1 : 0;
                    break;
                case 'metadata':
                    $value = $this->encodeMetadata($value ?? []);
                    break;
                default:
                    $value = $this->nullIfEmpty($value);
            }

            $fields[] = "`$column` = ?";
            $binds[] = $value;
        }

        if (empty($fields)) {
            return;
        }

        $fields[] = '`updated_at` = ?';
        $binds[] = date('Y-m-d H:i:s');
        $binds[] = $subscriptionId;

        QueryUtils::sqlStatementThrowException(
            'UPDATE `' . self::TABLE_SUBSCRIPTION . '` SET ' . implode(', ', $fields) . ' WHERE `subscription_id` = ?',
            $binds,
            true
        );
    }

    /**
     * Returns a list of subscriptions for a patient with aggregated usage counters.
     */
    public function getSubscriptionsForPatient(int $patientId): array
    {
        $this->ensureSchema();
        $sql = 'SELECT sub.*, pkg.`name` AS package_name, pkg.`periodicity_unit`, pkg.`periodicity_count`, pkg.`session_count`, '
            . 'pkg.`installment_options`, pkg.`base_price` AS package_base_price, pkg.`promo_price` AS package_promo_price'
            . ' FROM `' . self::TABLE_SUBSCRIPTION . '` sub'
            . ' INNER JOIN `' . self::TABLE_CATALOG . '` pkg ON pkg.`package_id` = sub.`package_id`'
            . ' WHERE sub.`patient_id` = ?'
            . ' ORDER BY sub.`created_at` DESC';
        $rows = QueryUtils::fetchRecords($sql, [$patientId], true);
        foreach ($rows as &$row) {
            $row['installment_options_decoded'] = $this->decodeJson($row['installment_selection']);
            $row['package_installments'] = $this->decodeJson($row['installment_options']);
            $row['usage'] = $this->getSubscriptionUsage((int)$row['subscription_id']);
            $row['next_session_suggestion'] = $this->getNextSuggestedSessionDate($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * Returns the aggregated session log entries for a subscription.
     */
    public function getSessionLogs(int $subscriptionId): array
    {
        $this->ensureSchema();
        $sql = 'SELECT log.* FROM `' . self::TABLE_SESSION_LOG . '` log'
            . ' WHERE log.`subscription_id` = ?'
            . ' ORDER BY log.`session_date` DESC, log.`created_at` DESC';
        return QueryUtils::fetchRecords($sql, [$subscriptionId], true);
    }

    /**
     * Stores a session or payment event for a subscription.
     */
    public function logSubscriptionEvent(array $payload): ProcessingResult
    {
        $this->ensureSchema();
        $result = new ProcessingResult();
        $subscriptionId = (int)($payload['subscription_id'] ?? 0);
        if ($subscriptionId <= 0) {
            $result->setValidationMessages(['subscription_id' => xlt('A subscription is required.')]);
            return $result;
        }

        $logType = $payload['log_type'] ?? 'session';
        if (!in_array($logType, ['session', 'payment', 'note'], true)) {
            $result->setValidationMessages(['log_type' => xlt('Unsupported log entry type.')]);
            return $result;
        }

        $now = date('Y-m-d H:i:s');
        $sessionDate = $this->parseDateTime($payload['session_date'] ?? null);
        $status = $payload['status'] ?? ($logType === 'session' ? 'completed' : 'recorded');
        $duration = $payload['duration_minutes'] ?? null;
        $duration = $duration !== null && $duration !== '' ? max(0, (int)$duration) : null;
        $paymentAmount = $this->parseMoney($payload['payment_amount'] ?? null);

        $record = [
            'subscription_id' => $subscriptionId,
            'log_type' => $logType,
            'session_date' => $sessionDate ? $sessionDate->format('Y-m-d H:i:s') : null,
            'status' => $status,
            'provider_id' => $payload['provider_id'] !== '' ? (int)$payload['provider_id'] : null,
            'appointment_id' => $payload['appointment_id'] !== '' ? (int)$payload['appointment_id'] : null,
            'notes' => $this->nullIfEmpty($payload['notes'] ?? null),
            'duration_minutes' => $duration,
            'payment_amount' => $paymentAmount,
            'payment_reference' => $this->nullIfEmpty($payload['payment_reference'] ?? null),
            'receipt_reference' => $this->nullIfEmpty($payload['receipt_reference'] ?? null),
            'metadata' => $this->encodeMetadata($payload['metadata'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $insertId = QueryUtils::sqlInsert(
            'INSERT INTO `' . self::TABLE_SESSION_LOG . '` (`subscription_id`, `log_type`, `session_date`, `status`, `provider_id`, '
            . '`appointment_id`, `notes`, `duration_minutes`, `payment_amount`, `payment_reference`, `receipt_reference`, `metadata`, '
            . '`created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($record)
        );

        $result->addData(array_merge(['log_id' => $insertId], $record));
        return $result;
    }

    /**
     * Returns a short high level summary of a patient subscriptions.
     */
    public function getPatientSummary(int $patientId): array
    {
        $subscriptions = $this->getSubscriptionsForPatient($patientId);
        $active = array_filter($subscriptions, static function ($row) {
            return ($row['status'] ?? '') === 'active';
        });
        $upcoming = null;
        foreach ($subscriptions as $subscription) {
            $suggested = $subscription['next_session_suggestion'];
            if ($suggested === null) {
                continue;
            }
            if ($upcoming === null || $suggested < $upcoming['date']) {
                $upcoming = [
                    'date' => $suggested,
                    'package_name' => $subscription['package_name'],
                ];
            }
        }

        $paymentsDue = null;
        foreach ($subscriptions as $subscription) {
            $nextBilling = $subscription['next_billing_date'];
            if (!$nextBilling) {
                continue;
            }
            if ($paymentsDue === null || $nextBilling < $paymentsDue['date']) {
                $paymentsDue = [
                    'date' => $nextBilling,
                    'amount' => $subscription['recurring_amount'] ?: $subscription['total_amount'],
                    'package_name' => $subscription['package_name'],
                ];
            }
        }

        $recentSessions = $this->getRecentSessions($patientId);

        return [
            'active_count' => count($active),
            'total_subscriptions' => count($subscriptions),
            'upcoming_session' => $upcoming,
            'next_billing' => $paymentsDue,
            'recent_sessions' => $recentSessions,
        ];
    }

    /**
     * Convenience helper that records a POS payment in the session log and moves the billing cycle forward.
     */
    public function registerRecurringPayment(int $subscriptionId, float $amount, ?string $paidAt = null, array $metadata = []): void
    {
        $this->logSubscriptionEvent([
            'subscription_id' => $subscriptionId,
            'log_type' => 'payment',
            'session_date' => $paidAt ?? date('Y-m-d H:i:s'),
            'status' => 'paid',
            'payment_amount' => $amount,
            'payment_reference' => $metadata['payment_reference'] ?? null,
            'receipt_reference' => $metadata['receipt_reference'] ?? null,
            'notes' => $metadata['notes'] ?? null,
            'metadata' => $metadata,
        ]);

        if (!empty($metadata['next_billing_date'])) {
            $this->updateSubscription($subscriptionId, ['next_billing_date' => $metadata['next_billing_date']]);
        }
    }

    /**
     * Calculates how many sessions were consumed, scheduled and completed.
     */
    public function getSubscriptionUsage(int $subscriptionId): array
    {
        $this->ensureSchema();
        $sql = 'SELECT log.`log_type`, log.`status` FROM `' . self::TABLE_SESSION_LOG . '` log WHERE log.`subscription_id` = ?';
        $records = QueryUtils::fetchRecords($sql, [$subscriptionId], true);
        $usage = [
            'completed' => 0,
            'scheduled' => 0,
            'payments' => 0,
        ];
        foreach ($records as $record) {
            if ($record['log_type'] === 'session') {
                if ($record['status'] === 'completed') {
                    $usage['completed']++;
                } elseif ($record['status'] === 'scheduled') {
                    $usage['scheduled']++;
                }
            } elseif ($record['log_type'] === 'payment') {
                $usage['payments']++;
            }
        }
        return $usage;
    }

    /**
     * Determines the next recommended session datetime for a subscription.
     */
    public function getNextSuggestedSessionDate(array $subscription): ?string
    {
        $lastSession = $this->getLastSessionDate((int)$subscription['subscription_id']);
        $reference = $lastSession ?? $subscription['next_session_date'] ?? $subscription['start_date'];
        if (!$reference) {
            return null;
        }

        try {
            $date = new DateTime($reference);
        } catch (Exception $e) {
            return null;
        }

        $unit = $subscription['periodicity_unit'] ?? 'month';
        $count = $subscription['periodicity_count'] ?? 1;
        $intervalSpec = $this->buildIntervalSpec($unit, (int)$count);
        if ($intervalSpec === null) {
            return $date->format('Y-m-d');
        }

        $date->add(new DateInterval($intervalSpec));
        return $date->format('Y-m-d');
    }

    /**
     * Returns the list of installment plans stored in the catalog row.
     */
    public function getInstallmentOptions(array $package): array
    {
        $options = $this->decodeJson($package['installment_options'] ?? null);
        if (empty($options) || !is_array($options)) {
            return [];
        }
        return $options;
    }

    /**
     * Extracts recent session history for the patient to display in summary cards.
     */
    private function getRecentSessions(int $patientId): array
    {
        $sql = 'SELECT log.`log_id`, log.`session_date`, log.`status`, log.`notes`, pkg.`name` AS package_name'
            . ' FROM `' . self::TABLE_SESSION_LOG . '` log'
            . ' INNER JOIN `' . self::TABLE_SUBSCRIPTION . '` sub ON sub.`subscription_id` = log.`subscription_id`'
            . ' INNER JOIN `' . self::TABLE_CATALOG . '` pkg ON pkg.`package_id` = sub.`package_id`'
            . ' WHERE sub.`patient_id` = ? AND log.`log_type` = ?'
            . ' ORDER BY log.`session_date` DESC, log.`created_at` DESC'
            . ' LIMIT 5';
        return QueryUtils::fetchRecords($sql, [$patientId, 'session'], true);
    }

    private function getLastSessionDate(int $subscriptionId): ?string
    {
        $sql = 'SELECT log.`session_date` FROM `' . self::TABLE_SESSION_LOG . '` log'
            . ' WHERE log.`subscription_id` = ? AND log.`log_type` = ? AND log.`status` = ?'
            . ' ORDER BY log.`session_date` DESC, log.`log_id` DESC LIMIT 1';
        $value = QueryUtils::fetchSingleValue($sql, 'session_date', [$subscriptionId, 'session', 'completed']);
        return $value ?: null;
    }

    private function parseMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = preg_replace('/[^0-9.,-]/', '', (string)$value);
        if ($normalized === '') {
            return null;
        }
        $normalized = str_replace(',', '.', $normalized);
        if (substr_count($normalized, '.') > 1) {
            $parts = explode('.', $normalized);
            $decimal = array_pop($parts);
            $normalized = implode('', $parts) . '.' . $decimal;
        }
        return (float)$normalized;
    }

    private function parseDate($value): ?DateTime
    {
        if (!$value) {
            return null;
        }
        try {
            return new DateTime((string)$value);
        } catch (Exception $e) {
            return null;
        }
    }

    private function parseDateTime($value): ?DateTime
    {
        if (!$value) {
            return null;
        }
        try {
            return new DateTime((string)$value);
        } catch (Exception $e) {
            return null;
        }
    }

    private function formatDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = $this->parseDate($value);
        return $date ? $date->format('Y-m-d') : null;
    }

    private function nullIfEmpty($value)
    {
        if ($value === null) {
            return null;
        }
        $value = is_string($value) ? trim($value) : $value;
        if ($value === '' || $value === []) {
            return null;
        }
        return $value;
    }

    private function normalizeInstallments(array $payload): array
    {
        $options = $payload['installments'] ?? [];
        if (empty($options) && isset($payload['installment_counts'])) {
            $options = [];
            $counts = $payload['installment_counts'];
            $amounts = $payload['installment_amounts'] ?? [];
            $descriptions = $payload['installment_descriptions'] ?? [];
            foreach ((array)$counts as $index => $count) {
                $count = (int)$count;
                $amount = $amounts[$index] ?? null;
                if ($count <= 0 || $amount === null || $amount === '') {
                    continue;
                }
                $options[] = [
                    'installments' => $count,
                    'amount' => $amount,
                    'description' => trim((string)($descriptions[$index] ?? '')),
                ];
            }
        }

        $errors = [];
        $normalized = [];
        foreach ((array)$options as $option) {
            $count = (int)($option['installments'] ?? 0);
            if ($count <= 0) {
                $errors['installments'] = xlt('Installment count must be greater than zero.');
                continue;
            }
            $amount = $this->parseMoney($option['amount'] ?? null);
            if ($amount === null || $amount <= 0) {
                $errors['installments'] = xlt('Installment amount must be a positive value.');
                continue;
            }
            $normalized[] = [
                'installments' => $count,
                'amount' => $amount,
                'description' => $this->nullIfEmpty($option['description'] ?? null),
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $normalized,
        ];
    }

    private function encodeMetadata($metadata): ?string
    {
        if (empty($metadata)) {
            return null;
        }
        if (!is_array($metadata)) {
            return json_encode(['value' => $metadata]);
        }
        return json_encode($metadata);
    }

    private function decodeJson($value)
    {
        if (!$value) {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildIntervalSpec(string $unit, int $count): ?string
    {
        $count = max(1, $count);
        switch ($unit) {
            case 'day':
                return 'P' . $count . 'D';
            case 'week':
                return 'P' . $count . 'W';
            case 'month':
                return 'P' . $count . 'M';
            case 'year':
                return 'P' . $count . 'Y';
            default:
                return null;
        }
    }

    private function createCatalogTable(): void
    {
        if (QueryUtils::existsTable(self::TABLE_CATALOG)) {
            return;
        }
        $sql = sprintf(
            'CREATE TABLE `%s` ('
            . ' `package_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `package_code` VARCHAR(50) DEFAULT NULL,'
            . ' `name` VARCHAR(120) NOT NULL,'
            . ' `description` TEXT DEFAULT NULL,'
            . ' `base_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,'
            . ' `promo_price` DECIMAL(12,2) DEFAULT NULL,'
            . ' `promo_start_date` DATE DEFAULT NULL,'
            . ' `promo_end_date` DATE DEFAULT NULL,'
            . ' `periodicity_unit` ENUM("day","week","month","year") NOT NULL DEFAULT "month",'
            . ' `periodicity_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,'
            . ' `session_count` SMALLINT UNSIGNED DEFAULT NULL,'
            . ' `installment_options` LONGTEXT DEFAULT NULL,'
            . ' `is_active` TINYINT(1) NOT NULL DEFAULT 1,'
            . ' `metadata` LONGTEXT DEFAULT NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`package_id`),'
            . ' UNIQUE KEY `uniq_package_code` (`package_code`),'
            . ' KEY `idx_package_active` (`is_active`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::TABLE_CATALOG
        );
        \sqlStatementNoLog($sql);
    }

    private function createSubscriptionTable(): void
    {
        if (QueryUtils::existsTable(self::TABLE_SUBSCRIPTION)) {
            return;
        }
        $sql = sprintf(
            'CREATE TABLE `%s` ('
            . ' `subscription_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `package_id` INT UNSIGNED NOT NULL,'
            . ' `patient_id` BIGINT UNSIGNED NOT NULL,'
            . ' `start_date` DATE NOT NULL,'
            . ' `end_date` DATE DEFAULT NULL,'
            . ' `status` ENUM("active","paused","completed","cancelled") NOT NULL DEFAULT "active",'
            . ' `total_sessions` SMALLINT UNSIGNED DEFAULT NULL,'
            . ' `installment_selection` LONGTEXT DEFAULT NULL,'
            . ' `total_amount` DECIMAL(12,2) DEFAULT NULL,'
            . ' `promo_price` DECIMAL(12,2) DEFAULT NULL,'
            . ' `recurring_amount` DECIMAL(12,2) DEFAULT NULL,'
            . ' `next_session_date` DATE DEFAULT NULL,'
            . ' `next_billing_date` DATE DEFAULT NULL,'
            . ' `billing_cycle_unit` ENUM("day","week","month","year") DEFAULT NULL,'
            . ' `billing_cycle_count` TINYINT UNSIGNED DEFAULT NULL,'
            . ' `auto_bill` TINYINT(1) NOT NULL DEFAULT 0,'
            . ' `pos_customer_reference` VARCHAR(191) DEFAULT NULL,'
            . ' `pos_agreement_reference` VARCHAR(191) DEFAULT NULL,'
            . ' `gateway_identifier` VARCHAR(191) DEFAULT NULL,'
            . ' `receipt_email` VARCHAR(191) DEFAULT NULL,'
            . ' `metadata` LONGTEXT DEFAULT NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`subscription_id`),'
            . ' KEY `idx_subscription_patient` (`patient_id`),'
            . ' KEY `idx_subscription_status` (`status`),'
            . ' CONSTRAINT `fk_subscription_package` FOREIGN KEY (`package_id`) REFERENCES `%s` (`package_id`)'
            . '   ON DELETE CASCADE ON UPDATE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::TABLE_SUBSCRIPTION,
            self::TABLE_CATALOG
        );
        \sqlStatementNoLog($sql);
    }

    private function createSessionLogTable(): void
    {
        if (QueryUtils::existsTable(self::TABLE_SESSION_LOG)) {
            return;
        }
        $sql = sprintf(
            'CREATE TABLE `%s` ('
            . ' `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `subscription_id` INT UNSIGNED NOT NULL,'
            . ' `log_type` ENUM("session","payment","note") NOT NULL DEFAULT "session",'
            . ' `session_date` DATETIME DEFAULT NULL,'
            . ' `status` VARCHAR(40) DEFAULT NULL,'
            . ' `provider_id` INT DEFAULT NULL,'
            . ' `appointment_id` INT DEFAULT NULL,'
            . ' `notes` TEXT DEFAULT NULL,'
            . ' `duration_minutes` SMALLINT UNSIGNED DEFAULT NULL,'
            . ' `payment_amount` DECIMAL(12,2) DEFAULT NULL,'
            . ' `payment_reference` VARCHAR(191) DEFAULT NULL,'
            . ' `receipt_reference` VARCHAR(191) DEFAULT NULL,'
            . ' `metadata` LONGTEXT DEFAULT NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`log_id`),'
            . ' KEY `idx_session_subscription` (`subscription_id`),'
            . ' KEY `idx_session_type` (`log_type`),'
            . ' KEY `idx_session_date` (`session_date`),'
            . ' CONSTRAINT `fk_session_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `%s` (`subscription_id`)'
            . '   ON DELETE CASCADE ON UPDATE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::TABLE_SESSION_LOG,
            self::TABLE_SUBSCRIPTION
        );
        \sqlStatementNoLog($sql);
    }
}

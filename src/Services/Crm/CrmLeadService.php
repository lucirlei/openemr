<?php

/**
 * CRM Lead Service
 *
 * Provides high level orchestration for the Saúde & Estética CRM module, including
 * lead persistence, pipeline metrics and loyalty scoring utilities.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Crm;

use Exception;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\BaseService;
use OpenEMR\Validators\ProcessingResult;
use Particle\Validator\Validator;
use Ramsey\Uuid\Uuid;

class CrmLeadService extends BaseService
{
    public function __construct()
    {
        parent::__construct('crm_leads');
    }

    /**
     * Creates a new lead record with validation and returns the saved row.
     */
    public function createLead(array $lead): ProcessingResult
    {
        $result = new ProcessingResult();
        $validator = $this->getValidator();
        $validation = $validator->validate($lead);

        if (!$validation->isValid()) {
            $result->setValidationMessages($validation->getMessages());
            return $result;
        }

        $uuid = $lead['uuid'] ?? Uuid::uuid4()->toString();

        $sql = "INSERT INTO crm_leads (uuid, full_name, email, phone, status, source, pipeline_stage, owner_id, campaign_id, patient_id, loyalty_points, notes)"
            . " VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";

        $bind = [
            $uuid,
            trim($lead['full_name']),
            $lead['email'] ?? null,
            $lead['phone'] ?? null,
            $lead['status'] ?? 'new',
            $lead['source'] ?? null,
            $lead['pipeline_stage'] ?? 'captured',
            $lead['owner_id'] ?? null,
            $lead['campaign_id'] ?? null,
            $lead['patient_id'] ?? null,
            $lead['loyalty_points'] ?? 0,
            $lead['notes'] ?? null,
        ];

        try {
            sqlInsert($sql, $bind);
            $row = $this->getLeadByUuid($uuid);
            if (!empty($row)) {
                $result->addData($row);
            }
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    /**
     * Fetches a lead row by uuid.
     */
    public function getLeadByUuid(string $uuid): ?array
    {
        $sql = "SELECT l.*, c.name AS campaign_name, c.uuid AS campaign_uuid"
            . " FROM crm_leads l"
            . " LEFT JOIN crm_campaigns c ON c.id = l.campaign_id"
            . " WHERE l.uuid = ?";

        $row = sqlQuery($sql, [$uuid]);
        if (empty($row)) {
            return null;
        }

        return $this->normalizeLeadRow($row);
    }

    /**
     * Updates a lead identified by uuid.
     */
    public function updateLead(string $uuid, array $changes): ProcessingResult
    {
        $result = new ProcessingResult();
        $existing = $this->getLeadByUuid($uuid);
        if (empty($existing)) {
            $result->setValidationMessages(['uuid' => xlt('Lead not found')]);
            return $result;
        }

        $allowedFields = ['full_name', 'email', 'phone', 'status', 'source', 'pipeline_stage', 'owner_id', 'campaign_id', 'patient_id', 'loyalty_points', 'notes'];
        $setParts = [];
        $bind = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $changes)) {
                $setParts[] = "$field = ?";
                $bind[] = $changes[$field];
            }
        }

        if (empty($setParts)) {
            $result->addData($existing);
            return $result;
        }

        $sql = 'UPDATE crm_leads SET ' . implode(', ', $setParts) . ', updated_at = NOW() WHERE uuid = ?';
        $bind[] = $uuid;

        try {
            sqlStatement($sql, $bind);
            $result->addData($this->getLeadByUuid($uuid));
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    /**
     * Returns a filtered list of leads.
     */
    public function listLeads(array $filters = []): ProcessingResult
    {
        $result = new ProcessingResult();

        $sql = "SELECT l.*, c.name AS campaign_name, c.uuid AS campaign_uuid"
            . " FROM crm_leads l"
            . " LEFT JOIN crm_campaigns c ON c.id = l.campaign_id"
            . " WHERE 1=1";

        $bind = [];

        if (!empty($filters['status'])) {
            $sql .= " AND l.status = ?";
            $bind[] = $filters['status'];
        }
        if (!empty($filters['pipeline_stage'])) {
            $sql .= " AND l.pipeline_stage = ?";
            $bind[] = $filters['pipeline_stage'];
        }
        if (!empty($filters['owner_id'])) {
            $sql .= " AND l.owner_id = ?";
            $bind[] = (int)$filters['owner_id'];
        }
        if (!empty($filters['campaign_uuid'])) {
            $sql .= " AND c.uuid = ?";
            $bind[] = $filters['campaign_uuid'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (l.full_name LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            array_push($bind, $search, $search, $search);
        }

        $sql .= " ORDER BY l.created_at DESC";
        if (!empty($filters['limit'])) {
            $limit = max(1, (int) $filters['limit']);
            $sql .= " LIMIT " . $limit;
        }

        try {
            $statement = sqlStatement($sql, $bind);
            while ($row = sqlFetchArray($statement)) {
                $result->addData($this->normalizeLeadRow($row));
            }
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    /**
     * Groups leads by pipeline stage for dashboard metrics.
     */
    public function getPipelineSummary(): array
    {
        $sql = "SELECT pipeline_stage, COUNT(*) AS total FROM crm_leads GROUP BY pipeline_stage ORDER BY total DESC";
        return QueryUtils::fetchRecords($sql, []);
    }

    /**
     * Returns the top leads ordered by loyalty points.
     */
    public function getLoyaltyLeaderboard(int $limit = 10): array
    {
        $sql = "SELECT uuid, full_name, loyalty_points, status FROM crm_leads ORDER BY loyalty_points DESC, full_name ASC LIMIT ?";
        $records = QueryUtils::fetchRecords($sql, [$limit]);
        $leaderboard = [];
        foreach ($records as $record) {
            $leaderboard[] = [
                'uuid' => $record['uuid'],
                'full_name' => $record['full_name'],
                'loyalty_points' => (int) $record['loyalty_points'],
                'status' => $record['status'],
            ];
        }

        return $leaderboard;
    }

    /**
     * Adds loyalty points to a lead and persists a reward record.
     */
    public function awardPoints(string $leadUuid, int $points, string $reason, string $rewardType = 'manual'): ProcessingResult
    {
        $result = new ProcessingResult();
        $lead = $this->getLeadByUuid($leadUuid);
        if (empty($lead)) {
            $result->setValidationMessages(['uuid' => xlt('Lead not found')]);
            return $result;
        }

        $rewardService = new CrmRewardService();

        try {
            sqlStatement('UPDATE crm_leads SET loyalty_points = loyalty_points + ?, updated_at = NOW() WHERE uuid = ?', [$points, $leadUuid]);
            $rewardService->recordReward((int) $lead['id'], $rewardType, $points, $reason);
            $result->addData($this->getLeadByUuid($leadUuid));
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    /**
     * Returns aggregated CRM dashboard metrics.
     */
    public function getDashboardMetrics(): array
    {
        $totals = QueryUtils::fetchRecords('SELECT COUNT(*) AS total FROM crm_leads', []);
        $activeCampaigns = QueryUtils::fetchRecords('SELECT COUNT(*) AS total FROM crm_campaigns WHERE status IN (\'active\', \'scheduled\')', []);
        $rewardTotals = QueryUtils::fetchRecords('SELECT COALESCE(SUM(points),0) AS points FROM crm_rewards', []);

        return [
            'total_leads' => (int) ($totals[0]['total'] ?? 0),
            'active_campaigns' => (int) ($activeCampaigns[0]['total'] ?? 0),
            'reward_points' => (int) ($rewardTotals[0]['points'] ?? 0),
        ];
    }

    public function getStatusSummary(): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM crm_leads GROUP BY status ORDER BY total DESC';
        return QueryUtils::fetchRecords($sql, []);
    }

    public function getMonthlyLeadSummary(int $months = 6): array
    {
        $months = max(1, (int) $months);
        $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS total"
            . " FROM crm_leads"
            . " WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$months} MONTH)"
            . " GROUP BY period ORDER BY period ASC";
        return QueryUtils::fetchRecords($sql, []);
    }

    private function getValidator(): Validator
    {
        $validator = new Validator();
        $validator->required('full_name')->lengthBetween(2, 150);
        $validator->optional('email')->email();
        $validator->optional('phone')->lengthBetween(2, 40);
        $validator->optional('status')->lengthBetween(2, 40);
        $validator->optional('source')->lengthBetween(2, 80);
        $validator->optional('pipeline_stage')->lengthBetween(2, 80);
        $validator->optional('owner_id')->numeric();
        $validator->optional('campaign_id')->numeric();
        $validator->optional('patient_id')->numeric();
        $validator->optional('loyalty_points')->numeric();
        return $validator;
    }

    private function normalizeLeadRow(array $row): array
    {
        $row['owner_id'] = $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
        $row['campaign_id'] = $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null;
        $row['patient_id'] = $row['patient_id'] !== null ? (int) $row['patient_id'] : null;
        $row['loyalty_points'] = (int) ($row['loyalty_points'] ?? 0);
        return $row;
    }
}


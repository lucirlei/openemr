<?php

/**
 * CRM Campaign Service
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

class CrmCampaignService extends BaseService
{
    public function __construct()
    {
        parent::__construct('crm_campaigns');
    }

    public function createCampaign(array $campaign): ProcessingResult
    {
        $result = new ProcessingResult();
        $validator = $this->buildValidator();
        $validation = $validator->validate($campaign);

        if (!$validation->isValid()) {
            $result->setValidationMessages($validation->getMessages());
            return $result;
        }

        $uuid = $campaign['uuid'] ?? Uuid::uuid4()->toString();

        $sql = "INSERT INTO crm_campaigns (uuid, name, status, start_date, end_date, budget, description, automation_config)"
            . " VALUES (?,?,?,?,?,?,?,?)";

        $bind = [
            $uuid,
            trim($campaign['name']),
            $campaign['status'] ?? 'draft',
            $campaign['start_date'] ?? null,
            $campaign['end_date'] ?? null,
            $campaign['budget'] ?? null,
            $campaign['description'] ?? null,
            isset($campaign['automation_config']) ? json_encode($campaign['automation_config']) : null,
        ];

        try {
            sqlInsert($sql, $bind);
            $row = $this->getCampaignByUuid($uuid);
            if (!empty($row)) {
                $result->addData($row);
            }
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    public function listCampaigns(array $filters = []): ProcessingResult
    {
        $result = new ProcessingResult();
        $sql = "SELECT * FROM crm_campaigns WHERE 1=1";
        $bind = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $bind[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE ? OR description LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            array_push($bind, $search, $search);
        }

        $sql .= " ORDER BY start_date DESC, created_at DESC";

        try {
            $statement = sqlStatement($sql, $bind);
            while ($row = sqlFetchArray($statement)) {
                $result->addData($this->normalizeCampaignRow($row));
            }
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    public function getCampaignByUuid(string $uuid): ?array
    {
        $row = sqlQuery('SELECT * FROM crm_campaigns WHERE uuid = ?', [$uuid]);
        if (empty($row)) {
            return null;
        }

        return $this->normalizeCampaignRow($row);
    }

    public function updateCampaign(string $uuid, array $data): ProcessingResult
    {
        $result = new ProcessingResult();
        $existing = $this->getCampaignByUuid($uuid);
        if (empty($existing)) {
            $result->setValidationMessages(['uuid' => xlt('Campaign not found')]);
            return $result;
        }

        $allowed = ['name', 'status', 'start_date', 'end_date', 'budget', 'description', 'automation_config'];
        $set = [];
        $bind = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'automation_config' && is_array($data[$field])) {
                    $set[] = "$field = ?";
                    $bind[] = json_encode($data[$field]);
                } else {
                    $set[] = "$field = ?";
                    $bind[] = $data[$field];
                }
            }
        }

        if (empty($set)) {
            $result->addData($existing);
            return $result;
        }

        $sql = 'UPDATE crm_campaigns SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE uuid = ?';
        $bind[] = $uuid;

        try {
            sqlStatement($sql, $bind);
            $result->addData($this->getCampaignByUuid($uuid));
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    public function getActiveCampaignOptions(): array
    {
        $records = QueryUtils::fetchRecords('SELECT uuid, name FROM crm_campaigns WHERE status IN (\'active\', \'scheduled\') ORDER BY name', []);
        $options = [];
        foreach ($records as $record) {
            $options[] = [
                'uuid' => $record['uuid'],
                'name' => $record['name'],
            ];
        }
        return $options;
    }

    public function getAutomationsDue(
        \DateTimeInterface $windowStart,
        \DateTimeInterface $windowEnd
    ): array {
        $sql = 'SELECT * FROM crm_campaigns WHERE automation_config IS NOT NULL AND status IN (\'active\', \'scheduled\')';
        $records = QueryUtils::fetchRecords($sql, []);
        $due = [];

        foreach ($records as $record) {
            $config = $record['automation_config'] ? json_decode($record['automation_config'], true) : null;
            if (empty($config) || empty($config['schedule'])) {
                continue;
            }

            foreach ((array) $config['schedule'] as $schedule) {
                if (empty($schedule['run_at'])) {
                    continue;
                }

                try {
                    $runAt = new \DateTimeImmutable($schedule['run_at']);
                } catch (Exception $exception) {
                    continue;
                }

                if ($runAt >= $windowStart && $runAt <= $windowEnd) {
                    $record['automation_payload'] = $schedule;
                    $record['automation_config_decoded'] = $config;
                    $due[] = $this->normalizeCampaignRow($record);
                }
            }
        }

        return $due;
    }

    private function buildValidator(): Validator
    {
        $validator = new Validator();
        $validator->required('name')->lengthBetween(2, 120);
        $validator->optional('status')->lengthBetween(2, 30);
        $validator->optional('start_date')->date('Y-m-d');
        $validator->optional('end_date')->date('Y-m-d');
        $validator->optional('budget')->numeric();
        return $validator;
    }

    private function normalizeCampaignRow(array $row): array
    {
        if (!empty($row['automation_config']) && is_string($row['automation_config'])) {
            $row['automation_config'] = json_decode($row['automation_config'], true);
        }
        return $row;
    }
}


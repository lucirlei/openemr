<?php

/**
 * CRM Interaction Service
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

class CrmInteractionService extends BaseService
{
    public function __construct()
    {
        parent::__construct('crm_interactions');
    }

    public function logInteraction(int $leadId, array $interaction): ProcessingResult
    {
        $result = new ProcessingResult();
        $validator = $this->buildValidator();
        $validation = $validator->validate($interaction + ['lead_id' => $leadId]);

        if (!$validation->isValid()) {
            $result->setValidationMessages($validation->getMessages());
            return $result;
        }

        $sql = "INSERT INTO crm_interactions (lead_id, interaction_type, channel, subject, message, payload, scheduled_at, completed_at, user_id, outcome)"
            . " VALUES (?,?,?,?,?,?,?,?,?,?)";

        $payload = $interaction['payload'] ?? null;
        if (is_array($payload)) {
            $payload = json_encode($payload);
        }

        $bind = [
            $leadId,
            $interaction['interaction_type'],
            $interaction['channel'] ?? null,
            $interaction['subject'] ?? null,
            $interaction['message'] ?? null,
            $payload,
            $interaction['scheduled_at'] ?? null,
            $interaction['completed_at'] ?? null,
            $interaction['user_id'] ?? null,
            $interaction['outcome'] ?? null,
        ];

        try {
            sqlInsert($sql, $bind);
            $result->addData($this->getMostRecentInteraction($leadId));
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    public function listInteractions(int $leadId): ProcessingResult
    {
        $result = new ProcessingResult();

        try {
            $statement = sqlStatement('SELECT * FROM crm_interactions WHERE lead_id = ? ORDER BY created_at DESC', [$leadId]);
            while ($row = sqlFetchArray($statement)) {
                if (!empty($row['payload']) && is_string($row['payload'])) {
                    $row['payload'] = json_decode($row['payload'], true);
                }
                $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
                $result->addData($row);
            }
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    public function listRecentInteractions(int $limit = 10): array
    {
        $limit = max(1, $limit);
        $sql = 'SELECT ci.*, cl.uuid AS lead_uuid, cl.full_name AS lead_name '
            . 'FROM crm_interactions ci '
            . 'JOIN crm_leads cl ON cl.id = ci.lead_id '
            . 'ORDER BY ci.created_at DESC '
            . 'LIMIT ?';

        $records = QueryUtils::fetchRecords($sql, [$limit]);
        $normalized = [];

        foreach ($records as $record) {
            if (!empty($record['payload']) && is_string($record['payload'])) {
                $record['payload'] = json_decode($record['payload'], true);
            }

            $normalized[] = [
                'id' => (int) $record['id'],
                'lead_uuid' => $record['lead_uuid'],
                'lead_name' => $record['lead_name'],
                'interaction_type' => $record['interaction_type'],
                'channel' => $record['channel'],
                'subject' => $record['subject'],
                'message' => $record['message'],
                'payload' => $record['payload'],
                'scheduled_at' => $record['scheduled_at'],
                'completed_at' => $record['completed_at'],
                'user_id' => $record['user_id'] !== null ? (int) $record['user_id'] : null,
                'outcome' => $record['outcome'],
                'created_at' => $record['created_at'],
            ];
        }

        return $normalized;
    }

    private function buildValidator(): Validator
    {
        $validator = new Validator();
        $validator->required('lead_id')->numeric();
        $validator->required('interaction_type')->lengthBetween(2, 40);
        $validator->optional('channel')->lengthBetween(2, 40);
        $validator->optional('subject')->lengthBetween(2, 150);
        $validator->optional('message')->lengthBetween(2, 65535);
        $validator->optional('scheduled_at')->datetime('Y-m-d H:i:s');
        $validator->optional('completed_at')->datetime('Y-m-d H:i:s');
        $validator->optional('user_id')->numeric();
        $validator->optional('outcome')->lengthBetween(2, 60);
        return $validator;
    }

    private function getMostRecentInteraction(int $leadId): ?array
    {
        $row = sqlQuery('SELECT * FROM crm_interactions WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$leadId]);
        if (!empty($row) && !empty($row['payload']) && is_string($row['payload'])) {
            $row['payload'] = json_decode($row['payload'], true);
        }
        return $row ?: null;
    }
}


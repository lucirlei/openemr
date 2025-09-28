<?php

/**
 * CRM Reward Service
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Crm;

use Exception;
use OpenEMR\Services\BaseService;
use OpenEMR\Validators\ProcessingResult;
use Particle\Validator\Validator;

class CrmRewardService extends BaseService
{
    public function __construct()
    {
        parent::__construct('crm_rewards');
    }

    public function recordReward(
        int $leadId,
        string $rewardType,
        int $points,
        ?string $description = null,
        ?string $expiresAt = null
    ): ProcessingResult {
        $result = new ProcessingResult();

        $validator = $this->buildValidator();
        $validation = $validator->validate([
            'lead_id' => $leadId,
            'reward_type' => $rewardType,
            'points' => $points,
            'description' => $description,
            'expires_at' => $expiresAt,
        ]);

        if (!$validation->isValid()) {
            $result->setValidationMessages($validation->getMessages());
            return $result;
        }

        $sql = 'INSERT INTO crm_rewards (lead_id, reward_type, points, description, expires_at) VALUES (?,?,?,?,?)';
        try {
            sqlInsert($sql, [$leadId, $rewardType, $points, $description, $expiresAt]);
            $result->addData($this->getMostRecentReward($leadId));
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    public function listRewardsForLead(int $leadId): ProcessingResult
    {
        $result = new ProcessingResult();
        try {
            $statement = sqlStatement('SELECT * FROM crm_rewards WHERE lead_id = ? ORDER BY awarded_at DESC', [$leadId]);
            while ($row = sqlFetchArray($statement)) {
                $row['points'] = (int) $row['points'];
                $result->addData($row);
            }
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    private function buildValidator(): Validator
    {
        $validator = new Validator();
        $validator->required('lead_id')->numeric();
        $validator->required('reward_type')->lengthBetween(2, 60);
        $validator->required('points')->numeric();
        $validator->optional('description')->lengthBetween(2, 65535);
        $validator->optional('expires_at')->datetime('Y-m-d H:i:s');
        return $validator;
    }

    private function getMostRecentReward(int $leadId): ?array
    {
        $row = sqlQuery('SELECT * FROM crm_rewards WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$leadId]);
        return $row ?: null;
    }
}


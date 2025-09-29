<?php

/**
 * Billing Plan Service
 *
 * Surfaces pricing levels configured in OpenEMR (pricelevel list options)
 * alongside aggregated price statistics so revenue teams can expose them
 * through REST and marketing automations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Billing;

use Exception;
use OpenEMR\Services\BaseService;
use OpenEMR\Validators\ProcessingResult;

class BillingPlanService extends BaseService
{
    public function __construct()
    {
        parent::__construct('prices');
    }

    public function listPlans(array $filters = []): ProcessingResult
    {
        $result = new ProcessingResult();

        $sql = "SELECT lo.option_id AS level, lo.title, lo.notes, lo.seq, lo.is_default, lo.activity,"
            . " COUNT(pr.pr_id) AS price_count,"
            . " COALESCE(AVG(pr.pr_price), 0) AS average_price,"
            . " COALESCE(SUM(pr.pr_price), 0) AS total_value"
            . " FROM list_options lo"
            . " LEFT JOIN prices pr ON pr.pr_level = lo.option_id"
            . " WHERE lo.list_id = 'pricelevel'";

        $bind = [];

        if (isset($filters['active'])) {
            $sql .= " AND lo.activity = ?";
            $bind[] = (int) (bool) $filters['active'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND lo.option_id = ?";
            $bind[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (lo.title LIKE ? OR lo.notes LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            array_push($bind, $search, $search);
        }

        $sql .= " GROUP BY lo.option_id, lo.title, lo.notes, lo.seq, lo.is_default, lo.activity";
        $sql .= " ORDER BY lo.is_default DESC, lo.seq ASC, lo.title ASC";

        if (!empty($filters['limit'])) {
            $limit = max(1, (int) $filters['limit']);
            $sql .= " LIMIT " . $limit;
        }

        try {
            $statement = sqlStatement($sql, $bind);
            while ($row = sqlFetchArray($statement)) {
                $result->addData($this->normalizePlanRow($row));
            }
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }

    private function normalizePlanRow(array $row): array
    {
        return [
            'level' => $row['level'],
            'title' => $row['title'],
            'description' => $row['notes'] ?? null,
            'is_default' => (bool) ($row['is_default'] ?? false),
            'active' => ((int) ($row['activity'] ?? 0)) === 1,
            'price_count' => (int) ($row['price_count'] ?? 0),
            'average_price' => round((float) ($row['average_price'] ?? 0), 2),
            'total_value' => round((float) ($row['total_value'] ?? 0), 2),
        ];
    }
}

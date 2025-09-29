<?php

/**
 * CRM Summary Rest Controller
 *
 * Aggregates CRM, agenda and PDV insights for dashboards or automation
 * workflows that consume the REST API. Results are normalized to JSON
 * structures that also back the OpenAPI contract tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\Crm\CrmCampaignService;
use OpenEMR\Services\Crm\CrmInteractionService;
use OpenEMR\Services\Crm\CrmLeadService;
use Psr\Http\Message\ResponseInterface;

class CrmSummaryRestController
{
    private CrmLeadService $leadService;

    private CrmCampaignService $campaignService;

    private CrmInteractionService $interactionService;

    public function __construct(
        ?CrmLeadService $leadService = null,
        ?CrmCampaignService $campaignService = null,
        ?CrmInteractionService $interactionService = null
    ) {
        $this->leadService = $leadService ?? new CrmLeadService();
        $this->campaignService = $campaignService ?? new CrmCampaignService();
        $this->interactionService = $interactionService ?? new CrmInteractionService();
    }

    public function getSummary(HttpRestRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $months = isset($query['months']) ? max(1, (int) $query['months']) : 6;
        $leaderboardLimit = isset($query['leaderboard_limit']) ? max(1, (int) $query['leaderboard_limit']) : 5;
        $interactionsLimit = isset($query['interactions_limit']) ? max(1, (int) $query['interactions_limit']) : 5;

        $metrics = $this->leadService->getDashboardMetrics();
        $pipelineByStage = array_map(function ($row) {
            return [
                'stage' => $row['pipeline_stage'] ?? ($row['stage'] ?? null),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }, $this->leadService->getPipelineSummary());

        $statusSummary = array_map(function ($row) {
            return [
                'status' => $row['status'] ?? null,
                'total' => (int) ($row['total'] ?? 0),
            ];
        }, $this->leadService->getStatusSummary());

        $monthlySummary = array_map(function ($row) {
            return [
                'period' => $row['period'] ?? null,
                'total' => (int) ($row['total'] ?? 0),
            ];
        }, $this->leadService->getMonthlyLeadSummary($months));

        $leaderboard = array_map(function ($row) {
            return [
                'uuid' => $row['uuid'],
                'full_name' => $row['full_name'],
                'loyalty_points' => (int) ($row['loyalty_points'] ?? 0),
                'status' => $row['status'] ?? null,
            ];
        }, $this->leadService->getLoyaltyLeaderboard($leaderboardLimit));

        $response = [
            'totals' => [
                'leads' => (int) ($metrics['total_leads'] ?? 0),
                'active_campaigns' => (int) ($metrics['active_campaigns'] ?? 0),
                'reward_points' => (int) ($metrics['reward_points'] ?? 0),
            ],
            'pipeline' => [
                'by_stage' => $pipelineByStage,
                'by_status' => $statusSummary,
            ],
            'monthly' => $monthlySummary,
            'leaderboard' => $leaderboard,
            'campaigns' => [
                'active' => $this->campaignService->getActiveCampaignOptions(),
            ],
            'recent_interactions' => $this->interactionService->listRecentInteractions($interactionsLimit),
        ];

        return RestControllerHelper::returnSingleObjectResponse($response);
    }
}

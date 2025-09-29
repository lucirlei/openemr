<?php

namespace OpenEMR\Tests\RestControllers\Crm;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\CrmSummaryRestController;
use OpenEMR\Services\Crm\CrmCampaignService;
use OpenEMR\Services\Crm\CrmInteractionService;
use OpenEMR\Services\Crm\CrmLeadService;
use PHPUnit\Framework\TestCase;

class CrmSummaryRestControllerTest extends TestCase
{
    public function testGetSummaryAggregatesPayload(): void
    {
        $request = $this->createMock(HttpRestRequest::class);
        $request->method('getQueryParams')->willReturn([
            'months' => 3,
            'leaderboard_limit' => 2,
            'interactions_limit' => 1,
        ]);

        $leadService = $this->createMock(CrmLeadService::class);
        $leadService->method('getDashboardMetrics')->willReturn([
            'total_leads' => 10,
            'active_campaigns' => 3,
            'reward_points' => 45,
        ]);
        $leadService->method('getPipelineSummary')->willReturn([
            ['pipeline_stage' => 'captured', 'total' => '5'],
        ]);
        $leadService->method('getStatusSummary')->willReturn([
            ['status' => 'new', 'total' => '2'],
        ]);
        $leadService->expects($this->once())
            ->method('getMonthlyLeadSummary')
            ->with(3)
            ->willReturn([
                ['period' => '2024-01', 'total' => '8'],
            ]);
        $leadService->expects($this->once())
            ->method('getLoyaltyLeaderboard')
            ->with(2)
            ->willReturn([
                ['uuid' => 'lead-1', 'full_name' => 'Lead Example', 'loyalty_points' => 12, 'status' => 'active'],
            ]);

        $campaignService = $this->createMock(CrmCampaignService::class);
        $campaignService->method('getActiveCampaignOptions')->willReturn([
            ['uuid' => 'camp-1', 'name' => 'Campanha A'],
        ]);

        $interactionService = $this->createMock(CrmInteractionService::class);
        $interactionService->expects($this->once())
            ->method('listRecentInteractions')
            ->with(1)
            ->willReturn([
                [
                    'id' => 99,
                    'lead_uuid' => 'lead-1',
                    'lead_name' => 'Lead Example',
                    'interaction_type' => 'webhook',
                    'channel' => 'agenda',
                    'subject' => 'Lead criado',
                    'message' => 'Lead capturado via webhook',
                    'payload' => ['source' => 'form'],
                    'scheduled_at' => null,
                    'completed_at' => null,
                    'user_id' => null,
                    'outcome' => 'queued',
                    'created_at' => '2024-01-10 12:00:00',
                ],
            ]);

        $controller = new CrmSummaryRestController($leadService, $campaignService, $interactionService);
        $response = $controller->getSummary($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(10, $payload['totals']['leads']);
        $this->assertSame(3, $payload['totals']['active_campaigns']);
        $this->assertSame(5, $payload['pipeline']['by_stage'][0]['total']);
        $this->assertSame('captured', $payload['pipeline']['by_stage'][0]['stage']);
        $this->assertSame(2, $payload['pipeline']['by_status'][0]['total']);
        $this->assertSame('2024-01', $payload['monthly'][0]['period']);
        $this->assertSame(8, $payload['monthly'][0]['total']);
        $this->assertSame('lead-1', $payload['leaderboard'][0]['uuid']);
        $this->assertSame('Campanha A', $payload['campaigns']['active'][0]['name']);
        $this->assertSame('Lead Example', $payload['recent_interactions'][0]['lead_name']);
    }
}

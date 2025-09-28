<?php

/**
 * CRM Integration Service
 *
 * Facilitates inbound webhooks (external forms) and outbound marketing
 * connectors. All activity is persisted in the CRM interaction ledger so that
 * automations and reports stay in sync.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Crm;

use Exception;
use OpenEMR\Validators\ProcessingResult;

class CrmIntegrationService
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

    /**
     * Consumes data from an inbound webhook (form submission, marketing platform, etc.).
     */
    public function handleInboundWebhook(array $payload, string $source): ProcessingResult
    {
        $lead = [
            'full_name' => $payload['full_name'] ?? trim(($payload['first_name'] ?? '') . ' ' . ($payload['last_name'] ?? '')),
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'status' => $payload['status'] ?? 'new',
            'source' => $source,
            'pipeline_stage' => $payload['pipeline_stage'] ?? 'captured',
            'notes' => $payload['notes'] ?? null,
        ];

        if (empty($lead['full_name'])) {
            $lead['full_name'] = xlt('Lead sem nome');
        }

        if (!empty($payload['campaign_uuid'])) {
            $campaign = $this->campaignService->getCampaignByUuid($payload['campaign_uuid']);
            if (!empty($campaign)) {
                $lead['campaign_id'] = $campaign['id'];
            }
        }

        if (!empty($payload['owner_id'])) {
            $lead['owner_id'] = (int) $payload['owner_id'];
        }

        $result = $this->leadService->createLead($lead);

        if ($result->hasErrors()) {
            return $result;
        }

        $leadRow = $result->getFirstDataResult();
        if (!empty($leadRow['id'])) {
            $this->interactionService->logInteraction((int) $leadRow['id'], [
                'interaction_type' => 'webhook',
                'channel' => $source,
                'subject' => xlt('Novo lead via webhook'),
                'message' => json_encode($payload),
                'payload' => $payload,
                'outcome' => 'captured',
            ]);
        }

        return $result;
    }

    /**
     * Queues an outbound webhook event that downstream connectors can consume.
     */
    public function queueOutboundWebhook(string $event, array $payload, string $destination): ProcessingResult
    {
        $result = new ProcessingResult();

        try {
            $leadUuid = $payload['lead_uuid'] ?? null;
            $leadId = null;
            if (!empty($leadUuid)) {
                $lead = $this->leadService->getLeadByUuid($leadUuid);
                if (!empty($lead)) {
                    $leadId = (int) $lead['id'];
                }
            }

            if ($leadId) {
                $this->interactionService->logInteraction($leadId, [
                    'interaction_type' => 'webhook',
                    'channel' => $destination,
                    'subject' => $event,
                    'message' => json_encode($payload),
                    'payload' => $payload,
                    'outcome' => 'queued',
                ]);
            }

            $result->addData([
                'event' => $event,
                'destination' => $destination,
                'lead_uuid' => $leadUuid,
            ]);
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
        }

        return $result;
    }
}


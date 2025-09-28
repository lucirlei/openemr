<?php

/**
 * CRM Automation Service
 *
 * Coordinates campaign automations with segmentation, message queuing and
 * interaction logging. The service is designed to be triggered from cron jobs
 * and reuses OpenEMR's MessageService for consistent delivery mechanics.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Crm;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\MessageService;
use OpenEMR\Validators\ProcessingResult;

class CrmAutomationService
{
    private MessageService $messageService;
    private CrmLeadService $leadService;
    private CrmCampaignService $campaignService;
    private CrmInteractionService $interactionService;
    private SystemLogger $logger;

    public function __construct(
        ?MessageService $messageService = null,
        ?CrmLeadService $leadService = null,
        ?CrmCampaignService $campaignService = null,
        ?CrmInteractionService $interactionService = null,
        ?SystemLogger $logger = null
    ) {
        $this->messageService = $messageService ?? new MessageService();
        $this->leadService = $leadService ?? new CrmLeadService();
        $this->campaignService = $campaignService ?? new CrmCampaignService();
        $this->interactionService = $interactionService ?? new CrmInteractionService();
        $this->logger = $logger ?? new SystemLogger();
    }

    /**
     * Dispatches automations scheduled within the provided window.
     */
    public function dispatchWindow(DateTimeInterface $windowStart, DateTimeInterface $windowEnd): ProcessingResult
    {
        $result = new ProcessingResult();

        try {
            $campaigns = $this->campaignService->getAutomationsDue($windowStart, $windowEnd);
        } catch (Exception $exception) {
            $result->addInternalError($exception->getMessage());
            return $result;
        }

        foreach ($campaigns as $campaign) {
            $payload = $campaign['automation_payload'] ?? [];
            $filters = $payload['filters'] ?? [];
            $leadsResult = $this->leadService->listLeads($filters);

            if ($leadsResult->hasErrors()) {
                $result->addProcessingResult($leadsResult);
                continue;
            }

            $leads = $leadsResult->getData();

            foreach ($leads as $lead) {
                try {
                    $dispatchSummary = $this->queueCommunication($lead, $campaign, $payload);
                    if ($dispatchSummary !== null) {
                        $result->addData($dispatchSummary);
                    }
                } catch (Exception $exception) {
                    $this->logger->errorLogCaller('crm_automation_failure', [
                        'error' => $exception->getMessage(),
                        'lead_uuid' => $lead['uuid'] ?? null,
                        'campaign_uuid' => $campaign['uuid'] ?? null,
                    ]);
                    $result->addInternalError($exception->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Queues a communication for a lead and logs the interaction.
     */
    private function queueCommunication(array $lead, array $campaign, array $payload): ?array
    {
        $channel = $payload['channel'] ?? 'email';
        $template = $payload['template'] ?? [];
        $subject = $template['subject'] ?? sprintf(xlt('Campanha %s'), $campaign['name'] ?? '');
        $bodyTemplate = $template['body'] ?? xlt('Olá {{lead_name}}, acompanhe nossa campanha {{campaign_name}}.');

        $replacements = [
            '{{lead_name}}' => $lead['full_name'] ?? '',
            '{{campaign_name}}' => $campaign['name'] ?? '',
            '{{pipeline_stage}}' => $lead['pipeline_stage'] ?? '',
        ];

        $body = strtr($bodyTemplate, $replacements);

        $messageData = [
            'from' => $payload['from'] ?? 'CRM Automation',
            'to' => $payload['to'] ?? ($lead['owner_id'] ? ('owner:' . $lead['owner_id']) : 'CRM Team'),
            'groupname' => $payload['groupname'] ?? 'CRM Automation',
            'title' => $subject,
            'message_status' => $payload['message_status'] ?? 'New',
            'body' => $body . ($lead['email'] ? "\nEmail: " . $lead['email'] : '')
        ];

        $messageValidation = $this->messageService->validate($messageData);
        if (!$messageValidation->isValid()) {
            throw new Exception(xlt('Mensagem inválida para automação CRM'));
        }

        $pid = isset($lead['patient_id']) && $lead['patient_id'] ? (int) $lead['patient_id'] : 0;
        $this->messageService->insert($pid, $messageData);

        $interactionPayload = [
            'interaction_type' => 'automation',
            'channel' => $channel,
            'subject' => $subject,
            'message' => $body,
            'payload' => [
                'campaign_uuid' => $campaign['uuid'] ?? null,
                'channel' => $channel,
                'lead_uuid' => $lead['uuid'] ?? null,
                'target_email' => $lead['email'] ?? null,
                'template' => $template,
            ],
            'completed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'outcome' => 'queued',
        ];

        $leadId = (int) ($lead['id'] ?? 0);
        if ($leadId > 0) {
            $this->interactionService->logInteraction($leadId, $interactionPayload);
        }

        return [
            'lead_uuid' => $lead['uuid'] ?? null,
            'campaign_uuid' => $campaign['uuid'] ?? null,
            'channel' => $channel,
            'subject' => $subject,
        ];
    }
}


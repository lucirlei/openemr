<?php

/**
 * CRM Message Bus
 *
 * Provides a lightweight messaging abstraction so CRM lead lifecycle
 * events can be propagated to other OpenEMR modules through outbound
 * webhooks. Downstream services such as the appointment scheduler or
 * point of sale (PDV) listeners can subscribe to those webhook topics
 * to keep their state synchronized.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Crm;

use OpenEMR\Common\Logging\SystemLoggerAwareTrait;
use OpenEMR\Validators\ProcessingResult;

class CrmMessageBus
{
    use SystemLoggerAwareTrait;

    private CrmIntegrationService $integrationService;

    /**
     * @var string[]
     */
    private array $destinations;

    public function __construct(?CrmIntegrationService $integrationService = null, array $destinations = ['agenda', 'pdv'])
    {
        if ($integrationService !== null) {
            $this->integrationService = $integrationService;
        }
        $this->destinations = $destinations;
    }

    public function setIntegrationService(CrmIntegrationService $integrationService): void
    {
        $this->integrationService = $integrationService;
    }

    public function publishLeadEvent(string $event, array $lead, array $metadata = []): void
    {
        if (empty($lead['uuid'])) {
            return;
        }

        $payload = array_merge([
            'lead_uuid' => $lead['uuid'],
            'full_name' => $lead['full_name'] ?? null,
            'status' => $lead['status'] ?? null,
            'pipeline_stage' => $lead['pipeline_stage'] ?? null,
            'owner_id' => $lead['owner_id'] ?? null,
        ], $metadata);

        foreach ($this->destinations as $destination) {
            $result = $this->getIntegrationService()->queueOutboundWebhook($event, $payload, $destination);
            $this->logProcessingResult($event, $destination, $result);
        }
    }

    private function logProcessingResult(string $event, string $destination, ProcessingResult $result): void
    {
        if ($result->hasErrors()) {
            $this->getSystemLogger()->error('CRM message dispatch failed', [
                'event' => $event,
                'destination' => $destination,
                'validationErrors' => $result->getValidationMessages(),
                'internalErrors' => $result->getInternalErrors(),
            ]);
            return;
        }

        $this->getSystemLogger()->debug('CRM message dispatched', [
            'event' => $event,
            'destination' => $destination,
            'payload' => $result->getFirstDataResult(),
        ]);
    }

    private function getIntegrationService(): CrmIntegrationService
    {
        if (!isset($this->integrationService)) {
            $this->integrationService = new CrmIntegrationService();
        }
        return $this->integrationService;
    }
}

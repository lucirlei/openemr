<?php

/**
 * CRM Lead Rest Controller
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\Crm\CrmLeadService;
use Symfony\Component\HttpFoundation\Response;

class CrmLeadRestController
{
    private CrmLeadService $leadService;

    public function __construct(?CrmLeadService $leadService = null)
    {
        $this->leadService = $leadService ?? new CrmLeadService();
    }

    public function getAll(HttpRestRequest $request)
    {
        $filters = $request->getQueryParams();
        $processingResult = $this->leadService->listLeads($filters);

        return RestControllerHelper::createProcessingResultResponse($request, $processingResult, Response::HTTP_OK, true);
    }

    public function getOne(string $uuid, HttpRestRequest $request)
    {
        $lead = $this->leadService->getLeadByUuid($uuid);
        if (empty($lead)) {
            return RestControllerHelper::getNotFoundResponse();
        }

        return RestControllerHelper::returnSingleObjectResponse($lead);
    }

    public function post(HttpRestRequest $request, array $data)
    {
        $processingResult = $this->leadService->createLead($data);

        return RestControllerHelper::createProcessingResultResponse($request, $processingResult, Response::HTTP_CREATED);
    }

    public function put(string $uuid, HttpRestRequest $request, array $data)
    {
        $processingResult = $this->leadService->updateLead($uuid, $data);

        return RestControllerHelper::createProcessingResultResponse($request, $processingResult, Response::HTTP_OK);
    }

    public function postReward(string $uuid, HttpRestRequest $request, array $data)
    {
        $points = (int) ($data['points'] ?? 0);
        $reason = $data['reason'] ?? xlt('Ajuste manual');
        $type = $data['reward_type'] ?? 'manual';

        $processingResult = $this->leadService->awardPoints($uuid, $points, $reason, $type);

        return RestControllerHelper::createProcessingResultResponse($request, $processingResult, Response::HTTP_OK);
    }
}


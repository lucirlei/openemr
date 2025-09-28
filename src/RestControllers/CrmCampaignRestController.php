<?php

/**
 * CRM Campaign Rest Controller
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\Crm\CrmCampaignService;
use Symfony\Component\HttpFoundation\Response;

class CrmCampaignRestController
{
    private CrmCampaignService $campaignService;

    public function __construct(?CrmCampaignService $campaignService = null)
    {
        $this->campaignService = $campaignService ?? new CrmCampaignService();
    }

    public function getAll(HttpRestRequest $request)
    {
        $processingResult = $this->campaignService->listCampaigns($request->getQueryParams());
        return RestControllerHelper::createProcessingResultResponse($request, $processingResult, Response::HTTP_OK, true);
    }

    public function getOne(string $uuid)
    {
        $campaign = $this->campaignService->getCampaignByUuid($uuid);
        if (empty($campaign)) {
            return RestControllerHelper::getNotFoundResponse();
        }

        return RestControllerHelper::returnSingleObjectResponse($campaign);
    }

    public function post(HttpRestRequest $request, array $data)
    {
        $processingResult = $this->campaignService->createCampaign($data);

        return RestControllerHelper::createProcessingResultResponse($request, $processingResult, Response::HTTP_CREATED);
    }

    public function put(string $uuid, HttpRestRequest $request, array $data)
    {
        $processingResult = $this->campaignService->updateCampaign($uuid, $data);

        return RestControllerHelper::createProcessingResultResponse($request, $processingResult, Response::HTTP_OK);
    }
}


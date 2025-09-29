<?php

/**
 * Billing Plan Rest Controller
 *
 * Exposes pricing level catalog information to downstream systems via
 * the standard REST API.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\Billing\BillingPlanService;
use Symfony\Component\HttpFoundation\Response;

class BillingPlanRestController
{
    private BillingPlanService $planService;

    public function __construct(?BillingPlanService $planService = null)
    {
        $this->planService = $planService ?? new BillingPlanService();
    }

    public function getAll(HttpRestRequest $request)
    {
        $processingResult = $this->planService->listPlans($request->getQueryParams());

        return RestControllerHelper::createProcessingResultResponse(
            $request,
            $processingResult,
            Response::HTTP_OK,
            true
        );
    }
}

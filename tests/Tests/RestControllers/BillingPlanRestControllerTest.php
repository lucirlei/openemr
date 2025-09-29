<?php

namespace OpenEMR\Tests\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\BillingPlanRestController;
use OpenEMR\Services\Billing\BillingPlanService;
use OpenEMR\Validators\ProcessingResult;
use PHPUnit\Framework\TestCase;

class BillingPlanRestControllerTest extends TestCase
{
    public function testGetAllReturnsPlans(): void
    {
        $request = new HttpRestRequest(['active' => 1], [], [], [], [], ['REDIRECT_URL' => '/api/billing/plans']);
        $request->setMethod('GET');

        $processingResult = new ProcessingResult();
        $processingResult->addData([
            'level' => 'standard',
            'title' => 'Plano Padrão',
            'description' => null,
            'is_default' => true,
            'active' => true,
            'price_count' => 10,
            'average_price' => 150.5,
            'total_value' => 1505.0,
        ]);

        $planService = $this->createMock(BillingPlanService::class);
        $planService->expects($this->once())
            ->method('listPlans')
            ->with(['active' => 1])
            ->willReturn($processingResult);

        $controller = new BillingPlanRestController($planService);
        $response = $controller->getAll($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame([], $payload['validationErrors']);
        $this->assertSame('standard', $payload['data'][0]['level']);
        $this->assertSame(10, $payload['data'][0]['price_count']);
        $this->assertSame(150.5, $payload['data'][0]['average_price']);
    }
}

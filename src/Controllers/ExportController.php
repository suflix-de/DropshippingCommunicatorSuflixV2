<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use DropshippingCommunicatorSuflix\Services\ExportService;

/**
 * REST endpoint: POST /dropshipping-communicator-suflix/send/{orderId}
 *
 * This controller is called by a PlentyONE Flow via the "Call REST route"
 * procedure or a custom webhook action.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exportService,
        private readonly Response      $response,
    ) {}

    /**
     * Trigger export for a single order.
     *
     * @param  Request $request
     * @param  int     $orderId  Route parameter
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function sendOrder(Request $request, int $orderId): \Symfony\Component\HttpFoundation\Response
    {
        $result = $this->exportService->processOrder($orderId);

        $statusCode = $result['success'] ? 200 : 500;

        return $this->response->json($result, $statusCode);
    }
}

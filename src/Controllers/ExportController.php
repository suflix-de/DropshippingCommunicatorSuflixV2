<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ExportController extends Controller
{
    public function sendOrder(Request $request, Response $response, int $orderId): \Symfony\Component\HttpFoundation\Response
    {
        try {
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);

            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);

            $order = $authHelper->processUnguarded(function() use ($orderRepo, $orderId) {
                return $orderRepo->findOrderById($orderId);
            });

            /** @var ConfigRepository $config */
            $config = pluginApp(ConfigRepository::class);

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            $subject = 'Test E-Mail Auftrag: ' . $orderId;
            $body    = 'Dies ist ein Test. Auftrags-ID: ' . $orderId;

            $recipients = ['post@suflix.de'];

            $mailer->sendHtml($body, $recipients, $subject);

            return $response->json([
                'success'    => true,
                'message'    => 'Test E-Mail versendet!',
                'recipients' => $recipients,
                'subject'    => $subject,
            ]);

        } catch (\Throwable $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }
}

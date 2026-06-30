<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\ConfigRepository;

class ExportController extends Controller
{
    public function sendOrder(Request $request, Response $response, int $orderId): \Symfony\Component\HttpFoundation\Response
    {
        try {
            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);
            $order = $orderRepo->findById($orderId, ['*'], [
                'amounts',
                'addresses',
                'orderItems.variation',
                'orderItems.properties',
                'orderItems.amounts',
                'properties',
                'documents',
                'relations',
            ]);

            /** @var ConfigRepository $config */
            $config = pluginApp(ConfigRepository::class);

            /** @var TxtFileService $txtService */
            $txtService = pluginApp(TxtFileService::class);

            /** @var PlaceholderService $placeholderService */
            $placeholderService = pluginApp(PlaceholderService::class);

            /** @var EmailTemplatesSendServiceContract $mailService */
            $mailService = pluginApp(EmailTemplatesSendServiceContract::class);

            $txtContent  = $txtService->build($order);
            $txtFilename = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.txt.filename', 'Auftrag_[OrderId].txt'),
                $order
            );

            $subject = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.subject', 'Neue Bestellung [OrderId]'),
                $order
            );

            $body = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.body', ''),
                $order
            );

            $recipients = $this->splitEmails(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.recipients', '')
            );

            $bcc = $this->splitEmails(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.bcc', '')
            );

            if (empty($recipients)) {
                return $response->json(['success' => false, 'message' => 'Keine Empfänger konfiguriert.'], 500);
            }

            // ── Anhänge aufbauen ─────────────────────────────────────────────
            $attachments = [
                [
                    'fileName' => $txtFilename,
                    'mimeType' => 'text/plain',
                    'content'  => base64_encode($txtContent),
                ],
            ];

            // Lieferschein anhängen falls vorhanden
            if (!empty($order->documents)) {
                foreach ($order->documents as $doc) {
                    if ((string)($doc->type ?? '') === 'deliveryNote' && !empty($doc->content)) {
                        $number = (string)($doc->number ?? $doc->displayNumber ?? $orderId);
                        $attachments[] = [
                            'fileName' => 'Lieferschein_' . $number . '.pdf',
                            'mimeType' => 'application/pdf',
                            'content'  => $doc->content,
                        ];
                        break;
                    }
                }
            }

            // ── E-Mail versenden ─────────────────────────────────────────────
            $payload = [
                'subject'     => $subject,
                'body'        => $body,
                'receivers'   => array_map(fn($email) => ['email' => $email], $recipients),
                'bcc'         => array_map(fn($email) => ['email' => $email], $bcc),
                'attachments' => $attachments,
            ];

            $mailService->sendPreview($payload);

            return $response->json([
                'success'    => true,
                'message'    => 'E-Mail erfolgreich versendet.',
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

    private function splitEmails(string $raw): array
    {
        $parts = preg_split('/[,;]+/', $raw);
        $result = [];
        foreach ($parts as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result[] = $email;
            }
        }
        return array_values(array_unique($result));
    }
}

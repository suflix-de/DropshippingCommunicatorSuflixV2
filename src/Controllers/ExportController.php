<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;

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

            /** @var TxtFileService $txtService */
            $txtService = pluginApp(TxtFileService::class);

            /** @var PlaceholderService $placeholderService */
            $placeholderService = pluginApp(PlaceholderService::class);

            $txtContent = $txtService->build($order);
            if (empty($txtContent)) {
                $txtContent = 'k;fehler;' . $orderId . ';;;;;;;;;;;;;;' . "\r\n";
            }

            $txtFilename = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.txt.filename', 'Auftrag_[OrderId].txt'),
                $order
            );
            $subject = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.subject', 'Neue Bestellung [OrderId]'),
                $order
            );
            $body = $placeholderService->replace(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.body', 'Neue Bestellung'),
                $order
            );
            $recipients = $this->splitEmails(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.recipients', '')
            );
            $bcc = $this->splitEmails(
                (string)$config->get('DropshippingCommunicatorSuflix.mail.bcc', '')
            );
            $smtpHost       = (string)$config->get('DropshippingCommunicatorSuflix.smtp.host', '');
            $smtpPort       = (int)$config->get('DropshippingCommunicatorSuflix.smtp.port', 587);
            $smtpUser       = (string)$config->get('DropshippingCommunicatorSuflix.smtp.username', '');
            $smtpPass       = (string)$config->get('DropshippingCommunicatorSuflix.smtp.password', '');
            $smtpEncryption = (string)$config->get('DropshippingCommunicatorSuflix.smtp.encryption', 'tls');
            $fromEmail      = (string)$config->get('DropshippingCommunicatorSuflix.smtp.from_email', '');
            $fromName       = (string)$config->get('DropshippingCommunicatorSuflix.smtp.from_name', 'Suflix');

            if (empty($recipients) || empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) {
                return $response->json(['success' => false, 'message' => 'Empfänger oder SMTP-Konfiguration fehlt.'], 500);
            }

            // ── SwiftMailer direkt verwenden ─────────────────────────────────
            $transport = new \Swift_SmtpTransport($smtpHost, $smtpPort, $smtpEncryption);
            $transport->setUsername($smtpUser)->setPassword($smtpPass);

            $mailer = new \Swift_Mailer($transport);

            $message = (new \Swift_Message())
                ->setSubject($subject)
                ->setFrom([$fromEmail => $fromName])
                ->setTo($recipients)
                ->setBody($body, 'text/html', 'utf-8');

            if (!empty($bcc)) {
                $message->setBcc($bcc);
            }

            // TXT-Anhang
            $message->attach(
                new \Swift_Attachment($txtContent, $txtFilename, 'text/plain')
            );

            // Lieferschein-PDF falls vorhanden
            $orderArray = json_decode(json_encode($order), true);
            foreach (($orderArray['documents'] ?? []) as $doc) {
                if (($doc['type'] ?? '') === 'deliveryNote' && !empty($doc['content'])) {
                    $pdfNum     = (string)($doc['number'] ?? $doc['displayNumber'] ?? $orderId);
                    $pdfName    = 'Lieferschein_' . $pdfNum . '.pdf';
                    $pdfContent = base64_decode($doc['content']);
                    $message->attach(
                        new \Swift_Attachment($pdfContent, $pdfName, 'application/pdf')
                    );
                    break;
                }
            }

            $sent = $mailer->send($message);

            return $response->json([
                'success'    => $sent > 0,
                'message'    => $sent > 0 ? 'E-Mail mit Anhang versendet!' : 'E-Mail konnte nicht gesendet werden.',
                'recipients' => $recipients,
                'sent'       => $sent,
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

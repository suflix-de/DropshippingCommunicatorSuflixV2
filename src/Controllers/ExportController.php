<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Mail\Contracts\MailerContract;
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

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

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

            if (empty($recipients)) {
                return $response->json(['success' => false, 'message' => 'Keine Empfänger konfiguriert.'], 500);
            }

            // ── Strategie: sendHtml() für Body + sendFromMime() nur mit Anhang ──
            // 1. E-Mail mit Body
            $mailer->sendHtml($body, $recipients, $subject, [], $bcc);

            // 2. Separater MIME-Anhang – NUR Content-Type Header, kein Subject/MIME-Version
            //    damit PlentyONE die Envelope-Header selbst setzt
            $boundary = 'SUFLIX_' . md5((string)$orderId . (string)time());
            $nl = "\r\n";

            $mime  = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $nl;
            $mime .= $nl;

            // Placeholder-Body damit E-Mail nicht leer ist
            $mime .= '--' . $boundary . $nl;
            $mime .= 'Content-Type: text/plain; charset=UTF-8' . $nl;
            $mime .= $nl;
            $mime .= 'Anlagen zur Bestellung ' . $orderId . $nl;
            $mime .= $nl;

            // TXT-Anhang
            $mime .= '--' . $boundary . $nl;
            $mime .= 'Content-Type: text/plain; charset=UTF-8; name="' . $txtFilename . '"' . $nl;
            $mime .= 'Content-Transfer-Encoding: base64' . $nl;
            $mime .= 'Content-Disposition: attachment; filename="' . $txtFilename . '"' . $nl;
            $mime .= $nl;
            $mime .= chunk_split(base64_encode($txtContent)) . $nl;

            // Lieferschein-PDF falls vorhanden
            $orderArray = json_decode(json_encode($order), true);
            foreach (($orderArray['documents'] ?? []) as $doc) {
                if (($doc['type'] ?? '') === 'deliveryNote' && !empty($doc['content'])) {
                    $pdfNum  = (string)($doc['number'] ?? $doc['displayNumber'] ?? $orderId);
                    $pdfName = 'Lieferschein_' . $pdfNum . '.pdf';
                    $mime .= '--' . $boundary . $nl;
                    $mime .= 'Content-Type: application/pdf; name="' . $pdfName . '"' . $nl;
                    $mime .= 'Content-Transfer-Encoding: base64' . $nl;
                    $mime .= 'Content-Disposition: attachment; filename="' . $pdfName . '"' . $nl;
                    $mime .= $nl;
                    $mime .= chunk_split($doc['content']) . $nl;
                    break;
                }
            }

            $mime .= '--' . $boundary . '--' . $nl;

            // Zweite E-Mail nur mit Anhang (Betreff mit [Anhang] Prefix)
            $mailer->sendFromMime($mime, $recipients, [], [], $bcc);

            return $response->json([
                'success'    => true,
                'message'    => 'E-Mails versendet!',
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

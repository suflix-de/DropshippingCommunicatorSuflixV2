<?php

namespace DropshippingCommunicatorSuflix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;

class ExportController extends Controller
{
    const PLUGIN_NAME = 'DropshippingCommunicatorSuflix';

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

            /** @var StorageRepositoryContract $storage */
            $storage = pluginApp(StorageRepositoryContract::class);

            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            /** @var DocumentRepositoryContract $documentRepo */
            $documentRepo = pluginApp(DocumentRepositoryContract::class);

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

            // ── Lieferschein StorageObject direkt vom DocumentRepository holen ─
            $attachments  = [];
            $docDebug     = [];

            try {
                $documents = $authHelper->processUnguarded(function() use ($documentRepo, $orderId) {
                    return $documentRepo->getDocumentsForOrder($orderId);
                });

                foreach ($documents as $doc) {
                    $docDebug[] = [
                        'type'       => $doc->type ?? 'unknown',
                        'id'         => $doc->id ?? null,
                        'number'     => $doc->number ?? null,
                        'properties' => array_keys((array)$doc),
                    ];

                    if (($doc->type ?? '') === 'deliveryNote') {
                        // Versuche StorageObject direkt vom Dokument zu bekommen
                        if (isset($doc->storageObject) && $doc->storageObject !== null) {
                            $attachments[] = $doc->storageObject;
                        } elseif (isset($doc->path) && !empty($doc->path)) {
                            // Versuche über S3-Pfad
                            $pdfObject = $storage->getObject(self::PLUGIN_NAME, $doc->path);
                            if ($pdfObject !== null) {
                                $attachments[] = $pdfObject;
                            }
                        }
                        // Fallback: StorageObject manuell erstellen
                        if (empty($attachments) && !empty($doc->content)) {
                            $pdfNum    = (string)($doc->numberWithPrefix ?? $doc->number ?? $orderId);
                            $pdfKey    = 'ls_' . $orderId . '_' . $pdfNum . '.pdf';
                            $pdfObject = pluginApp(\Plenty\Modules\Cloud\Storage\Models\StorageObject::class);
                            $pdfObject->key      = $pdfKey;
                            $pdfObject->body     = base64_decode($doc->content);
                            $pdfObject->filename = 'Lieferschein_' . $pdfNum . '.pdf';
                            $attachments[] = $pdfObject;
                        }
                        break;
                    }
                }
            } catch (\Throwable $docEx) {
                $docDebug[] = ['error' => $docEx->getMessage()];
            }

            // ── TXT StorageObject manuell erstellen ──────────────────────────
            $txtObject           = pluginApp(\Plenty\Modules\Cloud\Storage\Models\StorageObject::class);
            $txtObject->key      = 'txt_' . $orderId . '_' . time() . '.txt';
            $txtObject->body     = $txtContent;
            $txtObject->filename = $txtFilename;
            array_unshift($attachments, $txtObject);

            // ── E-Mail versenden ─────────────────────────────────────────────
            $mailer->sendHtml($body, $recipients, $subject, [], $bcc, null, $attachments);

            return $response->json([
                'success'        => true,
                'message'        => 'E-Mail versendet!',
                'recipients'     => $recipients,
                'attachments'    => count($attachments),
                'docDebug'       => $docDebug,
                'txtObjectProps' => array_keys((array)$txtObject),
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

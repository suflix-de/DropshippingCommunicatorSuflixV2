<?php

namespace DropshippingCommunicatorSuflix\EventProcedures;

use Exception;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Mail\Contracts\MailerContract;
use Plenty\Plugin\ConfigRepository;
use DropshippingCommunicatorSuflix\Services\TxtFileService;
use DropshippingCommunicatorSuflix\Services\PlaceholderService;

class SendOrderMailProcedure
{
    public function handle(EventProceduresTriggered $event): void
    {
        $orderId = (int)$event->getOrder()->id;

        /** @var OrderRepositoryContract $orderRepo */
        $orderRepo = pluginApp(OrderRepositoryContract::class);
        $order = $orderRepo->findById($orderId, ['*'], [
            'amounts',
            'addresses',
            'orderItems.variation',
            'orderItems.properties',
            'properties',
            'documents',
        ]);

        /** @var ConfigRepository $config */
        $config = pluginApp(ConfigRepository::class);

        /** @var TxtFileService $txtService */
        $txtService = pluginApp(TxtFileService::class);

        /** @var PlaceholderService $placeholderService */
        $placeholderService = pluginApp(PlaceholderService::class);

        // ── TXT erstellen ────────────────────────────────────────────────────
        $txtContent  = $txtService->build($order);
        $txtFilename = $placeholderService->replace(
            (string)$config->get('DropshippingCommunicatorSuflix.txt.filename', 'Auftrag_[OrderId].txt'),
            $order
        );

        // ── E-Mail-Daten ─────────────────────────────────────────────────────
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
            throw new Exception('Suflix DropshippingCommunicator: Keine Empfänger konfiguriert.');
        }

        // ── Anhänge ──────────────────────────────────────────────────────────
        $attachments = [[
            'name'    => $txtFilename,
            'content' => base64_encode($txtContent),
            'mime'    => 'text/plain',
        ]];

        // Lieferschein-PDF anhängen falls vorhanden
        $deliveryNoteAttachment = $this->findDeliveryNote($order);
        if ($deliveryNoteAttachment !== null) {
            $attachments[] = $deliveryNoteAttachment;
        }

        // ── E-Mail versenden ─────────────────────────────────────────────────
        /** @var MailerContract $mailer */
        $mailer = pluginApp(MailerContract::class);

        $mail = $mailer->to($recipients)->subject($subject)->html($body);

        if (!empty($bcc)) {
            $mail->bcc($bcc);
        }

        foreach ($attachments as $attachment) {
            $mail->attachData(
                base64_decode($attachment['content']),
                $attachment['name'],
                ['mime' => $attachment['mime']]
            );
        }

        $mail->send();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function findDeliveryNote($order): ?array
    {
        if (empty($order->documents)) {
            return null;
        }
        foreach ($order->documents as $doc) {
            if ((string)($doc->type ?? '') === 'deliveryNote' && !empty($doc->content)) {
                $number = (string)($doc->number ?? $doc->displayNumber ?? $order->id);
                return [
                    'name'    => 'Lieferschein_' . $number . '.pdf',
                    'content' => $doc->content,
                    'mime'    => 'application/pdf',
                ];
            }
        }
        return null;
    }
}

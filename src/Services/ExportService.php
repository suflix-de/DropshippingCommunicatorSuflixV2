<?php

namespace DropshippingCommunicatorSuflix\Services;

use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Document\Models\Document;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Plugin\Log\Loggable;
use DropshippingCommunicatorSuflix\Helpers\TxtGenerator;

/**
 * Main orchestrator: loads the order, builds the TXT, fetches the delivery-note
 * PDF (Lieferschein) and sends everything by e-mail.
 */
class ExportService
{
    use Loggable;

    public function __construct(
        private readonly OrderRepositoryContract    $orderRepo,
        private readonly DocumentRepositoryContract $documentRepo,
        private readonly MailerContract             $mailer,
        private readonly ConfigService              $config,
        private readonly TxtGenerator               $txtGenerator,
    ) {}

    /**
     * Process a single order: generate TXT, attach delivery note, send e-mail.
     *
     * @param  int  $orderId
     * @return array{success: bool, message: string}
     */
    public function processOrder(int $orderId): array
    {
        // ── 1. Load order ────────────────────────────────────────────────────
        try {
            $order = $this->orderRepo->findOrderById($orderId);
        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('Order not found', ['orderId' => $orderId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Auftrag nicht gefunden: ' . $e->getMessage()];
        }

        $orderArray = $order->toArray();

        // ── 2. Find the delivery note document (Lieferschein) ────────────────
        $deliveryNoteNumber = '';
        $deliveryNotePdfBase64 = null;

        try {
            $documents = $this->documentRepo->getDocumentsForOrder($orderId);
            foreach ($documents as $doc) {
                // Document type 7 = Lieferschein in PlentyONE
                if ($doc->type === Document::DELIVERY_NOTE) {
                    $deliveryNoteNumber    = (string)$doc->numberWithPrefix;
                    $deliveryNotePdfBase64 = $doc->content ?? null;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->warning('Could not load delivery note', ['orderId' => $orderId, 'error' => $e->getMessage()]);
            // Non-fatal: continue without delivery note
        }

        // ── 3. Generate TXT content ──────────────────────────────────────────
        $txtContent  = $this->txtGenerator->generate($orderArray, $deliveryNoteNumber);
        $txtFilename = 'Auftrag_' . $orderId . '.txt';

        // ── 4. Build e-mail subject ──────────────────────────────────────────
        $subject = str_replace(
            ['{orderId}', '{deliveryNote}'],
            [$orderId, $deliveryNoteNumber],
            $this->config->getEmailSubjectTemplate()
        );

        // ── 5. Send e-mail ───────────────────────────────────────────────────
        $toEmails  = $this->config->getToEmails();
        $bccEmails = $this->config->getBccEmails();
        $fromEmail = $this->config->getFromEmail();
        $fromName  = $this->config->getFromName();

        if (empty($toEmails)) {
            return ['success' => false, 'message' => 'Keine Empfänger-E-Mail-Adressen konfiguriert.'];
        }

        try {
            $mail = $this->mailer
                ->to($toEmails)
                ->from($fromEmail, $fromName)
                ->subject($subject)
                ->text($this->buildEmailBody($orderId, $deliveryNoteNumber))
                ->attachData(
                    $txtContent,
                    $txtFilename,
                    ['mime' => 'text/plain']
                );

            // Attach delivery-note PDF if available
            if ($deliveryNotePdfBase64 !== null) {
                $pdfContent  = base64_decode($deliveryNotePdfBase64);
                $pdfFilename = 'Lieferschein_' . $deliveryNoteNumber . '.pdf';
                $mail->attachData($pdfContent, $pdfFilename, ['mime' => 'application/pdf']);
            }

            // BCC addresses
            if (!empty($bccEmails)) {
                $mail->bcc($bccEmails);
            }

            $mail->send();

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('E-Mail Versand fehlgeschlagen', ['orderId' => $orderId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'E-Mail Versand fehlgeschlagen: ' . $e->getMessage()];
        }

        $this->getLogger(__METHOD__)->info('Export erfolgreich versendet', [
            'orderId'            => $orderId,
            'deliveryNoteNumber' => $deliveryNoteNumber,
            'recipients'         => $toEmails,
        ]);

        return [
            'success' => true,
            'message' => sprintf(
                'Export für Auftrag %d erfolgreich an %s versendet.',
                $orderId,
                implode(', ', $toEmails)
            ),
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function buildEmailBody(int $orderId, string $deliveryNoteNumber): string
    {
        $haendlerNr = $this->config->getHaendlerkundennummer();
        return implode("\n", [
            'Sehr geehrte Damen und Herren,',
            '',
            'im Anhang finden Sie die Bestelldaten sowie den Lieferschein für folgenden Auftrag:',
            '',
            '  Auftragsnummer (Kunde): ' . $orderId,
            '  Lieferscheinnummer:     ' . ($deliveryNoteNumber ?: '—'),
            '  Händlerkundennummer:    ' . $haendlerNr,
            '',
            'Mit freundlichen Grüßen',
            $this->config->getFromName(),
        ]);
    }
}

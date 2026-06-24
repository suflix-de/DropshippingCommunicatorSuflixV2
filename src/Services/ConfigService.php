<?php

namespace DropshippingCommunicatorSuflix\Services;

use Plenty\Plugin\ConfigRepository;

/**
 * Reads plugin configuration values from the PlentyONE plugin config.
 */
class ConfigService
{
    /** @var ConfigRepository */
    private ConfigRepository $config;

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    /** Händlerkundennummer beim Lieferanten, z. B. "11061" */
    public function getHaendlerkundennummer(): string
    {
        return (string)$this->config->get('DropshippingCommunicatorSuflix.haendlerkundennummer', '');
    }

    /**
     * Primäre Empfänger-E-Mail-Adressen (kommagetrennt im Config-Feld).
     * @return string[]
     */
    public function getToEmails(): array
    {
        $raw = (string)$this->config->get('DropshippingCommunicatorSuflix.to_emails', '');
        return $this->splitEmails($raw);
    }

    /**
     * BCC-E-Mail-Adresse(n) (kommagetrennt).
     * @return string[]
     */
    public function getBccEmails(): array
    {
        $raw = (string)$this->config->get('DropshippingCommunicatorSuflix.bcc_emails', '');
        return $this->splitEmails($raw);
    }

    /** Absender-E-Mail-Adresse */
    public function getFromEmail(): string
    {
        return (string)$this->config->get('DropshippingCommunicatorSuflix.from_email', '');
    }

    /** Absender-Name */
    public function getFromName(): string
    {
        return (string)$this->config->get('DropshippingCommunicatorSuflix.from_name', '');
    }

    /**
     * Varianten-IDs, bei denen es sich um Bundle-Eltern handelt.
     * Eingetragen als kommagetrennte Liste von Integer-IDs.
     * @return int[]
     */
    public function getBundleVariationIds(): array
    {
        $raw = (string)$this->config->get('DropshippingCommunicatorSuflix.bundle_variation_ids', '');
        if (empty(trim($raw))) {
            return [];
        }
        return array_map('intval', array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Betreff-Template; Platzhalter: {orderId}, {deliveryNote} */
    public function getEmailSubjectTemplate(): string
    {
        return (string)$this->config->get(
            'DropshippingCommunicatorSuflix.email_subject',
            'Bestellung {orderId} – Lieferschein {deliveryNote}'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Split a comma-separated email string into a trimmed array, removing blanks.
     * @return string[]
     */
    private function splitEmails(string $raw): array
    {
        if (empty(trim($raw))) {
            return [];
        }
        return array_values(
            array_filter(array_map('trim', explode(',', $raw)))
        );
    }
}

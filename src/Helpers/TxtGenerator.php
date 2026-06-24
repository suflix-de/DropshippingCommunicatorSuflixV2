<?php

namespace DropshippingCommunicatorSuflix\Helpers;

use DropshippingCommunicatorSuflix\Services\ConfigService;

/**
 * Generates the supplier TXT export file.
 *
 * Format:
 *   k;{haendlerkundennummer};{auftragsnummer};{firmenname};{anrede};{kundenname};;{strasse};{lieferland};{plz};{ort};{telefon};{lieferscheinnummer};{email};
 *   p;{externeId};{menge};{variantenname};
 *
 * Bundle items: If an order item has a variation ID listed in the bundle exclusion
 * config, the bundle parent line is skipped and only its children are exported.
 */
class TxtGenerator
{
    /** @var ConfigService */
    private ConfigService $config;

    public function __construct(ConfigService $config)
    {
        $this->config = $config;
    }

    /**
     * Build the full TXT content for one order.
     *
     * @param array $order        The PlentyONE order data array (from REST API)
     * @param string $deliveryNoteNumber  The delivery note / Lieferschein number
     * @return string
     */
    public function generate(array $order, string $deliveryNoteNumber): string
    {
        $lines = [];

        // ── Customer / header line ───────────────────────────────────────────
        $address   = $this->extractDeliveryAddress($order);
        $contact   = $this->extractContact($order);
        $email     = $this->extractEmail($order);

        $haendlerNr   = $this->config->getHaendlerkundennummer();
        $auftragsNr   = (string)($order['id'] ?? '');
        $firmenname   = $address['company'] ?? '';
        $anrede       = $address['salutation'] ?? '';
        $kundenname   = trim(($address['firstName'] ?? '') . ' ' . ($address['lastName'] ?? ''));
        $adresszusatz = $address['additional'] ?? '';
        $strasse      = trim(($address['street'] ?? '') . ' ' . ($address['houseNumber'] ?? ''));
        $land         = $address['countryIsoCode'] ?? 'DE';
        $plz          = $address['postalCode'] ?? '';
        $ort          = $address['city'] ?? '';
        $telefon      = $contact['phone'] ?? '';

        $lines[] = implode(';', [
            'k',
            $haendlerNr,
            $auftragsNr,
            $firmenname,
            $anrede,
            $kundenname,
            $adresszusatz,
            $strasse,
            $land,
            $plz,
            $ort,
            $telefon,
            $deliveryNoteNumber,
            $email,
            '',   // trailing semicolon field
        ]);

        // ── Order item lines ─────────────────────────────────────────────────
        $bundleVariationIds = $this->config->getBundleVariationIds(); // array of int/string
        $orderItems         = $order['orderItems'] ?? [];

        // Collect bundle children variation IDs that appear in the order,
        // so we know which bundle parents to skip.
        $bundleParentItemIds = [];
        foreach ($orderItems as $item) {
            $variationId = (int)($item['itemVariationId'] ?? 0);
            if (in_array($variationId, $bundleVariationIds, true)) {
                // Mark this order-item as a bundle parent; collect its ID
                $bundleParentItemIds[] = (int)($item['id'] ?? 0);
            }
        }

        foreach ($orderItems as $item) {
            $type        = $item['typeId'] ?? 1; // 1 = Variation, 3 = Bundle child, etc.
            $variationId = (int)($item['itemVariationId'] ?? 0);
            $orderItemId = (int)($item['id'] ?? 0);

            // Skip the bundle parent itself
            if (in_array($variationId, $bundleVariationIds, true)) {
                continue;
            }

            // Skip shipping / surcharge positions (typeId != 1 and != 2 for bundle component)
            // typeId 1 = Variation, 2 = Bundle component, 6 = Shipping, etc.
            if (!in_array($type, [1, 2], true)) {
                continue;
            }

            $externeId   = $item['properties']['externalId'] ?? ($item['orderItemName'] ?? '');
            // Try to get the externalId from item properties
            foreach (($item['properties'] ?? []) as $prop) {
                if (($prop['typeId'] ?? 0) === 26) { // 26 = External item ID in PlentyONE
                    $externeId = $prop['value'] ?? $externeId;
                    break;
                }
            }

            $menge       = (int)($item['quantity'] ?? 1);
            $variantenname = $item['orderItemName'] ?? '';

            $lines[] = implode(';', [
                'p',
                $externeId,
                $menge,
                $variantenname,
                '',  // trailing semicolon field
            ]);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Extract the delivery address from the order.
     * Checks address relations for type 2 (delivery address).
     */
    private function extractDeliveryAddress(array $order): array
    {
        $addresses = $order['addressRelations'] ?? [];
        foreach ($addresses as $relation) {
            // typeId 2 = delivery address in PlentyONE
            if (($relation['typeId'] ?? 0) === 2) {
                $addr = $relation['address'] ?? [];
                return [
                    'company'     => $addr['companyName'] ?? '',
                    'salutation'  => $this->mapSalutation($addr['gender'] ?? ''),
                    'firstName'   => $addr['firstName'] ?? '',
                    'lastName'    => $addr['lastName'] ?? '',
                    'additional'  => $addr['additional'] ?? '',
                    'street'      => $addr['address1'] ?? '',
                    'houseNumber' => $addr['address2'] ?? '',
                    'postalCode'  => $addr['postalCode'] ?? '',
                    'city'        => $addr['town'] ?? '',
                    'countryIsoCode' => $addr['countryIsoCode2'] ?? 'DE',
                ];
            }
        }
        return [];
    }

    /**
     * Extract phone number from order contact options or address options.
     */
    private function extractContact(array $order): array
    {
        $addresses = $order['addressRelations'] ?? [];
        foreach ($addresses as $relation) {
            if (($relation['typeId'] ?? 0) === 2) {
                $options = $relation['address']['options'] ?? [];
                foreach ($options as $opt) {
                    // typeId 4 = phone in PlentyONE address options
                    if (($opt['typeId'] ?? 0) === 4) {
                        return ['phone' => $opt['value'] ?? ''];
                    }
                }
            }
        }
        return ['phone' => ''];
    }

    /**
     * Extract the customer's e-mail address from the order.
     */
    private function extractEmail(array $order): string
    {
        $addresses = $order['addressRelations'] ?? [];
        foreach ($addresses as $relation) {
            if (($relation['typeId'] ?? 0) === 2) {
                $options = $relation['address']['options'] ?? [];
                foreach ($options as $opt) {
                    // typeId 5 = email in PlentyONE address options
                    if (($opt['typeId'] ?? 0) === 5) {
                        return $opt['value'] ?? '';
                    }
                }
            }
        }
        return '';
    }

    /**
     * Map PlentyONE gender string to German salutation.
     */
    private function mapSalutation(string $gender): string
    {
        return match (strtolower($gender)) {
            'male'    => 'Herr',
            'female'  => 'Frau',
            default   => '',
        };
    }
}

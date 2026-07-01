<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Normalize between the UCP (schema.org-style) address shape and the flat
 * shape PrestaShop's Address object uses. Ported from FD_UCP_Address.
 */
final class UcpAddress
{
    /**
     * UCP address -> PrestaShop address fields.
     *
     * @param array<string,mixed> $ucp
     * @return array<string,string>
     */
    public static function ucpToPs(array $ucp): array
    {
        return [
            'address1' => (string) ($ucp['street_address'] ?? ''),
            'address2' => (string) ($ucp['extended_address'] ?? ''),
            'city' => (string) ($ucp['address_locality'] ?? ''),
            'state' => (string) ($ucp['address_region'] ?? ''),
            'postcode' => (string) ($ucp['postal_code'] ?? ''),
            'country' => strtoupper((string) ($ucp['address_country'] ?? '')),
        ];
    }

    /**
     * PrestaShop address fields -> UCP address.
     *
     * @param array<string,mixed> $ps
     * @return array<string,string>
     */
    public static function psToUcp(array $ps): array
    {
        $addr = [
            'address_country' => (string) ($ps['country'] ?? ''),
            'address_locality' => (string) ($ps['city'] ?? ''),
            'postal_code' => (string) ($ps['postcode'] ?? ''),
        ];

        if (!empty($ps['address1'])) {
            $addr['street_address'] = (string) $ps['address1'];
        }
        if (!empty($ps['address2'])) {
            $addr['extended_address'] = (string) $ps['address2'];
        }
        if (!empty($ps['state'])) {
            $addr['address_region'] = (string) $ps['state'];
        }

        return $addr;
    }
}

<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\UcpAddress;
use PHPUnit\Framework\TestCase;

/**
 * Ported from woocommerce-ucp AddressTest — the UCP <-> PrestaShop address
 * mapping (UcpAddress::ucpToPs / psToUcp).
 */
final class UcpAddressTest extends TestCase
{
    public function test_ucp_to_ps_maps_all_fields(): void
    {
        $ucp = [
            'street_address' => '123 Main St',
            'extended_address' => 'Suite 4',
            'address_locality' => 'San Francisco',
            'address_region' => 'CA',
            'postal_code' => '94105',
            'address_country' => 'us',
        ];

        $ps = UcpAddress::ucpToPs($ucp);

        $this->assertSame('123 Main St', $ps['address1']);
        $this->assertSame('Suite 4', $ps['address2']);
        $this->assertSame('San Francisco', $ps['city']);
        $this->assertSame('CA', $ps['state']);
        $this->assertSame('94105', $ps['postcode']);
        $this->assertSame('US', $ps['country']); // normalized to upper-case
    }

    public function test_ucp_to_ps_handles_missing_fields(): void
    {
        $ps = UcpAddress::ucpToPs(['address_country' => 'HK']);

        $this->assertSame('', $ps['address1']);
        $this->assertSame('', $ps['address2']);
        $this->assertSame('', $ps['city']);
        $this->assertSame('HK', $ps['country']);
    }

    public function test_ps_to_ucp_maps_all_fields(): void
    {
        $ps = [
            'address1' => '456 Market St',
            'address2' => 'Floor 2',
            'city' => 'Hong Kong',
            'state' => 'HK',
            'postcode' => '999077',
            'country' => 'HK',
        ];

        $ucp = UcpAddress::psToUcp($ps);

        $this->assertSame('HK', $ucp['address_country']);
        $this->assertSame('Hong Kong', $ucp['address_locality']);
        $this->assertSame('999077', $ucp['postal_code']);
        $this->assertSame('456 Market St', $ucp['street_address']);
        $this->assertSame('Floor 2', $ucp['extended_address']);
        $this->assertSame('HK', $ucp['address_region']);
    }

    public function test_ps_to_ucp_omits_empty_optional_fields(): void
    {
        $ps = [
            'address1' => '',
            'address2' => '',
            'city' => 'London',
            'state' => '',
            'postcode' => 'SW1A 1AA',
            'country' => 'GB',
        ];

        $ucp = UcpAddress::psToUcp($ps);

        $this->assertArrayNotHasKey('street_address', $ucp);
        $this->assertArrayNotHasKey('extended_address', $ucp);
        $this->assertArrayNotHasKey('address_region', $ucp);
        $this->assertSame('London', $ucp['address_locality']);
    }

    public function test_roundtrip_preserves_data(): void
    {
        $original = [
            'street_address' => '1 Market St',
            'address_locality' => 'San Francisco',
            'address_region' => 'CA',
            'postal_code' => '94105',
            'address_country' => 'US',
        ];

        $ps = UcpAddress::ucpToPs($original);
        $ucp = UcpAddress::psToUcp($ps);

        $this->assertSame($original['street_address'], $ucp['street_address']);
        $this->assertSame($original['address_locality'], $ucp['address_locality']);
        $this->assertSame($original['address_region'], $ucp['address_region']);
        $this->assertSame($original['postal_code'], $ucp['postal_code']);
        $this->assertSame($original['address_country'], $ucp['address_country']);
    }
}

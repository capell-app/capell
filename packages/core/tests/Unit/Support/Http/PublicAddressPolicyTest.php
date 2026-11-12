<?php

declare(strict_types=1);

use Capell\Core\Support\Http\PublicAddressPolicy;

it('accepts globally routable unicast addresses', function (string $address): void {
    expect((new PublicAddressPolicy)->isPublicAddress($address))->toBeTrue();
})->with([
    'IPv4' => '93.184.216.34',
    'IPv4 beside carrier-grade NAT' => '100.128.0.1',
    'IPv4 beside benchmarking' => '198.20.0.1',
    'IPv6' => '2606:2800:220:1:248:1893:25c8:1946',
    'bracketed IPv6 URL host' => '[2a00:1450:4009:81f::200e]',
]);

it('refuses addresses that PHP private and reserved range flags let through', function (string $address): void {
    expect(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))->not->toBeFalse()
        ->and((new PublicAddressPolicy)->isPublicAddress($address))->toBeFalse();
})->with([
    'carrier-grade NAT' => '100.64.0.1',
    'carrier-grade NAT upper bound' => '100.127.255.254',
    'IETF protocol assignments' => '192.0.0.8',
    'benchmarking' => '198.18.0.1',
    'benchmarking upper half' => '198.19.255.254',
    'IPv4 documentation' => '198.51.100.7',
    '6to4 relay anycast' => '192.88.99.1',
    'IPv4 multicast' => '224.0.0.251',
    'NAT64 embedding loopback' => '64:ff9b::7f00:1',
    'NAT64 local-use prefix' => '64:ff9b:1::a00:1',
    '6to4 embedding a private address' => '2002:a00:1::1',
    'Teredo' => '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    'IPv6 benchmarking' => '2001:2::1',
    'IPv6 discard' => '100::1',
    'IPv6 documentation' => '2001:db8::1',
    'deprecated IPv6 site local' => 'fec0::1',
    'IPv6 multicast' => 'ff02::1',
]);

it('refuses private, loopback, link-local and metadata addresses', function (string $address): void {
    expect((new PublicAddressPolicy)->isPublicAddress($address))->toBeFalse();
})->with([
    'unspecified IPv4' => '0.0.0.0',
    'loopback' => '127.0.0.1',
    'private class A' => '10.0.0.5',
    'private class B' => '172.16.0.1',
    'private class C' => '192.168.1.1',
    'cloud metadata' => '169.254.169.254',
    'limited broadcast' => '255.255.255.255',
    'IPv6 unspecified' => '::',
    'IPv6 loopback' => '::1',
    'bracketed IPv6 loopback' => '[::1]',
    'IPv4-compatible loopback' => '::127.0.0.1',
    'IPv4-mapped loopback' => '::ffff:127.0.0.1',
    'IPv4-mapped metadata' => '::ffff:a9fe:a9fe',
    'IPv4-mapped public address' => '::ffff:93.184.216.34',
    'unique local' => 'fd00::1',
    'link local' => 'fe80::1',
]);

it('refuses values that are not a plain IP address', function (string $value): void {
    expect((new PublicAddressPolicy)->isPublicAddress($value))->toBeFalse();
})->with([
    'empty' => '',
    'host name' => 'example.com',
    'hexadecimal IPv4' => '0x7f.1',
    'decimal IPv4' => '2130706433',
    'zone index' => 'fe80::1%eth0',
    'public address with zone index' => '2606:2800:220:1:248:1893:25c8:1946%1',
    'half bracketed' => '[::1',
]);

it('requires at least one resolved address and every resolved address to be public', function (array $addresses, bool $expected): void {
    expect((new PublicAddressPolicy)->allPublic($addresses))->toBe($expected);
})->with([
    'all public' => [['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], true],
    'nothing resolved' => [[], false],
    'one internal address among public ones' => [['93.184.216.34', '100.64.0.1'], false],
    'non-string entry' => [['93.184.216.34', null], false],
]);

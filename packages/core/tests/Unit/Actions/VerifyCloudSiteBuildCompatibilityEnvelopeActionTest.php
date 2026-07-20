<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\VerifyCloudSiteBuildCompatibilityEnvelopeAction;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Composer\InstalledVersions;

function cloudCompatibilityEnvelope(array $overrides = []): array
{
    $root = InstalledVersions::getRootPackage();
    $coreVersion = (string) ($root['pretty_version'] ?? '');
    $coreReference = (string) ($root['reference'] ?? '');
    $coreInstallPath = dirname(__DIR__, 3);
    $facts = [
        'schema_version' => 1,
        'target' => [
            'capell_api_version' => CapellExtensionApi::CURRENT_VERSION,
            'core_version' => ltrim($coreVersion, 'v'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'filament_version' => ltrim((string) InstalledVersions::getPrettyVersion('filament/filament'), 'v'),
            'platform' => strtolower(PHP_OS_FAMILY),
        ],
        'package_releases' => [[
            'name' => 'capell-app/core',
            'version' => ltrim($coreVersion, 'v'),
            'release_identity' => 'composer-reference:' . $coreReference,
            'compatibility' => [],
            'source_reference' => $coreReference,
            'artifact_sha256' => '',
            'install_manifest_sha256' => hash_file('sha256', $coreInstallPath . '/composer.json'),
        ]],
    ];

    return array_replace_recursive($facts, $overrides);
}

it('accepts a signed envelope bound to the observed target', function (): void {
    $token = str_repeat('a', 64);
    $payload = base64_encode(json_encode(cloudCompatibilityEnvelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    resolve(VerifyCloudSiteBuildCompatibilityEnvelopeAction::class)->handle(
        $token,
        ['required' => true, 'payload' => $payload, 'signature' => hash_hmac('sha256', $payload, $token)],
        '',
    );
})->throwsNoExceptions();

it('rejects tampered or incompatible target evidence', function (array $overrides, bool $tamperSignature): void {
    $token = str_repeat('b', 64);
    $payload = base64_encode(json_encode(cloudCompatibilityEnvelope($overrides), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $signature = $tamperSignature ? str_repeat('0', 64) : hash_hmac('sha256', $payload, $token);

    resolve(VerifyCloudSiteBuildCompatibilityEnvelopeAction::class)->handle(
        $token,
        ['required' => true, 'payload' => $payload, 'signature' => $signature],
        '',
    );
})->with([
    'tampered signature' => [[], true],
    'incompatible PHP target' => [['target' => ['php_version' => '9.0.0']], false],
    'wrong Core source reference' => [['package_releases' => [['source_reference' => str_repeat('0', 40)]]], false],
    'wrong Core installed manifest' => [['package_releases' => [['install_manifest_sha256' => str_repeat('0', 64)]]], false],
])->throws(RuntimeException::class, 'compatibility evidence is missing, invalid, or incompatible');

it('rejects removal of required evidence but permits an explicit trusted legacy response', function (): void {
    $action = resolve(VerifyCloudSiteBuildCompatibilityEnvelopeAction::class);

    expect(fn () => $action->handle(str_repeat('c', 64), ['required' => true], ''))
        ->toThrow(RuntimeException::class, 'compatibility evidence is missing')
        ->and(fn () => $action->handle(str_repeat('c', 64), ['required' => false], ''))
        ->not->toThrow(RuntimeException::class);
});

it('rejects a signed envelope with the wrong installed source or manifest evidence', function (string $field, string $value): void {
    $token = str_repeat('d', 64);
    $envelope = cloudCompatibilityEnvelope();
    $root = InstalledVersions::getRootPackage();
    $installPath = dirname(__DIR__, 4) . '/frontend';
    $release = [
        'name' => 'capell-app/frontend',
        'version' => ltrim((string) ($root['pretty_version'] ?? ''), 'v'),
        'release_identity' => 'sha256:' . str_repeat('1', 64),
        'compatibility' => [
            'capell_api' => '*', 'core' => '*', 'php' => '*', 'laravel' => '*', 'filament' => '*',
            'platform' => strtolower(PHP_OS_FAMILY),
        ],
        'source_reference' => (string) ($root['reference'] ?? ''),
        'artifact_sha256' => str_repeat('2', 64),
        'install_manifest_sha256' => hash_file('sha256', $installPath . '/composer.json'),
    ];
    $release[$field] = $value;
    $envelope['package_releases'][] = $release;
    $payload = base64_encode(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    resolve(VerifyCloudSiteBuildCompatibilityEnvelopeAction::class)->handle(
        $token,
        ['required' => true, 'payload' => $payload, 'signature' => hash_hmac('sha256', $payload, $token)],
        'capell-app/frontend',
    );
})->with([
    'wrong source reference' => ['source_reference', str_repeat('0', 40)],
    'wrong installed manifest' => ['install_manifest_sha256', str_repeat('0', 64)],
])->throws(RuntimeException::class, 'compatibility evidence is missing, invalid, or incompatible');

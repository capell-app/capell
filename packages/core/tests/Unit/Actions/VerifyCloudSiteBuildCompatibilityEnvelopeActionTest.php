<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\VerifyCloudSiteBuildCompatibilityEnvelopeAction;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Composer\InstalledVersions;

function cloudCompatibilityEnvelope(array $overrides = []): array
{
    $coreVersion = (string) InstalledVersions::getPrettyVersion('capell-app/core');
    $coreReference = (string) InstalledVersions::getReference('capell-app/core');
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
        ]],
    ];

    return array_replace_recursive($facts, $overrides);
}

it('accepts a signed envelope bound to the observed target', function (): void {
    $token = str_repeat('a', 64);
    $payload = base64_encode(json_encode(cloudCompatibilityEnvelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    resolve(VerifyCloudSiteBuildCompatibilityEnvelopeAction::class)->handle(
        $token,
        $payload,
        hash_hmac('sha256', $payload, $token),
        '',
    );
})->throwsNoExceptions();

it('rejects tampered or incompatible target evidence', function (array $overrides, bool $tamperSignature): void {
    $token = str_repeat('b', 64);
    $payload = base64_encode(json_encode(cloudCompatibilityEnvelope($overrides), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $signature = $tamperSignature ? str_repeat('0', 64) : hash_hmac('sha256', $payload, $token);

    resolve(VerifyCloudSiteBuildCompatibilityEnvelopeAction::class)->handle($token, $payload, $signature, '');
})->with([
    'tampered signature' => [[], true],
    'incompatible PHP target' => [['target' => ['php_version' => '9.0.0']], false],
])->throws(RuntimeException::class, 'compatibility evidence is missing, invalid, or incompatible');

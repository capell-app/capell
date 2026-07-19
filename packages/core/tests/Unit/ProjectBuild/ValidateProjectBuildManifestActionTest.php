<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestAction;
use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestAction;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Illuminate\Validation\ValidationException;

/** @return array<string, mixed> */
function validProjectBuildManifestPayload(): array
{
    return [
        'schemaVersion' => 1,
        'buildId' => '019f7bf4-45b4-70f1-b8c9-f88d8c783b41',
        'createdAt' => '2026-07-19T12:00:00+00:00',
        'siteSpec' => [
            'schemaVersion' => 1,
            'key' => 'site-spec',
            'type' => 'site-spec',
            'path' => 'artifacts/site-spec.json',
            'digest' => str_repeat('a', 64),
            'sizeBytes' => 512,
            'mediaType' => 'application/json',
        ],
        'artifacts' => [[
            'key' => 'theme',
            'type' => 'capell-theme',
            'path' => 'artifacts/theme.zip',
            'digest' => str_repeat('b', 64),
            'sizeBytes' => 4096,
            'mediaType' => 'application/zip',
        ]],
        'packages' => [[
            'name' => 'capell-app/navigation',
            'version' => '1.0.4',
            'releaseIdentity' => str_repeat('c', 40),
            'installOrder' => 10,
        ]],
        'sites' => [[
            'key' => 'primary',
            'defaultLocale' => 'en-GB',
            'locales' => ['en-GB'],
        ]],
        'routes' => [[
            'siteKey' => 'primary',
            'locale' => 'en-GB',
            'path' => '/',
        ]],
        'compatibility' => [
            'capell' => '^1.0',
            'php' => '^8.4',
            'platforms' => ['local', 'laravel-cloud'],
        ],
        'signature' => [
            'algorithm' => 'ed25519',
            'keyId' => 'capell-build-2026-01',
            'value' => base64_encode(str_repeat('s', 64)),
        ],
    ];
}

it('validates a complete manifest and produces stable canonical bytes', function (): void {
    $manifest = ValidateProjectBuildManifestAction::run(validProjectBuildManifestPayload());
    assert($manifest instanceof ProjectBuildManifestData);

    $reordered = array_reverse(validProjectBuildManifestPayload(), true);
    $reorderedManifest = ValidateProjectBuildManifestAction::run($reordered);
    assert($reorderedManifest instanceof ProjectBuildManifestData);

    $canonical = CanonicalizeProjectBuildManifestAction::run($manifest);
    $reorderedCanonical = CanonicalizeProjectBuildManifestAction::run($reorderedManifest);

    expect($canonical)->toBe($reorderedCanonical)
        ->and(json_decode($canonical, true, 512, JSON_THROW_ON_ERROR))->toBeArray()
        ->and(hash('sha256', $canonical))->toHaveLength(64);
});

it('accepts schema-compatible RFC 3339 timestamp forms', function (string $createdAt): void {
    $payload = validProjectBuildManifestPayload();
    $payload['createdAt'] = $createdAt;

    expect(ValidateProjectBuildManifestAction::run($payload)->createdAt)->toBe($createdAt);
})->with([
    'Zulu' => '2026-07-19T12:00:00Z',
    'fractional Zulu' => '2026-07-19T12:00:00.123456Z',
    'offset' => '2026-07-19T12:00:00+01:00',
    'fractional offset' => '2026-07-19T12:00:00.1-04:00',
]);

it('rejects structurally unsafe or inconsistent manifests', function (Closure $mutate): void {
    $payload = validProjectBuildManifestPayload();
    $mutate($payload);

    expect(fn (): mixed => ValidateProjectBuildManifestAction::run($payload))
        ->toThrow(ValidationException::class);
})->with([
    'future schema' => [static function (array &$payload): void {
        $payload['schemaVersion'] = 2;
    }],
    'future SiteSpec schema' => [static function (array &$payload): void {
        $payload['siteSpec']['schemaVersion'] = 2;
    }],
    'unknown root property' => [static function (array &$payload): void {
        $payload['customerId'] = 123;
    }],
    'associative artifact collection' => [static function (array &$payload): void {
        $payload['artifacts'] = ['theme' => $payload['artifacts'][0]];
    }],
    'associative package collection' => [static function (array &$payload): void {
        $payload['packages'] = ['navigation' => $payload['packages'][0]];
    }],
    'associative site collection' => [static function (array &$payload): void {
        $payload['sites'] = ['primary' => $payload['sites'][0]];
    }],
    'associative locale collection' => [static function (array &$payload): void {
        $payload['sites'][0]['locales'] = ['default' => 'en-GB'];
    }],
    'associative route collection' => [static function (array &$payload): void {
        $payload['routes'] = ['home' => $payload['routes'][0]];
    }],
    'associative platform collection' => [static function (array &$payload): void {
        $payload['compatibility']['platforms'] = ['local' => 'local'];
    }],
    'calendar-invalid timestamp' => [static function (array &$payload): void {
        $payload['createdAt'] = '2026-02-30T12:00:00Z';
    }],
    'unsafe artifact path' => [static function (array &$payload): void {
        $payload['artifacts'][0]['path'] = '../theme.zip';
    }],
    'malformed digest' => [static function (array &$payload): void {
        $payload['siteSpec']['digest'] = 'ABC';
    }],
    'duplicate artifact key' => [static function (array &$payload): void {
        $payload['artifacts'][0]['key'] = 'site-spec';
    }],
    'missing default locale' => [static function (array &$payload): void {
        $payload['sites'][0]['defaultLocale'] = 'fr-FR';
    }],
    'unknown route site' => [static function (array &$payload): void {
        $payload['routes'][0]['siteKey'] = 'missing';
    }],
    'unknown route locale' => [static function (array &$payload): void {
        $payload['routes'][0]['locale'] = 'fr-FR';
    }],
    'duplicate install order' => [static function (array &$payload): void {
        $payload['packages'][] = [
            'name' => 'capell-app/form-builder',
            'version' => '1.0.1',
            'releaseIdentity' => str_repeat('d', 40),
            'installOrder' => 10,
        ];
    }],
    'invalid route path' => [static function (array &$payload): void {
        $payload['routes'][0]['path'] = 'https://example.com';
    }],
    'duplicate site locale' => [static function (array &$payload): void {
        $payload['sites'][0]['locales'][] = 'en-GB';
    }],
    'invalid signature length' => [static function (array &$payload): void {
        $payload['signature']['value'] = base64_encode('too-short');
    }],
]);

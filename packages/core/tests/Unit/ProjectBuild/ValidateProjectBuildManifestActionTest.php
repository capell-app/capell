<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestAction;
use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestAction;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Tests\Support\ProjectBuildManifestFixture;
use Illuminate\Validation\ValidationException;

it('validates a complete manifest and produces stable canonical bytes', function (): void {
    $manifest = ValidateProjectBuildManifestAction::run(ProjectBuildManifestFixture::payload());
    assert($manifest instanceof ProjectBuildManifestData);

    $reordered = array_reverse(ProjectBuildManifestFixture::payload(), true);
    $reorderedManifest = ValidateProjectBuildManifestAction::run($reordered);
    assert($reorderedManifest instanceof ProjectBuildManifestData);

    $canonical = CanonicalizeProjectBuildManifestAction::run($manifest);
    $reorderedCanonical = CanonicalizeProjectBuildManifestAction::run($reorderedManifest);

    expect($canonical)->toBe($reorderedCanonical)
        ->and(json_decode($canonical, true, 512, JSON_THROW_ON_ERROR))->toBeArray()
        ->and(hash('sha256', $canonical))->toHaveLength(64);
});

it('accepts schema-compatible RFC 3339 timestamp forms', function (string $createdAt): void {
    $payload = ProjectBuildManifestFixture::payload();
    $payload['createdAt'] = $createdAt;

    expect(ValidateProjectBuildManifestAction::run($payload)->createdAt)->toBe($createdAt);
})->with([
    'Zulu' => '2026-07-19T12:00:00Z',
    'fractional Zulu' => '2026-07-19T12:00:00.123456Z',
    'offset' => '2026-07-19T12:00:00+01:00',
    'fractional offset' => '2026-07-19T12:00:00.1-04:00',
]);

it('rejects structurally unsafe or inconsistent manifests', function (Closure $mutate): void {
    $payload = ProjectBuildManifestFixture::payload();
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
    'empty Capell compatibility' => [static function (array &$payload): void {
        $payload['compatibility']['capell'] = '';
    }],
    'empty PHP compatibility' => [static function (array &$payload): void {
        $payload['compatibility']['php'] = '';
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

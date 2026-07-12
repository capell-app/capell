<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\ManifestValidator;

it('rejects legacy manifest v2 contracts', function (): void {
    $validator = new ManifestValidator;

    expect(fn () => $validator->validate([
        'manifest-version' => 2,
        'name' => 'capell-app/blog',
        'kind' => 'package',
        'capell-version' => '^1.0',
        'surfaces' => ['admin', 'frontend'],
    ]))->toThrow(InvalidManifestException::class, 'manifest-version 3');
});

it('rejects v3 manifests that keep the v2 capell-version field', function (): void {
    $validator = new ManifestValidator;

    $manifest = manifestV3ValidatorLegacyFixture();
    $manifest['capell-version'] = '^1.0';

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ValidatorComposerJson()))
        ->toThrow(InvalidManifestException::class, 'capell-version');
});

it('accepts a minimal manifest v3 contract', function (): void {
    $validator = new ManifestValidator;

    expect(fn () => $validator->validate(
        manifestV3ValidatorLegacyFixture(),
        composerJson: manifestV3ValidatorComposerJson(),
    ))->not->toThrow(InvalidManifestException::class);
});

it('accepts every shared extension surface taxonomy value plus legacy runtime surfaces', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3ValidatorLegacyFixture();
    $manifest['surfaces'] = [
        'content',
        'admin',
        'frontend',
        'workflow',
        'delivery',
        'operations',
        'integrations',
        'marketplace',
        'console',
        'shared',
    ];

    expect(fn () => $validator->validate(
        $manifest,
        composerJson: manifestV3ValidatorComposerJson(),
    ))->not->toThrow(InvalidManifestException::class);
});

function manifestV3ValidatorLegacyFixture(): array
{
    return capellManifestV3Array();
}

function manifestV3ValidatorComposerJson(): array
{
    return [
        'name' => 'vendor/package',
        'autoload' => [
            'psr-4' => [
                'Vendor\\Package\\' => 'src/',
            ],
        ],
    ];
}

<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

final class ProjectBuildManifestFixture
{
    /** @return array<string, mixed> */
    public static function payload(): array
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
}

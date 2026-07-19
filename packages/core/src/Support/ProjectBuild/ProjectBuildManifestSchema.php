<?php

declare(strict_types=1);

namespace Capell\Core\Support\ProjectBuild;

final class ProjectBuildManifestSchema
{
    /** @return array<string, mixed> */
    public static function toArray(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://schemas.capell.app/project-build-manifest/v1.json',
            'title' => 'Capell Project Build Manifest v1',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schemaVersion', 'buildId', 'createdAt', 'siteSpec', 'artifacts', 'packages', 'sites', 'routes', 'compatibility', 'signature'],
            'properties' => [
                'schemaVersion' => ['const' => 1],
                'buildId' => ['type' => 'string', 'format' => 'uuid'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'siteSpec' => ['$ref' => '#/$defs/artifact'],
                'artifacts' => ['type' => 'array', 'maxItems' => 1000, 'items' => ['$ref' => '#/$defs/artifact']],
                'packages' => ['type' => 'array', 'maxItems' => 250, 'items' => ['$ref' => '#/$defs/package']],
                'sites' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['$ref' => '#/$defs/site']],
                'routes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10000, 'items' => ['$ref' => '#/$defs/route']],
                'compatibility' => ['$ref' => '#/$defs/compatibility'],
                'signature' => ['$ref' => '#/$defs/signature'],
            ],
            '$defs' => [
                'artifact' => self::object(
                    ['key', 'type', 'path', 'digest', 'sizeBytes', 'mediaType'],
                    [
                        'key' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{0,99}$'],
                        'type' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,63}$'],
                        'path' => ['type' => 'string', 'pattern' => '^(?!/)(?!.*(?:^|/)\.\.(?:/|$))(?!.*\\\\)[^/]+(?:/[^/]+)*$'],
                        'digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                        'sizeBytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483648],
                        'mediaType' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$'],
                    ],
                ),
                'package' => self::object(
                    ['name', 'version', 'releaseIdentity', 'installOrder'],
                    [
                        'name' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$'],
                        'version' => ['type' => 'string', 'pattern' => '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'],
                        'releaseIdentity' => ['type' => 'string', 'pattern' => '^[a-f0-9]{40}$'],
                        'installOrder' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ),
                'site' => self::object(
                    ['key', 'defaultLocale', 'locales'],
                    [
                        'key' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,63}$'],
                        'defaultLocale' => ['$ref' => '#/$defs/locale'],
                        'locales' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['$ref' => '#/$defs/locale']],
                    ],
                ),
                'route' => self::object(
                    ['siteKey', 'locale', 'path'],
                    [
                        'siteKey' => ['type' => 'string'],
                        'locale' => ['$ref' => '#/$defs/locale'],
                        'path' => ['type' => 'string', 'pattern' => '^/'],
                    ],
                ),
                'compatibility' => self::object(
                    ['capell', 'php', 'platforms'],
                    [
                        'capell' => ['type' => 'string'],
                        'php' => ['type' => 'string'],
                        'platforms' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,63}$']],
                    ],
                ),
                'signature' => self::object(
                    ['algorithm', 'keyId', 'value'],
                    [
                        'algorithm' => ['const' => 'ed25519'],
                        'keyId' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'],
                        'value' => ['type' => 'string', 'contentEncoding' => 'base64', 'minLength' => 88, 'maxLength' => 88],
                    ],
                ),
                'locale' => ['type' => 'string', 'pattern' => '^[a-z]{2,3}(?:-[A-Z]{2})?$'],
            ],
        ];
    }

    /**
     * @param  list<string>  $required
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $required, array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }
}

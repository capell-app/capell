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
                'schemaVersion' => ['type' => 'integer', 'const' => ProjectBuildManifestConstraints::CURRENT_SCHEMA_VERSION],
                'buildId' => ['type' => 'string', 'format' => 'uuid'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time', 'pattern' => ProjectBuildManifestConstraints::DATE_TIME_PATTERN],
                'siteSpec' => ['$ref' => '#/$defs/siteSpecArtifact'],
                'artifacts' => ['type' => 'array', 'maxItems' => ProjectBuildManifestConstraints::MAX_ARTIFACTS, 'items' => ['$ref' => '#/$defs/artifact']],
                'packages' => ['type' => 'array', 'maxItems' => ProjectBuildManifestConstraints::MAX_PACKAGES, 'items' => ['$ref' => '#/$defs/package']],
                'sites' => ['type' => 'array', 'minItems' => 1, 'maxItems' => ProjectBuildManifestConstraints::MAX_SITES, 'items' => ['$ref' => '#/$defs/site']],
                'routes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => ProjectBuildManifestConstraints::MAX_ROUTES, 'items' => ['$ref' => '#/$defs/route']],
                'compatibility' => ['$ref' => '#/$defs/compatibility'],
                'signature' => ['$ref' => '#/$defs/signature'],
            ],
            '$defs' => [
                'artifact' => self::object(
                    ['key', 'type', 'path', 'digest', 'sizeBytes', 'mediaType'],
                    [
                        'key' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::ARTIFACT_KEY_PATTERN],
                        'type' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::ARTIFACT_TYPE_PATTERN],
                        'path' => ['type' => 'string', 'maxLength' => ProjectBuildManifestConstraints::MAX_ARTIFACT_PATH_LENGTH, 'pattern' => ProjectBuildManifestConstraints::ARTIFACT_PATH_PATTERN],
                        'digest' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::DIGEST_PATTERN],
                        'sizeBytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ProjectBuildManifestConstraints::MAX_ARTIFACT_SIZE_BYTES],
                        'mediaType' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::MEDIA_TYPE_PATTERN],
                    ],
                ),
                'siteSpecArtifact' => self::object(
                    ['schemaVersion', 'key', 'type', 'path', 'digest', 'sizeBytes', 'mediaType'],
                    [
                        'schemaVersion' => ['type' => 'integer', 'const' => ProjectBuildManifestConstraints::CURRENT_SITE_SPEC_SCHEMA_VERSION],
                        'key' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::ARTIFACT_KEY_PATTERN],
                        'type' => ['const' => 'site-spec'],
                        'path' => ['type' => 'string', 'maxLength' => ProjectBuildManifestConstraints::MAX_ARTIFACT_PATH_LENGTH, 'pattern' => ProjectBuildManifestConstraints::ARTIFACT_PATH_PATTERN],
                        'digest' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::DIGEST_PATTERN],
                        'sizeBytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ProjectBuildManifestConstraints::MAX_ARTIFACT_SIZE_BYTES],
                        'mediaType' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::MEDIA_TYPE_PATTERN],
                    ],
                ),
                'package' => self::object(
                    ['name', 'version', 'releaseIdentity', 'installOrder'],
                    [
                        'name' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::PACKAGE_NAME_PATTERN],
                        'version' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::PACKAGE_VERSION_PATTERN],
                        'releaseIdentity' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::RELEASE_IDENTITY_PATTERN],
                        'installOrder' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ),
                'site' => self::object(
                    ['key', 'defaultLocale', 'locales'],
                    [
                        'key' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::SITE_KEY_PATTERN],
                        'defaultLocale' => ['$ref' => '#/$defs/locale'],
                        'locales' => ['type' => 'array', 'minItems' => 1, 'maxItems' => ProjectBuildManifestConstraints::MAX_LOCALES_PER_SITE, 'uniqueItems' => true, 'items' => ['$ref' => '#/$defs/locale']],
                    ],
                ),
                'route' => self::object(
                    ['siteKey', 'locale', 'path'],
                    [
                        'siteKey' => ['type' => 'string'],
                        'locale' => ['$ref' => '#/$defs/locale'],
                        'path' => ['type' => 'string', 'maxLength' => ProjectBuildManifestConstraints::MAX_ROUTE_PATH_LENGTH, 'pattern' => ProjectBuildManifestConstraints::ROUTE_PATH_PATTERN],
                    ],
                ),
                'compatibility' => self::object(
                    ['capell', 'php', 'platforms'],
                    [
                        'capell' => ['type' => 'string', 'maxLength' => ProjectBuildManifestConstraints::MAX_COMPATIBILITY_LENGTH],
                        'php' => ['type' => 'string', 'maxLength' => ProjectBuildManifestConstraints::MAX_COMPATIBILITY_LENGTH],
                        'platforms' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::PLATFORM_PATTERN]],
                    ],
                ),
                'signature' => self::object(
                    ['algorithm', 'keyId', 'value'],
                    [
                        'algorithm' => ['const' => 'ed25519'],
                        'keyId' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::KEY_ID_PATTERN],
                        'value' => ['type' => 'string', 'contentEncoding' => 'base64', 'minLength' => ProjectBuildManifestConstraints::ED25519_SIGNATURE_BASE64_LENGTH, 'maxLength' => ProjectBuildManifestConstraints::ED25519_SIGNATURE_BASE64_LENGTH, 'pattern' => ProjectBuildManifestConstraints::SIGNATURE_PATTERN],
                    ],
                ),
                'locale' => ['type' => 'string', 'pattern' => ProjectBuildManifestConstraints::LOCALE_PATTERN],
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

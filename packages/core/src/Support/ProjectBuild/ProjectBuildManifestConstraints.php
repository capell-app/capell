<?php

declare(strict_types=1);

namespace Capell\Core\Support\ProjectBuild;

final class ProjectBuildManifestConstraints
{
    public const int CURRENT_SCHEMA_VERSION = 1;

    public const int CURRENT_SITE_SPEC_SCHEMA_VERSION = 1;

    public const int MAX_ARTIFACTS = 1000;

    public const int MAX_ARTIFACT_PATH_LENGTH = 255;

    public const int MAX_ARTIFACT_SIZE_BYTES = 2147483648;

    public const int MAX_PACKAGES = 250;

    public const int MAX_SITES = 100;

    public const int MAX_LOCALES_PER_SITE = 100;

    public const int MAX_ROUTES = 10000;

    public const int MAX_ROUTE_PATH_LENGTH = 2048;

    public const int MAX_COMPATIBILITY_LENGTH = 100;

    public const int ED25519_SIGNATURE_BYTES = 64;

    public const int ED25519_SIGNATURE_BASE64_LENGTH = 88;

    public const string ARTIFACT_KEY_PATTERN = '^[a-z0-9][a-z0-9._-]{0,99}$';

    public const string ARTIFACT_TYPE_PATTERN = '^[a-z0-9][a-z0-9-]{0,63}$';

    public const string ARTIFACT_PATH_PATTERN = '^(?!/)(?!.*(?:^|/)\.\.(?:/|$))(?!.*\\\\)[^/]+(?:/[^/]+)*$';

    public const string DIGEST_PATTERN = '^[a-f0-9]{64}$';

    public const string MEDIA_TYPE_PATTERN = '^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$';

    public const string PACKAGE_NAME_PATTERN = '^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$';

    public const string PACKAGE_VERSION_PATTERN = '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$';

    public const string RELEASE_IDENTITY_PATTERN = '^[a-f0-9]{40}$';

    public const string SITE_KEY_PATTERN = '^[a-z0-9][a-z0-9-]{0,63}$';

    public const string LOCALE_PATTERN = '^[a-z]{2,3}(?:-[A-Z]{2})?$';

    public const string ROUTE_PATH_PATTERN = '^/(?:[A-Za-z0-9._~!$&\'()*+,;=:@%-]+/?)*$';

    public const string PLATFORM_PATTERN = '^[a-z0-9][a-z0-9-]{0,63}$';

    public const string KEY_ID_PATTERN = '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$';

    public const string SIGNATURE_PATTERN = '^(?:[A-Za-z0-9+/]{4}){21}[A-Za-z0-9+/]{2}==$';

    public const string DATE_TIME_PATTERN = '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$';
}

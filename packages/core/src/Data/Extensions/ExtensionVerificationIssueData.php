<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionVerificationIssueData extends Data
{
    public const string NOT_INSTALLED = 'not_installed';

    public const string VERSION_OUTSIDE_CONSTRAINT = 'version_outside_constraint';

    public const string NOT_AN_UPGRADE = 'not_an_upgrade';

    public const string COMPOSER_PLUGIN = 'composer_plugin';

    public const string MANIFEST_MISSING = 'manifest_missing';

    public const string MANIFEST_INVALID = 'manifest_invalid';

    public const string MANIFEST_IDENTITY_MISMATCH = 'manifest_identity_mismatch';

    public const string AUTOLOAD_ESCAPES_PACKAGE = 'autoload_escapes_package';

    public const string PROVIDER_FILE_MISSING = 'provider_file_missing';

    public const string CHECKSUM_MISMATCH = 'checksum_mismatch';

    public const string CHECKSUM_MISSING = 'checksum_missing';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $nextAction,
    ) {}
}

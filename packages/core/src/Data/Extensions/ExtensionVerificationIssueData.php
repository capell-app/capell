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

    public const string CONTENT_HASH_MISMATCH = 'content_hash_mismatch';

    public const string CONTENT_HASH_UNREADABLE = 'content_hash_unreadable';

    public const string QUARANTINED = 'quarantined';

    public const string CHECKSUM_MISSING = 'checksum_missing';

    public const string CHECKSUM_NOT_FOR_INSTALLED_RELEASE = 'checksum_not_for_installed_release';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $nextAction,
    ) {}

    /** @return array{message: string, next_action: string} */
    public static function describe(string $code, string $package, ?string $reason = null): array
    {
        $replace = ['package' => $package, 'reason' => $reason];

        return match ($code) {
            self::NOT_INSTALLED => [
                'message' => (string) __('capell-core::extensions.verification.not_installed.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.not_installed.next_action', $replace),
            ],
            self::VERSION_OUTSIDE_CONSTRAINT => [
                'message' => (string) __('capell-core::extensions.verification.version_outside_constraint.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.version_outside_constraint.next_action', $replace),
            ],
            self::NOT_AN_UPGRADE => [
                'message' => (string) __('capell-core::extensions.verification.not_an_upgrade.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.not_an_upgrade.next_action', $replace),
            ],
            self::COMPOSER_PLUGIN => [
                'message' => (string) __('capell-core::extensions.verification.composer_plugin.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.composer_plugin.next_action', $replace),
            ],
            self::MANIFEST_MISSING => [
                'message' => (string) __('capell-core::extensions.verification.manifest_missing.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.manifest_missing.next_action', $replace),
            ],
            self::MANIFEST_INVALID => [
                'message' => $reason === null ? (string) __('capell-core::extensions.verification.manifest_invalid_safe', $replace) : (string) __('capell-core::extensions.verification.manifest_invalid.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.manifest_invalid.next_action', $replace),
            ],
            self::MANIFEST_IDENTITY_MISMATCH => [
                'message' => (string) __('capell-core::extensions.verification.manifest_identity_mismatch.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.manifest_identity_mismatch.next_action', $replace),
            ],
            self::AUTOLOAD_ESCAPES_PACKAGE => [
                'message' => (string) __('capell-core::extensions.verification.autoload_escapes_package.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.autoload_escapes_package.next_action', $replace),
            ],
            self::PROVIDER_FILE_MISSING => [
                'message' => (string) __('capell-core::extensions.verification.provider_file_missing.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.provider_file_missing.next_action', $replace),
            ],
            self::CHECKSUM_MISMATCH => [
                'message' => (string) __('capell-core::extensions.verification.checksum_mismatch.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.checksum_mismatch.next_action', $replace),
            ],
            self::CONTENT_HASH_MISMATCH => [
                'message' => (string) __('capell-core::extensions.verification.content_hash_mismatch.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.content_hash_mismatch.next_action', $replace),
            ],
            self::CONTENT_HASH_UNREADABLE => [
                'message' => (string) __('capell-core::extensions.verification.content_hash_unreadable.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.content_hash_unreadable.next_action', $replace),
            ],
            self::QUARANTINED => [
                'message' => (string) __('capell-core::extensions.verification.quarantined.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.quarantined.next_action', $replace),
            ],
            self::CHECKSUM_MISSING => [
                'message' => (string) __('capell-core::extensions.verification.checksum_missing.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.checksum_missing.next_action', $replace),
            ],
            self::CHECKSUM_NOT_FOR_INSTALLED_RELEASE => [
                'message' => (string) __('capell-core::extensions.verification.checksum_not_for_installed_release.message', $replace),
                'next_action' => (string) __('capell-core::extensions.verification.checksum_not_for_installed_release.next_action', $replace),
            ],
            default => ['message' => '', 'next_action' => ''],
        };
    }
}

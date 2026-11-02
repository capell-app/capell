<?php

declare(strict_types=1);

return [
    'verification' => [
        'manifest_invalid_safe' => 'The manifest for :package is invalid, so the extension cannot be verified.',
        'skipped' => 'Verification is not required for the trusted package :package.',
        'passed' => 'Verification passed for :package.',
        'failed' => 'Verification failed for :package.',
        'continued' => 'Verification failed for :package; continuing under the current policy.',
        'not_installed' => [
            'message' => 'The package :package is not installed, so it cannot be verified.',
            'next_action' => 'Install the package and try again.',
        ],
        'version_outside_constraint' => [
            'message' => 'The installed version of :package does not meet the requested version, so it cannot be accepted.',
            'next_action' => 'Install a version matching the requested constraint.',
        ],
        'not_an_upgrade' => [
            'message' => 'The installed version of :package is not newer than the previous version, so the upgrade cannot be accepted.',
            'next_action' => 'Install a newer version and try again.',
        ],
        'composer_plugin' => [
            'message' => 'The package :package declares a Composer plugin, so it cannot be accepted under the current policy.',
            'next_action' => 'Review the package and plugin policy before trying again.',
        ],
        'manifest_missing' => [
            'message' => 'The manifest for :package is missing, so the extension cannot be verified.',
            'next_action' => 'Reinstall the package with its manifest.',
        ],
        'manifest_invalid' => [
            'message' => 'The manifest for :package is invalid, so the extension cannot be verified: :reason',
            'next_action' => 'Correct the manifest and try again.',
        ],
        'manifest_identity_mismatch' => [
            'message' => 'The manifest identity for :package does not match the requested extension, so it cannot be accepted.',
            'next_action' => 'Install the intended package and try again.',
        ],
        'autoload_escapes_package' => [
            'message' => 'An autoload entry for :package is missing or points outside the package, so it cannot be accepted.',
            'next_action' => 'Correct the package autoload entries and try again.',
        ],
        'provider_file_missing' => [
            'message' => 'A class declared by :package has no matching file inside the package, so it cannot be accepted.',
            'next_action' => 'Restore the declared class file and try again.',
        ],
        'checksum_missing' => [
            'message' => 'The distribution checksum for :package is missing, so it cannot be compared.',
            'next_action' => 'Reinstall the package with its expected checksum.',
        ],
        'checksum_mismatch' => [
            'message' => 'The distribution checksum for :package differs from the expected value, so it cannot be accepted.',
            'next_action' => 'Obtain the package from a trusted source and try again.',
        ],
    ],
    'migration_cleanup_blocked' => 'Uninstall is incomplete because these published migrations could not be removed: :paths. Correct their filesystem permissions, then retry: :command. Uninstall hooks, data deletion and package removal have not run.',
    'auto_update_policies' => [
        'none' => 'Never update automatically',
        'patch' => 'Patch releases only',
        'minor' => 'Patch and minor releases',
        'security' => 'Security releases only',
    ],
    'release_kinds' => [
        'patch' => 'Patch release',
        'minor' => 'Minor release',
        'major' => 'Major release',
        'unknown' => 'Unknown release',
    ],
];

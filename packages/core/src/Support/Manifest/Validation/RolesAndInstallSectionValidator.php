<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Override;

/**
 * roles: ["default-theme"], rolePriority: 10 and install: {"tips": [...], "legacyTables": [...]}.
 * All three are optional; an absent field declares nothing.
 */
final readonly class RolesAndInstallSectionValidator implements ManifestSectionValidator
{
    /** @var list<string> */
    private const array INSTALL_FIELDS = ['tips', 'legacyTables'];

    #[Override]
    public function validate(array $data): void
    {
        $this->validateRoles($data);

        if (array_key_exists('rolePriority', $data) && ! is_int($data['rolePriority'])) {
            throw InvalidManifestException::invalidField('rolePriority', 'must be an integer');
        }

        $this->validateInstall($data);
    }

    /** @param array<string, mixed> $data */
    private function validateRoles(array $data): void
    {
        if (! array_key_exists('roles', $data)) {
            return;
        }

        if (! is_array($data['roles']) || ! array_is_list($data['roles'])) {
            throw InvalidManifestException::invalidField('roles', 'must be a list');
        }

        foreach ($data['roles'] as $role) {
            if (! is_string($role) || PackageRole::tryFrom($role) === null) {
                throw InvalidManifestException::invalidField('roles', 'must contain only: ' . implode(', ', PackageRole::values()));
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateInstall(array $data): void
    {
        if (! array_key_exists('install', $data)) {
            return;
        }

        $install = $data['install'];

        if (! is_array($install) || ($install !== [] && array_is_list($install))) {
            throw InvalidManifestException::invalidField('install', 'must be an object');
        }

        foreach (array_keys($install) as $field) {
            if (! in_array($field, self::INSTALL_FIELDS, true)) {
                throw InvalidManifestException::invalidField('install.' . $field, 'must be one of: ' . implode(', ', self::INSTALL_FIELDS));
            }
        }

        if (array_key_exists('tips', $install)) {
            $this->validateEntries($install['tips'], 'install.tips', ['variant' => 'string', 'priority' => 'int']);
        }

        if (array_key_exists('legacyTables', $install)) {
            $this->validateEntries($install['legacyTables'], 'install.legacyTables', ['name' => 'string', 'configKey' => 'string', 'migration' => 'string']);
        }
    }

    /** @param array<string, 'string'|'int'> $shape */
    private function validateEntries(mixed $entries, string $path, array $shape): void
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw InvalidManifestException::invalidField($path, 'must be a list');
        }

        foreach ($entries as $index => $entry) {
            $entryPath = $path . '.' . $index;

            if (! is_array($entry) || array_is_list($entry)) {
                throw InvalidManifestException::invalidField($entryPath, 'must be an object');
            }

            foreach (array_keys($entry) as $field) {
                if (! array_key_exists($field, $shape)) {
                    throw InvalidManifestException::invalidField($entryPath . '.' . $field, 'must be one of: ' . implode(', ', array_keys($shape)));
                }
            }

            foreach ($shape as $field => $type) {
                $value = $entry[$field] ?? null;
                $valid = $type === 'int' ? is_int($value) : is_string($value) && $value !== '';

                if (! $valid) {
                    throw InvalidManifestException::invalidField(
                        $entryPath . '.' . $field,
                        $type === 'int' ? 'must be an integer' : 'must be a non-empty string',
                    );
                }
            }
        }
    }
}

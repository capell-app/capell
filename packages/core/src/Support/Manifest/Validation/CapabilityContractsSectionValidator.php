<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Enums\CapabilityContract;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Override;

/**
 * capabilityContracts: {"provides": {"form-submissions": 1}, "consumes": {"form-catalogue": 1}}.
 * Keys must name a Core CapabilityContract and versions must be a major version Core defines.
 */
final readonly class CapabilityContractsSectionValidator implements ManifestSectionValidator
{
    private const array FIELDS = ['provides', 'consumes'];

    #[Override]
    public function validate(array $data): void
    {
        if (! array_key_exists('capabilityContracts', $data)) {
            return;
        }

        $section = $data['capabilityContracts'];

        if (! is_array($section) || ($section !== [] && array_is_list($section))) {
            throw InvalidManifestException::invalidField('capabilityContracts', 'must be an object');
        }

        foreach ($section as $field => $versions) {
            if (! in_array($field, self::FIELDS, true)) {
                throw InvalidManifestException::invalidField('capabilityContracts.' . $field, 'must be provides or consumes');
            }

            $this->validateVersions('capabilityContracts.' . $field, $versions);
        }
    }

    private function validateVersions(string $path, mixed $versions): void
    {
        if (! is_array($versions) || ($versions !== [] && array_is_list($versions))) {
            throw InvalidManifestException::invalidField($path, 'must map capability keys to major versions');
        }

        foreach ($versions as $key => $version) {
            $capability = is_string($key) ? CapabilityContract::tryFrom($key) : null;

            if (! $capability instanceof CapabilityContract) {
                throw InvalidManifestException::invalidField($path . '.' . $key, sprintf(
                    'is not a Core capability contract; use one of %s',
                    implode(', ', array_map(static fn (CapabilityContract $case): string => $case->value, CapabilityContract::cases())),
                ));
            }

            if (! is_int($version) || $version < 1 || $version > $capability->version()) {
                throw InvalidManifestException::invalidField($path . '.' . $key, sprintf('must be a major version from 1 to %d', $capability->version()));
            }
        }
    }
}

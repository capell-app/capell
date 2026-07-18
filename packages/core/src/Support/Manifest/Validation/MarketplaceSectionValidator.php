<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;

final readonly class MarketplaceSectionValidator implements ManifestSectionValidator
{
    public function __construct(private ManifestValidationRules $rules) {}

    public function validate(array $data): void
    {
        if (! is_array($data['marketplace'] ?? null)) {
            throw InvalidManifestException::missingField('marketplace');
        }

        $marketplace = $data['marketplace'];
        $this->rules->requiredStrings($marketplace, ['summary']);
        $this->rules->stringList($marketplace, 'categories');

        if (array_key_exists('hidden', $marketplace) && ! is_bool($marketplace['hidden'])) {
            throw InvalidManifestException::invalidField('marketplace.hidden', 'must be a boolean');
        }

        if (! is_array($marketplace['screenshots'] ?? null) || ! array_is_list($marketplace['screenshots'])) {
            throw InvalidManifestException::missingField('marketplace.screenshots');
        }

        foreach ($marketplace['screenshots'] as $index => $screenshot) {
            if (! is_array($screenshot)) {
                throw InvalidManifestException::invalidField('marketplace.screenshots.' . $index, 'must be an object');
            }

            $this->rules->requiredStrings($screenshot, ['path', 'alt', 'caption']);

            foreach (['alt', 'caption'] as $field) {
                if (mb_strlen(trim((string) $screenshot[$field])) < 12) {
                    throw InvalidManifestException::invalidField(sprintf('marketplace.screenshots.%d.%s', $index, $field), 'must be usable descriptive text');
                }
            }
        }
    }
}

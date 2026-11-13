<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Enums\ConsentCategory;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Override;

/**
 * privacy: {"purposes": {"youtube": "marketing"}}. Each named purpose a package asks
 * consent for maps to one optional consent category. Essential is refused: a purpose
 * marked essential would load without ever asking, so a package cannot opt its embeds
 * out of consent.
 */
final readonly class PrivacySectionValidator implements ManifestSectionValidator
{
    public const string PURPOSE_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

    /** @return list<string> */
    public static function categories(): array
    {
        return array_values(array_filter(
            ConsentCategory::values(),
            static fn (string $category): bool => $category !== ConsentCategory::Essential->value,
        ));
    }

    #[Override]
    public function validate(array $data): void
    {
        if (! array_key_exists('privacy', $data)) {
            return;
        }

        $section = $data['privacy'];

        if (! is_array($section) || ($section !== [] && array_is_list($section))) {
            throw InvalidManifestException::invalidField('privacy', 'must be an object');
        }

        foreach ($section as $field => $value) {
            if ($field !== 'purposes') {
                throw InvalidManifestException::invalidField('privacy.' . $field, 'must be purposes');
            }

            $this->validatePurposes($value);
        }
    }

    private function validatePurposes(mixed $purposes): void
    {
        if (! is_array($purposes) || ($purposes !== [] && array_is_list($purposes))) {
            throw InvalidManifestException::invalidField('privacy.purposes', 'must map purpose names to consent categories');
        }

        foreach ($purposes as $purpose => $category) {
            $path = 'privacy.purposes.' . $purpose;

            if (! is_string($purpose) || preg_match(self::PURPOSE_PATTERN, $purpose) !== 1) {
                throw InvalidManifestException::invalidField($path, 'must be lower case letters, digits, dots, dashes or underscores');
            }

            if (ConsentCategory::tryFrom($purpose) instanceof ConsentCategory) {
                throw InvalidManifestException::invalidField($path, 'is a consent category name; name the purpose instead, for example youtube');
            }

            if (! is_string($category) || ! in_array($category, self::categories(), true)) {
                throw InvalidManifestException::invalidField($path, 'must be one of ' . implode(', ', self::categories()));
            }
        }
    }
}

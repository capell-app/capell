<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;

final class ManifestValidationRules
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    public function requiredStrings(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw InvalidManifestException::missingField($field);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function stringList(array $data, string $field, bool $required = true): void
    {
        if (! array_key_exists($field, $data)) {
            if ($required) {
                throw InvalidManifestException::missingField($field);
            }

            return;
        }

        if (! is_array($data[$field]) || ! array_is_list($data[$field])) {
            throw InvalidManifestException::invalidField($field, 'must be a list');
        }

        foreach ($data[$field] as $value) {
            if (! is_string($value) || $value === '') {
                throw InvalidManifestException::invalidField($field, 'must contain non-empty strings');
            }
        }
    }
}

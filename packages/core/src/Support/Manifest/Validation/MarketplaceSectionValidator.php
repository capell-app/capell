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

        $this->validateOutcomeCopy($marketplace);

        if (! is_array($marketplace['screenshots'] ?? null) || ! array_is_list($marketplace['screenshots'])) {
            throw InvalidManifestException::missingField('marketplace.screenshots');
        }

        foreach ($marketplace['screenshots'] as $index => $screenshot) {
            if (! is_array($screenshot)) {
                throw InvalidManifestException::invalidField('marketplace.screenshots.' . $index, 'must be an object');
            }

            $this->rules->requiredStrings($screenshot, ['path', 'alt', 'caption']);

            if (! $this->isSafePackageRelativePath((string) $screenshot['path'])) {
                throw InvalidManifestException::invalidField(
                    sprintf('marketplace.screenshots.%d.path', $index),
                    'must be a safe package-relative path',
                );
            }

            foreach (['alt', 'caption'] as $field) {
                if (mb_strlen(trim((string) $screenshot[$field])) < 12) {
                    throw InvalidManifestException::invalidField(sprintf('marketplace.screenshots.%d.%s', $index, $field), 'must be usable descriptive text');
                }
            }
        }
    }

    /**
     * Optional listing copy that sells the outcome: the problem the extension removes, the
     * outcome a site gets, and the questions a buyer asks about this extension. Each is
     * optional, but when present it must be usable text so the listing never renders a blank.
     *
     * @param  array<string, mixed>  $marketplace
     */
    private function validateOutcomeCopy(array $marketplace): void
    {
        foreach (['problem', 'outcome'] as $field) {
            if (! array_key_exists($field, $marketplace)) {
                continue;
            }

            if (! is_string($marketplace[$field]) || mb_strlen(trim($marketplace[$field])) < 12) {
                throw InvalidManifestException::invalidField('marketplace.' . $field, 'must be usable descriptive text');
            }
        }

        if (! array_key_exists('faq', $marketplace)) {
            return;
        }

        if (! is_array($marketplace['faq']) || ! array_is_list($marketplace['faq'])) {
            throw InvalidManifestException::invalidField('marketplace.faq', 'must be a list of question and answer objects');
        }

        foreach ($marketplace['faq'] as $index => $entry) {
            if (! is_array($entry)) {
                throw InvalidManifestException::invalidField('marketplace.faq.' . $index, 'must be an object');
            }

            foreach (['question', 'answer'] as $field) {
                if (! is_string($entry[$field] ?? null) || mb_strlen(trim($entry[$field])) < 12) {
                    throw InvalidManifestException::invalidField(sprintf('marketplace.faq.%d.%s', $index, $field), 'must be usable descriptive text');
                }
            }

            foreach (array_keys($entry) as $field) {
                if (! in_array($field, ['question', 'answer'], true)) {
                    throw InvalidManifestException::invalidField(sprintf('marketplace.faq.%d.%s', $index, $field), 'is not part of a FAQ entry');
                }
            }
        }
    }

    private function isSafePackageRelativePath(string $path): bool
    {
        if (trim($path) === ''
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
            || str_ends_with($path, '/')
            || str_contains($path, '\\')) {
            return false;
        }

        return array_filter(
            explode('/', $path),
            static fn (string $segment): bool => in_array($segment, ['', '.', '..'], true),
        ) === [];
    }
}

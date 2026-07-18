<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;

final readonly class PerformanceSectionValidator implements ManifestSectionValidator
{
    public function __construct(private ManifestValidationRules $rules) {}

    public function validate(array $data): void
    {
        if (! is_array($data['performance'] ?? null)) {
            throw InvalidManifestException::missingField('performance');
        }

        $performance = $data['performance'];
        $this->rules->stringList($performance, 'cacheTags');

        if (! is_array($performance['cacheSafety'] ?? null)) {
            throw InvalidManifestException::missingField('cacheSafety');
        }

        $cacheSafety = $performance['cacheSafety'];

        foreach (['cacheable', 'sensitiveOutput', 'queueInvalidation'] as $field) {
            if (! array_key_exists($field, $cacheSafety) || ! is_bool($cacheSafety[$field])) {
                throw InvalidManifestException::invalidField('cacheSafety.' . $field, 'must be explicit boolean metadata');
            }
        }

        $this->rules->stringList($cacheSafety, 'variesBy');

        if (! array_key_exists('invalidationSources', $cacheSafety) || ! is_array($cacheSafety['invalidationSources']) || ! array_is_list($cacheSafety['invalidationSources'])) {
            throw InvalidManifestException::invalidField('cacheSafety.invalidationSources', 'must be a list');
        }

        foreach ($cacheSafety['invalidationSources'] as $index => $source) {
            if (! is_array($source)) {
                throw InvalidManifestException::invalidField('cacheSafety.invalidationSources.' . $index, 'must be an object');
            }

            $this->rules->requiredStrings($source, ['model']);
            $this->rules->stringList($source, 'events');
        }

        if ($cacheSafety['cacheable'] && $cacheSafety['invalidationSources'] === []) {
            throw InvalidManifestException::invalidField('cacheSafety.invalidationSources', 'cacheable frontend surfaces need invalidation metadata');
        }
    }
}

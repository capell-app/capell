<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;

final class CommercialSectionValidator implements ManifestSectionValidator
{
    private const array PROPOSAL_FIELDS = ['proposedLicense', 'requestedCertification', 'supportPolicy', 'privateDocsRequested'];

    public function validate(array $data): void
    {
        if (! is_array($data['commercial'] ?? null)) {
            throw InvalidManifestException::missingField('commercial');
        }

        foreach (array_keys($data['commercial']) as $field) {
            if (! in_array($field, self::PROPOSAL_FIELDS, true)) {
                throw InvalidManifestException::invalidField('commercial.' . $field, 'commercial manifest data is author proposal metadata only');
            }
        }

        if (! array_key_exists('privateDocsRequested', $data['commercial']) || ! is_bool($data['commercial']['privateDocsRequested'])) {
            throw InvalidManifestException::invalidField('commercial.privateDocsRequested', 'must be explicit boolean proposal metadata');
        }
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Data\PublicRead;

use Carbon\CarbonImmutable;

/**
 * The public-read rules a stored projection was built under: the eligibility
 * revision and the moment the rules next change on a schedule. A projection
 * stores it at build time and is served only while it is still current.
 *
 * @experimental
 */
final readonly class PublicReadStampData
{
    /**
     * @param  string|null  $revision  null when no package restricts public reads
     */
    public function __construct(
        public ?string $revision,
        public ?CarbonImmutable $validUntil,
    ) {}

    /**
     * A cache identity for this stamp. It changes when the revision moves and
     * when the scheduled transition it names has passed.
     */
    public function key(): string
    {
        if ($this->revision === null) {
            return 'unrestricted';
        }

        return $this->revision . '@' . ($this->validUntil instanceof CarbonImmutable ? (string) $this->validUntil->getTimestamp() : 'open');
    }

    public function validUntilTimestamp(): ?int
    {
        return $this->validUntil?->getTimestamp();
    }
}

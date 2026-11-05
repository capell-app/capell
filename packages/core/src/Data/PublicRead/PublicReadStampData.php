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
    /** The key of a stamp built while no package restricts public reads. */
    public const string UNRESTRICTED_KEY = 'unrestricted';

    /**
     * @param  string|null  $revision  null when no package restricts public reads
     */
    public function __construct(
        public ?string $revision,
        public ?CarbonImmutable $validUntil,
    ) {}

    /**
     * The stamp a key() names, or null when the text is not one. Lets a
     * stamp travel as text (in a token) and be checked at write time.
     */
    public static function fromKey(string $key): ?self
    {
        if ($key === self::UNRESTRICTED_KEY) {
            return new self(null, null);
        }

        $separator = strrpos($key, '@');

        if ($separator === false || $separator === 0) {
            return null;
        }

        $revision = substr($key, 0, $separator);
        $validUntil = substr($key, $separator + 1);

        if ($validUntil === 'open') {
            return new self($revision, null);
        }

        return ctype_digit($validUntil) ? new self($revision, CarbonImmutable::createFromTimestamp((int) $validUntil)) : null;
    }

    /**
     * A cache identity for this stamp. It changes when the revision moves and
     * when the scheduled transition it names has passed.
     */
    public function key(): string
    {
        if ($this->revision === null) {
            return self::UNRESTRICTED_KEY;
        }

        return $this->revision . '@' . ($this->validUntil instanceof CarbonImmutable ? (string) $this->validUntil->getTimestamp() : 'open');
    }

    public function validUntilTimestamp(): ?int
    {
        return $this->validUntil?->getTimestamp();
    }
}

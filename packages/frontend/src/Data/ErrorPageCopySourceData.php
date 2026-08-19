<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Override;
use Spatie\LaravelData\Data;

/**
 * One competing source for a single piece of error-page copy.
 */
final class ErrorPageCopySourceData extends Data
{
    public function __construct(
        /** 1-based position in the precedence order the blade actually applies. */
        public readonly int $order,
        /** Where the value comes from, e.g. a manifest path or a translation key. */
        public readonly string $source,
        public readonly bool $present,
        public readonly ?string $value = null,
        public readonly bool $won = false,
        /** Why this source did not win: a coded explanation, never free prose. */
        public readonly ?string $skippedBecause = null,
        /**
         * True when the blade never consults this source at render time. Listed
         * anyway because it looks authoritative and repeatedly gets blamed.
         */
        public readonly bool $consulted = true,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'source' => $this->source,
            'present' => $this->present,
            'value' => $this->value,
            'won' => $this->won,
            'skippedBecause' => $this->skippedBecause,
            'consulted' => $this->consulted,
        ];
    }
}

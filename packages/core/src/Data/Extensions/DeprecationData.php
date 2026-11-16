<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

/**
 * One deprecated surface that was used while this process ran: what was used,
 * what to use instead and when the old surface goes away.
 */
final readonly class DeprecationData
{
    public function __construct(
        public string $surface,
        public string $replacement,
        public string $removal,
        public int $occurrences = 1,
    ) {}

    public function withAnotherOccurrence(): self
    {
        return new self($this->surface, $this->replacement, $this->removal, $this->occurrences + 1);
    }

    /** @return array{surface: string, replacement: string, removal: string, occurrences: int} */
    public function toArray(): array
    {
        return [
            'surface' => $this->surface,
            'replacement' => $this->replacement,
            'removal' => $this->removal,
            'occurrences' => $this->occurrences,
        ];
    }
}

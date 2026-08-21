<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceInstallProgressQueryData extends Data
{
    /**
     * @param  list<int>  $attemptIds
     * @param  list<string>  $composerNames
     */
    public function __construct(
        public readonly array $attemptIds = [],
        public readonly array $composerNames = [],
    ) {}

    /** @param list<int> $attemptIds */
    public static function forAttemptIds(array $attemptIds): self
    {
        return new self(attemptIds: array_values(array_unique(array_filter(
            $attemptIds,
            static fn (int $attemptId): bool => $attemptId > 0,
        ))));
    }

    /** @param list<string> $composerNames */
    public static function forComposerNames(array $composerNames): self
    {
        return new self(composerNames: array_values(array_unique(array_filter(
            array_map(trim(...), $composerNames),
            static fn (string $composerName): bool => $composerName !== '',
        ))));
    }
}

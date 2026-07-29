<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

final readonly class MarketplaceSelectionInputData
{
    /**
     * @param  list<string>  $selectedComposerNames
     */
    public function __construct(
        public array $selectedComposerNames,
        public ?string $lockedKind,
        public bool $includeLocalExtensionState,
        public bool $canManageExtensions,
    ) {}

    /**
     * @param  array<int, mixed>  $selectedComposerNames
     */
    public static function make(
        array $selectedComposerNames,
        ?string $lockedKind,
        bool $includeLocalExtensionState,
        bool $canManageExtensions,
    ): self {
        return new self(
            selectedComposerNames: array_values(array_unique(array_filter(
                array_map(
                    static fn (mixed $composerName): ?string => is_string($composerName) && trim($composerName) !== ''
                        ? trim($composerName)
                        : null,
                    $selectedComposerNames,
                ),
                is_string(...),
            ))),
            lockedKind: $lockedKind,
            includeLocalExtensionState: $includeLocalExtensionState,
            canManageExtensions: $canManageExtensions,
        );
    }
}

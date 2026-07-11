<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Spatie\LaravelData\Data;

final class ActivityRevertSelectionData extends Data
{
    /**
     * @param  list<string>  $selectedPaths
     * @param  array<string, mixed>  $beforeValues
     */
    public function __construct(
        public readonly int|string $activityId,
        public readonly array $selectedPaths,
        public readonly array $beforeValues,
        public readonly int|string|null $actorId,
        public readonly ?string $subjectMorphType,
        public readonly ?string $subjectClass,
        public readonly int|string|null $subjectId,
        public readonly ?string $stableIdentifier,
        public readonly int|string|null $workspaceId,
    ) {}

    /**
     * @param  list<string>  $selectedPaths
     * @param  array<string, mixed>  $beforeValues
     */
    public static function fromActivity(
        int|string $activityId,
        array $selectedPaths,
        array $beforeValues,
        int|string|null $actorId,
        ?string $subjectMorphType,
        ?string $subjectClass,
        int|string|null $subjectId,
        ?string $stableIdentifier,
        int|string|null $workspaceId,
    ): self {
        return new self(
            activityId: $activityId,
            selectedPaths: $selectedPaths,
            beforeValues: $beforeValues,
            actorId: $actorId,
            subjectMorphType: $subjectMorphType,
            subjectClass: $subjectClass,
            subjectId: $subjectId,
            stableIdentifier: $stableIdentifier,
            workspaceId: $workspaceId,
        );
    }
}

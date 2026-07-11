<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Spatie\LaravelData\Data;

final class ActivityRevertResultData extends Data
{
    /**
     * @param  array<string, list<string>>  $skippedFields
     */
    public function __construct(
        public readonly bool $successful,
        public readonly string $messageKey,
        public readonly array $skippedFields = [],
        public readonly int|string|null $workspaceId = null,
    ) {}

    /**
     * @param  array<string, list<string>>  $skippedFields
     */
    public static function success(string $messageKey, array $skippedFields = []): self
    {
        return new self(
            successful: true,
            messageKey: $messageKey,
            skippedFields: $skippedFields,
        );
    }

    /**
     * @param  array<string, list<string>>  $skippedFields
     */
    public static function failed(string $messageKey, array $skippedFields = [], int|string|null $workspaceId = null): self
    {
        return new self(
            successful: false,
            messageKey: $messageKey,
            skippedFields: $skippedFields,
            workspaceId: $workspaceId,
        );
    }
}

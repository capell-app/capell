<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Spatie\LaravelData\Data;

final class ActivityPresentationData extends Data
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function __construct(
        public readonly string $summary,
        public readonly string $subjectLabel,
        public readonly ?string $subjectUrl,
        public readonly ?string $event,
        public readonly array $oldValues,
        public readonly array $newValues,
        public readonly bool $canRevert,
    ) {}
}

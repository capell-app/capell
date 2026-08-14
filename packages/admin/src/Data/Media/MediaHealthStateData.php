<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Media;

use Capell\Admin\Enums\MediaHealthIssueEnum;
use Spatie\LaravelData\Data;

final class MediaHealthStateData extends Data
{
    public function __construct(
        public readonly int $usageCount,
        public readonly bool $missingAlt,
        public readonly bool $missingRights,
        public readonly bool $duplicate,
        public readonly bool $unused,
    ) {}

    public function primaryIssue(): string
    {
        return $this->issues()[0];
    }

    /** @return list<string> */
    public function issues(): array
    {
        $issues = [];

        if ($this->missingAlt) {
            $issues[] = MediaHealthIssueEnum::MissingAlt->value;
        }

        if ($this->missingRights) {
            $issues[] = MediaHealthIssueEnum::MissingRights->value;
        }

        if ($this->duplicate) {
            $issues[] = MediaHealthIssueEnum::Duplicate->value;
        }

        if ($this->unused) {
            $issues[] = MediaHealthIssueEnum::Unused->value;
        }

        return $issues === [] ? [MediaHealthIssueEnum::Healthy->value] : $issues;
    }
}

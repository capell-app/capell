<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum MediaHealthIssueEnum: string
{
    case Healthy = 'healthy';

    case MissingAlt = 'missing_alt';

    case MissingRights = 'missing_rights';

    case Duplicate = 'duplicate';

    case Unused = 'unused';

    public function label(): string
    {
        return (string) __('capell-admin::media.health_issues.' . $this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $issue): array => [$issue->value => $issue->label()])
            ->all();
    }
}

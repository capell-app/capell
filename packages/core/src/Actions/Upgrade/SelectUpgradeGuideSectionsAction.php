<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SelectUpgradeGuideSectionsAction
{
    use AsFake;
    use AsObject;

    /** @return array<string, string>|null Null means the range or guide is unknown. */
    public function handle(?string $from, ?string $to, ?string $guide = null): ?array
    {
        if ($from === null || $to === null || preg_match('/^v?\d+\.\d+\.\d+$/', $from) !== 1 || preg_match('/^v?\d+\.\d+\.\d+$/', $to) !== 1) {
            return null;
        }

        $from = ltrim($from, 'v');
        $to = ltrim($to, 'v');
        if (version_compare($from, $to, '>')) {
            return null;
        }

        $guide ??= @file_get_contents(dirname(__DIR__, 3) . '/resources/upgrade-guide.md') ?: null;
        if ($guide === null || ! preg_match_all('/^## v(\d+\.\d+\.\d+)\s*\R(.*?)(?=^## |\z)/ms', $guide, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $sections = [];
        foreach ($matches as $match) {
            if (version_compare($match[1], $from, '>') && version_compare($match[1], $to, '<=')) {
                $sections[$match[1]] = trim($match[2]);
            }
        }

        ksort($sections, SORT_NATURAL);

        return $sections;
    }
}

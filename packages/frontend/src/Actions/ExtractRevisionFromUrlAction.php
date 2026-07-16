<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ExtractRevisionFromUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(string $url): ?int
    {
        $parts = parse_url($url);

        // Check for ?revision=123 in query string
        if (isset($parts['query'])) {
            $revisions = [];
            if (preg_match_all('/(?:^|&)revision=(\d+)(?=&|$)/', $parts['query'], $matches)) {
                $revisions = $matches[1];
            }

            if ($revisions !== []) {
                return (int) end($revisions);
            }
        }

        // Check for {123} at end of path (use path only)
        $path = $parts['path'] ?? $url;
        if (preg_match('/{(\d+)}$/', $path, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

final class UrlVisitFailedException extends RuntimeException
{
    public static function forUrl(string $url, string $reason): self
    {
        $parts = parse_url($url);
        $destination = is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && isset($parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '/')
                : __('capell::message.invalid_visit_destination');

        return new self(__('capell::message.url_visit_failed', [
            'url' => $destination,
            'reason' => $reason,
        ]));
    }
}

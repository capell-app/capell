<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Whether the site spec at a path will import as a private site. A spec is private unless it says
 * otherwise, and an unreadable or invalid file is left for the import itself to report.
 */
final class IsPrivateSiteSpecFileAction
{
    use AsFake;
    use AsObject;

    public function handle(string $path): bool
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return false;
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($payload) && ($payload['initialVisibility'] ?? 'private') !== 'public';
    }
}

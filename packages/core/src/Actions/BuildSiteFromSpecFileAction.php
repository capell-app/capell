<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Site;
use JsonException;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class BuildSiteFromSpecFileAction
{
    use AsObject;

    public function handle(string $path): Site
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new RuntimeException("Could not read a spec file at: {$path}");
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = [];
        }

        if (! is_array($payload)) {
            throw new RuntimeException('The site spec must decode to a JSON object.');
        }

        return BuildCapellSiteFromSpecAction::run(CapellSiteSpecData::validateAndCreate($payload));
    }
}

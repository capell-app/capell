<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class BuildSiteFromSpecFileAction
{
    use AsFake;
    use AsObject;

    public function handle(string $path): Site
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        throw_unless(is_string($contents), RuntimeException::class, 'Could not read a spec file at: ' . $path);

        try {
            $payload = JsonCodec::decode($contents);
        } catch (JsonException) {
            $payload = [];
        }

        throw_unless(is_array($payload), RuntimeException::class, 'The site spec must decode to a JSON object.');

        $validation = ValidateSiteSpecAction::run($payload, [], [], []);

        if (! $validation['valid'] || ! is_array($validation['normalized'])) {
            throw ValidationException::withMessages($validation['errors']);
        }

        return BuildCapellSiteFromSpecAction::run(CapellSiteSpecData::from($validation['normalized']));
    }
}

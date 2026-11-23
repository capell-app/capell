<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecLanguageData;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** Reads a SiteSpec JSON file and returns it validated. */
final class ReadSiteSpecFileAction
{
    use AsFake;
    use AsObject;

    /** @throws ValidationException when the file is not a valid SiteSpec */
    public function handle(string $path): CapellSiteSpecData
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        throw_unless(is_string($contents), RuntimeException::class, 'Could not read a spec file at: ' . $path);

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException((string) __('capell-core::message.site_spec_import_invalid_json', ['reason' => $jsonException->getMessage()]), $jsonException->getCode(), $jsonException);
        }

        throw_unless(is_array($payload), RuntimeException::class, 'The site spec must decode to a JSON object.');

        $validation = ValidateSiteSpecAction::run($payload, [], [], []);

        if (! $validation['valid'] || ! is_array($validation['normalized'])) {
            throw ValidationException::withMessages($validation['errors']);
        }

        // Validation normalises with defaults filled in. Record which language attributes the author wrote,
        // so a default is never mistaken for an instruction to change an existing shared language.
        $language = is_array($payload['language'] ?? null) ? $payload['language'] : [];
        $normalized = $validation['normalized'];
        $normalized['language'] = [
            ...(is_array($normalized['language'] ?? null) ? $normalized['language'] : []),
            'stated' => array_values(array_intersect(CapellSiteSpecLanguageData::ATTRIBUTES, array_keys($language))),
        ];

        return CapellSiteSpecData::from($normalized);
    }
}

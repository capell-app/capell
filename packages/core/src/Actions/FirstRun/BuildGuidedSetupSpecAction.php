<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Actions\ValidateSiteSpecAction;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\SiteSpecPresetData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecPresetRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Turns what a person told guided setup into a private SiteSpec: the chosen preset with their
 * name, one-line description, theme, colour and page choices laid over it. Guided setup applies
 * the result through the same SiteSpec path as an import, so it never writes content itself.
 */
final class BuildGuidedSetupSpecAction
{
    use AsFake;
    use AsObject;

    public const string HOME_SLUG = 'home';

    /** The SiteSpec package data key the Form Builder extension applies. */
    public const string FORMS_KEY = 'forms';

    public function __construct(
        private readonly SiteSpecPresetRegistry $presets,
        private readonly SiteSpecApplierRegistry $appliers,
    ) {}

    /**
     * @throws InvalidArgumentException when the preset is not registered
     * @throws ValidationException when the result is not a valid SiteSpec
     */
    public function handle(GuidedSetupInputData $input, ?Site $site = null): CapellSiteSpecData
    {
        $contactEmail = $input->contactEmail !== null && trim($input->contactEmail) !== '' ? trim($input->contactEmail) : null;

        Validator::make(
            ['contactEmail' => $contactEmail],
            ['contactEmail' => ['nullable', 'string', 'email', 'max:255']],
        )->validate();

        $preset = $this->presets->get($input->presetKey);

        throw_if(! $preset instanceof SiteSpecPresetData, InvalidArgumentException::class, sprintf('Guided setup has no preset called [%s].', $input->presetKey));

        $payload = $preset->spec;
        $payload['site'] = [
            ...(array) ($payload['site'] ?? []),
            'name' => trim($input->siteName),
            'description' => trim($input->tagline) !== '' ? trim($input->tagline) : ($payload['site']['description'] ?? null),
        ];

        if ($input->themeKey !== null && $input->themeKey !== '') {
            $payload['theme']['key'] = $input->themeKey;
        }

        if ($input->primaryColour !== null && $input->primaryColour !== '') {
            $payload['theme']['colors'] = [...(array) ($payload['theme']['colors'] ?? []), 'primary' => $input->primaryColour];
        }

        // Write in the language the site was installed in, so the preset applies to any site language.
        $language = $site?->language;

        if ($language instanceof Language) {
            $payload['language'] = [
                'code' => $language->code,
                'name' => $language->name,
                'locale' => $language->locale,
                'flag' => $language->flag,
                'default' => true,
            ];
        }

        $homeSlug = $this->existingHomeSlug($site, $language);

        $wanted = [self::HOME_SLUG, ...$input->pageSlugs];
        $payload['pages'] = array_values(array_filter(
            (array) ($payload['pages'] ?? []),
            static fn (mixed $page): bool => is_array($page) && in_array($page['slug'] ?? null, $wanted, true),
        ));
        // The installer's own home page is reused, not duplicated: the spec's home page takes its stored slug.
        if ($homeSlug !== null) {
            $payload['pages'] = array_map(
                static fn (array $page): array => data_get($page, 'slug') === self::HOME_SLUG
                    ? [...$page, 'meta' => [...(array) ($page['meta'] ?? []), 'slug' => $homeSlug]]
                    : $page,
                $payload['pages'],
            );
        }

        $payload['navigations'] = array_map(
            static fn (mixed $navigation): mixed => is_array($navigation) && is_array($navigation['pageSlugs'] ?? null)
                ? [...$navigation, 'pageSlugs' => array_values(array_intersect($navigation['pageSlugs'], $wanted))]
                : $navigation,
            (array) ($payload['navigations'] ?? []),
        );
        // Navigation belongs to the Navigation extension; without it the menu is left out rather than failing the setup.
        if (! $this->appliers->has('navigation')) {
            $payload['navigations'] = [];
        }

        $payload['packageData'] = $this->packageData((array) ($payload['packageData'] ?? []), $wanted, $contactEmail);

        if ($payload['packageData'] === []) {
            unset($payload['packageData']);
        }

        $payload['initialVisibility'] = 'private';
        $payload['acknowledgePublic'] = false;

        $validation = ValidateSiteSpecAction::run($payload, [], [], []);

        if (! $validation['valid'] || ! is_array($validation['normalized'])) {
            throw ValidationException::withMessages($validation['errors']);
        }

        return CapellSiteSpecData::from($validation['normalized']);
    }

    /**
     * Keeps only the package data an installed extension can apply, so a preset that offers a contact
     * form still applies on a site without the Form Builder extension. A form is kept only when the
     * page it belongs on is being created, and it sends its messages to the address the person gave.
     *
     * @param  array<array-key, mixed>  $packageData
     * @param  list<string>  $wanted
     * @return array<string, array<array-key, mixed>>
     */
    private function packageData(array $packageData, array $wanted, ?string $contactEmail): array
    {
        $kept = [];

        foreach ($packageData as $key => $data) {
            if (! is_string($key)) {
                continue;
            }

            if (! is_array($data)) {
                continue;
            }

            if (! $this->appliers->has($key)) {
                continue;
            }

            if ($key === self::FORMS_KEY) {
                $data = $this->forms($data, $wanted, $contactEmail);
            }

            if ($data !== []) {
                $kept[$key] = $data;
            }
        }

        return $kept;
    }

    /**
     * @param  array<array-key, mixed>  $forms
     * @param  list<string>  $wanted
     * @return list<array<array-key, mixed>>
     */
    private function forms(array $forms, array $wanted, ?string $contactEmail): array
    {
        $kept = [];

        foreach ($forms as $form) {
            if (! is_array($form)) {
                continue;
            }

            $page = $form['placeOnPage'] ?? null;

            if (is_string($page) && ! in_array($page, $wanted, true)) {
                continue;
            }

            $kept[] = $contactEmail === null ? $form : [...$form, 'notify' => $contactEmail];
        }

        return $kept;
    }

    private function existingHomeSlug(?Site $site, ?Language $language): ?string
    {
        if (! $site instanceof Site || ! $language instanceof Language) {
            return null;
        }

        $home = $site->pages()->homePage()->with('translations')->orderBy('id')->first();
        $translation = $home?->translations->firstWhere('language_id', $language->getKey());
        $slug = $translation?->meta['slug'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}

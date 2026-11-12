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

    private const string HOME_SLUG = 'home';

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

        $payload['initialVisibility'] = 'private';
        $payload['acknowledgePublic'] = false;

        $validation = ValidateSiteSpecAction::run($payload, [], [], []);

        if (! $validation['valid'] || ! is_array($validation['normalized'])) {
            throw ValidationException::withMessages($validation['errors']);
        }

        return CapellSiteSpecData::from($validation['normalized']);
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

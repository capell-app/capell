<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Support\CapellSiteSpecConstraints;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateSiteSpecAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $themeKeys
     * @param  array<int, string>  $pageTypeKeys
     * @param  array<int, string>  $sectionTypeKeys
     * @return array{valid: bool, errors: array<string, array<int, string>>, normalized: array<string, mixed>|null}
     */
    public function handle(array $payload, array $themeKeys, array $pageTypeKeys, array $sectionTypeKeys): array
    {
        try {
            $validator = Validator::make($payload, $this->rules($themeKeys, $pageTypeKeys, $sectionTypeKeys));
            $validator->after(function (LaravelValidator $validator) use ($payload): void {
                if ($this->totalContentLength($payload) > CapellSiteSpecConstraints::MAX_TOTAL_CONTENT_LENGTH) {
                    $validator->errors()->add('pages', sprintf(
                        'The total section content may not exceed %d characters.',
                        CapellSiteSpecConstraints::MAX_TOTAL_CONTENT_LENGTH,
                    ));
                }

                if (($payload['initialVisibility'] ?? 'private') === 'public' && ($payload['acknowledgePublic'] ?? false) !== true) {
                    $validator->errors()->add('acknowledgePublic', 'Public site specs require explicit acknowledgement.');
                }

                $this->validatePageReferences($validator, $payload);
                $this->validateMediaSource($validator, $payload);
                $this->validatePackageData($validator, $payload);
            });
            $validator->validate();
            $spec = CapellSiteSpecData::validateAndCreate($payload);

            return ['valid' => true, 'errors' => [], 'normalized' => $spec->toArray()];
        } catch (ValidationException $validationException) {
            return ['valid' => false, 'errors' => $validationException->errors(), 'normalized' => null];
        }
    }

    /**
     * @param  array<int, string>  $themeKeys
     * @param  array<int, string>  $pageTypeKeys
     * @param  array<int, string>  $sectionTypeKeys
     * @return array<string, mixed>
     */
    private function rules(array $themeKeys, array $pageTypeKeys, array $sectionTypeKeys): array
    {
        $hex = ['nullable', CapellSiteSpecConstraints::validationRegex(CapellSiteSpecConstraints::HEX_COLOUR_PATTERN)];
        $slug = ['required', 'string', CapellSiteSpecConstraints::validationRegex(CapellSiteSpecConstraints::SLUG_PATTERN)];
        $remoteUrl = ['nullable', 'string', 'max:' . CapellSiteSpecConstraints::MAX_REMOTE_URL_LENGTH, 'url:https'];
        $requiredRemoteUrl = ['required', 'string', 'max:' . CapellSiteSpecConstraints::MAX_REMOTE_URL_LENGTH, 'url:https'];

        return [
            'initialVisibility' => ['sometimes', 'string', Rule::in(['private', 'public'])],
            'acknowledgePublic' => ['sometimes', 'boolean'],
            'site' => ['required', 'array'],
            'site.name' => ['required', 'string', 'max:255'],
            'site.businessName' => ['nullable', 'string', 'max:255'],
            'site.organisationType' => ['nullable', 'string', 'max:255'],
            'site.description' => ['nullable', 'string', 'max:5000'],
            'theme' => ['required', 'array'],
            'theme.key' => ['required', 'string', ...$this->catalogueRules($themeKeys)],
            'theme.colors.primary' => $hex, 'theme.colors.secondary' => $hex, 'theme.colors.accent' => $hex,
            'theme.fontFamily' => ['nullable', 'string', 'max:255'],
            'theme.linkColor' => $hex,
            'theme.linkColorActive' => $hex,
            'theme.container' => ['nullable', 'string', 'max:255'],
            'theme.customCss' => ['nullable', 'string', 'max:50000'],
            'language' => ['sometimes', 'array'],
            'language.code' => ['sometimes', 'string', 'max:12'],
            'language.name' => ['sometimes', 'string', 'max:255'],
            'language.locale' => ['sometimes', 'string', 'max:32'],
            'language.flag' => ['sometimes', 'string', 'max:16'],
            'language.default' => ['sometimes', 'boolean'],
            'pages' => ['required', 'array', 'min:' . CapellSiteSpecConstraints::MIN_PAGES, 'max:' . CapellSiteSpecConstraints::MAX_PAGES],
            'pages.*' => ['array'],
            'pages.*.name' => ['required', 'string', 'max:255'],
            'pages.*.slug' => [...$slug, 'distinct'],
            'pages.*.title' => ['required', 'string', 'max:255'],
            'pages.*.url' => ['nullable', 'string', 'regex:/^\/[A-Za-z0-9\-._~!$&\'()*+,;=:@%\/]*$/'],
            'pages.*.description' => ['nullable', 'string', 'max:5000'],
            'pages.*.order' => ['sometimes', 'integer', 'min:0'],
            'pages.*.contentStructure' => ['sometimes', 'string', Rule::in(['html', 'blocks'])],
            'pages.*.pageType' => ['required', 'string', ...$this->catalogueRules($pageTypeKeys)],
            'pages.*.sections' => ['sometimes', 'array'],
            'pages.*.sections.*.type' => ['required', 'string', ...$this->catalogueRules($sectionTypeKeys)],
            'pages.*.sections.*.content' => ['required', 'string', 'max:' . CapellSiteSpecConstraints::MAX_SECTION_CONTENT_LENGTH],
            'pages.*.sections.*.title' => ['nullable', 'string', 'max:255'],
            'pages.*.sections.*.summary' => ['nullable', 'string', 'max:5000'],
            'pages.*.sections.*.order' => ['sometimes', 'integer', 'min:0'],
            'pages.*.sections.*.meta' => ['sometimes', 'array'],
            'pages.*.visibility' => ['sometimes', 'array'],
            'pages.*.meta' => ['sometimes', 'array'],
            'navigations' => ['sometimes', 'array', 'max:' . CapellSiteSpecConstraints::MAX_NAVIGATIONS],
            'navigations.*' => ['array'],
            'navigations.*.key' => [...$slug, 'distinct'],
            'navigations.*.name' => ['nullable', 'string', 'max:255'],
            'navigations.*.pageSlugs' => ['required', 'array'],
            'navigations.*.pageSlugs.*' => $slug,
            'media' => ['sometimes', 'array'],
            'media.sourceUrl' => $remoteUrl,
            'media.logo' => $remoteUrl,
            'media.images' => ['sometimes', 'array', 'max:' . CapellSiteSpecConstraints::MAX_MEDIA_IMAGES],
            'media.images.*' => $requiredRemoteUrl,
            'extensions' => ['sometimes', 'array', 'max:' . CapellSiteSpecConstraints::MAX_EXTENSIONS],
            'extensions.*' => [
                'string',
                CapellSiteSpecConstraints::validationRegex(CapellSiteSpecConstraints::COMPOSER_PACKAGE_PATTERN),
                'distinct',
            ],
            'packageData' => ['sometimes', 'array', 'max:' . CapellSiteSpecConstraints::MAX_EXTENSIONS],
            'packageData.*' => ['array'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validatePageReferences(LaravelValidator $validator, array $payload): void
    {
        $pages = $payload['pages'] ?? [];
        $pageSlugs = [];

        if (is_array($pages)) {
            foreach ($pages as $page) {
                if (is_array($page) && is_string($page['slug'] ?? null)) {
                    $pageSlugs[] = $page['slug'];
                }
            }
        }

        foreach ($payload['navigations'] ?? [] as $navigationIndex => $navigation) {
            if (! is_array($navigation)) {
                continue;
            }

            $seenPageSlugs = [];

            foreach ($navigation['pageSlugs'] ?? [] as $pageIndex => $pageSlug) {
                if (is_string($pageSlug) && ! in_array($pageSlug, $pageSlugs, true)) {
                    $validator->errors()->add(
                        sprintf('navigations.%s.pageSlugs.%s', $navigationIndex, $pageIndex),
                        sprintf('The referenced page slug [%s] is not present in the site spec.', $pageSlug),
                    );
                }

                if (is_string($pageSlug) && in_array($pageSlug, $seenPageSlugs, true)) {
                    $validator->errors()->add(
                        sprintf('navigations.%s.pageSlugs.%s', $navigationIndex, $pageIndex),
                        sprintf('The page slug [%s] may appear only once in a navigation.', $pageSlug),
                    );
                }

                if (is_string($pageSlug)) {
                    $seenPageSlugs[] = $pageSlug;
                }
            }
        }

        $images = data_get($payload, 'media.images', []);

        if (! is_array($images)) {
            return;
        }

        foreach (array_keys($images) as $pageSlug) {
            if (! is_string($pageSlug)
                || preg_match('/' . CapellSiteSpecConstraints::SLUG_PATTERN . '/', $pageSlug) !== 1
                || ! in_array($pageSlug, $pageSlugs, true)
            ) {
                $validator->errors()->add(
                    'media.images.' . $pageSlug,
                    sprintf('Media images must be keyed by a page slug present in the site spec; [%s] is invalid.', (string) $pageSlug),
                );
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateMediaSource(LaravelValidator $validator, array $payload): void
    {
        $media = $payload['media'] ?? null;

        if (! is_array($media)) {
            return;
        }

        $hasLogo = is_string($media['logo'] ?? null) && $media['logo'] !== '';
        $hasImages = is_array($media['images'] ?? null) && $media['images'] !== [];

        if (($hasLogo || $hasImages) && (! is_string($media['sourceUrl'] ?? null) || $media['sourceUrl'] === '')) {
            $validator->errors()->add('media.sourceUrl', 'A source URL is required when the site spec imports remote media.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validatePackageData(LaravelValidator $validator, array $payload): void
    {
        $packageData = $payload['packageData'] ?? [];

        if (! is_array($packageData)) {
            return;
        }

        if (strlen(json_encode($packageData, JSON_THROW_ON_ERROR)) > CapellSiteSpecConstraints::MAX_TOTAL_CONTENT_LENGTH) {
            $validator->errors()->add(
                'packageData',
                sprintf('SiteSpec package data may not exceed %d bytes.', CapellSiteSpecConstraints::MAX_TOTAL_CONTENT_LENGTH),
            );
        }

        foreach (array_keys($packageData) as $key) {
            if (! is_string($key)
                || preg_match('/' . CapellSiteSpecConstraints::SLUG_PATTERN . '/', $key) !== 1) {
                $validator->errors()->add(
                    'packageData.' . $key,
                    sprintf('SiteSpec package data key [%s] is invalid.', (string) $key),
                );
            }
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return list<In>
     */
    private function catalogueRules(array $keys): array
    {
        return $keys === [] ? [] : [Rule::in($keys)];
    }

    /** @param array<string, mixed> $payload */
    private function totalContentLength(array $payload): int
    {
        $length = 0;

        foreach ($payload['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }

            foreach ($page['sections'] ?? [] as $section) {
                if (is_array($section) && is_string($section['content'] ?? null)) {
                    $length += mb_strlen($section['content']);
                }
            }
        }

        return $length;
    }
}

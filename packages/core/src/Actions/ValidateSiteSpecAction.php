<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Support\CapellSiteSpecConstraints;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateSiteSpecAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $themeKeys
     * @param  array<int, string>  $pageTypeKeys
     * @param  array<int, string>  $sectionTypeKeys
     * @return array{valid: bool, errors: array<string, mixed>, normalized: array<string, mixed>|null}
     */
    public function handle(array $payload, array $themeKeys, array $pageTypeKeys, array $sectionTypeKeys): array
    {
        try {
            $validator = Validator::make($payload, $this->rules($themeKeys, $pageTypeKeys, $sectionTypeKeys));
            $validator->after(function ($validator) use ($payload): void {
                if ($this->totalContentLength($payload) > CapellSiteSpecConstraints::MAX_TOTAL_CONTENT_LENGTH) {
                    $validator->errors()->add('pages', sprintf(
                        'The total section content may not exceed %d characters.',
                        CapellSiteSpecConstraints::MAX_TOTAL_CONTENT_LENGTH,
                    ));
                }

                if (($payload['initialVisibility'] ?? 'private') === 'public' && ($payload['acknowledgePublic'] ?? false) !== true) {
                    $validator->errors()->add('acknowledgePublic', 'Public site specs require explicit acknowledgement.');
                }
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

        return [
            'initialVisibility' => ['sometimes', 'string', Rule::in(['private', 'public'])],
            'acknowledgePublic' => ['sometimes', 'boolean'],
            'theme.key' => ['required', 'string', Rule::in($themeKeys)],
            'theme.colors.primary' => $hex, 'theme.colors.secondary' => $hex, 'theme.colors.accent' => $hex,
            'pages' => ['required', 'array', 'min:' . CapellSiteSpecConstraints::MIN_PAGES, 'max:' . CapellSiteSpecConstraints::MAX_PAGES],
            'pages.*.slug' => ['required', 'string', CapellSiteSpecConstraints::validationRegex(CapellSiteSpecConstraints::SLUG_PATTERN), 'distinct'],
            'pages.*.url' => ['nullable', 'string', 'regex:/^\/[A-Za-z0-9\-._~!$&\'()*+,;=:@%\/]*$/'],
            'pages.*.pageType' => ['required', 'string', Rule::in($pageTypeKeys)],
            'pages.*.sections.*.type' => ['required', 'string', Rule::in($sectionTypeKeys)],
            'pages.*.sections.*.content' => ['required', 'string', 'max:' . CapellSiteSpecConstraints::MAX_SECTION_CONTENT_LENGTH],
        ];
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

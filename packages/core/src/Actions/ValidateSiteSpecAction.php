<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateSiteSpecAction
{
    use AsObject;

    public const MAX_TOTAL_CONTENT_LENGTH = 200000;

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
                if ($this->totalContentLength($payload) > self::MAX_TOTAL_CONTENT_LENGTH) {
                    $validator->errors()->add('pages', 'The total section content may not exceed 200000 characters.');
                }

                if (($payload['initialVisibility'] ?? 'private') === 'public' && ($payload['acknowledgePublic'] ?? false) !== true) {
                    $validator->errors()->add('acknowledgePublic', 'Public site specs require explicit acknowledgement.');
                }
            });
            $validator->validate();
            $spec = CapellSiteSpecData::validateAndCreate($payload);

            return ['valid' => true, 'errors' => [], 'normalized' => $spec->toArray()];
        } catch (ValidationException $exception) {
            return ['valid' => false, 'errors' => $exception->errors(), 'normalized' => null];
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
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'initialVisibility' => ['sometimes', 'string', Rule::in(['private', 'public'])],
            'acknowledgePublic' => ['sometimes', 'boolean'],
            'theme.key' => ['required', 'string', Rule::in($themeKeys)],
            'theme.colors.primary' => $hex, 'theme.colors.secondary' => $hex, 'theme.colors.accent' => $hex,
            'pages' => ['required', 'array', 'min:1', 'max:15'],
            'pages.*.slug' => ['required', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct'],
            'pages.*.url' => ['nullable', 'string', 'regex:/^\/[A-Za-z0-9\-._~!$&\'()*+,;=:@%\/]*$/'],
            'pages.*.pageType' => ['required', 'string', Rule::in($pageTypeKeys)],
            'pages.*.sections.*.type' => ['required', 'string', Rule::in($sectionTypeKeys)],
            'pages.*.sections.*.content' => ['required', 'string', 'max:' . SanitizeSiteSpecSectionHtmlAction::MAX_INPUT_LENGTH],
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

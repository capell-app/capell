<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Language;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Data\PublicLanguageSuggestionData;
use Capell\Frontend\Enums\VisitorLanguageDetection;
use Capell\Frontend\Facades\Frontend;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicLanguageSuggestionDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendSettingsReaderInterface $settings) {}

    public function handle(): PublicLanguageSuggestionData
    {
        // All visitors to a cached URL receive the same set of language choices.
        $mode = $this->settings->visitorLanguageDetection();
        $site = Frontend::site();
        $pageLanguage = Frontend::language();
        $languages = [];

        if ($mode !== VisitorLanguageDetection::Off && $pageLanguage instanceof Language && $site?->relationLoaded('siteDomains')) {
            foreach ($site->siteDomains as $domain) {
                if (! $domain->status) {
                    continue;
                }

                if (! $domain->relationLoaded('language')) {
                    continue;
                }

                $language = $domain->language;
                if (! $language instanceof Language) {
                    continue;
                }

                if (isset($languages[$language->id])) {
                    continue;
                }

                $tag = $this->tag($language);
                $languages[$language->id] = ['tag' => $tag, 'name' => (string) $language->name, 'direction' => Language::directionForCode($tag)];
            }
        }

        return new PublicLanguageSuggestionData(
            $mode,
            $pageLanguage instanceof Language ? $this->tag($pageLanguage) : '',
            (string) ($pageLanguage->name ?? ''),
            array_values($languages),
        );
    }

    private function tag(Language $language): string
    {
        return strtolower(str_replace('_', '-', filled($language->locale) ? (string) $language->locale : (string) $language->code));
    }
}

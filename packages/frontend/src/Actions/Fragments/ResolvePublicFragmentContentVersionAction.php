<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions\Fragments;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicFragmentContentVersionAction
{
    use AsObject;

    /**
     * @param  array<string, int|string>  $ownerContext
     */
    public function handle(
        Model&Pageable $page,
        Site $site,
        Language $language,
        Layout $layout,
        array $ownerContext,
    ): string {
        $freshPage = $page->newQuery()->withTrashed()->whereKey($page->getKey())->firstOrFail();
        $freshSite = Site::query()->withTrashed()->whereKey($site->getKey())->firstOrFail();
        $freshLanguage = Language::query()->withTrashed()->whereKey($language->getKey())->firstOrFail();
        $freshLayout = Layout::query()->withTrashed()->whereKey($layout->getKey())->firstOrFail();

        if (! $freshPage instanceof Pageable) {
            throw new LogicException('Public fragment pages must implement the Pageable contract.');
        }

        $translation = Translation::query()
            ->where('translatable_type', $freshPage->getMorphClass())
            ->where('translatable_id', $freshPage->getKey())
            ->where('language_id', $freshLanguage->getKey())
            ->first();
        $pageUrl = PageUrl::query()
            ->where('pageable_type', $freshPage->getMorphClass())
            ->where('pageable_id', $freshPage->getKey())
            ->where('site_id', $freshSite->getKey())
            ->where('language_id', $freshLanguage->getKey())
            ->first();

        $payload = $this->canonicalize([
            'page' => $freshPage->getAttributes(),
            'site' => $freshSite->getAttributes(),
            'language' => $freshLanguage->getAttributes(),
            'layout' => $freshLayout->getAttributes(),
            'translation' => $translation?->getAttributes(),
            'pageUrl' => $pageUrl?->getAttributes(),
            'ownerContext' => $ownerContext,
        ]);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonicalize($value);
            }
        }

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static void run(Site $site, Language $language)
 */
class SetupSiteLanguageAction
{
    use AsObject;

    public function handle(Site $site, Language $language): void
    {
        $translation = $site->translations()->first();
        $translationValues = $translation !== null
            ? Arr::except($translation->replicate()->attributesToArray(), [
                'id',
                'language_id',
                'translatable_type',
                'translatable_id',
                'created_by',
                'updated_by',
                'deleted_by',
                'created_at',
                'updated_at',
                'deleted_at',
            ])
            : [];

        $site->translations()->createOrFirst(['language_id' => $language->id], $translationValues);

        if ($site->siteDomains()->where('language_id', $language->id)->exists()) {
            return;
        }

        $siteDomain = $site->siteDomains()->first();

        if ($siteDomain !== null) {
            $path = '/' . $language->code;

            $replica = $siteDomain->duplicate([
                'full_url',
            ]);
            throw_if($replica === null, RuntimeException::class, 'Site domain could not be duplicated.');

            $replica->fill([
                'language_id' => $language->id,
                'path' => $path,
            ]);

            $replica->save();

            return;
        }

        $site->siteDomains()->create(['language_id' => $language->id]);
    }
}

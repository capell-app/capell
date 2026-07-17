<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, array{language_id: int, title: string, meta: array{slug: string}, content: string}> run(?int $siteId = null)
 */
class BuildDefaultTranslationsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, array{language_id: int, title: string, meta: array{slug: string}, content: string}>
     */
    public function handle(?int $siteId = null): array
    {
        if ($siteId === null || $siteId === 0) {
            /** @var class-string<Site> $siteModel */
            $siteModel = Site::class;

            $siteId = (int) ($siteModel::query()->where('default', true)->value('id') ?? 0) !== 0 ? (int) ($siteModel::query()->where('default', true)->value('id') ?? 0) : null;
        }

        /** @var class-string<SiteDomain> $model */
        $model = SiteDomain::class;

        return $model::query()
            ->where('site_id', $siteId)
            ->groupBy('language_id')
            ->pluck('language_id')
            ->mapWithKeys(fn (int $languageId): array => [
                (string) Str::uuid() => [
                    'language_id' => $languageId,
                    'title' => '',
                    'meta' => ['slug' => ''],
                    'content' => '',
                ],
            ])
            ->all();
    }
}

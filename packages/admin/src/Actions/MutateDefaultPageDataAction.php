<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Contracts\Actionable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run()
 */
class MutateDefaultPageDataAction implements Actionable
{
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $data = [];

        /** @var class-string<Site> $sideModel */
        $sideModel = Site::class;

        $site = $sideModel::getDefault();

        /** @var class-string<Layout> $layoutModel */
        $layoutModel = Layout::class;

        $data['layout_id'] = $layoutModel::query()->default()->value('id');

        /** @var class-string<Blueprint> $model */
        $model = Blueprint::class;

        $data['blueprint_id'] = $model::query()
            ->pageType()
            ->default()
            ->value('id');

        if ($site !== null) {
            $data['site_id'] = $site->id;

            $data['translations'] = [
                (string) Str::uuid() => [
                    'language_id' => $site->language_id,
                ],
            ];
        }

        return $data;
    }
}

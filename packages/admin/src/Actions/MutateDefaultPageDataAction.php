<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Contracts\Actionable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run()
 */
class MutateDefaultPageDataAction implements Actionable
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $data = [];

        $site = SiteAccess::current()->site(null);

        /** @var class-string<Layout> $layoutModel */
        $layoutModel = Layout::class;

        $data['layout_id'] = SiteAccess::current()->query($layoutModel)->default()->value('id');

        /** @var class-string<Blueprint> $model */
        $model = Blueprint::class;

        $data['blueprint_id'] = $model::query()
            ->pageType()
            ->default()
            ->value('id');

        if ($site instanceof Site) {
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

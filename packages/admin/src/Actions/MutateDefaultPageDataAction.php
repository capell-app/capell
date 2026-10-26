<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Actions\Pages\BuildPageLayoutOptionsQueryAction;
use Capell\Core\Contracts\Actionable;
use Capell\Core\Models\Page;
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

        $data['layout_id'] = BuildPageLayoutOptionsQueryAction::run($site?->id)->default()->value('id');

        $data['blueprint_id'] = Page::getDefaultType(null)?->getKey();

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

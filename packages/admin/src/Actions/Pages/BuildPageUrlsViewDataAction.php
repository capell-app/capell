<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Contracts\Support\FlagIconRenderer;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageUrlsViewDataAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{
     *     pageUrls: Collection<int, PageUrl>,
     *     flagIconRenderer: FlagIconRenderer
     * }
     */
    public function handle(Page $record): array
    {
        return [
            'pageUrls' => SiteAccess::current()->scope($record->pageUrls()->getQuery())->with(['language', 'siteDomain'])->get(),
            'flagIconRenderer' => resolve(FlagIconRenderer::class),
        ];
    }
}

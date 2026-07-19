<?php

declare(strict_types=1);

namespace Vendor\FakeAiPackage\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Capell\Core\Support\Publishing\PublishSentinel;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static Page run(Site $site, Language $language, string $name) */
final class CreateDraftPageAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site, Language $language, string $name): Page
    {
        $page = PublicationDateGuard::allow(fn (): Page => Page::query()->create([
            'site_id' => $site->id,
            'name' => $name,
            'visible_from' => PublishSentinel::draftValue(),
        ]));

        $page->translations()->create([
            'language_id' => $language->id,
            'title' => $name,
            'content' => '',
            'meta' => ['slug' => str($name)->slug()->toString()],
        ]);

        return $page;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing;

use Capell\Core\Data\Publishing\PublicationReadinessContextData;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublicationReadinessRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Read-only boundary for publication checks across the page's authored languages. */
/** @internal */
final class GetPublicationBlockingChecksAction
{
    use AsFake;
    use AsObject;

    /** @return list<string> */
    public function handle(Page $page): array
    {
        $registry = resolve(PublicationReadinessRegistry::class);
        if ($registry->contributors() === []) {
            return [];
        }

        $checks = [];
        foreach ($page->translations()->pluck('language_id')->unique() as $languageId) {
            $checks = [...$checks, ...$registry->blockingCheckIds($page, new PublicationReadinessContextData((int) $page->site_id, (int) $languageId))];
        }

        return array_values(array_unique($checks));
    }
}

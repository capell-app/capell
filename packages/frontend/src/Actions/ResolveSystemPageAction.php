<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\SystemPageResolver;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ?Pageable run(string $type, Site $site, Language $language)
 */
final class ResolveSystemPageAction
{
    use AsObject;

    public function handle(string $type, Site $site, Language $language): ?Pageable
    {
        foreach (app()->tagged(SystemPageResolver::TAG) as $resolver) {
            if (! $resolver instanceof SystemPageResolver) {
                continue;
            }

            $page = $resolver->resolve($type, $site, $language);

            if ($page instanceof Pageable) {
                return $page;
            }
        }

        return null;
    }
}

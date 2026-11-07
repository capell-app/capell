<?php

declare(strict_types=1);

namespace Capell\Core\Actions\PublicRead;

use Capell\Core\Models\PageUrl;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Asks the public-read eligibility contributors about one served page address.
 * An address whose absolute URL cannot be built is treated as not readable.
 *
 * @method static bool run(PageUrl $pageUrl)
 */
final class PageUrlIsPubliclyReadableAction
{
    use AsFake;
    use AsObject;

    public function handle(PageUrl $pageUrl): bool
    {
        return PageUrlsArePubliclyReadableAction::run([$pageUrl])[0];
    }
}

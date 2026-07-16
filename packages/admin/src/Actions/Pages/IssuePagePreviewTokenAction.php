<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Page;
use Illuminate\Support\Facades\URL;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Issues a 30-minute signed URL that lets an admin preview a draft page
 * without making it visible to the public.
 *
 * The signature includes the page id, the route name, and the expiry; the
 * route's controller validates it via the standard signed-route mechanism.
 *
 * @method static string run(Page $page, int $ttlMinutes = 30)
 */
final class IssuePagePreviewTokenAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, int $ttlMinutes = 30): string
    {
        return URL::temporarySignedRoute(
            'capell.admin.preview-page',
            now()->addMinutes(max(1, $ttlMinutes)),
            ['page' => $page->getKey()],
        );
    }
}

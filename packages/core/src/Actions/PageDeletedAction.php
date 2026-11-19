<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Events\PageDeleted;
use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class PageDeletedAction
{
    use AsFake;
    use AsObject;

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     * @param  array<string, mixed>  $formData
     */
    public function handle(Pageable $page, array $formData = []): void
    {
        if ($page instanceof Page) {
            // The page observer announces the deletion once the delete commits. Firing the event
            // here as well made listeners see a second deletion with no editor choices, so a
            // chosen redirect was overwritten by a 410.
            try {
                $page->rememberDeletionFormData($formData)->delete();
            } finally {
                $page->pullDeletionFormData();
            }

            return;
        }

        $page->delete();

        event(new PageDeleted($page, $formData));
    }
}

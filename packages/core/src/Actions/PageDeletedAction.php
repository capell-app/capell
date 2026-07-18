<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Events\PageDeleted;
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
        $page->delete();

        event(new PageDeleted($page, $formData));
    }
}

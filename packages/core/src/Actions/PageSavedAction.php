<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Events\PageSaved;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class PageSavedAction
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
        event(new PageSaved($page, $formData));
    }
}

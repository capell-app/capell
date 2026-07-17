<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Core\Models\Language;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ListAdminLanguageOptionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, string>
     */
    public function handle(): Collection
    {
        return Language::query()
            ->enabled()
            ->ordered()
            ->pluck('name', 'id');
    }
}

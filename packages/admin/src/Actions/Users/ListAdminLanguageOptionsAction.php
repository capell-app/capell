<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Core\Models\Language;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ListAdminLanguageOptionsAction
{
    use AsAction;

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

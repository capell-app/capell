<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Hides the getting-started checklist for one person; everyone else still sees it. */
final class DismissFirstRunChecklistAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $actor): void
    {
        resolve(AdminWorkspacePreferenceStore::class)->setFirstRunChecklistHidden($actor, true);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Shows the getting-started checklist again for a person who hid it. */
final class RestoreFirstRunChecklistAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $actor): void
    {
        resolve(AdminWorkspacePreferenceStore::class)->setFirstRunChecklistHidden($actor, false);
    }
}

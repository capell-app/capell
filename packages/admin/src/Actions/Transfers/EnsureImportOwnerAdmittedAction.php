<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Transfers;

use Capell\Admin\Enums\TransferDownloadEnum;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\Transfers\TransferOrigin;
use Capell\Admin\Support\Transfers\TransferOrigins;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Imports\Models\Import;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * An import's rows are written later, often by a queue worker with no request and so no panel stack. Each row
 * is written only while the person who started the import may still use the panel it was started in and may
 * still import the model on the row's site, checked in that site's permission scope as the job runs. An import
 * with no recorded panel, a panel Capell no longer extends, or a row without a known site writes nothing.
 */
final class EnsureImportOwnerAdmittedAction
{
    use AsObject;

    /** @param class-string<Model> $model */
    public function handle(Import $import, ?Authenticatable $actor, string $model, mixed $siteId): void
    {
        $origin = $import->exists
            ? resolve(TransferOrigins::class)->originFor(TransferDownloadEnum::ImportFailures, (string) $import->getKey())
            : null;
        $panel = $origin instanceof TransferOrigin ? resolve(InstalledPanelRuntime::class)->capellPanel($origin->panel) : null;

        if (! $panel instanceof Panel) {
            $this->refuse('capell-admin::message.import_origin_unknown');
        }

        if (! $actor instanceof FilamentUser || ! $actor->canAccessPanel($panel)) {
            $this->refuse('capell-admin::message.import_owner_not_admitted');
        }

        $site = is_numeric($siteId) && Site::query()->whereKey((int) $siteId)->exists() ? (int) $siteId : null;
        if ($site === null) {
            $this->refuse('capell-admin::message.import_site_unknown');
        }

        // As in a request, a global administrator's roles carry no site team, so they are read without one.
        $permitted = PermissionTeamContext::run(
            SiteAccess::forActor($actor)->isGlobal() ? null : $site,
            static fn (): bool => Gate::forUser($actor)->allows('import', $model),
            $actor instanceof Model ? $actor : null,
        );
        if (! $permitted) {
            $this->refuse('capell-admin::message.import_owner_not_permitted');
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['import' => __($message)]);
    }
}

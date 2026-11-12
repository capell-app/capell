<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Transfers;

use Capell\Admin\Enums\TransferDownloadEnum;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\Transfers\TransferOrigin;
use Capell\Admin\Support\Transfers\TransferOrigins;
use Filament\Actions\Imports\Models\Import;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * An import's rows are written later, often by a queue worker with no request and so no panel stack. Each row
 * is written only while the person who started the import may still use the panel it was started in; an import
 * with no recorded panel, or one started in a panel Capell no longer extends, writes nothing.
 */
final class EnsureImportOwnerAdmittedAction
{
    use AsObject;

    public function handle(Import $import, ?Authenticatable $actor): void
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
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['import' => __($message)]);
    }
}

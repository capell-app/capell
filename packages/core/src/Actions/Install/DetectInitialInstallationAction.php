<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class DetectInitialInstallationAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        try {
            $extension = new CapellExtension;
            $schema = $extension->getConnection()->getSchemaBuilder();
            if ($schema->hasTable($extension->getTable())
                && $extension->newQueryWithoutScopes()->whereIn('composer_name', ['capell-app/core', 'capell-app/admin'])->exists()) {
                return false;
            }

            $site = new Site;
            if (! $schema->hasTable($site->getTable())) {
                return true;
            }

            return ! $site->newQueryWithoutScopes()->exists();
        } catch (Throwable) {
            // An unavailable database is not positive evidence of a first install.
            return false;
        }
    }
}

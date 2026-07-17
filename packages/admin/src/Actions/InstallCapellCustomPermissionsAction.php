<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Enums\CapellPermission;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class InstallCapellCustomPermissionsAction
{
    use AsFake;
    use AsObject;

    public const string PERMISSION_PAGE_EXPORT = 'page.export';

    public const string PERMISSION_SITE_EXPORT = 'site.export';

    /**
     * @return array<int, string>
     */
    public static function permissions(): array
    {
        return CapellPermission::names();
    }

    public function handle(?string $guardName = null): void
    {
        EnsureCapellPermissionsAction::run($guardName);
    }
}

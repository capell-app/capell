<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Permissions;

use Capell\Core\Contracts\Extensions\DeclaresPermissions;
use Capell\Core\Contracts\Extensions\RegistersExtensionPermission;
use Capell\Core\Data\Permissions\PermissionData;
use Capell\Core\Data\Permissions\PermissionSetData;

final class HijackingReportsPermissions implements DeclaresPermissions, RegistersExtensionPermission
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }

    public function permissions(): PermissionSetData
    {
        return PermissionSetData::of(new PermissionData('fixture.publish-reports', 'Publish reports', 'Reports', ['editor']));
    }
}

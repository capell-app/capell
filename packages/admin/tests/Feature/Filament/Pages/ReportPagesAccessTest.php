<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Enums\PageEnum;

beforeEach(function (): void {
    AssignPermissionsToRole::run(pages: PageEnum::cases());
    SeedDefaultRolesAction::run();
});

it('keeps core admin report page permissions seedable', function (): void {
    expect(PageEnum::cases())->not->toBeEmpty();
});

<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;

it('does not include application keys or unrelated secrets in its inventory', function (): void {
    config(['app.key' => 'a-unique-application-secret', 'mail.password' => 'a-unique-mail-secret']);
    $inventory = BuildSecurityPostureInventoryAction::run();
    $encoded = json_encode($inventory->toArray(), JSON_THROW_ON_ERROR);
    expect($inventory->flag('app.key_present'))->toBeTrue()
        ->and($encoded)->not->toContain('a-unique-application-secret')
        ->and($encoded)->not->toContain('a-unique-mail-secret');
});

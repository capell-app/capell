<?php

declare(strict_types=1);

use Capell\Admin\Support\Agent\AgentAdminToolRegistry;

it('registers the bounded content, publication, and readiness tool ladder', function (): void {
    $registry = new AgentAdminToolRegistry;

    expect($registry->has('admin.page.properties.read'))->toBeTrue()
        ->and($registry->has('admin.term.properties.read'))->toBeTrue()
        ->and($registry->has('admin.page.properties.write'))->toBeTrue()
        ->and($registry->has('admin.page.terms.write'))->toBeTrue()
        ->and($registry->has('admin.page.draft.save'))->toBeTrue()
        ->and($registry->has('admin.page.publish'))->toBeTrue()
        ->and($registry->has('admin.page.publish.schedule'))->toBeTrue()
        ->and($registry->has('admin.page.agent_readiness.read'))->toBeTrue()
        ->and($registry->has('admin.site.agent_readiness.read'))->toBeTrue()
        ->and($registry->has('admin.settings.write'))->toBeTrue()
        ->and($registry->has('admin.blueprint.write'))->toBeTrue()
        ->and($registry->has('admin.structure.write'))->toBeTrue();
});

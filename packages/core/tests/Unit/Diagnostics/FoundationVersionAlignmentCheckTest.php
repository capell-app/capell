<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;
use Capell\Core\Support\Diagnostics\Checks\FoundationVersionAlignmentCheck;

it('reports differing foundation releases', function (): void {
    ResolveInstalledComposerVersionsAction::mock()->shouldReceive('handle')->once()->andReturn([
        'capell-app/core' => 'v1.0.72',
        'capell-app/admin' => '1.0.73',
        'capell-app/example' => '3.0.0',
    ]);

    $result = resolve(FoundationVersionAlignmentCheck::class)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->evidence['versions'])->not->toHaveKey('capell-app/example')
        ->and($result->remediation)->not->toBeNull();
});

it('recognises aligned releases and reports development versions as unknown', function (): void {
    ResolveInstalledComposerVersionsAction::mock()->shouldReceive('handle')->once()->andReturn([
        'capell-app/core' => 'v1.0.73',
        'capell-app/admin' => '1.0.73',
        'capell-app/frontend' => 'dev-main',
    ]);

    $result = resolve(FoundationVersionAlignmentCheck::class)->check();

    expect($result->passed)->toBeTrue()
        ->and($result->message)->toContain('could not be established')
        ->and($result->evidence['unknown'])->toBe(['capell-app/frontend']);
});

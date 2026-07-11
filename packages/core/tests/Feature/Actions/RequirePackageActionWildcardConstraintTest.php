<?php

declare(strict_types=1);

use Capell\Core\Actions\RequirePackageAction;

// Anonymous test double that bypasses composer invocation.
$TestAction = new class extends RequirePackageAction
{
    public function handle(string $name, ?string $token = null, ?string $provider = null, ?string $domain = null): array
    {
        // Directly invoke guard; skip composer side effects.
        $this->guardVersionConstraint($name);

        return [
            'package' => $name,
            'status' => 'skipped',
            'message' => 'Skipped composer invocation.',
            'output' => '',
            'auth_used' => false,
            'cache_cleared' => false,
        ];
    }
};

it('allows wildcard version constraint in local environment', function () use ($TestAction): void {
    config()->set('app.env', 'local');
    expect(fn (): array => $TestAction->handle('vendor/package:*'))
        ->not()->toThrow(RuntimeException::class);
});

it('allows wildcard version constraint in dev environment', function () use ($TestAction): void {
    config()->set('app.env', 'dev');
    expect(fn (): array => $TestAction->handle('vendor/package:1.*'))
        ->not()->toThrow(RuntimeException::class);
});

it('rejects wildcard version constraint in testing environment', function () use ($TestAction): void {
    config()->set('app.env', 'testing');
    expect(fn (): array => $TestAction->handle('vendor/package:*'))
        ->toThrow(RuntimeException::class);
});

it('rejects wildcard version constraint in production environment', function () use ($TestAction): void {
    config()->set('app.env', 'production');
    expect(fn (): array => $TestAction->handle('vendor/package:2.*'))
        ->toThrow(RuntimeException::class);
});

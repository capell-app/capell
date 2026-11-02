<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\AssertMarketplaceUpdateConstraintAction;
use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set('capell.extensions.verification.strict', true);
    app()->instance(MarketplaceInstalledPackageVersionResolver::class, new class implements MarketplaceInstalledPackageVersionResolver
    {
        #[Override]
        public function prettyVersion(string $composerName): string
        {
            return '1.1.0';
        }
    });
});

it('rejects unsafe strict update constraints', function (string $constraint): void {
    try {
        AssertMarketplaceUpdateConstraintAction::run('vendor/example', $constraint);
        $this->fail('The constraint should have been rejected.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['version_constraint'])->toEqual([__('capell-marketplace::marketplace.errors.strict_update_constraint')]);
    }
})->with(['', '*', '@dev', 'dev-main', '1.2.x-dev', '1.2.9999999-dev', 'dev-main as 1.2.0', '>=1.2', '>1.2', '^1.2 || >=2.0']);

it('accepts a bounded upgrade and retains the upgrade check', function (): void {
    AssertMarketplaceUpdateConstraintAction::run('vendor/example', '^1.2');
    expect(fn (): mixed => AssertMarketplaceUpdateConstraintAction::run('vendor/example', '^1.0'))
        ->toThrow(UnexpectedValueException::class, __('capell-marketplace::marketplace.errors.unsafe_update_constraint'));
});

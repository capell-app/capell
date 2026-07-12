# Testing Packages

Package tests should prove the package boots, registers its extension points, and owns its behavior.

## Test Case

Create a package test case that registers only the providers the package needs.

```php
protected function getPackageProviders($app): array
{
    return [
        \Capell\Core\Providers\CapellServiceProvider::class,
        \Capell\Admin\Providers\AdminServiceProvider::class,
        \Capell\Example\Providers\ExampleServiceProvider::class,
        \Capell\Example\Providers\AdminServiceProvider::class,
    ];
}
```

Force packages installed in tests when testing installed-only behavior:

```php
CapellCore::forcePackageInstalled('capell-app/example');
```

## Manifest Tests

Every package with `capell.json` should test that required manifest fields exist and providers are loadable.

## Provider Tests

Provider tests should assert:

- package metadata is registered.
- admin pages are registered through `CapellAdmin`.
- dashboard Filament widgets are registered in the correct dashboard slot.
- settings schemas are registered when present.

```php
it('registers package metadata', function (): void {
    $package = CapellCore::getPackage('capell-app/example');

    expect($package->name)->toBe('capell-app/example');
});
```

## Action Tests

Test Actions directly. Avoid HTTP tests unless the route or Filament page behavior is the subject.

```php
it('builds the package output data', function (): void {
    $data = BuildExampleOutputAction::run($input);

    expect($data)->toBeInstanceOf(ExampleOutputData::class);
});
```

## Admin Extension Tests

Test direct registration first, then add one render test for the user-facing surface.

```php
it('tags the page schema extender', function (): void {
    $extenders = collect(app()->tagged(PageSchemaExtender::TAG));

    expect($extenders)
        ->toContain(fn (PageSchemaExtender $extender): bool => $extender instanceof ExamplePageSchemaExtender);
});
```

```php
it('registers an extension settings page', function (): void {
    CapellCore::forcePackageInstalled('capell-app/example');

    expect(resolve(ExtensionPageRegistry::class)->get('capell-app/example'))
        ->toBe(ExampleSettingsPage::class);
});
```

## Frontend Output Tests

Any package that renders public HTML needs presence and absence assertions.

```php
it('renders public package output without authoring state', function (): void {
    $response = $this->get('/example-page');

    $response->assertOk();

    expect($response->getContent())
        ->toContain('Expected public copy')
        ->not->toContain('data-capell-editor')
        ->not->toContain('field_path')
        ->not->toContain('signed');
});
```

Run Blade view coverage when adding or changing package views:

```bash
composer coverage:blade
```

The check is ratcheted by `tests/BladeCoverage/baseline.json` and only counts views Laravel actually renders. See [Blade view coverage](../development/blade-view-coverage.md).

## Marketplace Tests

Marketplace-adjacent packages should prove local compatibility and authorization state handling without treating remote metadata as trusted code.

```php
it('records install intent only when an instance is connected', function (): void {
    MarketplaceInstance::factory()->create();

    $acquisition = CreateExtensionAcquisitionAction::run($listing);

    expect($acquisition->composerCommand)->toContain('composer require');
});
```

## Architecture Tests

Use arch tests to prevent package boundary regressions:

- package code should not import app-specific classes.
- frontend providers should not import Filament.
- admin providers should not run on frontend-only contexts.
- packages should not import sibling packages unless Composer requires them.

## Narrow Commands

Run the package suite during implementation:

```bash
vendor/bin/pest packages/example/tests --configuration=phpunit.xml
```

Run the host suite only when the package touches shared contracts, public rendering, install/upgrade, or admin boot:

```bash
composer test
```

## Next

- [Build an extension end to end](build-extension-end-to-end.md)
- [Extension point API reference](extension-point-api-reference.md)
- [Public HTML safety](../frontend/public-html-safety.md)

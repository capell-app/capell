# Package Provider Conventions

Package providers are composition roots. They bind services and register extension surfaces; domain work stays in Actions and other package-owned services.

## Boot Lifecycle

`AbstractPackageServiceProvider` runs two hooks after the application has booted:

- `bootPackage()` always runs, including package discovery and before installation. Use it only for work genuinely required to discover or install the package.
- `bootInstalledPackage()` runs only when discovery is not active and the package is installed. Ordinary runtime, admin, frontend, and settings registrations belong here.

Do not reproduce the installed-package check inside every provider. Override the narrow hook and return `$this`:

```php
protected function bootInstalledPackage(): self
{
    $this->surface()->settingsClass('example', ExampleSettings::class);

    return $this;
}
```

Implement the real registration path first. Provider wiring does not require a special `capell.test` hostname or a mandatory testing-environment gate; tests should exercise the same container registrations as the application.

## Extension Registration

Capell supports two integration mechanisms:

1. Container tags for focused, independently resolved contributors. Always use the contract's `TAG` constant rather than a raw string.
2. `AdminBridgeRegistry` and `AdminBridgeRegistrar` for a package-owned group of admin contributions or context-aware bridges.

Use the provider's `surface()` helper, backed by `PackageSurfaceRegistrar`, for core surfaces such as page types, models, subscribers, and package-owned settings. Register settings supplied by an external admin integration through `AdminBridgeRegistrar::settingsClass()`, `settingsSchema()`, and `settingsMetadata()`.

Do not use an admin bridge merely to avoid a tag. A single schema extender, panel extender, or contributor remains a tagged service; the registrar provides typed convenience methods for those tags.

## Registry APIs

Keyed registries may extend `AbstractKeyedRegistry`. Its storage API is deliberately protected: `setItem()`, `getItem()`, `hasItem()`, `allItems()`, and `clearItems()`.

The concrete registry owns its public vocabulary, validation, key construction, and return types. Do not expose these generic methods directly or reach into another registry's storage.

## Service Lifetimes

Choose the container lifetime from the state the service holds:

- Use a singleton for immutable or application-lifetime services.
- Use a scoped binding for request/job-local mutable state.
- If mutable state must remain singleton-scoped, implement `Resettable`, tag it with `Resettable::TAG`, and clear all request-derived state in `flushOctaneState()`.

This applies to registries and bridge services as well as ordinary services. A singleton must not retain a request, user, tenant, model, or site between Octane requests.

Capell does not require Laravel Octane, but it must remain fully functional when an application installs and enables Octane. Keep Octane references behind optional runtime checks so an ordinary Laravel installation does not need the package. Treat every worker request or job as an isolation boundary: resolve scoped collaborators from the current container, and reset any deliberately mutable singleton through Capell's reset contract.

Do not add a process-static cache as a shortcut around container resolution. Immutable bootstrap metadata may be cached through the existing manifest/cache services; request-derived state may not survive into the next Octane request.

## Provider Boundaries

Keep filesystem discovery and manifest building in the dedicated package bootstrap services. A package provider may delegate to those services, but should not scan Composer metadata, traverse directories, or read manifests itself. This keeps the boot path measurable and lets cached production boots avoid discovery work.

First-party extension contracts should be referenced directly. Use `class_exists()`, `interface_exists()`, or `method_exists()` only at a documented optional-integration boundary, such as Octane support when Octane is installed. Compatibility probes are not a substitute for updating a first-party consumer to the current contract.

## Shared Helpers

Avoid globally named helpers when a class or namespaced function will do. If a shared test/bootstrap helper must be global, guard it with `function_exists()` and give it a collision-resistant, feature-specific name. Generic names such as `fixture()`, `packagePath()`, or `makePackage()` can collide when suites are loaded together.

## Related Documentation

- [Service providers](../packages/service-providers.md)
- [Extension point chooser](../packages/extension-point-chooser.md)
- [Extension point API reference](../packages/extension-point-api-reference.md)

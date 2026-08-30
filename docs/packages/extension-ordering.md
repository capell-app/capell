# Extension ordering

Admin surface contributions and Frontend render-hook contributions share the
Core-owned `ExtensionOrderResolver`. Use a stable key and an owner for every
positioned contribution. Existing numeric priorities remain supported and are
adapted to `ExtensionPosition::priority()`.

```php
ExtensionPosition::first();
ExtensionPosition::last();
ExtensionPosition::priority(20);
ExtensionPosition::before('capell.core.banner');
ExtensionPosition::after('capell.core.banner');
```

Equal priorities retain registration order. Re-registering the same owner,
source, and key is idempotent; a different owner or source must use the
registry's explicit replacement method. Registries can be frozen once provider
boot has completed. Missing anchors and cycles retain deterministic fallback
order and are exposed through `orderingDiagnostics()` for audit tooling.

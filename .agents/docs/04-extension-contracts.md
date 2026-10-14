## Extension Contracts

- Register package behaviour from the correct manifest-v3 provider bucket.
  `metadata` and `install` providers are lifecycle-safe; `runtime`, `admin`, and
  `frontend` providers load only in their intended enabled contexts. Providers wire
  contracts and delegate work; they do not perform domain writes.
- Register page types and model aliases through `CapellCore::registerPageType()` and
  `CapellCore::registerModels()`. Use model interceptors only through the documented
  facade contract.
- Use `AdminBridgeRegistrar` for grouped Admin integration and
  `CapellAdmin::contributeToAdminSurface()` for focused surfaces. Extend forms,
  tables, actions, and workflows through their contract `TAG` constants, including
  `PageSchemaExtender::TAG` and `PageTableExtender::TAG`; never publish or copy Core
  schemas (`capell:admin-publish-schemas` is not an extension path).
- Register public hooks and assets through `RenderHookRegistry`,
  `FrontendResourceRegistry`, `TailwindAssetsRegistry`, and related typed
  registries. Declare cache dependencies through `CacheInvalidationRegistry`.
- Register package settings through `PackageSurfaceRegistrar`; external Admin
  integrations use `AdminBridgeRegistrar`. Do not write directly to the settings
  registry outside its supported provider boundary.
- Add install-time host edits through `InstallPatchRegistry`, not duplicated file
  editors or package imports in Core.
- Add every Core migration filename to
  `packages/core/src/Concerns/HasMigrations.php`. Settings migrations live in
  `database/settings/`, are registered by the owning install/setup flow, and must
  tolerate the settings table not existing during early bootstrap.
- `docs/packages/extension-surface-catalog.{json,md}` is generated from executable
  catalogue metadata. Stable surfaces, public signatures, Core migrations,
  constraints, and config keys are protected by
  `docs/packages/stable-extension-api-baseline.json`; compatibility drift requires
  an explicit matching decision record.

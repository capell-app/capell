# Core Filament neutrality on the 1.x line

Core owns CMS contracts and neutral configuration. Admin owns the translation
from that configuration to Filament fields, enum presentation, and Admin-only
diagnostics.

The 1.x line keeps the historical Core Filament-facing enums, media field
factory, and diagnostics action as documented compatibility adapters. They are
not a new extension surface: first-party code must use the Admin contributor
or adapter contracts, and packages should migrate at their next compatible
release. `filament/support` remains a 1.x dependency so existing consumers do
not lose resolution or rendering behaviour.

The compatibility inventory is intentionally finite and ratcheted by
`FilamentNeutralBoundaryTest`: `AssetEnum`, `CacheTime`,
`ExtensionAutoUpdatePolicyEnum`, `ExtensionReleaseKindEnum`,
`HeaderPositionEnum`, `ImageSourceType`, `InteractionTargetType`, `LayoutEnum`,
`MediaAlignment`, `MenuAlignmentEnum`, `PublishStatusEnum`,
`PublishVisibilityStateEnum`, `RedirectStatusCodeEnum`, `UrlTypeEnum`,
`PageTypeData`, `PatchStatus`, `MediaFieldFactory`,
`SpatieMediaFieldFactory`, and `CheckAdminPanelAccessAction`. New Core paths
must not add to this list.

For media fields, consume `Capell\\Core\\Contracts\\Media\\MediaUploadConfigurationFactory`
and translate `MediaUploadConfigurationData` in the package's UI boundary. For
Admin enum labels/options, register an
`Capell\\Admin\\Contracts\\EnumPresentationContributor` and resolve the
`EnumPresentationRegistry`. Admin diagnostics implement the Core
`DoctorCheck::TAG` contract and are tagged by the Admin provider.

Rollback is configuration-only: keep the generated 1.x manifests and adapter
bindings in place, then disable the new provider/contributor registration or
restore the previous package configuration. Do not remove the stable adapter
classes until the next-major decision and consumer fixtures are accepted.

## Next-major preparation

The draft decision and dependency baseline live in
[`core-next-major-compatibility-decision.json`](core-next-major-compatibility-decision.json)
and [`core-next-major-compatibility-baseline.json`](core-next-major-compatibility-baseline.json).
They are preparation artefacts only: no 2.x package line exists yet, and the
1.x composer constraint and adapters remain unchanged. The old-package failure
wording is pinned in
[`fixtures/core-2x-old-package-failure.txt`](fixtures/core-2x-old-package-failure.txt)
so the eventual solver/discovery error, upgrade guide, and changelog can land
together after CAP-0270 adoption evidence is available.

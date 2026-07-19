# Project Build Manifest Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the versioned, provider-neutral Project Build Manifest envelope, canonical reader, validation, migration seam, and package-owned artifact-handler registry required by Website Generator consumers.

**Architecture:** Core owns portable manifest structure, detached Ed25519 signing bytes and verification, structural/digest validation, and a fail-closed bundle validator without importing commerce, accounts, DesignSpec, or provider policy. Artifact bytes remain external to the envelope and are addressed by safe relative paths; core verifies size and SHA-256 before dispatching payload validation to a stable tagged handler. Capell App later wraps these portable bytes in a separate signed authorisation envelope.

**Tech Stack:** PHP 8.4, Laravel 13 container/provider lifecycle, Spatie Laravel Data, Pest 4, JSON Schema draft 2020-12.

---

### Task 1: Typed portable envelope

**Files:**
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildArtifactReferenceData.php`
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildPackageData.php`
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildSiteData.php`
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildRouteData.php`
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildCompatibilityData.php`
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildSignatureData.php`
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildSiteSpecReferenceData.php`
- Create: `packages/core/src/Data/ProjectBuild/ProjectBuildManifestData.php`
- Test: `packages/core/tests/Unit/ProjectBuild/ProjectBuildManifestDataTest.php`

- [ ] **Step 1: Write the failing typed-envelope test**

Construct one manifest with a `site-spec` reference, `capell-theme` artifact, exact package release identity, one site/default locale, one route, compatibility constraints, and Ed25519 signature metadata. Assert every nested value survives `toArray()` with list order preserved.

- [ ] **Step 2: Run the focused test and confirm missing classes**

Run: `vendor/bin/pest packages/core/tests/Unit/ProjectBuild/ProjectBuildManifestDataTest.php --configuration=phpunit.xml --compact`

Expected: FAIL because the ProjectBuild Data classes do not exist.

- [ ] **Step 3: Implement strict readonly Data objects**

Use constructor-promoted typed properties. The artifact reference contains `key`, `type`, safe relative `path`, lowercase SHA-256 `digest`, `sizeBytes`, and `mediaType`. The typed SiteSpec reference additionally carries its schema version. The envelope contains `schemaVersion`, UUID `buildId`, RFC 3339 `createdAt`, the exact SiteSpec reference, other artifacts, exact packages, site/locale topology, routes, compatibility, and signature metadata.

- [ ] **Step 4: Run the focused test**

Expected: PASS with exact nested values and ordering.

### Task 2: Structural validator and canonical bytes

**Files:**
- Create: `packages/core/src/Actions/ProjectBuild/ValidateProjectBuildManifestAction.php`
- Create: `packages/core/src/Actions/ProjectBuild/CanonicalizeProjectBuildManifestAction.php`
- Create: `packages/core/src/Actions/ProjectBuild/CanonicalizeProjectBuildManifestSigningInputAction.php`
- Create: `packages/core/src/Actions/ProjectBuild/VerifyProjectBuildManifestSignatureAction.php`
- Test: `packages/core/tests/Unit/ProjectBuild/ValidateProjectBuildManifestActionTest.php`

- [ ] **Step 1: Write rejection tests**

Cover unsupported schema versions, unsafe/absolute/traversal paths, malformed digests, byte limits, duplicate artifact keys/paths, duplicate packages/install order, missing default locale, route references to unknown sites/locales, invalid route paths, and duplicate site/locale/route identities.

- [ ] **Step 2: Run and confirm missing Action failures**

Run the validator test file directly with the root `phpunit.xml`.

- [ ] **Step 3: Implement cross-object validation**

Return the validated immutable Data object. Throw `ValidationException` with stable field paths. Require schema version `1`, `site-spec` as the SiteSpec artifact type, at least one site and route, exact Composer package names and versions, safe relative POSIX paths, bounded non-negative byte sizes, and lowercase SHA-256 digests.

- [ ] **Step 4: Implement canonical encoding**

Recursively sort associative object keys while preserving JSON list order, then encode with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Define detached signing input by omitting only `signature.value`, preserving the signed algorithm and key ID. Prove identical logical objects produce identical bytes and digest, and add real sign/verify/tamper/wrong-key tests.

- [ ] **Step 5: Run the validator/canonical tests**

Expected: PASS for the canonical fixture and every refusal case.

### Task 3: Artifact payload handler registry

**Files:**
- Create: `packages/core/src/Contracts/ProjectBuild/ProjectBuildArtifactHandler.php`
- Create: `packages/core/src/Support/ProjectBuild/ProjectBuildArtifactHandlerRegistry.php`
- Modify: `packages/core/src/Providers/CapellServiceProvider.php`
- Test: `packages/core/tests/Unit/ProjectBuild/ProjectBuildArtifactHandlerRegistryTest.php`

- [ ] **Step 1: Write failing registry tests**

Register a tagged fixture handler, assert sorted discovery, reject duplicate/empty types, refuse missing handlers, reject size and digest mismatches before handler execution, and call the handler exactly once for verified bytes.

- [ ] **Step 2: Implement the stable contract**

```php
interface ProjectBuildArtifactHandler
{
    public const string TAG = 'capell.project-build.artifact-handler';

    public function type(): string;

    public function validate(ProjectBuildArtifactReferenceData $artifact, string $bytes): void;
}
```

- [ ] **Step 3: Implement and register the scoped registry**

Discover handlers once through the stable container tag. Before dispatch, compare `strlen($bytes)` and `hash('sha256', $bytes)` with the immutable reference using constant-time digest comparison.

- [ ] **Step 4: Run focused registry tests**

Expected: PASS, including no handler call on integrity failure.

### Task 4: Compatibility reader and explicit migration registry

**Files:**
- Create: `packages/core/src/Contracts/ProjectBuild/ProjectBuildManifestMigration.php`
- Create: `packages/core/src/Support/ProjectBuild/ProjectBuildManifestMigrationRegistry.php`
- Create: `packages/core/src/Actions/ProjectBuild/ReadProjectBuildManifestAction.php`
- Modify: `packages/core/src/Providers/CapellServiceProvider.php`
- Test: `packages/core/tests/Unit/ProjectBuild/ReadProjectBuildManifestActionTest.php`

- [ ] **Step 1: Write reader/migration tests**

Assert v1 reads directly, malformed JSON is refused, future versions are refused, an explicitly registered Core-owned v0-to-v1 fixture migration runs once, migration gaps/cycles are refused, and the migrated result passes the same validator.

- [ ] **Step 2: Implement the migration seam**

Migrations declare integer `fromVersion()`, `toVersion()`, and `migrate(array $payload): array`. The internal Core-owned registry requires one forward migration per source version and rejects duplicates or non-forward transitions; companion packages migrate only their own typed artifact payloads.

- [ ] **Step 3: Implement the reader**

Decode with `JSON_THROW_ON_ERROR`, migrate sequentially to current version `1`, hydrate `ProjectBuildManifestData`, then run structural validation. No implicit best-effort coercion is allowed.

- [ ] **Step 4: Run focused reader tests**

Expected: PASS for direct and migrated v1, with explicit failures for malformed/future/gapped inputs.

### Task 5: JSON Schema, fixtures, and stable extension surface

**Files:**
- Create: `packages/core/src/Support/ProjectBuild/ProjectBuildManifestSchema.php`
- Create: `packages/core/tests/fixtures/project-build/one-site-one-locale.json`
- Create: `packages/core/tests/fixtures/project-build/two-site-two-locale.json`
- Create: `packages/core/tests/fixtures/project-build/artifacts/site-spec.json`
- Create: `packages/core/tests/Unit/ProjectBuild/ProjectBuildManifestSchemaTest.php`
- Modify: `packages/core/src/Actions/Extensions/BuildExtensionSurfaceCatalogAction.php`
- Modify: `packages/core/tests/Unit/Actions/Extensions/BuildExtensionSurfaceCatalogActionTest.php`
- Regenerate: `docs/packages/extension-surface-catalog.json`
- Regenerate: `docs/packages/extension-surface-catalog.md`
- Modify: `docs/packages/stable-extension-api-baseline.json`

- [ ] **Step 1: Write schema and fixture tests**

Assert draft 2020-12 identity, `additionalProperties: false`, every required envelope field, safe path/digest patterns, and successful reader validation for one-site/default-locale and two-site/two-locale fixtures.

- [ ] **Step 2: Implement the deterministic schema**

The schema describes only core-owned portable fields. It does not name DesignSpec properties, commerce, provider usage, customer IDs, entitlements, or signed preview URLs.

- [ ] **Step 3: Add stable catalogue entries**

Add the artifact-handler contract/tag plus signing-input, signature-verification, and fail-closed bundle Actions as Stable with direct contract-test IDs. Keep the Core-owned migration seam Internal and the manifest DTO/schema Experimental until the first compatible consumer release.

- [ ] **Step 4: Regenerate and verify extension catalogues**

Run: `php scripts/build-extension-surface-catalog.php`

Run: `php scripts/check-stable-extension-api.php`

Expected: generated outputs match and stable baseline changes are explicit.

### Task 6: Stage gate and release compatibility

- [ ] **Step 1: Format owned PHP files**

Run Pint against the exact Project Build files and modified provider/catalogue files.

- [ ] **Step 2: Run focused Project Build and extension-surface tests**

Expected: all focused tests pass with no warnings.

- [ ] **Step 3: Run core analysis and full preflight from the exact committed source**

Record unrelated failures separately; do not claim the gate if the exact source was not exercised.

- [ ] **Step 4: Expert review**

Run independent code and architecture reviews. Fix every validated P1/P2 finding and rerun focused evidence.

- [ ] **Step 5: Publish producer releases in order**

Release the lockstep Core foundation containing the manifest and SiteSpec seam, then release Navigation against it. Verify both remote tags and GitHub releases.

- [ ] **Step 6: Prove clean remote consumers and adopt app locks**

Run the app-owned remote consumer verifier, update the committed app `composer.json`/`composer.lock` pair to the verified releases, run focused app compatibility tests and canary, then commit the lock adoption.

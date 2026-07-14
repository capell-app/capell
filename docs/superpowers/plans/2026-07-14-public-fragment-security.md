# Public Fragment Security Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the global deferred-fragment builder with an owner-aware encrypted protocol whose endpoints revoke stale or ineligible references with generic 404 responses.

**Architecture:** Frontend owns the versioned envelope, encrypted codec, URL resolver registry, and authoritative public-context Action. Layout Builder and Marketing own separate resolver registrations and routes. Every endpoint resolves current page/site/language/layout/content state before applying cache headers or rendering HTML.

**Tech Stack:** PHP 8.4+, Laravel Crypt/container/routing, Spatie Laravel Data, Pest, Capell Frontend/Core, Layout Builder, consuming Laravel application.

---

## Repository ownership

- Foundation: `/Users/ben/Sites/packages/capell/capell-4`
- Layout Builder: `/Users/ben/Sites/packages/capell/capell-packages-4`
- Marketing consumer: `/Users/ben/Sites/capell-app`

Use clean task worktrees. The current companion-package worktree has unrelated changes and must not be edited or cleaned.

### Task 1: Lock the shared envelope and codec contract

**Files:**
- Delete: `packages/frontend/src/Contracts/DeferredFragmentReferenceBuilder.php`
- Create: `packages/frontend/src/Data/Fragments/PublicFragmentReferenceData.php`
- Create: `packages/frontend/src/Contracts/Fragments/PublicFragmentReferenceCodec.php`
- Create: `packages/frontend/src/Support/Fragments/EncryptedPublicFragmentReferenceCodec.php`
- Modify: `packages/frontend/src/Providers/FrontendServiceProvider.php`
- Test: `packages/frontend/tests/Unit/Fragments/EncryptedPublicFragmentReferenceCodecTest.php`

- [ ] **Step 1: Write failing codec tests** covering a complete round trip, malformed ciphertext, invalid JSON, missing fields, unsupported `formatVersion`, and non-scalar owner payload. Assert failures throw `PublicFragmentReferenceInvalid` without including token contents.

- [ ] **Step 2: Run the focused test and prove red**

  Run: `vendor/bin/pest packages/frontend/tests/Unit/Fragments/EncryptedPublicFragmentReferenceCodecTest.php`

  Expected: FAIL because the envelope and codec do not exist.

- [ ] **Step 3: Add the typed envelope**

  Implement the constructor exactly as:

  ```php
  final class PublicFragmentReferenceData extends Data
  {
      /** @param array<string, int|string> $ownerContext */
      public function __construct(
          public readonly string $owner,
          public readonly int $formatVersion,
          public readonly string $pageableType,
          public readonly int|string $pageableId,
          public readonly int|string $siteId,
          public readonly int|string $languageId,
          public readonly string $contentVersion,
          public readonly array $ownerContext,
      ) {}
  }
  ```

  Keep `CURRENT_FORMAT_VERSION = 1` in the codec, not in an owner package.

- [ ] **Step 4: Implement and bind the codec** using `Crypt::encryptString()` and `Crypt::decryptString()`, strict JSON decoding, an explicit allowed-key check, and one public invalid-reference exception. Never serialize model classes or model instances.

- [ ] **Step 5: Run the codec test**

  Expected: PASS; malformed cases expose only the generic exception message.

- [ ] **Step 6: Commit**

  ```bash
  git add packages/frontend/src packages/frontend/tests/Unit/Fragments
  git commit -m "feat(frontend): add encrypted public fragment reference codec"
  ```

### Task 2: Add explicit owner registration and URL resolution

**Files:**
- Create: `packages/frontend/src/Contracts/Fragments/PublicFragmentUrlResolver.php`
- Create: `packages/frontend/src/Support/Fragments/PublicFragmentUrlResolverRegistry.php`
- Create: `packages/frontend/src/Exceptions/DuplicatePublicFragmentOwner.php`
- Modify: `packages/frontend/src/Providers/FrontendServiceProvider.php`
- Modify: `packages/frontend/src/Actions/BuildInteractionRenderDataAction.php`
- Modify: `packages/admin/src/Filament/Components/Forms/Interactions/InteractionSettingsSchema.php`
- Test: `packages/frontend/tests/Unit/Fragments/PublicFragmentUrlResolverRegistryTest.php`
- Test: `packages/frontend/tests/Unit/Actions/BuildInteractionRenderDataActionTest.php`

- [ ] **Step 1: Write failing registry tests** proving exact owner lookup, unknown-owner rejection, duplicate-owner boot failure, and URL generation through only the registered resolver.

- [ ] **Step 2: Define the resolver contract**

  ```php
  interface PublicFragmentUrlResolver
  {
      public const string TAG = 'capell.frontend.public-fragment-url-resolver';

      public function owner(): string;

      public function url(PublicFragmentReferenceData $reference): string;
  }
  ```

- [ ] **Step 3: Implement a scoped registry** built from the tagged iterator. Reject empty/duplicate owners during registry construction and throw the same public invalid-reference exception for unknown owners.

- [ ] **Step 4: Replace global-builder consumers** so interaction render data accepts a typed reference/token, decodes it, and asks the registry for the matching owner. Admin exposes the fragment interaction target only when the registry contains at least one owner.

- [ ] **Step 5: Run the focused Frontend/Admin tests**

  Run: `vendor/bin/pest packages/frontend/tests/Unit/Fragments/PublicFragmentUrlResolverRegistryTest.php packages/frontend/tests/Unit/Actions/BuildInteractionRenderDataActionTest.php packages/admin/tests/Feature/Filament/Components/InteractionSettingsSchemaTest.php`

  Expected: PASS with no `DeferredFragmentReferenceBuilder` reference in the changed packages.

- [ ] **Step 6: Commit**

  ```bash
  git add packages/frontend packages/admin
  git commit -m "refactor(frontend): resolve fragments by explicit owner"
  ```

### Task 3: Centralize authoritative public-fragment context validation

**Files:**
- Create: `packages/frontend/src/Data/Fragments/PublicFragmentContextData.php`
- Create: `packages/frontend/src/Actions/Fragments/ResolvePublicFragmentContextAction.php`
- Test: `packages/frontend/tests/Feature/Fragments/ResolvePublicFragmentContextActionTest.php`

- [ ] **Step 1: Write the state matrix first** using frozen time. Cover published, draft sentinel, scheduled, expired, soft-deleted, missing page, wrong site, wrong language, inactive language, missing URL eligibility, stale content version, and mismatched layout identity.

- [ ] **Step 2: Implement the Action** to query the pageable without trusting hydrated token data, include soft-deleted detection, call the Core publication classifier, resolve the active site/language relationship, verify the declared owner context, and compare `contentVersion` with a deterministic current version derived from the authoritative public render inputs.

  Return only:

  ```php
  final readonly class PublicFragmentContextData
  {
      public function __construct(
          public Model&Pageable $page,
          public Site $site,
          public Language $language,
          public PublicFragmentReferenceData $reference,
      ) {}
  }
  ```

  Convert every eligibility failure into `ModelNotFoundException` so HTTP endpoints produce an indistinguishable 404.

- [ ] **Step 3: Run the matrix test**

  Expected: PASS and exactly one state outcome per fixture.

- [ ] **Step 4: Commit**

  ```bash
  git add packages/frontend/src/Actions/Fragments packages/frontend/src/Data/Fragments packages/frontend/tests/Feature/Fragments
  git commit -m "feat(frontend): authorize public fragment contexts"
  ```

### Task 4: Register Layout Builder as an isolated owner

**Files:**
- Create: `packages/layout-builder/src/Fragments/LayoutBuilderFragmentUrlResolver.php`
- Modify: `packages/layout-builder/src/LayoutBuilderServiceProvider.php`
- Modify: `packages/layout-builder/src/Actions/Fragments/RenderPublicFragmentAction.php`
- Modify: `packages/layout-builder/src/Http/Controllers/PublicFragmentController.php`
- Modify: `packages/layout-builder/src/Support/LayoutBuilderPublicWidgetAssetsRenderer.php`
- Modify: `packages/layout-builder/resources/views/components/layout/widget.blade.php`
- Modify: `packages/layout-builder/tests/Feature/Fragments/PublicFragmentRenderingTest.php`
- Test: `packages/layout-builder/tests/Feature/Fragments/LayoutBuilderFragmentContainerBindingTest.php`

- [ ] **Step 1: Add failing integration scenarios** for the real consuming container binding, publish-to-draft/expired/deleted revocation, stale content version, replacement token success, cross-site/language/layout rejection, malformed token, unknown owner, and unsupported version.

- [ ] **Step 2: Register owner `layout-builder`** under the shared tag and make its resolver generate only `capell-layout-builder.fragments.show`. Remove direct `/_fragments/` string assembly from Blade and support classes.

- [ ] **Step 3: Rework rendering** so the controller decodes, asserts `owner === 'layout-builder'`, runs `ResolvePublicFragmentContextAction`, verifies widget/layout ownership, renders, runs `PublicHtmlSafetyInspector`, and only then applies public cache headers.

- [ ] **Step 4: Run Layout Builder fragment tests**

  Run from `/Users/ben/Sites/packages/capell/capell-packages-4`: `vendor/bin/pest packages/layout-builder/tests/Feature/Fragments`

  Expected: PASS; every negative route scenario returns 404 with the same response body.

- [ ] **Step 5: Commit in the companion repository**

  ```bash
  git add packages/layout-builder
  git commit -m "feat(layout-builder): own and revoke public fragment references"
  ```

### Task 5: Register Marketing as a separate owner

**Files:**
- Delete: `app/Support/Marketing/Rendering/MarketingDeferredFragmentReferenceBuilder.php`
- Create: `app/Support/Marketing/Rendering/MarketingFragmentUrlResolver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Actions/Marketing/RenderDeferredMarketingFragmentAction.php`
- Modify: `tests/Feature/MarketingDeferredFragmentPlaceholderTest.php`
- Create: `tests/Feature/MarketingFragmentRevocationTest.php`

- [ ] **Step 1: Write failing owner-isolation tests** proving Marketing accepts only owner `marketing`, Layout Builder tokens cannot hit the Marketing renderer, Marketing tokens cannot hit Layout Builder, and content/publication changes revoke old tokens.

- [ ] **Step 2: Register owner `marketing`** through `PublicFragmentUrlResolver::TAG`; use the existing named Marketing fragment route and remove the global interface binding from `AppServiceProvider`.

- [ ] **Step 3: Delegate authorization** to the shared context Action before resolving Marketing block data. Preserve the existing public output safety test and apply cache headers only after successful render inspection.

- [ ] **Step 4: Run consumer tests**

  Run from `/Users/ben/Sites/capell-app`: `php artisan test tests/Feature/MarketingDeferredFragmentPlaceholderTest.php tests/Feature/MarketingFragmentRevocationTest.php tests/Feature/Marketing/PublicMarketingOutputSafetyTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit in the consuming app**

  ```bash
  git add app/Providers/AppServiceProvider.php app/Actions/Marketing app/Support/Marketing tests/Feature
  git commit -m "feat(marketing): isolate public fragment ownership"
  ```

### Task 6: Remove the superseded surface and verify the assembled contract

**Files:**
- Modify: `docs/frontend/widget-targets.md`
- Modify: `docs/performance/fragment-caching.md`
- Modify: `packages/frontend/tests/Unit/FrontendPackageTest.php`
- Create: `tests/Integration/PublicFragmentOwnershipContractTest.php`

- [ ] **Step 1: Add an architecture assertion** that `DeferredFragmentReferenceBuilder` is absent from source, docs, and container bindings across all three repositories.

- [ ] **Step 2: Update documentation** with envelope fields, owner registration, revocation semantics, generic 404 behavior, and the rule that cache headers follow authorization.

- [ ] **Step 3: Run combined verification**

  ```bash
  rg -n "DeferredFragmentReferenceBuilder" packages docs
  vendor/bin/pest packages/frontend/tests packages/admin/tests/Feature/Filament/Components/InteractionSettingsSchemaTest.php tests/Integration/PublicFragmentOwnershipContractTest.php
  ```

  Expected: `rg` exits 1 with no matches; Pest passes.

- [ ] **Step 4: Commit the foundation cleanup**

  ```bash
  git add docs packages/frontend packages/admin tests/Integration
  git commit -m "docs(frontend): formalize public fragment ownership contract"
  ```

## Exit gate

- Each real owner resolves only its own named route under actual container bindings.
- Every malformed, stale, unpublished, deleted, cross-site, cross-language, unknown-owner, and unsupported-version request returns generic 404.
- Successful fragments retain public-output safety inspection.
- Cache headers are absent on rejected responses.
- The old global builder has no source, binding, test, or documentation reference.

# Marketplace Policy and Cache Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce Marketplace install policy at the authoritative Action boundary and make translation cache invalidation follow ownership before dependency traversal.

**Architecture:** Marketplace calls enter through one typed request, fetch a fresh listing, evaluate direct and transitive maturity/entitlement/compatibility/consent, and record evidence before any install side effect. Frontend cache invalidation resolves translation ownership into graph roots, then uses the existing registry/executor to deduplicate and invalidate only dependent pages.

**Tech Stack:** PHP 8.4+, Laravel HTTP/Eloquent/queues/cache, Filament 4, Spatie Laravel Data, Pest.

---

### Task 1: Define the authoritative Marketplace install request

**Files:**
- Create: `packages/marketplace/src/Data/MarketplaceInstallRequestData.php`
- Create: `packages/marketplace/src/Data/MarketplaceInstallActorData.php`
- Create: `packages/marketplace/src/Data/MarketplaceInstallPolicyEvidenceData.php`
- Create: `packages/marketplace/src/Enums/MarketplaceInstallSource.php`
- Test: `packages/marketplace/tests/Unit/MarketplaceInstallRequestDataTest.php`

- [ ] **Step 1: Write failing construction tests** for local UI, hosted resume, table helper, CLI, and programmatic calls. Require selected slug, normalized install options, actor identity/source, and explicit `betaAcknowledged`.

- [ ] **Step 2: Implement immutable request types**. The top-level constructor is:

  ```php
  final class MarketplaceInstallRequestData extends Data
  {
      /** @param array<string, mixed> $options */
      public function __construct(
          public readonly string $extensionSlug,
          public readonly array $options,
          public readonly MarketplaceInstallActorData $actor,
          public readonly bool $betaAcknowledged,
          public readonly MarketplaceInstallSource $source,
      ) {}
  }
  ```

- [ ] **Step 3: Add policy evidence fields** for fetched listing fingerprint/time, selected maturity, dependency maturity map, entitlement decision, compatibility decision, consent decision, and final stable reason.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/marketplace/tests/Unit/MarketplaceInstallRequestDataTest.php`

  ```bash
  git add packages/marketplace/src/Data packages/marketplace/src/Enums packages/marketplace/tests/Unit
  git commit -m "feat(marketplace): type install requests and policy evidence"
  ```

### Task 2: Persist consent and evidence for every attempt

**Files:**
- Create: `packages/marketplace/database/migrations/2026_07_14_000001_add_policy_evidence_to_marketplace_install_attempts.php`
- Modify: `packages/marketplace/src/Models/MarketplaceInstallAttempt.php`
- Modify: `packages/marketplace/src/Actions/RecordMarketplaceInstallAttemptAction.php`
- Modify: `packages/marketplace/src/Actions/QueueMarketplaceInstallAttemptAction.php`
- Test: `packages/marketplace/tests/Feature/Models/MarketplaceInstallAttemptPolicyEvidenceTest.php`

- [ ] **Step 1: Write a failing persistence test** that records blocked and queued attempts and asserts `beta_acknowledged`, `policy_evidence`, actor/source context, and fetched listing fingerprint survive a reload.

- [ ] **Step 2: Add the migration** with non-null boolean `beta_acknowledged` defaulting false and nullable JSON `policy_evidence`. Add fillable properties and strict casts to the model.

- [ ] **Step 3: Require evidence in both recording Actions** so blocked policy decisions cannot bypass the ledger.

- [ ] **Step 4: Run migration/model tests and commit**

  Run: `vendor/bin/pest packages/marketplace/tests/Feature/Models/MarketplaceInstallAttemptPolicyEvidenceTest.php packages/marketplace/tests/Unit/Models/MarketplaceModelFillableTest.php`

  ```bash
  git add packages/marketplace/database packages/marketplace/src/Models packages/marketplace/src/Actions packages/marketplace/tests
  git commit -m "feat(marketplace): record install policy evidence"
  ```

### Task 3: Make `InstallMarketplaceExtensionAction` the policy boundary

**Files:**
- Modify: `packages/marketplace/src/Actions/InstallMarketplaceExtensionAction.php`
- Modify: `packages/marketplace/src/Actions/ResolveMarketplaceInstallEligibilityAction.php`
- Create: `packages/marketplace/src/Actions/BuildMarketplaceInstallPolicyEvidenceAction.php`
- Create: `packages/marketplace/src/Actions/ResolveMarketplaceDependencyMaturityAction.php`
- Modify: `packages/marketplace/tests/Feature/Actions/InstallMarketplaceExtensionActionTest.php`
- Create: `packages/marketplace/tests/Feature/Actions/MarketplaceInstallMaturityPolicyTest.php`

- [ ] **Step 1: Add failing authoritative-policy cases** for direct beta without acknowledgement, stable cached listing drifting to fresh beta, stable selection with beta transitive dependency, incompatible dependency, missing entitlement, successful acknowledged beta, and exact beta dependency identification.

- [ ] **Step 2: Change the Action signature** to accept only `MarketplaceInstallRequestData` plus injected collaborators. Fetch the listing from the remote/fresh provider before reading maturity or eligibility; cached catalogue data may populate UI but never authorize install.

- [ ] **Step 3: Evaluate in one order**: listing exists → direct/transitive maturity → compatibility → entitlement → explicit consent. Build evidence at every step, record a blocked attempt before returning, and begin acquisition/Composer work only after the final allowed decision.

- [ ] **Step 4: Preserve stable failure reasons** such as `beta_acknowledgement_required`, `beta_dependency_acknowledgement_required`, `incompatible`, and `entitlement_required`; include the exact dependency composer name in evidence, not in an unstructured exception message.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/marketplace/tests/Feature/Actions/InstallMarketplaceExtensionActionTest.php packages/marketplace/tests/Feature/Actions/MarketplaceInstallMaturityPolicyTest.php`

  ```bash
  git add packages/marketplace/src/Actions packages/marketplace/tests/Feature/Actions
  git commit -m "fix(marketplace): enforce fresh install policy centrally"
  ```

### Task 4: Route every install entry point through the typed boundary

**Files:**
- Modify: `packages/marketplace/src/Filament/Support/MarketplaceCatalogueTable.php`
- Modify: `packages/marketplace/src/Filament/Livewire/MarketplaceExtensionsBrowser.php`
- Modify: `packages/marketplace/src/Actions/CompleteMarketplaceInstallFlowAction.php`
- Modify: `packages/marketplace/src/Console/Commands/MarketplaceExtensionsLifecycleQaCommand.php`
- Modify: `packages/marketplace/tests/Feature/Filament/MarketplaceCatalogueTableTest.php`
- Modify: `packages/marketplace/tests/Feature/Filament/MarketplaceExtensionsBrowserTest.php`
- Modify: `packages/marketplace/tests/Feature/Actions/MarketplaceInstallFlowActionTest.php`
- Modify: `packages/marketplace/tests/Feature/Console/MarketplaceExtensionsLifecycleQaCommandTest.php`

- [ ] **Step 1: Change entry-point tests first** to spy on the typed request and assert identical beta policy for local, hosted, table, CLI, and direct Action calls.

- [ ] **Step 2: Build request DTOs at each boundary** using the authenticated actor or explicit system actor. Never pass raw `$arguments`/`$data` arrays into the authoritative Action.

- [ ] **Step 3: Add confirmation fields** for beta acknowledgement where applicable and reject prompt-free CLI beta installs unless `--acknowledge-beta` is explicit.

- [ ] **Step 4: Run entry-point tests and commit**

  Run: `vendor/bin/pest packages/marketplace/tests/Feature/Filament/MarketplaceCatalogueTableTest.php packages/marketplace/tests/Feature/Filament/MarketplaceExtensionsBrowserTest.php packages/marketplace/tests/Feature/Actions/MarketplaceInstallFlowActionTest.php packages/marketplace/tests/Feature/Console/MarketplaceExtensionsLifecycleQaCommandTest.php`

  ```bash
  git add packages/marketplace/src packages/marketplace/tests
  git commit -m "refactor(marketplace): unify install entry points"
  ```

### Task 5: Show direct and transitive maturity in review UI

**Files:**
- Modify: `packages/marketplace/resources/views/components/install-review.blade.php`
- Modify: `packages/marketplace/resources/lang/en/marketplace.php`
- Modify: `packages/marketplace/src/Data/ExtensionListingData.php`
- Modify: `packages/marketplace/tests/Feature/Filament/MarketplaceExtensionDetailPageTest.php`
- Modify: `packages/marketplace/tests/Feature/Filament/MarketplaceExtensionsBrowserTest.php`

- [ ] **Step 1: Add rendering assertions** for maturity badges on selected and dependency rows, exact beta dependency name, and acknowledgement copy.

- [ ] **Step 2: Render from typed listing/dependency data**; do not recalculate policy in Blade. Add all user-facing strings to translations.

- [ ] **Step 3: Run and commit**

  Run: `vendor/bin/pest packages/marketplace/tests/Feature/Filament/MarketplaceExtensionDetailPageTest.php packages/marketplace/tests/Feature/Filament/MarketplaceExtensionsBrowserTest.php`

  ```bash
  git add packages/marketplace/resources packages/marketplace/src/Data packages/marketplace/tests/Feature/Filament
  git commit -m "feat(marketplace): expose dependency maturity at install review"
  ```

### Task 6: Resolve translation ownership before cache traversal

**Files:**
- Create: `packages/frontend/src/Contracts/Cache/TranslationCacheDependencyResolver.php`
- Create: `packages/frontend/src/Support/Cache/TranslationCacheDependencyRegistry.php`
- Create: `packages/frontend/src/Support/Cache/Resolvers/PageableTranslationCacheDependencyResolver.php`
- Create: `packages/frontend/src/Support/Cache/Resolvers/MediaTranslationCacheDependencyResolver.php`
- Create: `packages/frontend/src/Support/Cache/Resolvers/SiteTranslationCacheDependencyResolver.php`
- Modify: `packages/frontend/src/Support/Cache/CacheInvalidationRegistry.php`
- Modify: `packages/frontend/src/Providers/FrontendServiceProvider.php`
- Test: `packages/frontend/tests/Unit/Cache/TranslationOwnershipCacheInvalidationTest.php`

- [ ] **Step 1: Write failing ownership tests** for pageable translation → page, media translation → media → dependent pages, site translation → declared site dependencies, unsupported owner → conservative registered behavior, and a cyclic dependency graph.

- [ ] **Step 2: Define the resolver contract** with explicit `supports(Translation $translation): bool` and `roots(Translation $translation): iterable<Model>` methods plus a tag constant.

- [ ] **Step 3: Implement registered ownership resolvers**. Resolve `Translation::translatable` first, then hand roots to the existing graph traversal. Maintain visited identities as `model-class:model-key`; deduplicate final `CacheInvalidationRule` values before executor calls.

- [ ] **Step 4: Keep dispatch thin**: the observer sends the changed model to the registry. Do not add invalidation methods to `Translation`, `Media`, `Site`, page models, or Filament components.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/frontend/tests/Unit/Cache/TranslationOwnershipCacheInvalidationTest.php packages/frontend/tests/Unit/Cache/GraphAwareCacheInvalidationRegistryTest.php`

  ```bash
  git add packages/frontend/src packages/frontend/tests/Unit/Cache
  git commit -m "fix(frontend): follow translation ownership for cache invalidation"
  ```

### Task 7: Prove cold hydration and warm mutation behavior

**Files:**
- Create: `packages/frontend/tests/Feature/Cache/LocalizedMediaMutationInvalidationTest.php`
- Modify: `packages/frontend/tests/Feature/Cache/PageModelCacheTest.php`
- Modify: `packages/frontend/tests/Unit/Cache/CacheInvalidationExecutorTest.php`

- [ ] **Step 1: Seed two pages** where only one depends on localized media. Warm page model and public render caches for both, mutate alt text, caption, credit, and decorative intent in separate data-set cases, and assert only the dependent page keys are forgotten.

- [ ] **Step 2: Add a cold-hydration case** where relations were not loaded before mutation and prove ownership resolution still finds all dependent pages.

- [ ] **Step 3: Add idempotency assertions** by executing the same plan twice and by presenting a cyclic graph. Assert no recursion, duplicate queue work, or global frontend flush.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/frontend/tests/Feature/Cache/LocalizedMediaMutationInvalidationTest.php packages/frontend/tests/Feature/Cache/PageModelCacheTest.php packages/frontend/tests/Unit/Cache`

  ```bash
  git add packages/frontend/tests
  git commit -m "test(frontend): cover localized media cache mutation"
  ```

## Exit gate

- A direct or transitive beta install without acknowledgement is blocked and recorded.
- Fresh listing drift cannot use cached stable maturity as authorization.
- Local, hosted, table, CLI, and direct calls share the same policy Action.
- Review UI identifies every beta dependency.
- Localized media metadata changes invalidate every dependent page and leave unrelated caches warm.
- Repeated/cyclic invalidation is finite and idempotent.

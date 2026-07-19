# Post-Implementation Self-Audit — Theme Runtime Custom Tokens

## Audit Metadata

- **Audit ID**: `AUDIT-THEME-RUNTIME-2026-07-19`
- **Audit date**: 2026-07-19 (Europe/London)
- **Mode**: Single-agent owner audit
- **Initial audit base**: `4f585c270` on `main`
- **Audited worktree patch SHA-256**: `7d0952edbe5f903401b5e07172b0bc4bcda8394c47b7dc58e0e3cafaa844cd71`
- **Audited files**:
    - `packages/core/src/ThemeStudio/Actions/ResolveThemeRuntimeAction.php`
    - `packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php`
- **Requirement source**: `../capell-packages-4/docs/prompts/package-improvement-agent-prompts-2026-07-19.md`, Prompt 1, especially tasks 2 and 4
- **Evidence convention**: A checked audit task means the area was examined, not that it passed. `PASS`, `FAIL`, `GAP`, `N/A`, and `PRE-EXISTING` state the result.
- **Snapshot caveat**: `HEAD` advanced through unrelated local commits while the audit was running. The two-file patch hash stayed unchanged, but the repository-wide preflight no longer represented an immutable snapshot and was stopped with exit 130.

## Executive Summary

- [x] **AUDIT-EXEC-1 [Overall readiness]**: **Not Ready**. The intended Liquid Glass `glassDepth` path works, but the new extension-token path accepts unsafe token identifiers and reserved-name collisions.
- [x] **AUDIT-EXEC-2 [Risk distribution]**: Critical 0; High 0; Medium 4; Low 2.
- [x] **AUDIT-EXEC-3 [Most critical gap]**: A package or local theme definition can place CSS syntax in a declared custom-token key. That key is emitted unchanged into anonymous CSS, allowing arbitrary CSS rule injection.
- [x] **AUDIT-EXEC-4 [Immediate action]**: Validate custom-token identifiers in core, reserve every core-emitted `--theme-*` property, and add runtime/public-output regression tests before release.
- [x] **AUDIT-EXEC-5 [Go/No-Go]**: **NO-GO** for merging or releasing this worktree patch in its current form.

### Release Conditions

- [ ] **AUDIT-GATE-1 [Identifier boundary]**: Only camel-case custom-token keys accepted by the admin schema may enter `BrandProfileData::$customTokens`, with a defensive check again at CSS token emission.
- [ ] **AUDIT-GATE-2 [Reserved namespace]**: Custom keys that map to existing core properties such as `--theme-radius-value` must be rejected.
- [ ] **AUDIT-GATE-3 [Security regression]**: The exact malformed-key reproduction in `AUDIT-FIND-3.1` must produce no injected CSS.
- [ ] **AUDIT-GATE-4 [Public integration]**: A frontend hook or HTTP feature test must render a valid package-specific token and prove malformed keys, undeclared keys, out-of-vocabulary values, authoring metadata, and unsafe CSS are absent.
- [ ] **AUDIT-GATE-5 [Clean verification]**: Focused tests, targeted PHPStan/Pint/Rector, the cross-package Foundation/Liquid Glass contracts, coverage, and `composer preflight` must pass from one fixed clean snapshot.
- [ ] **AUDIT-GATE-6 [CI verification]**: The committed SHA must pass the PHP 8.4 / Laravel 12 and Laravel 13 CI matrices. No CI run can cover the current uncommitted patch.

## Core Task Checklist

- [x] **AUDIT-CORE-1 [Audit]**: Change scope and requirements mapped below; result `FAIL` because the CSS identifier boundary is incomplete.
- [x] **AUDIT-CORE-2 [Validate]**: Unit, runtime, frontend-hook, admin-schema, cross-package, coverage, formatter, refactor, static-analysis, and dependency-audit evidence collected.
- [x] **AUDIT-CORE-3 [Probe]**: Malformed keys, reserved-name collisions, invalid values, empty/unknown tokens, write failure, concurrency, and inheritance risks examined.
- [x] **AUDIT-CORE-4 [Assess security/privacy]**: CSS injection confirmed; SQL, command, path, authentication, session, token, PII, secret, and encryption changes are `N/A` for this diff.
- [x] **AUDIT-CORE-5 [Measure]**: A 50,000-iteration microbenchmark measured a 12.703 microsecond per-resolution delta; full load testing was not performed.
- [x] **AUDIT-CORE-6 [Evaluate operations]**: Deployment, rollback, observability, CI, environment parity, and monitoring reviewed; clean full-gate and current CI evidence remain open.
- [x] **AUDIT-CORE-7 [Verify documentation]**: Existing frontend theme docs already describe package-specific keys, but no ticket, changelog entry, stakeholder notice, or support handoff was found for this slice.
- [x] **AUDIT-CORE-8 [Synthesize]**: Six prioritized findings, six remediation tasks, a proposed patch, and a No-Go recommendation are recorded.

## 1. Scope and Requirements Analysis

### Change Description

The source change replaces direct `BrandProfileData::merge()` calls at the end of layered theme resolution with `ResolveBrandProfileAction`. Preset defaults and per-theme overrides are still collected parent-first and child-last, but they now also flow through the existing declared custom-token allowlist. The added test proves that a declared `glassDepth=prismatic` override reaches generated CSS and that one undeclared unsafe value does not.

### Requirement Mapping

| Requirement ID | Requirement                                                           | Implementation                                                                                                                                                     | Test evidence                                                  | Assessment                                                        |
| -------------- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------- | ----------------------------------------------------------------- |
| `AUDIT-REQ-1`  | Emit active-theme package-specific `--theme-*` values on public pages | `ResolveThemeRuntimeAction.php:116-123` delegates layered values to `ResolveBrandProfileAction`; `BrandProfileData.php:110-112` maps custom keys to CSS properties | `ThemeTokenRendererTest.php:151-204`                           | **PARTIAL** — valid key works; identifier boundary fails          |
| `AUDIT-REQ-2`  | Accept only declared values from a closed vocabulary                  | `ResolveBrandProfileAction.php:49-84` checks declaration, string value, and strict option membership                                                               | `ResolveBrandProfileActionTest.php:10-67`; focused Pest passes | **PASS** for values                                               |
| `AUDIT-REQ-3`  | Preserve preset/override precedence                                   | Existing parent-to-child default and override folds remain at `ResolveThemeRuntimeAction.php:94-113`; combined values at line 121 retain override precedence       | Valid override beats preset in the added runtime test          | **PASS**, with inheritance-chain test gap                         |
| `AUDIT-REQ-4`  | Emitted CSS must be safe                                              | `ThemeTokenValidator.php:39-56` validates values                                                                                                                   | Unsafe undeclared and invalid declared values are covered      | **FAIL** — property names are not validated                       |
| `AUDIT-REQ-5`  | Public output contains no authoring metadata                          | Only CSS custom properties are added; frontend hook remains the existing anonymous-safe path                                                                       | No route-level custom-extra test                               | **PARTIAL** — static inspection passes; public HTTP proof missing |
| `AUDIT-REQ-6`  | Keep Blade logic-free and reuse Actions/Data boundaries               | Resolution remains in Actions/Data; no Blade changes                                                                                                               | PHPStan/Pint/Rector targeted checks pass                       | **PASS**                                                          |

### Scope Boundaries

- In scope: layered runtime resolution, declared custom-token admission, generated token CSS, the frontend head hook as the immediate consumer, and affected test/CI/release contracts.
- Not changed: settings persistence, Filament forms, theme definitions, package manifests, routes, controllers, policies, authentication, database schema, queues, external clients, HTML cache invalidation, or deployment infrastructure.
- Potentially affected: every registered package or local-app theme that declares `frontend.editor.tokens`, generated theme-token file caching, anonymous head output, theme inheritance, and companion theme contract tests.
- New dependencies: none. The patch only reuses existing core classes `ResolveBrandProfileAction` and `ThemeOverrideData`.
- Rollback scope: revert the two-file implementation patch and deploy the previous core build. No database rollback is required. Purge/warm public HTML caches after rollback because cached HTML may contain the inline token style. Content-addressed token files can remain; they become unreachable.
- Known limitation: only Liquid Glass currently declares a marketed extra token (`glassDepth`), but this change exposes a general extension surface, so the neutral core boundary must be safe for future third-party declarations.

### Detailed Scope Task Ledger

- [x] **AUDIT-SCOPE-1.1 [Change Description]**: `PASS` — summarized above with exact files and lines.
- [x] **AUDIT-SCOPE-1.2 [Requirement Mapping]**: `PARTIAL` — mapped to the sibling Prompt 1; no ticket/issue identifier was present.
- [x] **AUDIT-SCOPE-1.3 [Scope Boundaries]**: `PASS` — changed and potentially affected surfaces identified.
- [x] **AUDIT-SCOPE-1.4 [Risk Areas]**: `FAIL` — anonymous CSS emission is the highest-risk modified boundary.
- [x] **AUDIT-SCOPE-1.5 [Dependencies]**: `PASS` — two existing core classes reused; no package dependency change.
- [x] **AUDIT-SCOPE-1.6 [Rollback Scope]**: `PASS` — source-only rollback plus public HTML cache purge documented.
- [x] **AUDIT-SCOPE-1.7 [Implementation Coverage]**: `PARTIAL` — intended valid extra works; unsafe identifiers and collisions are incomplete.
- [x] **AUDIT-SCOPE-1.8 [Missing Features]**: `GAP` — core-level identifier validation and reserved-property enforcement are missing.
- [x] **AUDIT-SCOPE-1.9 [Known Limitations]**: `PASS` — no browser/HTTP proof, no clean full gate, and only one real extra-token consumer documented.
- [x] **AUDIT-SCOPE-1.10 [Partial Implementation]**: `FAIL` — value allowlisting exists, but name allowlisting does not.
- [x] **AUDIT-SCOPE-1.11 [Technical Debt]**: `PRE-EXISTING` — non-atomic generated CSS writes and silent invalid-token drops remain.
- [x] **AUDIT-SCOPE-1.12 [Documentation Updates]**: `PARTIAL` — existing theme docs are compatible; changelog/release note absent.
- [x] **AUDIT-SCOPE-1.13 [Feature Traceability]**: `PARTIAL` — prompt-to-code mapping exists, but no repository-local ticket or commit exists.
- [x] **AUDIT-SCOPE-1.14 [Acceptance Criteria]**: `FAIL` — public CSS safety criterion is not met.
- [x] **AUDIT-SCOPE-1.15 [Compliance Requirements]**: `PARTIAL` — public-output safety fails for CSS integrity; no PII/regulatory change identified.

## 2. Test Evidence Collection

### Evidence Summary

| Evidence ID     | Timestamp               | Command / inspection                                                                          | Result                                                                                                                                            |
| --------------- | ----------------------- | --------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AUDIT-EVID-1`  | 17:07:58 BST            | `git diff --check` then focused core Pest files                                               | `PASS`; 10 tests, 27 assertions, 1.535 s                                                                                                          |
| `AUDIT-EVID-2`  | 17:08:35 BST            | Frontend hook plus admin schema Pest files                                                    | `PASS`; 8 tests, 25 assertions, 2.263 s                                                                                                           |
| `AUDIT-EVID-3`  | 17:10:05 BST            | Malformed declared-key PHP reproduction                                                       | `FAIL`; emitted `--theme-x; } body { display:none; } /*: safe;`                                                                                   |
| `AUDIT-EVID-4`  | 17:11:56 BST            | Reserved `radiusValue` PHP reproduction                                                       | `FAIL`; emitted `--theme-radius-value: 999px;`                                                                                                    |
| `AUDIT-EVID-5`  | 17:12:06 BST            | Focused PCOV run at default 128 MB                                                            | `BLOCKED`; fatal memory exhaustion before metrics, exit 255                                                                                       |
| `AUDIT-EVID-6`  | 17:12:28 BST            | Same PCOV run with CI memory settings                                                         | `PASS`; 10 tests, 27 assertions; `ResolveBrandProfileAction` 81.8%, `ResolveThemeRuntimeAction` 79.2%; focused total 2.1% is not a release metric |
| `AUDIT-EVID-7`  | 17:12:53 BST            | 50,000-iteration resolver microbenchmark                                                      | old merge 7.230 us; resolved custom path 19.933 us; delta 12.703 us; 14.22 MiB peak                                                               |
| `AUDIT-EVID-8`  | 17:19:00 BST            | Foundation schema/runtime/token plus Liquid Glass definition tests in linked sibling checkout | `PASS`; 15 tests, 610 assertions, 85.957 s; sibling had one unrelated dirty test file                                                             |
| `AUDIT-EVID-9`  | approximately 17:23 BST | Exact-file Pint, Rector dry-run, and PHPStan                                                  | `PASS`; 0 formatter issues, 0 Rector changes, 0 PHPStan errors                                                                                    |
| `AUDIT-EVID-10` | 17:24:10 BST            | `composer audit --locked --no-interaction`                                                    | `PASS`; no known locked-dependency advisories                                                                                                     |
| `AUDIT-EVID-11` | 17:24:49 BST            | Environment/lock inspection                                                                   | local PHP 8.5.5, Laravel 13.19.0, Testbench 11.1.0, Pest 4.7.0                                                                                    |
| `AUDIT-EVID-12` | audit run               | `composer preflight`                                                                          | `ABORTED`, exit 130; shared `HEAD` and unrelated Media files changed during PHPStan, invalidating snapshot attribution                            |
| `AUDIT-EVID-13` | 17:31:20 BST            | Final patch-hash, focused Pest, Markdown format, and task-ledger verification                 | `PASS`; patch hash unchanged; 10 tests, 27 assertions; 176 unique task definitions                                                                |

### Coverage Assessment

- The modified delegation lines execute in the passing runtime test.
- Focused coverage reports `ResolveBrandProfileAction` at 81.8% and `ResolveThemeRuntimeAction` at 79.2%.
- The repository release workflow requires 90% overall coverage, but no clean full coverage run exists for the uncommitted patch.
- Uncovered or unproven paths relevant to this change include malformed token names, reserved-property collisions, inherited custom-token declarations, malformed declaration shapes, public HTTP output, first-write concurrency, and real PHP 8.4 / Laravel 12 behavior.
- The added runtime test's title says it rejects values outside the closed vocabulary, but its unsafe payload is attached to an undeclared token. The direct `ResolveBrandProfileActionTest` covers an invalid declared value; the runtime/public-output level does not.

### Test Task Ledger

- [x] **AUDIT-TEST-2.1 [Commands Executed]**: `PASS` — exact commands and timestamps recorded under `Commands` and `AUDIT-EVID-*`.
- [x] **AUDIT-TEST-2.2 [Test Results]**: `PARTIAL` — all completed focused tests pass; clean full suite unavailable.
- [x] **AUDIT-TEST-2.3 [Test Logs]**: `PASS` — decisive counts, durations, exit codes, failures, and reproduction output retained.
- [x] **AUDIT-TEST-2.4 [Coverage Reports]**: `GAP` — focused metrics exist; 90% release threshold not proven.
- [x] **AUDIT-TEST-2.5 [Unit Tests]**: `PASS` — core, admin, frontend, Foundation, and Liquid Glass focused unit suites pass.
- [x] **AUDIT-TEST-2.6 [Integration Tests]**: `PARTIAL` — registry plus real filesystem token store exercised in a Unit-labelled test; no full integration suite.
- [x] **AUDIT-TEST-2.7 [End-to-End Tests]**: `GAP` — no browser or full HTTP route exercised with a custom extra token.
- [x] **AUDIT-TEST-2.8 [API Tests]**: `N/A` — no API contract or endpoint changed.
- [x] **AUDIT-TEST-2.9 [Contract Tests]**: `PASS/PARTIAL` — 610 cross-package assertions pass, but the sibling checkout was not clean.
- [x] **AUDIT-TEST-2.10 [Uncovered Code]**: `GAP` — identifier, collision, inheritance, malformed shape, and concurrency paths listed above.
- [x] **AUDIT-TEST-2.11 [Error Paths]**: `PARTIAL` — invalid values and unwritable store covered; malformed names are not safely handled.
- [x] **AUDIT-TEST-2.12 [Skipped Tests]**: `PASS` — no skips reported by completed commands.
- [x] **AUDIT-TEST-2.13 [Failed Tests]**: `PASS` — no Pest assertion failures; one coverage process failed for memory and was successfully rerun with CI settings.
- [x] **AUDIT-TEST-2.14 [Flaky Tests]**: `GAP` — no rerun-based flake study; no flake observed in completed commands.
- [x] **AUDIT-TEST-2.15 [Environment Parity]**: `FAIL` — local PHP differs from CI and the advertised CI MySQL matrix is forced back to SQLite by `phpunit.xml`.
- [x] **AUDIT-TEST-2.16 [UI Tests]**: `GAP` — no rendered browser flow or screenshot proves the package-specific token on a real page.
- [x] **AUDIT-TEST-2.17 [External Service Mocking]**: `N/A` — the changed path has no external HTTP, queue, mail, or third-party service dependency.

## 3. Detailed Findings — Risk and Security

- [ ] **AUDIT-FIND-3.1 [Declared custom-token names can inject arbitrary public CSS]**:
    - **Evidence**: `ResolveBrandProfileAction.php:57-84` accepts any string declaration key; `BrandProfileData.php:116-120` only camel-to-kebab transforms it; `ThemeTokenValidator.php:14-18` validates values but not names; `ThemeTokenRenderer.php:16-18` concatenates the name into CSS. `AUDIT-EVID-3` emitted:

        ```css
        --theme-x; } body { display:none; } /*: safe;
        ```

    - **Impact**: A malformed registered or local-app theme definition can inject arbitrary CSS rules into anonymous pages. This violates the deterministic public-output contract and can hide content or alter calls to action. The declaration source is developer/package-controlled, so this is not an authentication bypass or remote-code-execution path.
    - **Severity**: Medium
    - **Probability**: Low because the declaration comes from installed package/local theme metadata; the impact begins as soon as a malformed definition uses this new generic extension path.
    - **Recommendation**: Enforce the same camel-case key grammar used by the admin schema in neutral core code and defensively filter again when mapping custom tokens to CSS properties.
    - **Fallback**: Revert this runtime delegation and leave extras un-emitted until the neutral validation contract is available.
    - **Status**: Open — release blocker
    - **Owner**: Capell Core / Theme Studio
    - **Verification**: Run the exact reproduction after the patch and assert no injected selector, brace, comment, semicolon, or unsafe property is present in `BrandProfileData::tokens()`, renderer CSS, frontend hook HTML, or an HTTP response.
    - **Timeline**: Immediate; estimated 2–4 hours including tests.

- [ ] **AUDIT-FIND-3.2 [Custom keys can overwrite reserved core theme properties]**:
    - **Evidence**: Custom tokens are spread after core tokens at `BrandProfileData.php:87-112`. `radiusValue` is not a supported input token, so the resolver admits it, maps it to the existing `--theme-radius-value`, and overwrites the derived radius. `AUDIT-EVID-4` produced `--theme-radius-value: 999px;` while the base radius was `md`.
    - **Impact**: Theme extras can bypass controlled core vocabularies and derived safety behavior, producing inconsistent output and breaking the stable theme-token contract.
    - **Severity**: Medium
    - **Recommendation**: Reserve every property name returned by the base `BrandProfileData::tokens()` map; reject any custom key whose normalized property collides.
    - **Fallback**: Prefix package-specific extras with a separate package namespace, but that is a broader public contract change and is not preferred for this fix.
    - **Status**: Open — release blocker
    - **Owner**: Capell Core / Theme Studio
    - **Verification**: Assert `radiusValue`, `headingScaleRatio`, `cardDensityGap`, `overlayOpacity`, `primary`, and all other normalized collisions are dropped while `glassDepth` remains.
    - **Timeline**: Immediate; included in the 2–4 hour boundary fix.

- [ ] **AUDIT-FIND-3.3 [Runtime/public security regression coverage is incomplete]**:
    - **Evidence**: `ThemeTokenRendererTest.php:151-204` proves one valid extra and one undeclared unsafe value. It does not exercise an unsafe declared name, a reserved collision, a declared out-of-vocabulary value, theme inheritance, or the actual frontend head hook/HTTP response. Focused action coverage is 81.8%/79.2%, below the repository's 90% release target when considered as an overall gate.
    - **Impact**: The two confirmed defects passed every existing focused test, so the current suite cannot prevent regression at the new trust boundary.
    - **Severity**: Medium
    - **Recommendation**: Add direct core boundary datasets plus a real `ThemeTokenHeadCloseHook` or route-backed test with the actual `ThemeTokenStore`.
    - **Status**: Open — release blocker with `AUDIT-FIND-3.1` and `AUDIT-FIND-3.2`
    - **Owner**: Capell Core and Frontend
    - **Verification**: New tests fail against the current patch, pass after remediation, and the focused PCOV report covers every new guard branch.
    - **Timeline**: Immediate; estimated 2–4 hours.

- [ ] **AUDIT-FIND-3.4 [The advertised MySQL full-test matrix is forced to SQLite]**:
    - **Evidence**: `.github/workflows/test-full.yml:94-101` writes MySQL settings, but `phpunit.xml:36-38` declares `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and blank `DB_URL` with `force="true"`. PHPUnit's `PhpHandler.php:140-141` overwrites an existing value when force is true.
    - **Impact**: CI can report a `DBmysql` job while actually using SQLite, weakening production parity and confidence in database-sensitive changes.
    - **Severity**: Medium
    - **Recommendation**: Remove `force="true"` from the three DB variables and inject the matrix database as process environment variables, then add an assertion/log line that reports the active driver.
    - **Status**: Open — short-term platform fix; not a blocker for this pure in-memory/filesystem slice once its own blockers are resolved.
    - **Owner**: Capell CI / Core maintainers
    - **Verification**: A MySQL matrix test asserts `DB::connection()->getDriverName() === 'mysql'`; SQLite jobs assert `sqlite`.
    - **Timeline**: Short term; estimated 1–2 hours plus one CI cycle.

- [ ] **AUDIT-FIND-3.5 [First-write token CSS publication is non-atomic]**:
    - **Evidence**: `ThemeTokenStore.php:18-40` checks existence, renders, then uses `File::put()` without a lock or temporary-file rename. A concurrent request can observe the path while the first write is incomplete. The generated content is deterministic, so competing writers should eventually converge.
    - **Impact**: Rare first-request partial or empty inline token CSS under concurrency; visual degradation rather than data corruption.
    - **Severity**: Low
    - **Recommendation**: Write to a unique temporary file in the same directory and atomically rename, tolerating a competing winner.
    - **Status**: Open — pre-existing, long-term improvement
    - **Owner**: Capell Core / Theme Studio
    - **Verification**: A multi-process test races identical writes and repeatedly reads only complete expected CSS.
    - **Timeline**: Long term; estimated 0.5–1 day.

- [ ] **AUDIT-FIND-3.6 [Release provenance and communication evidence are incomplete]**:
    - **Evidence**: The patch is uncommitted, has no repository-local ticket/issue reference, no changelog entry, no CI run, and no stakeholder/support notification record. The sibling prompt is the only discovered requirements source. The shared `HEAD` moved during audit.
    - **Impact**: Reviewers and support cannot link the eventual release artifact to a stable implementation snapshot or clearly identify the user-visible theme-token correction.
    - **Severity**: Low
    - **Recommendation**: Commit the implementation and tests as one focused change with the requirement reference, add a concise changelog/release note, run CI on that SHA, and include support/customer impact in release communication.
    - **Status**: Open — release-process condition
    - **Owner**: Release owner
    - **Verification**: Commit SHA, CI URLs, changelog entry, and notification/handoff reference are recorded in the release ticket.
    - **Timeline**: Before release; estimated under 1 hour excluding CI.

### Edge Case and Negative Test Ledger

- [x] **AUDIT-EDGE-3.1 [Input Boundaries]**: `PARTIAL` — closed sets constrain values; declaration count/size is unbounded but package-controlled.
- [x] **AUDIT-EDGE-3.2 [Empty Inputs]**: `PASS` by inspection — empty declarations/overrides return base tokens.
- [x] **AUDIT-EDGE-3.3 [Null Handling]**: `PASS` by inspection — null/non-string custom values are skipped; no new nullable API.
- [x] **AUDIT-EDGE-3.4 [Overflow/Underflow]**: `N/A` — no numeric arithmetic in the changed path.
- [x] **AUDIT-EDGE-3.5 [Malformed Data]**: `FAIL` — malformed token identifiers reach CSS.
- [x] **AUDIT-EDGE-3.6 [Type Mismatches]**: `PARTIAL` — declaration/value shapes are skipped; malformed inner `themeOverrides` arrays remain a pre-existing runtime risk.
- [x] **AUDIT-EDGE-3.7 [Missing Fields]**: `PASS` by inspection — missing editor/tokens/options/value data is ignored safely.
- [x] **AUDIT-EDGE-3.8 [Encoding Issues]**: `FAIL` — arbitrary key bytes/newlines/punctuation are not constrained in core.
- [x] **AUDIT-EDGE-3.9 [Concurrent Access]**: `GAP` — no concurrent test; non-atomic first write identified.
- [x] **AUDIT-EDGE-3.10 [Race Conditions]**: `PRE-EXISTING` — deterministic write race described in `AUDIT-FIND-3.5`.
- [x] **AUDIT-EDGE-3.11 [Deadlock Scenarios]**: `N/A` — no locks, database transaction, or cross-process wait introduced.
- [x] **AUDIT-EDGE-3.12 [Exception Handling]**: `PASS/PARTIAL` — token-store `Throwable` is reported and rendering continues; malformed identifiers do not throw and instead emit.
- [x] **AUDIT-EDGE-3.13 [Retry Logic]**: `N/A` — no remote dependency; subsequent requests naturally retry a missing token file.
- [x] **AUDIT-EDGE-3.14 [Partial Updates]**: `N/A` for data; generated-file partial publication is covered by `AUDIT-FIND-3.5`.
- [x] **AUDIT-EDGE-3.15 [Data Corruption]**: `PASS` for persisted data — no writes; CSS artifact integrity has a low race risk.
- [x] **AUDIT-EDGE-3.16 [Transaction Safety]**: `N/A` — no database transaction or multi-record write.

### Security and Privacy Ledger

- [x] **AUDIT-SEC-4.1 [Auth Checks]**: `N/A` — no endpoint or authorization path changed.
- [x] **AUDIT-SEC-4.2 [Permission Changes]**: `N/A` — no permission changes.
- [x] **AUDIT-SEC-4.3 [Session Management]**: `N/A` — no session use.
- [x] **AUDIT-SEC-4.4 [Token Handling]**: `N/A` for auth tokens; theme design tokens are assessed separately.
- [x] **AUDIT-SEC-4.5 [Privilege Escalation]**: `N/A` — no privilege boundary changed; installed/local theme metadata is the trust source.
- [x] **AUDIT-SEC-4.6 [Injection Risks]**: `FAIL` — arbitrary CSS injection confirmed; no SQL or command construction exists in the changed path.
- [x] **AUDIT-SEC-4.7 [Input Sanitization]**: `FAIL/PARTIAL` — values sanitized; names are not.
- [x] **AUDIT-SEC-4.8 [Path Traversal]**: `PASS` by inspection — filenames use a SHA-256-derived asset key, not raw theme/token strings.
- [x] **AUDIT-SEC-4.9 [Sensitive Data Handling]**: `PASS` — only public presentation values are emitted; no sensitive data added.
- [x] **AUDIT-SEC-4.10 [Logging Security]**: `PASS` for this diff — no new logging; existing write exception reporting unchanged.
- [x] **AUDIT-SEC-4.11 [Encryption Validation]**: `N/A` — no protected data at rest or in transit.
- [x] **AUDIT-SEC-4.12 [PII Handling]**: `N/A` — no PII processed.
- [x] **AUDIT-SEC-4.13 [Secret Management]**: `N/A` — no secret/config credential change.
- [x] **AUDIT-SEC-4.14 [Config Changes]**: `PASS` — no application configuration diff; CI DB-force issue is pre-existing.
- [x] **AUDIT-SEC-4.15 [Debug Information]**: `PASS` — no debug output introduced; malformed key risk affects CSS only.

## 4. Performance and Reliability Assessment

### Quantitative Result

The CLI microbenchmark used one standard token and one custom token for 50,000 warmed iterations:

```text
iterations=50000 old_merge_us=7.230 resolved_custom_us=19.933
delta_us=12.703 ratio=2.76 peak_mib=14.22
```

The relative multiplier is visible because the old operation is tiny. The absolute 12.703 microsecond delta is approximately 0.064% of the 20 ms frontend render budget declared by Liquid Glass. This is a synthetic resolver-only measurement, not an HTTP p95 or load test.

### Performance and Reliability Ledger

- [x] **AUDIT-PERF-5.1 [Response Time]**: `PASS/PARTIAL` — 12.703 us measured delta; no HTTP p95 measurement.
- [x] **AUDIT-PERF-5.2 [Throughput]**: `GAP` — no request-level throughput test.
- [x] **AUDIT-PERF-5.3 [Resource Usage]**: `PASS/PARTIAL` — 14.22 MiB CLI peak for benchmark process; no production profile.
- [x] **AUDIT-PERF-5.4 [Database Performance]**: `N/A` — no queries or persistence.
- [x] **AUDIT-PERF-5.5 [Cache Efficiency]**: `PASS` by inspection — deterministic asset keys retain file reuse; extra closed-set values increase finite cache cardinality.
- [x] **AUDIT-PERF-5.6 [Load Testing]**: `GAP` — not run.
- [x] **AUDIT-PERF-5.7 [Resource Limits]**: `PARTIAL` — metadata array sizes are unbounded but package-controlled; coverage required CI memory settings.
- [x] **AUDIT-PERF-5.8 [Bottleneck Identification]**: `PASS` — no material CPU bottleneck observed; first-write filesystem I/O remains dominant.
- [x] **AUDIT-PERF-5.9 [Timeout Handling]**: `N/A` — no remote call/process introduced.
- [x] **AUDIT-PERF-5.10 [Circuit Breakers]**: `N/A` — no external service.
- [x] **AUDIT-PERF-5.11 [Graceful Degradation]**: `PASS` — token-store write failures return runtime data without a stylesheet; existing test passes.
- [x] **AUDIT-PERF-5.12 [Failure Isolation]**: `PARTIAL` — file-write failure isolated, but malformed token names contaminate the full CSS block.
- [x] **AUDIT-PERF-5.13 [Partial Outages]**: `N/A` — no external dependency; storage unavailability follows existing fallback.
- [x] **AUDIT-PERF-5.14 [Dependency Failures]**: `PASS/PARTIAL` — local store exception covered; no Composer dependency change.
- [x] **AUDIT-PERF-5.15 [Cascading Failures]**: `LOW RISK` — malformed CSS affects presentation, not server availability; cached HTML can extend impact until purged.

## 5. Operational Readiness Review

### Deployment and Rollback

- Deploy as an ordinary core package code release after the blocker fix; there are no migrations, config changes, background jobs, or deployment ordering constraints.
- Canary one non-critical site using Liquid Glass and one standard-schema theme before broad rollout.
- Verify the generated/inline CSS, public HTML safety, and response/error telemetry before widening.
- Rollback by restoring the previous core release and purging/warming public HTML caches. No database rollback or token-file deletion is required.
- No feature flag or kill switch exists. A source rollback is adequate for this small boundary once a release artifact is available; before that, reverting the delegation is the safe fallback.

### Operational Readiness Ledger

- [x] **AUDIT-OPS-6.1 [Logging]**: `PARTIAL` — existing token-store exceptions are reported; invalid declarations are silently dropped or currently emitted.
- [x] **AUDIT-OPS-6.2 [Metrics]**: `GAP/N/A` — no operation-specific metric; standard HTTP latency/error monitoring should cover rollout.
- [x] **AUDIT-OPS-6.3 [Tracing]**: `N/A` — no cross-service operation.
- [x] **AUDIT-OPS-6.4 [Health Checks]**: `PARTIAL` — standard application health applies; theme validation does not currently prove custom-token identifier safety.
- [x] **AUDIT-OPS-6.5 [Alert Rules]**: `GAP` — no evidence of release-specific alert configuration.
- [x] **AUDIT-OPS-6.6 [Dashboards]**: `GAP` — no dashboard evidence supplied; use standard HTTP/storage error dashboards.
- [x] **AUDIT-OPS-6.7 [Runbook Updates]**: `GAP` — no runbook update found; rollback steps are documented in this audit.
- [x] **AUDIT-OPS-6.8 [Escalation Procedures]**: `GAP` — no release ticket/support handoff evidence.
- [x] **AUDIT-OPS-6.9 [Deployment Strategy]**: `PARTIAL` — code-only canary and rollback defined; not executed.
- [x] **AUDIT-OPS-6.10 [Database Migrations]**: `N/A` — none.
- [x] **AUDIT-OPS-6.11 [Feature Flags]**: `N/A/PARTIAL` — none; source rollback is the contingency.
- [x] **AUDIT-OPS-6.12 [Rollback Plan]**: `PASS` as a plan; untested in staging.
- [x] **AUDIT-OPS-6.13 [Alert Thresholds]**: `GAP` — use existing 5xx/latency/storage thresholds; exact values not evidenced.
- [x] **AUDIT-OPS-6.14 [Escalation Paths]**: `GAP` — release owner and support contact must be recorded externally.

### CI/CD, Artifact, and Environment Review

- Pull requests touching these PHP paths trigger the sharded fast-test workflow for Laravel 12 and 13 on PHP 8.4.
- Pushes to `main` trigger the full workflow, nominally Laravel 12/13 and MySQL, but `AUDIT-FIND-3.4` prevents genuine MySQL parity.
- The release coverage workflow uses PCOV and enforces 90% overall coverage on PHP 8.4 / Laravel 13 / SQLite.
- Public release smoke runs `composer preflight:all`, which includes dependency audit and documentation contracts.
- The current uncommitted patch has no reproducible build artifact, version, CI run, canary, staging rollback exercise, or production evidence.

### CI/CD and Deployment Infrastructure Ledger

- [x] **AUDIT-CI-8.1 [Pipeline Stages]**: `PASS/PARTIAL` — build/test, quality, dependency-audit, coverage, and release-smoke stages exist; none has run for the uncommitted patch.
- [x] **AUDIT-CI-8.2 [Promotion Gates]**: `PARTIAL` — 90% release coverage and zero-failure commands are configured; clean current evidence is absent.
- [x] **AUDIT-CI-8.3 [Artifact Versioning]**: `GAP` — no commit, tag, split-package version, or reproducible artifact exists for this slice.
- [x] **AUDIT-CI-8.4 [Configuration Injection]**: `FAIL` — the MySQL matrix values are overridden by forced PHPUnit SQLite variables.
- [x] **AUDIT-CI-8.5 [Pipeline Logs and Warnings]**: `GAP` — no CI log exists; local focused logs and the aborted preflight are recorded.
- [x] **AUDIT-OBS-8.6 [Latency/Error/Throughput/Saturation Metrics]**: `PARTIAL` — standard application telemetry is expected but no dashboard or emitted metric definition was supplied.
- [x] **AUDIT-OBS-8.7 [Structured Logs and Correlation]**: `N/A` for the pure resolver; existing reported file-write exceptions were not changed.
- [x] **AUDIT-OBS-8.8 [Distributed Spans]**: `N/A` — no service/database boundary is introduced.
- [x] **AUDIT-OBS-8.9 [Dashboard and Alert Definitions]**: `GAP` — no configuration evidence; release plan names the signals to monitor.
- [x] **AUDIT-DEPLOY-8.10 [Blue-Green/Canary]**: `PARTIAL` — canary is recommended; no environment execution evidence.
- [x] **AUDIT-DEPLOY-8.11 [Migration Rollback]**: `N/A` — no database migration.
- [x] **AUDIT-DEPLOY-8.12 [Feature Flag/Kill Switch]**: `N/A/PARTIAL` — none exists; source rollback is the defined fallback.
- [x] **AUDIT-DEPLOY-8.13 [Load Balancer/Routing Compatibility]**: `N/A` — no route, listener, or network topology change.
- [x] **AUDIT-DEPLOY-8.14 [Staging Rollback Exercise]**: `GAP` — plan exists but has not been executed end to end.

## 6. Documentation and Communication

### Documentation Assessment

`docs/frontend/themes.md` already states that package-specific keys may exist and that public theme output must be safe, so the intended behavior does not require a new architecture concept. A changelog/release note is still warranted because custom extras change from fallback-only to emitted public CSS. No API docs, migration guide, or deprecation notice is needed.

### Documentation and Communication Ledger

- [x] **AUDIT-DOC-7.1 [README Updates]**: `N/A` — no README contract changed.
- [x] **AUDIT-DOC-7.2 [API Documentation]**: `N/A` — no API surface changed.
- [x] **AUDIT-DOC-7.3 [Architecture Docs]**: `PASS` — existing frontend themes documentation covers the runtime path and package-specific keys.
- [x] **AUDIT-DOC-7.4 [Change Logs]**: `GAP` — no entry for the user-visible custom-extra fix.
- [x] **AUDIT-DOC-7.5 [Migration Guides]**: `N/A` — no migration or consumer action required for safe keys.
- [x] **AUDIT-DOC-7.6 [Deprecation Notices]**: `N/A` — no deprecation.
- [x] **AUDIT-DOC-7.7 [User-Facing Changes]**: `GAP` — themes with extras can visibly change; release note absent.
- [x] **AUDIT-DOC-7.8 [Breaking Changes]**: `PARTIAL` — intended behavior is additive, but rejecting previously accepted malformed/reserved custom keys should be documented as hardening.
- [x] **AUDIT-DOC-7.9 [Known Issues]**: `PASS` in this audit — six findings listed; not yet in a release tracker.
- [x] **AUDIT-DOC-7.10 [Impact Teams]**: Theme package owners, Core/Frontend maintainers, release owner, and support are affected.
- [x] **AUDIT-DOC-7.11 [Notification Status]**: `GAP` — no notification evidence.
- [x] **AUDIT-DOC-7.12 [Support Handoff]**: `GAP` — no handoff evidence.

## 7. Remediation Recommendations

- [ ] **AUDIT-REM-1.1 [Validate and reserve custom token names in neutral core]**:
    - **Category**: Immediate
    - **Description**: Add one canonical `BrandProfileData::supportsCustomToken()` guard that validates camel-case identifiers and rejects normalized collisions with every base token. Apply it in both resolver admission and final token mapping.
    - **Dependencies**: None.
    - **Validation Steps**: Unsafe-name and reserved-name datasets; exact reproductions; PHPStan/Pint/Rector; focused coverage.
    - **Fallback**: Revert custom-extra delegation.
    - **Effort / Complexity**: 2–4 hours / Simple.
    - **Release Impact**: Blocks release.

- [ ] **AUDIT-REM-1.2 [Add runtime and public-output security regression tests]**:
    - **Category**: Immediate
    - **Description**: Cover valid, undeclared, invalid declared, malformed-name, reserved-name, inherited, and direct `BrandProfileData` custom tokens through the renderer and frontend hook/HTTP output.
    - **Dependencies**: `AUDIT-REM-1.1` for green state.
    - **Validation Steps**: Prove new tests fail on current code, pass with remediation, then run focused PCOV.
    - **Effort / Complexity**: 2–4 hours / Simple-to-moderate.
    - **Release Impact**: Blocks release.

- [ ] **AUDIT-REM-2.1 [Run immutable release verification]**:
    - **Category**: Immediate
    - **Description**: Commit the fixed slice, ensure the primary checkout is stable, and run focused tests, cross-package contracts, 90% release coverage, documentation contracts, and `composer preflight` on the same SHA.
    - **Dependencies**: `AUDIT-REM-1.1`, `AUDIT-REM-1.2`, stable checkout.
    - **Validation Steps**: Record complete command output, commit SHA, and CI URLs.
    - **Effort / Complexity**: 1–3 hours plus CI / Simple.
    - **Release Impact**: Blocks release.

- [ ] **AUDIT-REM-2.2 [Restore genuine CI database parity]**:
    - **Category**: Short-term
    - **Description**: Stop forcing SQLite in MySQL jobs, export matrix DB values as process environment variables, and assert the active driver.
    - **Dependencies**: CI workflow access and one MySQL service run.
    - **Validation Steps**: Laravel 12 and 13 MySQL jobs print/assert `mysql`; SQLite jobs print/assert `sqlite`.
    - **Effort / Complexity**: 1–2 hours plus CI / Simple.
    - **Release Impact**: Advisory for this slice; blocker for claims of MySQL coverage.

- [ ] **AUDIT-REM-2.3 [Create release traceability and support handoff]**:
    - **Category**: Short-term
    - **Description**: Link requirement/ticket, focused commit, changelog entry, CI evidence, customer impact, rollback, and support owner.
    - **Dependencies**: Final commit and CI result.
    - **Validation Steps**: Release record contains all references.
    - **Effort / Complexity**: Under 1 hour / Simple.
    - **Release Impact**: Required before production promotion.

- [ ] **AUDIT-REM-3.1 [Make token CSS publication atomic]**:
    - **Category**: Long-term
    - **Description**: Same-directory temporary write plus atomic rename, with safe handling when a concurrent writer wins.
    - **Dependencies**: Cross-platform filesystem behavior review.
    - **Validation Steps**: Multi-process race test and read-integrity assertions.
    - **Effort / Complexity**: 0.5–1 day / Moderate.
    - **Release Impact**: Non-blocking advisory.

## 8. Effort and Priority Assessment

| Priority | Task            | Severity addressed           | Effort         | Complexity      | Dependencies         | Release impact |
| -------- | --------------- | ---------------------------- | -------------- | --------------- | -------------------- | -------------- |
| 1        | `AUDIT-REM-1.1` | Medium injection + collision | 2–4 hours      | Simple          | None                 | Blocker        |
| 2        | `AUDIT-REM-1.2` | Medium coverage/regression   | 2–4 hours      | Simple–moderate | Remediation 1.1      | Blocker        |
| 3        | `AUDIT-REM-2.1` | Verification uncertainty     | 1–3 hours + CI | Simple          | Remediations 1.1–1.2 | Blocker        |
| 4        | `AUDIT-REM-2.3` | Low provenance               | <1 hour        | Simple          | Final SHA/CI         | Pre-release    |
| 5        | `AUDIT-REM-2.2` | Medium parity                | 1–2 hours + CI | Simple          | CI access            | Advisory here  |
| 6        | `AUDIT-REM-3.1` | Low reliability              | 0.5–1 day      | Moderate        | Filesystem review    | Non-blocking   |

## 9. Proposed Code Changes

The preferred fix keeps validation in neutral core, protects both Action-driven and directly constructed `BrandProfileData`, and does not make core depend on admin validation code.

```diff
diff --git a/packages/core/src/ThemeStudio/Data/BrandProfileData.php b/packages/core/src/ThemeStudio/Data/BrandProfileData.php
--- a/packages/core/src/ThemeStudio/Data/BrandProfileData.php
+++ b/packages/core/src/ThemeStudio/Data/BrandProfileData.php
@@
     public static function supportsToken(string $key): bool
     {
         return in_array($key, [
             // Existing supported profile keys...
         ], true);
     }
+
+    public static function supportsCustomToken(string $key): bool
+    {
+        if (preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/', $key) !== 1) {
+            return false;
+        }
+
+        return ! array_key_exists(
+            self::customProperty($key),
+            (new self)->baseTokens(),
+        );
+    }
@@
     public function tokens(): array
     {
         return [
+            ...$this->baseTokens(),
+            ...collect($this->customTokens)
+                ->filter(
+                    fn (string $value, string $key): bool => self::supportsCustomToken($key),
+                )
+                ->mapWithKeys(
+                    fn (string $value, string $key): array => [self::customProperty($key) => $value],
+                )
+                ->all(),
+        ];
+    }
+
+    /** @return array<string, string> */
+    private function baseTokens(): array
+    {
+        return [
             '--theme-primary' => $this->primaryColor,
             // Existing core token map through --theme-overlay-opacity...
-            ...collect($this->customTokens)
-                ->mapWithKeys(fn (string $value, string $key): array => [$this->customProperty($key) => $value])
-                ->all(),
         ];
     }

-    private function customProperty(string $key): string
+    private static function customProperty(string $key): string
     {
         $kebab = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $key));

         return '--theme-' . $kebab;
diff --git a/packages/core/src/ThemeStudio/Actions/ResolveBrandProfileAction.php b/packages/core/src/ThemeStudio/Actions/ResolveBrandProfileAction.php
--- a/packages/core/src/ThemeStudio/Actions/ResolveBrandProfileAction.php
+++ b/packages/core/src/ThemeStudio/Actions/ResolveBrandProfileAction.php
@@
             if (BrandProfileData::supportsToken($key)) {
                 continue;
             }
+
+            if (! BrandProfileData::supportsCustomToken($key)) {
+                continue;
+            }
diff --git a/packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php b/packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php
--- a/packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php
+++ b/packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php
@@
+it('rejects unsafe and reserved custom token names before rendering', function (): void {
+    $brand = (new BrandProfileData(radius: 'md'))->withCustomTokens([
+        'glassDepth' => 'prismatic',
+        'radiusValue' => '999px',
+        'x; } body { display:none; } /*' => 'safe',
+    ]);
+
+    $css = (new ThemeTokenRenderer)->css($brand);
+
+    expect($css)
+        ->toContain('--theme-glass-depth: prismatic;')
+        ->toContain('--theme-radius-value: 0.5rem;')
+        ->not->toContain('999px')
+        ->not->toContain('body { display:none;');
+});
```

Also extend the runtime test with datasets for a declared out-of-vocabulary value, inherited declarations, and the real frontend hook. Do not rely only on the defensive renderer test.

### CI Parity Change

Use process environment values in the matrix job and remove `force="true"` from the three database entries in `phpunit.xml`. Add a test or setup assertion for the active driver. Do not merely keep writing `.env` while PHPUnit has already forced process values.

## 10. Commands

### Commands Executed During Audit

```bash
git diff --check

./vendor/bin/pest \
  packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php \
  packages/core/tests/Unit/ThemeStudio/ResolveBrandProfileActionTest.php \
  --configuration=phpunit.xml

./vendor/bin/pest \
  packages/frontend/tests/Unit/Providers/ThemeRuntimeRenderHookTest.php \
  packages/admin/tests/Unit/Actions/Themes/ResolveThemeEditorSchemaActionTest.php \
  --configuration=phpunit.xml

php -d memory_limit=-1 -d max_execution_time=0 \
  -d pcov.enabled=1 -d pcov.directory=packages \
  ./vendor/bin/pest \
  packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php \
  packages/core/tests/Unit/ThemeStudio/ResolveBrandProfileActionTest.php \
  --coverage --only-covered \
  --coverage-filter=packages/core/src/ThemeStudio/Actions \
  --configuration=phpunit.xml

./vendor/bin/pint --test \
  packages/core/src/ThemeStudio/Actions/ResolveThemeRuntimeAction.php \
  packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php

XDEBUG_MODE=off php vendor/bin/rector process \
  packages/core/src/ThemeStudio/Actions/ResolveThemeRuntimeAction.php \
  packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php \
  --dry-run --no-progress-bar

XDEBUG_MODE=off ./vendor/bin/phpstan analyse \
  packages/core/src/ThemeStudio/Actions/ResolveThemeRuntimeAction.php \
  packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php \
  --configuration=phpstan.neon --memory-limit=2G --no-progress

composer audit --locked --no-interaction
composer preflight # stopped with exit 130 after the shared snapshot changed
```

Cross-package command, run from `../capell-packages-4`:

```bash
./vendor/bin/pest \
  packages/theme-foundation/tests/Unit/ThemeEditorSchemaConsumptionTest.php \
  packages/theme-foundation/tests/Unit/ThemeRuntimeSettingsBindingTest.php \
  packages/theme-foundation/tests/Unit/ThemeTokenConsumptionTest.php \
  packages/theme-liquid-glass/tests/Unit/LiquidGlassThemeDefinitionTest.php \
  --configuration=phpunit.xml
```

### Required Local Recheck After Remediation

```bash
git diff --check

./vendor/bin/pest \
  packages/core/tests/Unit/ThemeStudio/ThemeTokenRendererTest.php \
  packages/core/tests/Unit/ThemeStudio/ResolveBrandProfileActionTest.php \
  packages/frontend/tests/Unit/Providers/ThemeRuntimeRenderHookTest.php \
  --configuration=phpunit.xml

composer lint
composer analyze
composer check:docs-links
composer check:docs-orphans
composer check:extension-surfaces
composer check:stable-extension-api
composer preflight
```

Run `composer preflight:all` only in a clean clone/CI because the current full-stage script includes formatting commands.

### Required CI / Staging Verification

```bash
# CI: PHP 8.4 with Laravel 12 and 13 matrices
composer run test:fast:ci
composer run test:all:ci

# Release coverage contract
php -d memory_limit=-1 -d max_execution_time=0 \
  -d pcov.enabled=1 -d pcov.directory=packages \
  vendor/bin/pest --coverage --min=90 \
  --coverage-clover=coverage/clover.xml \
  --stop-on-error --stop-on-failure \
  --configuration=phpunit.xml

# Clean release gate
composer preflight:all
```

Staging must render a real Liquid Glass page and inspect `style[data-capell-theme-tokens]` or its linked artifact for the expected `--theme-glass-depth` value and the absence of injected/authoring content.

## 11. Monitoring and Contingency Plan

### Rollout Window

- **0–15 minutes**: Canary one site. Verify HTTP 2xx rate, page rendering, generated token CSS completeness, storage-write exceptions, and absence of public-output safety failures.
- **15–60 minutes**: Exercise all Liquid Glass presets plus one standard-schema theme on mobile and desktop; compare visual defaults and p95 latency to the pre-release baseline.
- **1–24 hours**: Monitor 5xx rate, theme token write exceptions, cache behavior, support reports, and unexpected layout/contrast changes before declaring stable.

### Success Criteria

- [ ] **AUDIT-MON-1 [CSS integrity]**: Every emitted property name matches `^--theme-[a-z][a-z0-9-]*$` and no custom key collides with a core property.
- [ ] **AUDIT-MON-2 [Functional]**: Valid `glassDepth` values render for all three Liquid Glass presets and explicit overrides win.
- [ ] **AUDIT-MON-3 [Safety]**: Malformed, undeclared, and out-of-vocabulary inputs produce no CSS and no authoring metadata appears publicly.
- [ ] **AUDIT-MON-4 [Reliability]**: No increase in token-store exceptions or HTTP 5xx responses.
- [ ] **AUDIT-MON-5 [Performance]**: Public p95 latency remains within the established site baseline and the 20 ms theme render budget.
- [ ] **AUDIT-MON-6 [Compatibility]**: Standard themes keep visually equivalent defaults; PHP 8.4/Laravel 12 and 13 CI stays green.

### Contingency

If any success criterion fails, stop rollout, restore the previous core release, purge/warm public HTML caches, verify the old token CSS, and notify support/theme owners. Generated content-addressed CSS files need not be deleted unless an operator chooses to reclaim storage.

## 12. Final Quality Assurance Checklist

### Verification Discipline

- [x] **AUDIT-QA-1 [Evidence per finding]**: Every finding has code/command evidence.
- [x] **AUDIT-QA-2 [Requirement traceability]**: Requirements mapped to code and available tests.
- [x] **AUDIT-QA-3 [Missing coverage]**: Full coverage, HTTP/browser, inheritance, concurrency, and matrix gaps explicitly called out.
- [x] **AUDIT-QA-4 [Critical reproduction]**: No Critical finding; minimal reproduction supplied for the leading Medium CSS injection finding.
- [x] **AUDIT-QA-5 [Evidence quality]**: Facts, static inferences, incomplete evidence, moving-snapshot contamination, and timestamps distinguished.

### Actionable Recommendations

- [x] **AUDIT-QA-6 [Testable fixes]**: Each remediation has validation steps, owner, effort, dependencies, and release impact.
- [x] **AUDIT-QA-7 [Risk ordering]**: CSS safety and correctness precede coverage, CI parity, documentation, and atomic-write improvements.
- [x] **AUDIT-QA-8 [Staging/canary]**: Required before broad rollout.
- [x] **AUDIT-QA-9 [Fallbacks]**: Revert delegation/source release and purge public HTML caches.

### Risk Contextualization

- [x] **AUDIT-QA-10 [Release blockers]**: `AUDIT-FIND-3.1`, `AUDIT-FIND-3.2`, `AUDIT-FIND-3.3`, and clean verification are blockers.
- [x] **AUDIT-QA-11 [User-visible impact]**: Public theme presentation and calls to action can be altered by injected CSS.
- [x] **AUDIT-QA-12 [On-call/support impact]**: Visual breakage may arrive as customer reports without server errors; support handoff is required.
- [x] **AUDIT-QA-13 [Regression risk]**: Medium-high for the generic extension boundary; low for the known `glassDepth` key in isolation.
- [x] **AUDIT-QA-14 [Security breadth]**: Authentication, authorization, input validation, CSS injection, data protection, secrets, and configuration reviewed.
- [x] **AUDIT-QA-15 [Performance metrics]**: Quantitative resolver metrics included; absence of HTTP/load results stated.
- [x] **AUDIT-QA-16 [Operational breadth]**: Observability, alerting, deploy, rollback, feature flags, migrations, CI, and support covered.
- [x] **AUDIT-QA-17 [Finding fields]**: Every finding has severity, status, owner, recommendation, verification, and timeline.
- [x] **AUDIT-QA-18 [Recommendation clarity]**: **NO-GO** with six explicit release conditions.
- [ ] **AUDIT-QA-19 [Coverage threshold]**: Pending — the repository's 90% release coverage threshold has not been demonstrated for a fixed immutable SHA.
- [ ] **AUDIT-QA-20 [Staging rollback]**: Pending — rollback and cache-purge steps are documented but not executed in staging.
- [ ] **AUDIT-QA-21 [Stakeholder communication]**: Pending — release notification and support handoff evidence do not exist.

## Final Recommendation

**NO-GO.** Do not commit, merge, or deploy the current implementation as release-ready. The valid Liquid Glass extra-token behavior is demonstrated and its measured runtime cost is negligible, but the generic public CSS boundary admits unsafe property names and reserved-name collisions. Apply `AUDIT-REM-1.1` and `AUDIT-REM-1.2`, then obtain clean-snapshot local and CI evidence under `AUDIT-REM-2.1`. The CI database-parity, release-traceability, and atomic-write items should remain tracked even though they are not all blockers for this specific pure-code slice.

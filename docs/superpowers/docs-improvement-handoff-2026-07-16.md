# Docs improvement handoff — 2026-07-16

Session-to-session handoff for the ongoing documentation improvement effort across
`capell-4` and `capell-packages-4`. Written for the next agent; self-contained.
Internal note — not part of public docs navigation (`docs/superpowers/` is
orphan-allowlisted).

## Progress report

### Landed (2026-07-15 session)

| Repo | Branch | Commit | Content |
| --- | --- | --- | --- |
| capell-4 | `chore/standards-consistency` | `31d9df5e0` | 26-file sweep: 10 dead anchors fixed (install.md heading renames, `capell-app/installer` rename), stale private-credentials troubleshooting entry rewritten for public Packagist, phantom `config/audit.php` table and unread `CAPELL_FRONTEND_ASSET_BUILD_TOOL` env row removed, site-health.md verification pointed at real tests, 2 orphaned admin docs linked, index wording aligned to page H1s, `## Next` added to 6 leaf docs, `Read Next` → `Next` normalised, discovery troubleshooting deduplicated |
| capell-4 | `chore/standards-consistency` | `f5f000dcf` | Dashboard widget docs deduplicated: `packages/admin/docs/dashboard-widget-customization.md` is canonical; `docs/admin/dashboard-widget-development.md` is a 32-line task entry page; 3 code-contradicted claims corrected |
| capell-4 | `chore/standards-consistency` | `84182f9a5` | Second extension walkthrough (463 lines) folded into `build-extension-end-to-end.md` / `package-anatomy.md` / `admin-extensions.md` etc.; every moved claim verified against code |
| capell-packages-4 | `feature/ai-native-admin` | `c4013fb35` | frontend-optimizer: `readme-narrative.json` evidence repointed after the resource-graph migration deleted `tests/Feature/`; README regenerated; stale pest shard entry dropped; docs gate restored to green |

**Branch caveat:** none of these are on `main`. Both shared checkouts were on
concurrent sessions' feature branches at commit time. If those branches stall or
get abandoned, cherry-pick the four SHAs above — all are docs-only and conflict-free
by construction.

### Follow-ups already closed by other sessions (verified 2026-07-16)

- `505fd0eb7` (packages-4) restored `PublicHeadOutputSafetyTest` end-to-end through
  the resource graph; `frontend-optimizer/docs/improvement-plan.md` updated to
  Done/Shipped. The "restore head safety test" task is CLOSED.
- `def15e618` (packages-4) retargeted the diagnostics-package link, and
  `107d3c8ee` (capell-4) then deleted the `how-to-create-a-capell-extension.md`
  redirect stub. That thread is CLOSED (the stale orphan-allowlist entry was
  removed in the same commit as this handoff).

### Verified baseline at handoff time

- **capell-4:** all five gates PASS — `composer check:docs-links` (1000+ relative
  links), `check:docs-orphans`, `check:root-docs`, `check:docs-requirements`,
  `check:docs-env`.
- **capell-packages-4:** `php scripts/audit-package-docs.php` **FAILS**:
  `packages/theme-platform/README.md` links two missing blade views
  (`resources/views/widget/workflow-rails.blade.php`,
  `resources/views/widget/security-proof.blade.php`), and the same missing-evidence
  paths make `php scripts/generate-package-readmes.php --check` throw fatally at
  line 165 — identical failure pattern to the frontend-optimizer one fixed in
  `c4013fb35`. Plus ~130 warning-level missing optional screenshots.

## Plan for the next agent

Priority order. Each item is standalone.

### P1 — theme-platform narrative references deleted views

Fix `packages/theme-platform/docs/readme-narrative.json` (repoint or drop the two
blade-view evidence paths), then regenerate via `php scripts/generate-package-readmes.php`
and keep ONLY the theme-platform outputs (see Traps for why). **Check ownership
first**: the theme session churns these packages daily; if `git status` shows
theme-platform files dirty, leave it to them and just verify later.

### P1 — cherry-pick decision

If `chore/standards-consistency` or `feature/ai-native-admin` have not merged
within a few days, raise the four docs commits for cherry-pick to `main` so the
hosted docs rebuild (docs.capell.app builds on release) picks them up.

### P2 — add anchor validation to the links gate

`scripts/check-docs-links.php` validates file existence only. The 10 dead anchors
fixed in `31d9df5e0` were invisible to it. Extend it to validate `#fragment`
targets: GitHub-slugify headings (lowercase; strip `` ` ``*_~[]()!``; non-word
chars except dash/space removed; spaces → dashes; `-N` suffix for duplicates),
skip fenced code blocks, and honour explicit `id="..."` attributes in HTML blocks
(the packages-4 README uses `<details id="foundation-widgets">`). Without this,
every heading rename silently re-breaks links.

### P2 — external links have never been checked

Every checker skips `http(s)` links. The glossary and several package docs
deep-link to `https://docs.capell.app/...` paths that have never been verified
against the deployed site. One-off crawl or a CI-tolerant checker with an
allowlist for flaky hosts.

### P2 — 16 stale generated READMEs (packages-4)

`generate-package-readmes.php --check` listed 16 stale outputs beyond
frontend-optimizer (layout-builder, search, and 13 theme packages) — narrative
sources edited without regeneration. Owned by the in-flight theme work. Once the
theme session's tree is quiet, run the generator and commit the regenerated files.

### P2 — missing optional screenshots (~130 warnings, packages-4)

`audit-package-docs.php` warns on `screenshots.json` entries whose PNGs don't
exist (all `required:false`). A task chip existed for this; re-run the audit for
the current count before starting (commit `3175caf30` touched capture contracts).

### P3 — content-accuracy audit of capell-packages-4 package docs

capell-4 got a deep doc-vs-code accuracy audit (commands, flags, env vars, class
names). capell-packages-4 only got its structural gate. The ~98 packages' hand-
authored docs (`docs/*.md` beyond generated files) have NOT been verified against
their code. Highest-traffic first: publishing-studio, seo-suite, blog, search,
migration-assistant.

### P3 — deliberate leftovers, in case standards tighten

- `docs/frontend/widget-{registration,state,targets}.md` reachable only via the
  `widgets.md` hub, not the section index (accepted hub pattern).
- `docs/platform/` and `docs/examples/` have no section index.
- `## Next Steps` (package overviews), `## Related*`, `## Further Reading`
  variants left as semantically distinct from `## Next`.
- packages-4 README `#foundation-widgets` anchor relies on `<details id>` —
  works on GitHub, dead on strict renderers.
- packages-4 root notes: `CONTEXT.md` last touched 2026-05-06 (freshness pass
  candidate); `REVIEW-2026-06-26-capell-packages.md` findings closed (archival
  candidate). `SITE_SEARCH_HANDOFF.md` is an active cross-session note — leave.
- Image/screenshot freshness in capell-4 docs was never audited.

## Traps (read before editing)

1. **Both checkouts are shared with concurrent sessions.** Verify
   `git branch --show-current` immediately before every commit; the branch moves
   mid-session. Commit with an explicit pathspec (`git commit -m ... -- <paths>`),
   never bare `git add` + `git commit` — another session's staged files WILL get
   swept in (it happened; fixed via `git reset --soft` + pathspec re-commit). Full
   details in memory `feedback_shared_checkout_use_worktrees`.
2. **Never hand-edit generated files in packages-4**: every package `README.md`
   and `docs/overview.md` is generated. Sources are `docs/readme-narrative.json`
   and `docs/overview.admin.md`. Fix the source, run the generator, and `git
   checkout --` any regenerated outputs you did not intend to touch (the generator
   is all-or-nothing).
3. **Don't run full preflight/test suites while the other sessions' PHP is dirty**
   — results reflect their half-done work. Use the docs gates plus targeted
   checks, and say so in the report.
4. **`docs/packages.md` is an intentional redirect stub** (allowlisted in
   `scripts/check-docs-orphans.php`) for published docs-site URLs. The rulebook is
   `docs/development/docs-ownership.md`.
5. **Attestation-drift test failures in packages-4** (`ReleaseCatalogueReadinessTest`)
   are environmental while the theme session has uncommitted changes — check
   whether failures mention files you touched before assuming causation.

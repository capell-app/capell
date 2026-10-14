# Capell Foundation Monorepo

This foundation monorepo owns stable Core, Admin, Frontend, Installer and Marketplace contracts. App owns commerce/marketing/operations; optional features belong in companion Packages.

- Read the affected package's nested AGENTS.md and the ownership/extension routes below before changes. Core must not import optional packages, UI siblings or provider runtimes.
- Use strict types, Actions for business work, Data for structured boundaries, translations and thin entry points. Mark real vendor/interface overrides with `#[Override]`; trait replacements must not carry it.
- Public output, cache, fragments and exports must contain no authoring state or internals. Blade receives hydrated render data, without queries/lazy-loading. Rendering changes need presence and absence behaviour assertions.
- Use manifest provider buckets and typed registries; preserve stable API baselines and migration registration. Never bypass extension contracts or edit vendor code.
- Preserve foreign work. Use this checkout's wrapper, explicit origin/main worktree bases and the shared external `.capell-wt` root. Provision runtime work; instruction-only checks need no application stack/dependency install unless a hook requires tooling.
- Every change gets an owned branch/PR. Stage explicit paths; inspect HEAD/status before committing and `git show --stat HEAD` before pushing. Stop on index errors; do not change shared remotes/config inside worktrees.
- Read verification procedures before checks, discover changed symbols/callers, collect related failures and run final affected/full gates. Do not weaken tests or replace required full gates. Line coverage stays at least 90%; hosted CI requirements before merge remain in force.
- Screenshot acceptance requires shared-runner provenance; ad-hoc browser captures are diagnostic only. Read the screenshot route before capture.
- Programme work starts at `./ledger brief --project capell` and selected task evidence. Ledger IDs belong in commit/PR history, not source. Merges/releases/cleanup retain their existing authorisation and ownership checks.

## Procedure routing

Read only the procedure for the operation you are about to perform, before executing it. The detailed rules remain mandatory within their scope. Commands and bare file paths below are relative to the checkout root. Do not load the complete reference directory.

| Operation or concern | Required reference |
|---|---|
| Capell Foundation Monorepo | [Read procedure](.agents/docs/00-capell-foundation-monorepo.md) |
| Ownership Boundaries | [Read procedure](.agents/docs/01-ownership-boundaries.md) |
| Ledger References Don't Belong In Source Code | [Read procedure](.agents/docs/02-ledger-references-don-t-belong-in-source-code.md) |
| Public Rendering Safety | [Read procedure](.agents/docs/03-public-rendering-safety.md) |
| Extension Contracts | [Read procedure](.agents/docs/04-extension-contracts.md) |
| Screenshot Evidence | [Read procedure](.agents/docs/05-screenshot-evidence.md) |
| Verification Commands | [Read procedure](.agents/docs/06-verification-commands.md) |
| Verification Operating Pattern | [Read procedure](.agents/docs/07-verification-operating-pattern.md) |
| Local Hazards | [Read procedure](.agents/docs/08-local-hazards.md) |
| Line coverage stays at or above 90% — not negotiable | [Read procedure](.agents/docs/09-line-coverage-stays-at-or-above-90-not-negotiable.md) |
| Held pull requests carry the `awaiting-merge` label | [Read procedure](.agents/docs/10-held-pull-requests-carry-the-awaiting-merge-label.md) |

Keep the scope, evidence and unresolved approval visible at closeout.

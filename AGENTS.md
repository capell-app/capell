# Capell Monorepo Instructions

## Git Workflow

- Never commit directly to `main`. Always branch (`feat/`, `fix/`, `chore/`, `docs/`), push, and open a PR — even for single-file or agent-driven changes. `main` on `capell-app/capell` requires a PR via branch protection; do not rely on that alone.

## Working Agreement

- Preserve unrelated local changes and keep each diff scoped to the requested package or contract.
- Use strict types, explicit parameter and return types, PSR-12, and existing Laravel and Filament conventions.
- Prefer small Actions for domain work and Data objects for structured boundaries. Controllers, commands, resources, and components should delegate.
- Use translations for user-facing copy. Add dependencies only when the owning package genuinely needs them.
- Test meaningful behaviour at the narrowest useful level before running broader repository checks.

## Package Boundaries

- `packages/core` owns neutral CMS models, contracts, registries, installation primitives, and deterministic SiteSpec import. It must not depend on admin, frontend, marketplace, or commercial AI runtimes.
- `packages/admin` owns Filament authoring UI. Keep business behaviour in Actions and keep admin state out of public output.
- `packages/frontend` owns anonymous rendering, cache and static-export behaviour, and public assets. Public Blade must not query the database or leak authoring metadata.
- `packages/installer` orchestrates package installation. Reuse package-owned setup commands and Actions instead of duplicating their behaviour.
- `packages/marketplace` owns marketplace transport and installation coordination, while core remains the source of package lifecycle state.

## SiteSpec And AI

- Core owns the SiteSpec contract, validation, safe media intake, and deterministic import command.
- AI generation, prompt execution, provider clients, metering, and authoring products belong in external commercial packages.
- Package-specific SiteSpec blocks are applied through typed package-owned appliers. Do not introduce optional package model dependencies into core.
- Never expose package names, prompts, run metadata, signed URLs, credentials, or editor-only fields in anonymous HTML.

## Generated Contracts

- Do not hand-edit `docs/packages/extension-surface-catalog.json` or its Markdown companion. Update executable catalogue metadata, then run `php scripts/build-extension-surface-catalog.php`.
- Stable extension API changes must keep `docs/packages/stable-extension-api-baseline.json` and their direct contract tests current.

## Verification

- Focused Pest: `./vendor/bin/pest path/to/Test.php --configuration=phpunit.xml`
- Formatting: `composer lint`
- Static analysis: `composer analyze`
- Repository gate: `composer preflight`
- Documentation contracts: `composer check:docs-links`, `composer check:docs-orphans`, `composer check:extension-surfaces`, and `composer check:stable-extension-api`
- Host and container keep separate tool caches on purpose: `phpstan.neon` pins
  `tmpDir` to `var/phpstan/full`, and the container masks `var/` with a named
  volume (`docker-compose.yml`) because it sees this repo as
  `/home/capell/current` while the host sees `/Users/...`. Do not remove that
  volume to "share" the cache — one shared `var/` makes whichever context ran
  last leave the other reporting
  `Internal error: "phar://…/phpstorm-stubs/….stub" is not a file`, which reads
  like a code fault but is only stale paths.
- If that error ever appears, it is a cache, never the code. Confirm with
  `grep -c /home/capell/current var/phpstan/full/resultCache.php` (host runs
  should return 0), then delete `var/phpstan/full` and re-run.
- Each worktree has its own `var/`, so a branch can be green in a worktree and
  red in the primary checkout for this reason alone. Compare caches before
  reading code.

## Planning Notes

- Keep package contracts and code-coupled documentation in this repository.
- Keep cross-repository programme tracking, release evidence, and internal
  review notes outside this repository; `docs/superpowers/` is ignored for that
  purpose.
- Never commit secrets, customer data, credentials, or production exports.

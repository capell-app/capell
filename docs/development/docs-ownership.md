# Docs Ownership Rules

Use this checklist before adding or moving documentation.

## Placement

| Content                                              | Location                                         |
| ---------------------------------------------------- | ------------------------------------------------ |
| Host package contracts and extension points          | `capell-4/docs` or the owning host package docs  |
| Host package implementation details                  | `capell-4/packages/<package>/docs`               |
| Companion package features                           | `capell-packages-4/packages/<package>/docs`      |
| Marketing, brand, website copy, or sales positioning | the Capell website repository, outside this one  |
| Historical plans, audits, or internal review notes   | outside public docs unless still actively useful |

## Rules

- Update an existing page before adding a new file.
- Every doc must be linked from `docs/README.md`, a section index, a package overview, or another doc.
- Follow the [documentation visual standard](../standards/documentation-visuals.md) for linked originals, captions, theme-aware pairs, ownership, and evidence boundaries.
- Do not keep "moved" stubs unless an external published URL needs a temporary redirect.
- Do not document optional package behavior as built-in host behavior.
- Keep package-specific install commands in the package that owns them.
- Prefer short task pages over broad narrative pages.
- End leaf docs with a small `Next` section when there is a natural follow-up.
- Skills shipped in `packages/*/resources/boost/skills/` (and their `references/` files) are installed into third-party applications by Composer. Write for that reader: say "the Capell CMS" or "this package", use generic placeholders for local paths, and never mention `capell-4`, `capell-packages-4`, the monorepo, a developer's local absolute paths or "companion packages". Naming a Composer package or a Capell concept is fine.

## Guards that pin documentation

Front-door docs (root README, `packages/*/README.md`, `docs/README.md`, CONTRIBUTING, SECURITY) are checked by scripts that can fail on a prose-only change:

- `scripts/check-root-docs.php` checks the root `composer.json` aggregate identity and fails on any root-level Markdown file outside its allow-list, so a scratch note in the repository root breaks it. README prose is free-form there.
- `scripts/check-readme-engineering-standards.php` pins README badge substrings against the real PHPStan, workflow and metrics configuration, so do not change badge claims. `docs/reference/readme-engineering-metrics.json` is regenerated with `--update`, never edited by hand.
- Several README links are contract-tested entry points (for example the "Build a page" link in `PageBuildingGuideTest`); `scripts/check-docs-links.php` only validates links that are present, never that a required one still exists.
- `scripts/check-docs-links.php` toggles an inside-fence flag at every triple-backtick line. An odd fence count hides every later heading and anchors report as broken. Check that the number of fence lines in the file is even before debugging anything else; a careless `replace_all` that eats a newline can merge a command into the closing fence.
- `scripts/check-doc-screenshot-coverage.js` requires every Markdown file outside a `docs/` directory (other than its ignore list, `AGENTS.md` and `CLAUDE.md`) to embed at least one local image, and validates every embedded image path in the files it scans (Markdown images, `<img src>`, `<source srcset>`; fenced and inline code are skipped).

After a docs edit run `composer check:docs-links`, `check:docs-orphans`, `check:docs-requirements`, `check:docs-commands` and `check:docs-screenshots`, plus `scripts/check-root-docs.php` and `scripts/check-readme-engineering-standards.php`.

## Review Questions

- Who is the reader: installer, package author, operator, maintainer, or editor?
- What is the next action after reading?
- Is the command, class, config key, or package name current?
- Does this duplicate another page?
- Would this be better in a package README?

## Next

- [Docs route map](../README.md)
- [Documentation visuals](../standards/documentation-visuals.md)
- [Host, package, or app code](package-boundaries.md)
- [Package authoring](../packages/README.md)

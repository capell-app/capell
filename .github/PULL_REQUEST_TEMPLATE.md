## Summary

<!-- What does this change do, and why? -->

## Checklist

- [ ] Branch is prefixed with its type (`feat/`, `fix/`, `docs/`, `chore/`, `refactor/`, `test/`) and commits are concise, imperative, and scoped (see [CONTRIBUTING.md](../CONTRIBUTING.md#branch--commit-conventions)).
- [ ] `composer preflight` passes locally (Rector, Pint, PHPStan).
- [ ] `composer test` passes locally (Pest), with a happy-path test and at least one edge case for new commands or services.
- [ ] New/changed public rendering, cache, or sitemap behaviour has tests proving anonymous and non-admin responses don't expose authoring markers, signed admin URLs, model IDs, selectors, or package internals (if applicable).
- [ ] Package boundaries are respected: Core does not depend on Admin or Frontend; Admin and Frontend don't depend on each other or on Core internals beyond documented public interfaces (see [CONTRIBUTING.md](../CONTRIBUTING.md#package-independence)).
- [ ] `README.md` is updated for any user-facing additions (commands, env vars, extension points), and new deep-dive docs are added under `docs/` and linked from README where relevant.
- [ ] `CHANGELOG.md` is updated (Added / Changed / Fixed / Deprecated / Removed).
- [ ] No secrets or `.env` files are committed; new controllers/commands validate and authorize with appropriate policies/gates.

## Related issues

<!-- Link any issues this closes or relates to -->

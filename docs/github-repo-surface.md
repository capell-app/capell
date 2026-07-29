# GitHub repo surface — prepared commands

Prepared `gh` commands for tidying up the `capell-app` GitHub organisation's public
surface. **None of these have been run.** They mutate a public repository's
settings, so run them yourself after reviewing, from a machine authenticated as
an org owner (`gh auth status`).

`docs/superpowers/` does not exist in this repo, so this file lives at
`docs/github-repo-surface.md` instead.

## 1. Disable the empty wiki

```bash
gh repo edit capell-app/capell --enable-wiki=false
```

Rationale: the wiki is enabled but has no pages. An empty wiki tab reads as
neglect to a visitor evaluating the project; disable it until there's content
worth publishing there.

## 2. Disable Discussions

```bash
gh repo edit capell-app/capell --enable-discussions=false
```

Rationale: Discussions is enabled but empty, and nobody is currently staffed to
answer it. This is a deliberate decision, not a placeholder — re-enable when
launch marketing starts and someone owns triage.

Verified against `gh repo edit --help` (2026-07-29): `--enable-discussions` is a
supported boolean flag, and `gh`'s own help text confirms the off-syntax used
above (`To toggle a setting off, use the --<flag>=false syntax.`). No web-UI
fallback is needed.

## 3. Add missing topics to the root repo

Current topics, read via:

```bash
gh repo view capell-app/capell --json repositoryTopics
```

Result (2026-07-29): `capell`, `capell-cms`, `cms`, `filament`, `laravel`,
`livewire`, `multi-site`, `multilingual`, `php`, `multisite` — ten topics, none
of which overlap with the four requested below.

```bash
gh repo edit capell-app/capell \
  --add-topic laravel-cms \
  --add-topic content-management-system \
  --add-topic laravel-package \
  --add-topic filament-php
```

Rationale: these are terms a prospective adopter actually searches GitHub
topics for; the existing ten are accurate but don't include any of them.

## 4. Mirror sensible topics onto the public split repos

The split repos are the individually-installable Composer packages published
from this monorepo. Names were taken from `config/release-packages.json` and
confirmed against `.github/workflows/split-monorepo.yml` (the `repositories:
admin,core,frontend,installer,marketplace` list passed to the split App
token) and each package's `composer.json` `name` field
(`capell-app/{admin,core,frontend,installer,marketplace}`):

- `capell-app/core`
- `capell-app/admin`
- `capell-app/frontend`
- `capell-app/installer`
- `capell-app/marketplace`

Current topics on each (read via `gh repo view capell-app/<repo> --json
repositoryTopics,description`, 2026-07-29):

| Repo | Current topics |
|---|---|
| core | capell, capell-cms, cms, content-management, laravel, php, multilingual, multisite |
| admin | admin-panel, capell, capell-cms, cms, filament, laravel, livewire, php |
| frontend | blade, capell, capell-cms, cms, frontend, laravel, php, rendering, caching, themes |
| installer | capell, capell-cms, filament, installer, laravel, php, setup, onboarding |
| marketplace | capell, capell-cms, extensions, filament, laravel, marketplace, package-management, php, ecommerce |

Each split repo's existing topics are already specific and accurate to what
that package does — none of the four root-level additions above translate
cleanly to every split repo (`content-management-system` and `laravel-cms` are
product-level claims about the whole CMS, not about e.g. the installer alone;
`filament-php` doesn't apply to `frontend`, which renders public output and has
no Filament dependency). The one topic genuinely missing from all five and
applicable to all five is `laravel-package`, since each is independently
`composer require`-able:

```bash
gh repo edit capell-app/core        --add-topic laravel-package
gh repo edit capell-app/admin       --add-topic laravel-package
gh repo edit capell-app/frontend    --add-topic laravel-package
gh repo edit capell-app/installer   --add-topic laravel-package
gh repo edit capell-app/marketplace --add-topic laravel-package
```

Rationale: surfaces each split package under the `laravel-package` topic
search without adding product-level claims to repos where they don't apply.

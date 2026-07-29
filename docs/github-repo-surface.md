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

## Org-level actions (not repo-level)

The sections above act on individual repositories. These four act on the
`capell-app` organisation itself — its profile page, its verified-domain
badge, its pinned-repo rail, and the `.github` profile-repo it does not yet
have. **None of these have been run either.** Where a `gh` command exists it
is given below; where the action is web-UI-only, that is stated instead of a
fabricated command.

### 5. Set the org description

Current state, read via:

```bash
gh api orgs/capell-app --jq '.description'
```

Result (2026-07-29): empty string. The org page currently shows no
description at all next to `capell-app`.

```bash
gh api orgs/capell-app -X PATCH -f description="Open-source Laravel CMS built on Filament"
```

Rationale: this is the one line a visitor sees before deciding whether to
click in further; right now there is nothing there at all.

### 6. Verify the `capell.app` domain

Web UI only. Confirmed via GraphQL schema introspection (2026-07-29) that
`addVerifiableDomain` / `verifyVerifiableDomain` mutations exist, but the
underlying flow needs a DNS TXT record added under `capell.app` first and a
manual verify click in **Organization settings → Verified & approved
domains** — there is no single `gh` command that does both halves, and the
DNS step is outside GitHub entirely. Do this from Organization settings in
the browser, not via `gh api`.

Rationale: gives the org the GitHub "Verified" badge, which is a trust signal
a prospective adopter checks before installing a package from an unfamiliar
org.

### 7. Pin repositories at org level

Current state, read via:

```bash
gh api graphql -f query='query { organization(login: "capell-app") { pinnedItems(first: 10, types: [REPOSITORY]) { totalCount } } }'
```

Result (2026-07-29): `totalCount: 0` — nothing is pinned.

Web UI only. GraphQL schema introspection (2026-07-29) shows no
`pinRepository`/`unpinRepository`-style mutation exposed for organizations
(the only `pin*` mutations in the schema are for issues, issue comments, and
environments), and `gh repo` has no `pin` subcommand. Pin from the org's
profile page (**Customize your pins**), choosing:

- `capell-app/capell` — the monorepo, the actual product.
- `capell-app/capell-skeleton` — the starter/skeleton repo.
- `capell-app/pest-plugin-blade-coverage` — the org's most-downloaded
  artifact (1,752 downloads), currently unpinned and easy for a visitor to
  miss entirely.

Rationale: the org page defaults to sorting repos by recent activity, which
buries the actual product monorepo under whatever split repo the last split
workflow touched. Pinning fixes that regardless of split timing.

### 8. Create `capell-app/.github` with the profile README

Web UI (or `gh repo create`, which is a mutating command and therefore not
listed as something to run here). The intended content is drafted at
[`docs/org-profile-README.md`](org-profile-README.md) in this repo — copy it
to `profile/README.md` in the new `capell-app/.github` repository once that
repository exists.

Rationale: this is the file GitHub renders as `github.com/capell-app`'s
front page. Today that URL shows a bare org page — empty description, no
profile repo, 2 followers — to every visitor who clicks the site's GitHub
icon.

### Separate action: `pest-plugin-blade-coverage`'s own README

`capell-app/pest-plugin-blade-coverage` is the org's most-adopted artifact
(1,752 downloads, ahead of the five foundation packages combined) and its own
README does not currently mention Capell at all, so a developer who finds it
independently has no path back to the CMS it was built for. That repository
is not checked out here, so fixing its README is a separate action for Ben,
not something implemented as part of this task.

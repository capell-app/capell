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
badge, its pinned-repo rail, and its existing-but-private `.github`
profile-repo. **None of these have been run either.** Where a `gh` command
exists it is given below; where the action is web-UI-only, that is stated
instead of a fabricated command.

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

### 8. Make `capell-app/.github` public and update its profile README

**Correction (2026-07-29): this repo already exists.** An earlier version of
this doc said `capell-app/.github` needed to be created — that was wrong.
Verified via `gh api repos/capell-app/.github`:

- created `2026-05-26T16:41:09Z`
- **private** — a private `.github` repo's `profile/README.md` does not
  render at `github.com/capell-app`, the same as if the repo didn't exist at
  all. This, not a missing repo, is why that URL shows a bare org page today.
- description: "GitHub organization profile and repository map for Capell."
- already contains its own `profile/README.md` (1,623 bytes, read via
  `gh api repos/capell-app/.github/contents/profile/README.md`) — an
  internal-facing maintainer repo map and contribution-flow doc (canonical
  vs. generated-split repos, where contributor PRs get forwarded), not a
  public marketing profile.

So there is no "create the repo" step. The corrected sequence:

1. Decide what happens to the existing internal `profile/README.md`: replace
   it outright with the draft at
   [`docs/org-profile-README.md`](org-profile-README.md), or merge the two
   (e.g. move the internal repo-map/contribution-flow content into
   `CONTRIBUTING.md` or another internal doc first, so it isn't lost, then
   replace `profile/README.md` with the public draft). This is Ben's call,
   not decided here — the existing content is genuinely useful to
   maintainers, just wrong for a public front page.
2. Push the chosen content to `profile/README.md` in `capell-app/.github`.
3. Make the repository public:

   ```bash
   gh repo edit capell-app/.github --visibility public --accept-visibility-change-consequences
   ```

   `--visibility` and its required `--accept-visibility-change-consequences`
   companion flag are confirmed via `gh repo edit --help` (2026-07-29); `gh`
   warns changing visibility can affect stars/watchers and repo ranking, so
   review that before running it. This is a mutating command and therefore
   not run as part of this task.

Rationale: `profile/README.md` in a **public** `.github` repo is the file
GitHub renders as `github.com/capell-app`'s front page. Today that URL shows
a bare org page — empty description, no visible profile, 2 followers — to
every visitor who clicks the site's GitHub icon, because the repo that would
fix this is private, not absent.

### Release visibility — informational only, no action prepared

Verified via `gh release list -R capell-app/<repo>` (2026-07-29): all five
split repos — `core`, `admin`, `frontend`, `installer`, `marketplace` — have
full, populated GitHub Releases histories from `v1.0.0` through `v1.0.24`,
the latest published 2026-07-29. The monorepo, `capell-app/capell`, has
**zero** releases (`gh release list -R capell-app/capell` returns nothing).

This is a genuine split-vs-monorepo asymmetry, not a gap this doc proposes to
close: the per-package release record already exists and is current, so
anything depending on "does Capell have release notes" (e.g. a public
changelog page) can already point at the five split repos' releases today.
Whether the monorepo itself should also carry releases — and if so, what
they'd contain given it's the source the splits are generated from — is
Ben's decision, not proposed here.

### Separate action: `pest-plugin-blade-coverage`'s own README

`capell-app/pest-plugin-blade-coverage` is the org's most-adopted artifact
(1,752 downloads, ahead of the five foundation packages combined) and its own
README does not currently mention Capell at all, so a developer who finds it
independently has no path back to the CMS it was built for. That repository
is not checked out here, so fixing its README is a separate action for Ben,
not something implemented as part of this task.

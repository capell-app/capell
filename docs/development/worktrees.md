# Git worktrees

Use a git worktree when more than one person or agent session is working in this repo
at once. Each linked worktree has its own checked-out files, branch, `HEAD`, and index,
while the repository objects and local branch refs remain shared. That isolates
uncommitted edits and staging, but deleting or moving a shared branch ref still affects
every checkout.

Worktrees live outside the checkout, in one `.capell-wt` root shared by the App,
Core and companion-package repositories. Set `CAPELL_WORKTREE_ROOT` to that root
(the App's `./capell worktree` reads the same variable); otherwise use
`../.capell-wt`. When the App and Core checkouts sit in different parent
directories, the fallback produces a second root whose orphaned worktrees nobody
cleans up, so set the variable. Never create one inside the repository — an
in-tree worktree is deleted by `git clean -fdx` run in the primary checkout, and
every tree-wide scan then walks two copies of the source.

```bash
git worktree add "${CAPELL_WORKTREE_ROOT:-../.capell-wt}/core-my-feature" -b feature/my-feature
```

## First: are you running PHP on the host, or in Docker?

This decides how you provision `vendor/`, and getting it wrong costs you a session.

- **Tooling through `./capell` (Docker)** — the normal case in this repo. Do a real
  install in the worktree. See [Docker worktrees](#docker-worktrees) below.
- **PHP on the host** — use the fast hybrid `vendor/` from
  `scripts/init-worktree.sh`. See [Give the worktree a vendor/ in seconds](#give-the-worktree-a-vendor-in-seconds).

## Docker worktrees

`scripts/init-worktree.sh` builds `vendor/` out of **host-absolute** symlinks into the
primary checkout (`/Users/...` on macOS). The container never sees those paths:
`docker-compose.yml` bind-mounts the worktree at `/home/capell/current`, and it does not
mount the primary checkout at all. Every symlinked package therefore dangles inside the
container.

PHP does not report that as a missing package. It reports a fatal on the first `require`
of a dangling path, before a single test runs:

```
require(/Users/.../vendor/symfony/deprecation-contracts/function.php): Failed to open stream
```

which reads like a corrupt install rather than a wrong-tree layout. That misdiagnosis cost
one agent session 6 of its 21 commands.

Do this instead, in the worktree:

```bash
./capell up
./capell composer install
```

`./capell` derives a per-worktree Compose project from the directory name, so this stack
is isolated from the primary checkout's. The install is a real, self-contained `vendor/`
that resolves inside the container, and with a warm Composer cache it is not slow — a
measured cold-worktree run on 2026-08-19 took **41 seconds**, plus a few seconds for
`./capell up`.

`scripts/init-worktree.sh` now detects this repo's Docker harness and refuses to run,
naming that remedy, rather than leaving a `vendor/` that only works on the host. Pass
`--host-only` to override it if you really do run PHP on the host and never through
`./capell`.

Remember to `./capell down` when the worktree is idle. Parallel worktree stacks compound.

## Give the worktree a vendor/ in seconds

**Host-only.** See [Docker worktrees](#docker-worktrees) first if you run tooling through
`./capell`.

A fresh worktree has no `vendor/`, and a full `composer install` costs several minutes
and about 1.5 GB. Run this instead:

```bash
bash scripts/init-worktree.sh --host-only
```

It completes in under ten seconds, uses about 75 MB, verifies itself, and refuses to
leave a broken `vendor/` behind. Pass `--force` to rebuild an existing one.

### Do not symlink vendor/ yourself

This is the part that matters, because the failure is silent.

Composer's generated autoloader computes `$baseDir` from `__DIR__` inside
`vendor/composer/`, and PHP resolves symlinks when evaluating `__DIR__`. If `vendor/`
— or just `vendor/composer/` — is a symlink into the primary checkout, `$baseDir`
becomes the **primary checkout**. Every `Capell\*` class then loads from the primary
tree. Your worktree's edits are invisible, and the suite passes while exercising
completely different code. On a shared checkout it is worse: you are testing another
session's uncommitted work.

`scripts/init-worktree.sh` avoids this by keeping the parts that determine `$baseDir`
real, and sharing only what is safe to share:

| Path                                                                                                       | Treatment | Why                                                                                           |
| ---------------------------------------------------------------------------------------------------------- | --------- | --------------------------------------------------------------------------------------------- |
| `vendor/composer/`, `vendor/autoload.php`                                                                  | real copy | `$baseDir` must resolve to this worktree                                                      |
| `vendor/bin/`                                                                                              | real copy | binary proxies must point at this worktree's autoloader                                       |
| `vendor/<vendor>/<package>/`                                                                               | symlink   | third-party code, identical in both trees                                                     |
| `pestphp/pest`, `phpunit/phpunit`, `laravel/pint`, `phpstan/phpstan`, `rector/rector`, `brianium/paratest` | real copy | their bin scripts walk `__DIR__` upward to find an autoloader, and would find the primary one |

### Known limitation — verify before trusting a full-suite run

The provisioner regenerates the copied autoloader from the worktree's manifest,
including development source mappings. The primary vendor metadata may predate
a new fixture namespace or package source path. Copying it without regeneration
can pass a single Core-class probe while leaving those newer classes unavailable.
Autoload regeneration disables Composer scripts and plugins and writes only the
owned loader; it does not install or update shared dependencies.

The script fixes the common failure, not every one. Because third-party packages are
symlinked, any code that walks upward from inside `vendor/` — `dirname(__DIR__, N)`, or a
Composer `ClassLoader` re-registered at runtime — resolves into the **primary checkout**,
and can pull `Capell\*` classes from there.

Most suites are unaffected. The core `Unit/Support` suite is not: running it before
another suite has been observed to load `Capell\Admin\*` from the primary tree, so the
later tests silently exercise the wrong code. Confirmed 2026-07-23 by printing
`ReflectionClass::getFileName()` mid-run.

Practical rule: use the script for fast, targeted runs, and check that the classes you
changed resolve inside the worktree before believing a result. If you need an
authoritative full-suite run, do a real `composer install` in the worktree.

Pest's architecture presets also follow symlinks before excluding the checkout's
`vendor/` directory, so external dependency roots can be mistaken for application
source. This can fatally load optional integrations such as Symfony Serializer's
`SerializerPass` without its optional `PriorityTaggedServiceTrait`. Use an independent
vendor tree for authoritative architecture gates. A process-only bootstrap restoring
the intended vendor exclusion is a diagnostic workaround, not clean-vendor evidence.

Do not run `composer test` in a hybrid worktree. Its `clear` step runs
`testbench package:purge-skeleton` and removes
`vendor/orchestra/testbench-core/laravel/{vendor,database/migrations}`;
`testbench-core` is a symlink there, so the removal mutates the primary checkout's
skeleton. Run `vendor/bin/pest <paths>` instead. `scripts/check-vendor-integrity.php`
(run first by `clear`) refuses a full suite on a hybrid vendor for the same reason.

### Verifying

Verify before trusting a test result:

```bash
php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("Capell\Core\Enums\ImageSourceType"))->getFileName(), PHP_EOL;'
```

The path must be inside your worktree. If it points at the primary checkout, delete
`vendor/` and start again — every green test you have seen is meaningless.

### Never mutate dependencies from a hybrid worktree

Composer scripts such as `composer test:unit` are safe. Dependency-mutating commands
such as `install`, `require`, `update`, and `remove` are not: the hybrid `vendor/`
contains symlinks into the primary checkout. Manage dependencies in the primary
checkout, then re-run `scripts/init-worktree.sh --host-only --force` in each owned
host worktree. The force option replaces that worktree's vendor layout.

## Node dependencies

`scripts/init-worktree.sh` gives the worktree its own `node_modules/` as well as its
own `vendor/`. It needs one: `composer preflight` runs prettier and eslint, so without
`node_modules/` the whole preflight exits before a single PHP stage runs, and the error
names npm rather than the real cause.

Unlike `vendor/`, this is not shared. The script clones the directory copy-on-write,
which on APFS shares the underlying blocks with the primary checkout until something
writes — about a second, and almost no disk. On a filesystem without clone support it
falls back to `npm ci`. An existing `node_modules/` is left alone.

### npm is safe here, unlike composer

The warning above about dependency-mutating Composer commands does not apply to npm.
Because `node_modules/` is a real directory rather than a symlink, `npm install` and
`npm ci` in a worktree cannot reach the primary checkout.

That isolation is the reason for the clone. `npm ci` deletes `node_modules/` before it
installs, so had the directory been symlinked, one `npm ci` in one worktree would have
wiped the primary checkout's copy out from under every other worktree and session using
it.

## Running tests in a worktree

In a Docker worktree, run them through the wrapper, which supplies the testing
environment the package database guard requires:

```bash
./capell pest packages/frontend/tests/Feature/SomeTest.php --configuration=phpunit.xml --compact
```

`./capell exec vendor/bin/pest ...` skips that environment and fails with
`Refusing to run Capell package Pest tests against database [capell_4]`.

Before the first test or preflight in a fresh worktree, install its dependencies:

```bash
./capell up
./capell composer install
```

The wrapper checks for `vendor/bin/pest` before test and preflight commands and
fails with this bootstrap instruction when it is absent. Never symlink
`vendor/` from another checkout: Composer's autoloader must resolve Capell
classes from the current worktree. Use `bash scripts/init-worktree.sh
--host-only` only when all tooling will run on the host.

On the host it is identical to the primary checkout, but note that this repo's tooling
needs an explicit memory limit — PHP's 128 MB default causes a fatal that looks like a broken setup:

```bash
composer test:unit
```

For a narrower run:

```bash
vendor/bin/pest --compact --configuration=phpunit.xml packages/core/tests/Unit
```

Do not pass `-d memory_limit` to Pest: `phpunit.xml` sets the limit through `<ini>`, which
overrides the command-line flag in every process (see [CI](ci.md#pest-memory-limit-and-parallel-flags)).
PHPStan has no such file, so it still needs the flag:

```bash
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress packages/core/src
```

## Committing from a worktree

Each worktree has its own index. Path-scoped commits are still useful when more than one
task is active in the same worktree:

- Commit by pathspec — `git commit -F - -- path/one path/two`. Plain `git add X && git commit`
  commits the **whole index**, including unrelated files staged in that checkout.
- For a new file, `git add -N <path>` first, or the pathspec commit errors.
- Verify immediately with `git show --stat`: the file count must match your pathspec.
- Verify the intended slice, inspect the staged paths and commit only that slice.
  Isolation protects you from other sessions; it does not replace verification.

### Shared checkouts

Several sessions may share one checkout, and the working tree and index are then shared
state. Another session's `git checkout`, `reset --hard` or merge silently reverts
uncommitted edits, so:

- Run `git branch --show-current` immediately before every commit. The checkout can be
  switched to another feature branch mid-session; note which branch a commit landed on.
- Verify each intended slice and inspect the index before committing it. Prefer an
  isolated worktree when another session is writing.
- Install the snapshot hooks once from the primary checkout with
  `python3 scripts/hooks/install-pre-commit.py`. Linked worktrees share that hook; the
  installer refuses to change shared hooks from a linked worktree. The hook checks the
  active commit index in a private snapshot; pre-push checks each pushed range against
  its own target-commit snapshot. Neither hook stashes the original checkout.
  Existing legacy hooks are copied and run in that snapshot; review any legacy hook
  first because arbitrary code can still write absolute paths outside it. If a formatter
  changes the snapshot, the commit stops: coordinate with other writers before
  formatting, then review the result and re-stage.
- The commit snapshot refuses merge commits, unmerged or sparse indexes, staged symlinks,
  submodules, clean/smudge filters such as LFS, checkout encoding or ident substitutions,
  unspecified text line endings and local `info/attributes`; it also requires the
  corresponding host dependencies for PHP or frontend checks. The installed Composer
  and npm graphs must agree with the snapshot manifests and lock files. Dependency
  trees are copied and checked for concurrent changes; their temporary disk demand
  depends on the installed graph. Shared dependency symlinks and path dependencies
  that cannot be represented inside the snapshot are refused; provision a coherent,
  supported dependency graph before retrying. It does not bypass checks or change
  Git configuration.
  The known hook configuration selects the needed dependency trees by staged file
  type; an existing legacy hook or an unfamiliar hook configuration or local hook
  implementation conservatively checks both. When changing the hook set or its
  scripts, review and refresh the fingerprints in `scripts/hooks/pre-commit.py` to
  restore selective checks. Dependencies of the listed scripts need the same review.
- Both snapshots refuse symlinks that escape the snapshot. The push snapshot also
  refuses submodules and the same unsupported checkout attributes. For origin
  branches, PHP analysis requires the pushed commit to be checked out and refuses
  uncommitted edits to changed PHP files.
- Reinstall the snapshot entrypoints after running `pre-commit install` for either
  hook type: the framework's entrypoint reintroduces its worktree stash.
  A missing hook in a newly cloned checkout is not protected until installation.
  Coordinate installation with every linked worktree: the shared launcher loads the
  entrypoint from the invoking checkout, so older branches without these scripts
  cannot commit or push until they include the hook tooling.
- A commit that sweeps in another session's staged files can record an older copy of
  their work. Stop and preserve the commit and current files; inspect the impact and
  obtain the required approval before any reset or revert.

### Cleaning up

Remove scratch worktrees when they have served their purpose, and delete a merged branch
locally and on the remote in the same pass. Only remove what you created: a worktree
whose HEAD matches no remote branch (`git branch -r --contains <sha>`) holds unpushed
work, and uncommitted files count too.

### Auditing branches in a squash-merge repository

Pull requests are squash-merged, so `git branch --merged` and
`git merge-base --is-ancestor` report merged branches as unmerged: branch commits never
become ancestors of `main`. Instead:

1. No-op merge test: `git merge-tree --write-tree origin/main <branch>`. If the resulting
   tree equals `git rev-parse origin/main^{tree}`, the branch is fully merged.
2. Direction test: if `merge-tree` reports conflicts, check
   `git diff origin/main <branch> -- <file>`. If the branch's changes appear as removals,
   the branch is behind `main`, not ahead.
3. Feature-presence test: confirm the feature exists on `main` (list the directory,
   search for the identifier) instead of trusting history.
4. Ground truth for "was a PR merged": `gh pr list --state all --json headRefName,state`.

To rebase a stale branch across the squash gap, use the end-state rule. For each
conflicting file, if `git diff origin/main <branch-tip> -- <file>` is empty, taking
`main`'s side at every intermediate rebase step cannot change the final tree, so it is
safe to auto-resolve. Only files with a non-empty end-state diff need judgement; for
those, search both trees for the APIs each side references (`git grep`): the branch is
usually the stale side.

## Further reading

- [Development index](index.md)
- [Local development](local-development.md)
- [CI](ci.md)

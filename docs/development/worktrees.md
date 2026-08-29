# Git worktrees

Use a git worktree when more than one person or agent session is working in this repo
at once. Each linked worktree has its own checked-out files, branch, `HEAD`, and index,
while the repository objects and local branch refs remain shared. That isolates
uncommitted edits and staging, but deleting or moving a shared branch ref still affects
every checkout.

Worktrees live outside the checkout, in the shared `.capell-wt` root beside the
repositories — `../.capell-wt` relative to this repository. Never create one
inside the repository — an in-tree worktree is deleted by `git clean -fdx` run
in the primary checkout, and every tree-wide scan then walks two copies of the
source.

```bash
git worktree add ../.capell-wt/core-my-feature -b feature/my-feature
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

| Path | Treatment | Why |
| --- | --- | --- |
| `vendor/composer/`, `vendor/autoload.php` | real copy | `$baseDir` must resolve to this worktree |
| `vendor/bin/` | real copy | binary proxies must point at this worktree's autoloader |
| `vendor/<vendor>/<package>/` | symlink | third-party code, identical in both trees |
| `pestphp/pest`, `phpunit/phpunit`, `laravel/pint`, `phpstan/phpstan`, `rector/rector`, `brianium/paratest` | real copy | their bin scripts walk `__DIR__` upward to find an autoloader, and would find the primary one |

### Known limitation — verify before trusting a full-suite run

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
checkout, then re-run `scripts/init-worktree.sh --force` in each worktree.

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
php -d memory_limit=1G vendor/bin/pest --compact --configuration=phpunit.xml packages/core/tests/Unit
```

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
- Commit each slice as its edits land rather than batching until the end. Isolation
  protects you from other sessions; frequent commits protect you from everything else.

## Further reading

- [Development index](index.md)
- [Local development](local-development.md)
- [CI](ci.md)

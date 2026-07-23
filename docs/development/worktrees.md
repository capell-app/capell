# Git worktrees

Use a git worktree when more than one person or agent session is working in this repo
at once. The working tree is shared mutable state with no lock: another session's
`git checkout`, `git reset --hard`, or branch switch silently reverts your uncommitted
edits and makes test runs fail or hang. Commits survive that; uncommitted work does not.

```bash
git worktree add ../capell-4-my-feature -b feature/my-feature
```

## Give the worktree a vendor/ in seconds

A fresh worktree has no `vendor/`, and a full `composer install` costs several minutes
and about 1.5 GB. Run this instead:

```bash
bash scripts/init-worktree.sh
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

If you set this up by hand anyway, verify it before trusting a single test result:

```bash
php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("Capell\Core\Enums\Concerns\HasEnumOptions"))->getFileName(), PHP_EOL;'
```

The path must be inside your worktree. If it points at the primary checkout, delete
`vendor/` and start again — every green test you have seen is meaningless.

### Never run Composer from a worktree

`composer install`, `require`, `update`, and `remove` would write through the symlinks
into the primary checkout's packages. Manage dependencies in the primary checkout, then
re-run `scripts/init-worktree.sh --force` in each worktree.

## Running tests in a worktree

Identical to the primary checkout, but note that this repo's tooling needs an explicit
memory limit — PHP's 128 MB default causes a fatal that looks like a broken setup:

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

`--parallel` is unavailable in a worktree built this way; runs are serial. The full core
unit suite takes roughly three minutes.

## Committing from a worktree

The shared-checkout rules still apply, because the index is shared:

- Commit by pathspec — `git commit -F - -- path/one path/two`. Plain `git add X && git commit`
  commits the **whole index**, so a concurrent session's staged files ride along in your
  commit, at whatever revision they staged, which can silently revert their work.
- For a new file, `git add -N <path>` first, or the pathspec commit errors.
- Verify immediately with `git show --stat`: the file count must match your pathspec.
- Commit each slice as its edits land rather than batching until the end. Isolation
  protects you from other sessions; frequent commits protect you from everything else.

## Further reading

- [Development index](index.md)
- [Local development](local-development.md)
- [CI](ci.md)

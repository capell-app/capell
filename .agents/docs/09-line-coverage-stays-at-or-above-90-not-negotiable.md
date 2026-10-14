## Line coverage stays at or above 90% — not negotiable

`scripts/merge-clover-coverage.php` fails the coverage job below
`REQUIRED_COVERAGE_PERCENTAGE` (90). Never lower it, and never delete or skip
tests without replacing the lines they covered. To slim a slow or coupled
test, rewrite it, don't delete it. Fold near-duplicate cases into a `->with()`
dataset, and replace hand-built mocks of collaborators with the shared fakes:

- `Capell\Tests\Support\Fakes\FakeProcessFactory` for anything resolved
  through `ProcessFactoryInterface`. Use `bind()`, `push()` scripted results
  and `expect()` commands, then assert `getEnv()`/`getTimeout()` on the
  recorded `FakeProcess`. Do not Mockery-mock a Symfony `Process`; its
  `run()`/`mustRun()`/`wait()` are `@final`, so a mock or subclass that
  overrides them is invisible to PHPStan and drifts from real behaviour.
- `Capell\Tests\Support\Fakes\FakeConsoleKernel` for Artisan calls, in place
  of `Mockery::mock(ConsoleKernel::class)`.

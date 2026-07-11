---
applyTo: '**/*Test.php,tests/**/*.php,packages/*/tests/**/*.php'
---

# Pest Tests

- Use Pest, not new PHPUnit test classes.
- Test Actions through `ActionClass::run()` unless the test is specifically about an alternate entrypoint.
- Prefer real data for cross-package behavior, rendering, settings, migrations, and extension points. Mock only external boundaries.
- Start narrow: `vendor/bin/pest packages/{package}/tests --configuration=phpunit.xml --filter=<name>`.
- Add both expected behavior and fallback/denied/edge-path assertions for shared contracts, frontend rendering, cache behavior, package APIs, and admin workflows.
- Keep test names behavior-focused and setup clear enough for the next agent to continue without chat history.

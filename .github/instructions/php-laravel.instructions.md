---
applyTo: '**/*.php'
---

# PHP and Laravel

- Add `declare(strict_types=1);` to every PHP source file.
- Use explicit parameter and return types on methods and closures. Use precise PHPDoc only for generics, array shapes, or external contracts PHP cannot express.
- Avoid one-letter variables in production code, tests, closures, migrations, and examples. Use names like `$query`, `$record`, `$payload`, `$layout`, `$site`.
- Prefer full closures over arrow functions when a parameter or return type improves clarity.
- Use Actions for business operations and Data objects for structured boundaries. Do not move domain logic into controllers, resources, widgets, commands, or Blade.
- Use enums for persisted or shared contract strings once a value crosses layers.
- Keep comments sparse. Explain rationale, migration, security, performance, or non-obvious invariants only.
- Do not add test-only branches such as `app()->runningUnitTests()` to production code.

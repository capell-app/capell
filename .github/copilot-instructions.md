# Copilot Instructions for Capell Core

Keep this always-on context short. Load `AGENTS.md` for deeper repository policy only when the task needs it.

- This is the Capell 4 core monorepo.
- Start from the package, class, test, or command named in the request. Avoid broad scans when the target is known.
- Follow existing sibling patterns before adding new abstractions, providers, commands, resources, or tests.
- Use strict typed PHP, PSR-12, descriptive names, explicit method and closure types, and `declare(strict_types=1);`.
- Keep domain behavior in Actions/Data/services. Keep controllers, Filament pages/resources, Livewire components, and commands thin.
- Use Capell extension points instead of reaching across package internals.
- Core must not import Admin, Frontend, Marketplace, or companion package classes unless an established boundary already allows it.
- User-facing strings go through package translations.
- Test meaningful behavior with the narrowest useful Pest command first, then broaden only when the change touches shared contracts.
- Do not edit generated screenshots, caches, `vendor`, `node_modules`, storage, or build output unless the task explicitly requires it.

Path-specific rules live in `.github/instructions/*.instructions.md`; check the response references to confirm Copilot loaded the relevant files.

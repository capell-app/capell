# Changelog

## v2.0.85 - 2026-07-09

### Changed

- Polished the publish status panel, event-sourcing flow, theme registry, and documentation diagrams.
- Hardened the Capell upgrade pipeline, including report-only dry runs and Docker harness startup.
- Reduced CI shard time and made screenshot generation more reliable.

### Fixed

- Corrected nested Blocks-to-HTML conversion, error-page handling, cache invalidation, and Filament 5.7 compatibility.

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.84...v2.0.85

## v2.0.84 - 2026-07-05

### Changed

- Simplified theme rendering and added ordered generic sections for more complete theme previews.

### Fixed

- Added translated skip-to-content links to frontend themes.

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.83...v2.0.84

## v2.0.83 - 2026-07-04

### Fixed

- Decoupled marketplace Admin and Core constraints from the marketplace package's own version so compatible package sets resolve correctly.

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.82...v2.0.83

## v2.0.82 - 2026-07-04

### Added

- Added public Layout Builder graph rendering and the frontend floating UI runtime.
- Added ordered generic Theme Studio sections for complete theme demonstrations.

### Fixed

- Prepared public render data before Blade responses, prevented frontend routes from matching asset URLs, and guarded fresh-install mail logos.

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.81...v2.0.82

## v2.0.81 - 2026-07-02

### Fixed

- Allowed public `data-capell-insights-*` runtime attributes while keeping the public render scanner strict.
- Corrected off-by-one errors in theme key, namespace, and package short-name derivation.

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.80...v2.0.81

## v2.0.33 - 2026-06-03

### What's Changed

- Feature/site logo by @howdu in https://github.com/capell-app/capell/pull/155

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.32...v2.0.33

## v2.0.32 - 2026-06-02

### What's Changed

- Configure site logo in email by @howdu in https://github.com/capell-app/capell/pull/152
- Feature/site logo by @howdu in https://github.com/capell-app/capell/pull/154
- Fix edit page sidebar class assertions by @howdu in https://github.com/capell-app/capell/pull/153

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.31...v2.0.32

## v2.0.31 - 2026-06-01

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.30...v2.0.31

## v2.0.30 - 2026-06-01

### What's Changed

- Simplify the dashboard widgets by @howdu in https://github.com/capell-app/capell/pull/146
- Hotfix/dashboard widgets by @howdu in https://github.com/capell-app/capell/pull/147
- Hotfix/dashboard widgets by @howdu in https://github.com/capell-app/capell/pull/149
- chore(deps): bump swiper from 11.2.10 to 12.1.2 by @dependabot[bot] in https://github.com/capell-app/capell/pull/148
- Use deterministic activity details screenshot scenario by @howdu in https://github.com/capell-app/capell/pull/151
- Fixes by @howdu in https://github.com/capell-app/capell/pull/150

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.29...v2.0.30

## v2.0.29 - 2026-05-29

### What's Changed

- chore: fix preflight failures by @howdu in https://github.com/capell-app/capell/pull/133
- chore(deps): bump postcss from 8.5.8 to 8.5.15 in /packages/frontend by @dependabot[bot] in https://github.com/capell-app/capell/pull/135
- chore(deps-dev): bump vite from 8.0.3 to 8.0.14 in /packages/frontend by @dependabot[bot] in https://github.com/capell-app/capell/pull/139
- chore(deps-dev): bump ajv from 6.12.6 to 6.15.0 by @dependabot[bot] in https://github.com/capell-app/capell/pull/140
- Feat/marketplace premium install flow v2 hardening by @howdu in https://github.com/capell-app/capell/pull/134
- Feat/theme library editor rebuild by @howdu in https://github.com/capell-app/capell/pull/141
- chore(deps-dev): bump brace-expansion from 1.1.12 to 1.1.15 by @dependabot[bot] in https://github.com/capell-app/capell/pull/138
- chore(deps-dev): bump flatted from 3.3.3 to 3.4.2 by @dependabot[bot] in https://github.com/capell-app/capell/pull/136
- chore(deps-dev): bump minimatch from 3.1.2 to 3.1.5 by @dependabot[bot] in https://github.com/capell-app/capell/pull/137
- chore(deps-dev): bump symfony/yaml from 7.4.11 to 7.4.13 by @dependabot[bot] in https://github.com/capell-app/capell/pull/142
- chore(deps): bump symfony/polyfill-intl-idn from 1.37.0 to 1.38.1 by @dependabot[bot] in https://github.com/capell-app/capell/pull/143
- [codex] Add recoverable site deletion by @howdu in https://github.com/capell-app/capell/pull/144
- Automate core screenshot workflows by @howdu in https://github.com/capell-app/capell/pull/145

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.28...v2.0.29

## v2.0.27 - 2026-05-20

### What's Changed

- chore(deps): bump danharrin/monorepo-split-github-action from 2.4.4 to 2.4.5 by @dependabot[bot] in https://github.com/capell-app/capell/pull/132

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.26...v2.0.27

## v2.0.26 - 2026-05-18

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.25...v2.0.26

## v2.0.24 - 2026-05-05

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.23...v2.0.24

## v2.0.23 - 2026-05-05

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.22...v2.0.23

## v2.0.22 - 2026-05-04

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.21...v2.0.22

## v2.0.21 - 2026-05-03

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.20...v2.0.21

## v2.0.20 - 2026-05-03

### What's Changed

- Feat/capell permission sync catalog by @howdu in https://github.com/capell-app/capell/pull/129

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.19...v2.0.20

## v2.0.19 - 2026-05-02

### What's Changed

- fix: remove theme utilities from frontend bundle by @capell-app in https://github.com/capell-app/capell/pull/128

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.18...v2.0.19

## v2.0.17 - 2026-04-22

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.16...v2.0.17

## v2.0.16 - 2026-04-22

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.15...v2.0.16

## v2.0.14 - 2026-04-13

### What's Changed

<<<<<<< HEAD

- # feature/main by @howdu in https://github.com/capell-app/capell/pull/50

* feature/4.x by @howdu in https://github.com/capell-app/capell/pull/50
    > > > > > > > d249c1b29 (fix: require meaningful release notes)

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.13...v2.0.14

## v2.0.13 - 2026-04-04

### What's Changed

- Fix driftingly/rector-laravel compatibility by @capell-app in https://github.com/capell-app/capell/pull/49

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.12...v2.0.13

## v2.0.12 - 2026-04-04

### What's Changed

<<<<<<< HEAD

- # Feature/main by @capell-app in https://github.com/capell-app/capell/pull/48

* Feature/4.x by @capell-app in https://github.com/capell-app/capell/pull/48
    > > > > > > > d249c1b29 (fix: require meaningful release notes)

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.11...v2.0.12

## v2.0.11 - 2026-04-01

### What's Changed

<<<<<<< HEAD

- Bump dependabot/fetch-metadata from 2.5.0 to 3.0.0 by @dependabot[bot] in https://github.com/capell-app/capell/pull/47
- Bump actions/upload-artifact from 6 to 7 by @dependabot[bot] in https://github.com/capell-app/capell/pull/45
- # Feature/main by @howdu in https://github.com/capell-app/capell/pull/46

* Bump dependabot/fetch-metadata from 2.5.0 to 3.0.0 by @dependabot[bot] in https://github.com/capell-app/capell/pull/47
* Bump actions/upload-artifact from 6 to 7 by @dependabot[bot] in https://github.com/capell-app/capell/pull/45
* Feature/4.x by @howdu in https://github.com/capell-app/capell/pull/46
    > > > > > > > d249c1b29 (fix: require meaningful release notes)

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.10...v2.0.11

## v2.0.10 - 2026-02-20

### What's Changed

<<<<<<< HEAD

- Add tests by @howdu in https://github.com/capell-app/capell/pull/35
- Bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/capell-app/capell/pull/36
- Feature/main by @howdu in https://github.com/capell-app/capell/pull/37
- Bump danharrin/monorepo-split-github-action from 2.4.0 to 2.4.2 by @dependabot[bot] in https://github.com/capell-app/capell/pull/39
- Feature/main by @howdu in https://github.com/capell-app/capell/pull/41
- Bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/capell-app/capell/pull/40
- Bump actions/upload-artifact from 4 to 6 by @dependabot[bot] in https://github.com/capell-app/capell/pull/38
- Bump danharrin/monorepo-split-github-action from 2.4.2 to 2.4.4 by @dependabot[bot] in https://github.com/capell-app/capell/pull/42
- Feature/main by @howdu in https://github.com/capell-app/capell/pull/43
- # Feature/main by @howdu in https://github.com/capell-app/capell/pull/44

* Add tests by @howdu in https://github.com/capell-app/capell/pull/35
* Bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/capell-app/capell/pull/36
* Feature/4.x by @howdu in https://github.com/capell-app/capell/pull/37
* Bump danharrin/monorepo-split-github-action from 2.4.0 to 2.4.2 by @dependabot[bot] in https://github.com/capell-app/capell/pull/39
* Feature/4.x by @howdu in https://github.com/capell-app/capell/pull/41
* Bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/capell-app/capell/pull/40
* Bump actions/upload-artifact from 4 to 6 by @dependabot[bot] in https://github.com/capell-app/capell/pull/38
* Bump danharrin/monorepo-split-github-action from 2.4.2 to 2.4.4 by @dependabot[bot] in https://github.com/capell-app/capell/pull/42
* Feature/4.x by @howdu in https://github.com/capell-app/capell/pull/43
* Feature/4.x by @howdu in https://github.com/capell-app/capell/pull/44
    > > > > > > > d249c1b29 (fix: require meaningful release notes)

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.9...v2.0.10

## v2.0.9 - 2025-12-31

### What's Changed

- Feature/main by @howdu in https://github.com/capell-app/capell/pull/34
- Bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/capell-app/capell/pull/33
- Bump actions/setup-node from 4 to 6 by @dependabot[bot] in https://github.com/capell-app/capell/pull/32
- Bump actions/cache from 4 to 5 by @dependabot[bot] in https://github.com/capell-app/capell/pull/30

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.8...v2.0.9

## v2.0.8 - 2025-12-23

### What's Changed

- Feature/main by @howdu in https://github.com/capell-app/capell/pull/31

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.7...v2.0.8

## v2.0.7 - 2025-12-12

### What's Changed

- Improve documentation, config, and Blade component handling by @howdu in https://github.com/capell-app/capell/pull/29

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.6...v2.0.7

## v2.0.6 - 2025-12-07

### What's Changed

- Enforce Strict Package Independence, Expand Coding and Comment Standards, and Standardise Build/Test Configuration for Consistency and Maintainability by @howdu in https://github.com/capell-app/capell/pull/28

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.5...v2.0.6

## v2.0.5 - 2025-12-01

### What's Changed

- Feature/main by @howdu in https://github.com/capell-app/capell/pull/26
- Feature/main by @howdu in https://github.com/capell-app/capell/pull/27
- Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/capell-app/capell/pull/25

**Full Changelog**: https://github.com/capell-app/capell/compare/v2.0.4...v2.0.5

## v1.0.5 - 2025-07-13

### What's Changed

- Feature/update by @capell-app in https://github.com/capell-app/capell/pull/8

**Full Changelog**: https://github.com/capell-app/capell/compare/v1.0.4...v1.0.5

## v1.0.4 - 2025-07-11

### What's Changed

- Feature/update by @howdu in https://github.com/capell-app/capell/pull/7

**Full Changelog**: https://github.com/capell-app/capell/compare/v.1.0.3...v1.0.4

## v1.0.2 - 2025-06-20

### What's Changed

- Bump stefanzweifel/git-auto-commit-action from 5 to 6 by @dependabot in https://github.com/capell-app/capell/pull/6
- Feature/separation by @howdu in https://github.com/capell-app/capell/pull/5

### New Contributors

- @dependabot made their first contribution in https://github.com/capell-app/capell/pull/6

**Full Changelog**: https://github.com/capell-app/capell/compare/v.1.0.1...v1.0.2

## v.1.0.1 - 2025-06-10

**Full Changelog**: https://github.com/capell-app/capell/compare/v1.0.0...v.1.0.1

## v1.0.0 - 2025-06-09

### What's Changed

- Fix tests by @howdu in https://github.com/capell-app/capell/pull/1
- Hotfix/rename by @howdu in https://github.com/capell-app/capell/pull/2
- Hotfix/rename by @howdu in https://github.com/capell-app/capell/pull/3
- Hotfix/rename by @capell-app in https://github.com/capell-app/capell/pull/4

### New Contributors

- @howdu made their first contribution in https://github.com/capell-app/capell/pull/1
- @capell-app made their first contribution in https://github.com/capell-app/capell/pull/4

**Full Changelog**: https://github.com/capell-app/capell/commits/v1.0.0

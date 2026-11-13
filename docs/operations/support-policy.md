# Capell support window and LTS policy

Use this policy to plan a Foundation upgrade or a client maintenance agreement. The current public Foundation line is **1.x**. It covers Core, Admin, Frontend, Installer and Marketplace, and the version-aligned `capell-app/capell` aggregate.

The commitments below come from the repository's [security policy](../../SECURITY.md), [changelog](../../CHANGELOG.md), Composer manifests and [extension API versioning policy](../packages/extension-api-versioning.md). Where those sources do not establish a fact, this page says so and states no date or window.

## Supported Foundation lines and dates

| Line           | Status                                                                   | Release date evidence                                                                                          | Security coverage                                                                                                              | Bug-fix end date                                             |
| -------------- | ------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------ |
| Foundation 1.x | Current public line                                                      | The changelog records the 1.0.0 public re-baseline on 11 July 2026; later minors need their own release dates. | Each 1.x minor receives at least 24 months from its release date. The latest 1.x minor remains supported while 1.x is current. | No bug-fix window or end date is established for 1.x minors. |
| 2.x (planned)  | Compatibility planning exists; no supported release is established here. | No release of a next major has been published, so it has no release date.                                      | No security window is established until a release exists.                                                                      | No bug-fix window is established until a release exists.     |

After the next major release, the latest 1.x minor receives security fixes for at least 12 months. The 24-month commitment for each minor also remains in force. Upgrade all installed Foundation packages together to the same supported release before requesting a fix, as required by the security policy.

Release dates for later 1.x minors are recorded in the changelog. The end-of-life date for the latest 1.x minor cannot be stated until the next major is released.

The 11 July 2026 date identifies the public re-baseline. It does not establish the publication date of a later package version or the deployment date of any application. Use the affected package's release notes and tag when identifying a fix. An `Unreleased` changelog entry does not establish that a fix has shipped.

## LTS designation and optional packages

The security commitment above applies to Foundation 1.x. The repository does not establish a separate long-term support (LTS) designation or a longer LTS bug-fix window.

No Foundation line is designated LTS yet; this page will name one when it is. Until then, do not describe a line as LTS.

Optional packages have their own versions and maturity. A Foundation support window does not establish the support lifetime or compatibility of every optional package.

Each optional package records its own compatibility range and release dates in its release notes. This page does not state support windows for optional packages.

## One public version line

The [changelog](../../CHANGELOG.md#v100---2026-07-11) identifies 1.x as the public line after the re-baseline. The five split-package Composer manifests alias `dev-main` to `1.x-dev`, and the [aggregate manifest](../../composer.json) uses the same alias. A development alias is a Composer compatibility label, not a tagged stable release.

Historical `v2.0.x` tags may remain in local clones. They do not establish a current 2.x support promise. The [next-major compatibility decision](../packages/core-next-major-compatibility-decision.json) is planning evidence; it is not a release announcement.

This page does not name a latest published version. Local repository refs do not show what Packagist has indexed.

The extension API version is separate from the Foundation release version. A package's `capellApiVersion` constraint must match the extension API it has tested; matching the constraint alone does not prove behavioural compatibility.

## PHP, Laravel and Filament compatibility

The aggregate [Composer manifest](../../composer.json) currently requires PHP `^8.4`, Laravel `^13.0` and Filament `^5.7.6`. The [installation requirements](../getting-started/install.md#requirements) describe the required runtime and extensions. The [Core support policy](../../packages/core/README.md#requirements-and-support-policy) covers the declared dependency ranges and says that an upstream dependency reaching end of life may require a dependency upgrade to receive a safe fix. These constraints do not promise adoption of an upstream release on a particular date.

Capell's own support dates are not derived from upstream PHP, Laravel or Filament end dates.

## Upgrade and deprecation promise

The [extension API policy](../packages/extension-api-versioning.md#deprecation-and-removal) establishes the stable-surface commitment:

- Deprecate a stable API first, name its replacement and list it in a minor release's notes. It keeps working unchanged.
- Retain it for at least one minor release; do not remove it in the release that deprecates it.
- Remove it only in the next major release, with migration guidance in that release's notes.
- Do not introduce a behavioural or signature break to a stable surface within a major line when it cannot follow that process. Experimental and internal surfaces are outside this commitment.

Capell has not committed to a calendar notice period beyond the one-minor-release minimum.

Before changing production, back up the database, media and application configuration, review the target release notes and test the upgrade in a staging application. Follow [Upgrading Capell](upgrading.md) for package updates, migration planning, durable upgrade records, cache refresh and post-upgrade checks. Run the upgrade dry run before applying changes and test optional packages against the target release.

The upgrade tooling records work and reports compatibility findings. It does not establish that a backup exists or that every migration is reversible. Follow [Backups and restore](backups.md) for disaster recovery; Page history and upgrade-step rollback have narrower scopes.

## Related guides

- [Upgrading Capell](upgrading.md)
- [Extension API versioning](../packages/extension-api-versioning.md)
- [Security reporting](../../SECURITY.md#reporting-a-vulnerability)

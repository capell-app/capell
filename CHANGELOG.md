# Changelog

All notable changes to Capell are documented in this file. Release notes for
the 1.x line are published as [GitHub Releases](https://github.com/capell-app/capell/releases)
on this repository; each published release is appended here automatically by
the `Update Changelog` workflow.

## Unreleased

### Added

- Added fail-closed Project Build target compatibility verification and a consumer-owned package installation boundary for applying signed manifests in exact release order.

### Fixed

- Emitted declared package-specific theme identity tokens while rejecting malformed names, reserved core-property collisions, and values outside their declared vocabulary.
- Published generated theme-token CSS atomically and restored genuine database selection in the full CI matrix.

## v1.0.0 - 2026-07-11

Public re-baseline. The repository history was consolidated into a fresh 1.0.0
baseline on 2026-07-11. Releases continue from this baseline as root `v1.0.x`
tags, with per-package tags for the split packages (core, admin, frontend,
installer, marketplace).

## Pre-reset releases (2025-06-09 to 2026-07-09)

Changelog entries for the pre-reset `v2.0.x` and original `v1.0.x` release
lines were removed as part of the 2026-07-11 re-baseline: those tags no longer
exist on this repository, so their version numbers and GitHub compare links no
longer resolve. The work they described is included in the consolidated 1.0.0
baseline above.

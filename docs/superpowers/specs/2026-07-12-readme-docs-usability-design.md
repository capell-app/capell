# README And Documentation Usability Design

**Date:** 2026-07-12
**Status:** Approved

## Goal

Make Capell's repository documentation easier to scan, trust, and navigate for two primary audiences:

- evaluators and Laravel developers installing or adopting Capell;
- maintainers and extension developers working with Capell's package boundaries and extension points.

The pass should help each reader reach the next useful action quickly without turning the documentation into a larger or more repetitive body of prose.

## Documentation Shape

Use progressive disclosure across four layers:

1. The root `README.md` explains what Capell is, who it suits, the shortest verified install path, the repository package boundaries, and the most useful next destinations.
2. `docs/README.md` routes readers by job: evaluate or install, build a site, build an extension, operate Capell, or maintain the monorepo.
3. Section indexes provide concise, task-led navigation within each documentation area.
4. Package READMEs stand alone in split repositories by documenting ownership, installation, configuration, runtime surfaces, focused verification, troubleshooting, and deeper package docs.

Leaf guides remain the source for detailed workflows. They should only change when the audit finds a concrete accuracy, navigation, formatting, or readability problem.

## Editorial Rules

- Lead with the reader's practical next action.
- Prefer short paragraphs, active voice, exact class and command names, and task-led link labels.
- Remove repeated positioning, duplicated policy, ceremonial introductions, and oversized navigation tables where they slow scanning.
- Keep package boundaries explicit so Core, Admin, Frontend, Installer, Marketplace, and optional packages are not presented as one undifferentiated product.
- Keep public frontend safety constraints visible where rendering or extensions are discussed.
- Preserve useful screenshots and diagrams, but do not use visuals as decoration between every heading.
- Do not add new pages when an existing page or index can carry the information clearly.

## Source Of Truth

Documentation claims must be checked against:

- root and package `composer.json` files;
- package providers, config files, routes, commands, Actions, and tests;
- `.env.example`, CI workflows, and repository scripts;
- current package-owned documentation and the docs ownership rules.

Commands, supported versions, environment variables, package names, URLs, and extension-point symbols must be copied from those sources rather than inferred from older prose.

## Scope

The implementation pass may update:

- `README.md`;
- `docs/README.md` and section indexes;
- the five foundation package READMEs;
- leaf docs with a verified problem;
- documentation-specific CI or coverage configuration already present in the working tree;
- small documentation validation tooling when the repository has no reliable equivalent.

The existing README badge additions, Codecov configuration, and coverage release workflow edits are part of this work and will be reviewed rather than treated as unrelated changes.

The pass will not redesign the documentation website, change product behaviour, introduce new dependencies without a demonstrated need, or rewrite accurate specialist guides solely for stylistic uniformity.

## Verification

Verification should be proportional and source-backed:

1. Check local Markdown links and image paths across the repository documentation.
2. Check Markdown structure for malformed tables, heading skips, and obvious formatting defects.
3. Validate YAML and JSON files changed by the pass.
4. Run the smallest repository checks relevant to documentation, workflow, or Composer metadata changes.
5. Review the final diff for duplicated content, accidental product claims, user changes outside scope, and generated noise.

## Completion

The work is complete when both audiences have an obvious route from the root README to their next task, package READMEs remain useful in split repositories, examples and links agree with the codebase, verification passes, and the focused documentation changes are committed.

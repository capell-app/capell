# README Quality Badges Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the generic README PHP Quality badge with compact, explicit badges for the quality and security checks the repository enforces.

**Architecture:** This is a documentation-only change to the root badge row. Static Shields.io badges express enforced configuration and link to the authoritative PHP Quality workflow; existing dynamic GitHub Actions and Codecov badges remain untouched for live status.

**Tech Stack:** Markdown, Shields.io badge endpoints, GitHub Actions workflow links.

---

## File structure

- Modify: `README.md:5-11` — replace the generic PHP Quality workflow badge with explicit PHPStan, Composer audit, Pint, and Rector badges while retaining the existing release, test, coverage, runtime, and documentation badges.
- Create: `docs/superpowers/plans/2026-07-15-readme-quality-badges.md` — record this implementation plan.

### Task 1: Make the quality checks explicit

**Files:**
- Modify: `README.md:5-11`

- [x] **Step 1: Replace the generic PHP Quality badge with explicit quality badges**

Replace this badge:

```markdown
[![PHP Quality](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
```

With these four badges, placed after the release badge and before the existing Tests badge:

```markdown
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-777BB4?style=flat-square&logo=php&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Composer Audit](https://img.shields.io/badge/Composer-audit-885630?style=flat-square&logo=composer&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Pint](https://img.shields.io/badge/Pint-checked-4F5B93?style=flat-square&logo=laravel&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Modernization](https://img.shields.io/badge/Modernization-enforced-6C5CE7?style=flat-square)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
```

The remaining existing badges must retain their current order and targets.

- [x] **Step 2: Validate root README conventions**

Run:

```bash
composer run check:root-docs
```

Expected: command exits with status `0` and reports the root documentation check passed.

- [x] **Step 3: Inspect the focused diff**

Run:

```bash
git diff --check && git diff -- README.md
```

Expected: no whitespace errors; diff changes only the badge row and preserves all non-badge README content.

- [x] **Step 4: Commit the completed documentation change**

Run:

```bash
git add README.md docs/superpowers/plans/2026-07-15-readme-quality-badges.md
git commit -m "docs: highlight repository quality checks"
```

Expected: one focused commit that contains the README badges and this implementation record.

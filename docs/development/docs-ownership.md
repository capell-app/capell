# Docs Ownership Rules


Use this checklist before adding or moving documentation.

## Placement

| Content                                              | Location                                         |
| ---------------------------------------------------- | ------------------------------------------------ |
| Host package contracts and extension points          | `capell-4/docs` or the owning host package docs  |
| Host package implementation details                  | `capell-4/packages/<package>/docs`               |
| Companion package features                           | `capell-packages-4/packages/<package>/docs`      |
| Marketing, brand, website copy, or sales positioning | `/Users/ben/Sites/packages/capell/docs`          |
| Historical plans, audits, or internal review notes   | outside public docs unless still actively useful |

## Rules

- Update an existing page before adding a new file.
- Every doc must be linked from `docs/README.md`, a section index, a package overview, or another doc.
- Do not keep "moved" stubs unless an external published URL needs a temporary redirect.
- Do not document optional package behavior as built-in host behavior.
- Keep package-specific install commands in the package that owns them.
- Prefer short task pages over broad narrative pages.
- End leaf docs with a small `Next` section when there is a natural follow-up.

## Review Questions

- Who is the reader: installer, package author, operator, maintainer, or editor?
- What is the next action after reading?
- Is the command, class, config key, or package name current?
- Does this duplicate another page?
- Would this be better in a package README?

## Next

- [Docs route map](../README.md)
- [Host, package, or app code](package-boundaries.md)
- [Package authoring](../packages/README.md)

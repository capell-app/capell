# Installer Package Instructions

- The installer coordinates package-owned install and setup behaviour; it should not reimplement that behaviour.
- Keep interactive prompts, non-interactive options, and persisted install plans aligned and deterministic.
- Destructive paths require explicit confirmation or the documented force value. Preserve safe non-TTY defaults.
- Pass structured state through Actions and Data objects, and surface actionable translated errors at the command or UI boundary.
- Test fresh, resumed, and non-interactive paths when changing orchestration, package selection, or failure recovery.

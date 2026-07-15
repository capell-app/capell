# Running Capell in a container


> **Status:** Skeleton — sections below are outlines. Contributions welcome.

## Scope

Running Capell inside Docker, Laravel Sail, or any containerized PHP-FPM + web-server setup. Covers filesystem paths, database drivers, `docker exec` patterns for running `capell:*` commands, and gotchas specific to containerized installs.

## Quickstart (to be filled)

- Minimal `docker-compose.yml` for Capell on MySQL.
- Minimal `docker-compose.yml` for Capell on SQLite.
- How to wire your site's domain for Capell's multi-domain resolution.

## Running artisan commands in a container

- `docker exec -it <app> php artisan capell:install` — the non-interactive flags (`--no-interaction`, `--user=`, `--url=`, `--packages=`) matter here because a `docker exec -it` without TTY can behave half-interactive.
- Recommended patterns: put `capell:install --no-interaction --user=<email> --url=<url>` in a `docker-entrypoint.sh`.

For a full package install from an entrypoint or CI job, pass every required value explicitly:

```bash
docker exec <app> php artisan capell:install \
  --no-interaction \
  --url="${APP_URL}" \
  --user=admin@example.com \
  --all-packages \
  --developer-tooling
```

If the container image should not rewrite Boost guidelines, skills, or MCP files during boot, add `--no-boost-install`. This still installs the Composer packages required for developer tooling.

## Filesystem caveats

- Symlinked vendor packages: see [Tailwind vendor CSS](../frontend/tailwind-vendor-css.md) for the Tailwind v4 resolver quirk.
- Storage link: `php artisan storage:link` runs before Capell can serve media.

## Databases

- **SQLite.** Minimal setup for local dev; remember to mount the sqlite file as a volume.
- **MySQL.** Use the same image tag across team (pin to `mysql:8.4`). Capell currently assumes InnoDB + utf8mb4.
- **Postgres.** Not officially tested; tracking follow-up.

## Creating a user when `activity_log` doesn't exist yet

- Symptom: `make:filament-user` fails on a fresh DB because the Activity Log observer tries to write to a non-existent table.
- Fix: ensure `php artisan migrate --force` completes before the `make:filament-user` step in your Dockerfile/entrypoint.

## Health check

- `php artisan capell:doctor` — run inside the container after boot; output is the single source of truth for "is Capell ready".

## Related

- [Install guide](../getting-started/install.md)
- [Troubleshooting](../operations/troubleshooting.md)
- [First session](../getting-started/first-session.md)

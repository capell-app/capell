# Versioned upgrade steps

## v1.0.0

- Back up the database, environment and storage, and test the restore before deployment.
- Update the foundation packages together, then run `php artisan capell:upgrade --dry-run` and `php artisan capell:upgrade`.

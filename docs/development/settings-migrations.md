# Settings Migrations

![Capell Settings Migrations screenshot](../images/admin-dashboard.png)

Capell uses [Spatie Laravel Settings](https://github.com/spatie/laravel-settings) for managing application settings. Settings migrations are different from regular database migrations.

## Directory Structure

Settings migrations must be placed in the `database/settings/` directory within a package, **not** `database/migrations/`:

```
packages/your-package/
├── database/
│   ├── migrations/           # ← Regular table migrations
│   │   ├── create_tables.php
│   │   └── add_columns.php
│   └── settings/             # ← Settings migrations (MUST be here)
│       ├── create_settings.php
│       └── update_settings.php
```

## Creating Settings Migrations

Settings migrations extend `Spatie\LaravelSettings\Migrations\SettingsMigration`:

```php
<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Use exists() check to avoid errors on re-runs
        if (! $this->migration-assistant->exists('mygroup.setting_key')) {
            $this->migration-assistant->add('mygroup.setting_key', 'default_value');
        }
    }

    public function down(): void
    {
        $this->migration-assistant->delete('mygroup.setting_key');
    }
};
```

## Publishing and Running Settings Migrations

### In InstallCommand

Register settings migrations in your package's `InstallCommand`:

```php
public function handle(): int
{
    $settings = __DIR__ . '/../../../database/settings';

    $this->call('capell:publish-migrations', [
        '--type' => 'settings',
        '--items' => [
            'create_my_settings',
            'update_my_settings_add_field',  // Later updates
        ],
        '--path' => $settings,
    ]);

    $this->call('migrate');

    return Command::SUCCESS;
}
```

### Manual Publishing

If a settings migration is added after initial installation:

```bash
php artisan capell:publish-migrations \
  --type=settings \
  --items="2026_04_18_000001_update_my_settings" \
  --path="/path/to/package/database/settings"

php artisan migrate
```

## Best Practices

✅ **DO:**

- Place settings migrations in `database/settings/`
- Use `exists()` checks to make migrations idempotent
- Give migrations descriptive names: `update_settings_add_field`
- List all settings migrations in your `InstallCommand`
- Use clear naming: `create_*_settings.php`, `update_*_settings_*.php`

❌ **DON'T:**

- Place settings migrations in `database/migrations/`
- Add settings without existence checks (causes errors on re-run)
- Forget to register migrations in `InstallCommand`
- Create settings without a corresponding migration

## Troubleshooting

### MissingSettings Exception

If you see: `Tried loading settings 'MyClass', and the following properties were missing: ...`

**Cause:** Settings migration hasn't been published or run.

**Fix:**

1. Ensure migration is in `database/settings/` directory
2. Ensure `InstallCommand` lists the migration
3. Run: `php artisan capell:publish-migrations --type=settings ...`
4. Run: `php artisan migrate`

### Settings Not Persisting

Check that:

1. Migration's `up()` method uses `exists()` check
2. The settings group name in migration matches Settings class `group()`
3. Database `settings` table has the records

## Next

- [Development](index.md)
- [Actions, Data, and Settings](../packages/data-actions-settings.md)
- [Database and Migrations](../packages/database-and-migrations.md)

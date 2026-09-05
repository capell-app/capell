<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Agent;

use Capell\Admin\Actions\PersistMissingSettingsDefaultsAction;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Settings\CoreSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelSettings\Settings;

final class UpdateAgentSettingsAction
{
    /** @var array<string, class-string<Settings>> */
    private const array SETTINGS_CLASSES = [
        'core' => CoreSettings::class,
        'admin' => AdminSettings::class,
    ];

    /** @var array<string, array<string, string>> */
    private const array RULES = [
        'core' => [
            'default_locale' => 'sometimes|string|max:16',
            'default_image_source' => 'sometimes|string|in:url,upload,media',
            'allowed_image_sources' => 'sometimes|string|max:32',
            'allowed_remote_image_domains' => 'sometimes|array|max:50',
            'allowed_remote_image_domains.*' => 'string|max:255',
            'allow_relative_image_urls' => 'sometimes|boolean',
        ],
        'admin' => [
            'show_helper_tooltips' => 'sometimes|boolean',
            'show_configurator_path_hints' => 'sometimes|boolean',
            'hide_info_banner' => 'sometimes|boolean',
            'show_resource_statistics' => 'sometimes|boolean',
            'enable_activity_timeline' => 'sometimes|boolean',
            'enable_header_navigation_tree' => 'sometimes|boolean',
            'my_work_queue_limit' => 'sometimes|integer|min:1|max:100',
            'recently_published_limit' => 'sometimes|integer|min:1|max:100',
            'cache_health_refresh_interval_seconds' => 'sometimes|integer|min:10|max:3600',
            'analytics_default_period_days' => 'sometimes|integer|min:1|max:365',
            'analytics_refresh_interval_seconds' => 'sometimes|integer|min:10|max:3600',
            'analytics_top_n_limit' => 'sometimes|integer|min:1|max:100',
        ],
    ];

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function validate(string $group, array $values): array
    {
        $rules = self::RULES[$group] ?? throw ValidationException::withMessages([
            'group' => __('capell-admin::agent.settings_group_invalid'),
        ]);

        $editableKeys = array_values(array_filter(
            array_keys($rules),
            static fn (string $key): bool => ! str_ends_with($key, '.*'),
        ));
        $unknown = array_diff(array_keys($values), $editableKeys);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'values' => __('capell-admin::agent.settings_keys_invalid'),
            ]);
        }

        /** @var array<string, mixed> $validated */
        $validated = validator($values, $rules)->validate();

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function handle(string $group, array $values): array
    {
        $validated = $this->validate($group, $values);
        $settingsClass = self::SETTINGS_CLASSES[$group] ?? throw ValidationException::withMessages([
            'group' => __('capell-admin::agent.settings_group_invalid'),
        ]);

        return DB::transaction(function () use ($settingsClass, $validated): array {
            PersistMissingSettingsDefaultsAction::run($settingsClass);
            $settings = resolve($settingsClass);
            $settings->fill($validated);
            $settings->save();

            return $settings->toArray();
        });
    }

    /** @return array<string, mixed> */
    public function current(string $group): array
    {
        $settingsClass = self::SETTINGS_CLASSES[$group] ?? throw ValidationException::withMessages([
            'group' => __('capell-admin::agent.settings_group_invalid'),
        ]);

        PersistMissingSettingsDefaultsAction::run($settingsClass);

        return resolve($settingsClass)->toArray();
    }
}

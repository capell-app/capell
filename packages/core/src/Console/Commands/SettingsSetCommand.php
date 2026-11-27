<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Settings\WriteSettingAction;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class SettingsSetCommand extends Command
{
    protected $description = 'Change one Capell setting';

    protected $signature = 'capell:settings:set
        {setting : The setting as group.name (for example core.default_locale)}
        {value : The new value; use true or false, a number, null, or a JSON array where the setting needs one}';

    public function handle(): int
    {
        $setting = (string) $this->argument('setting');

        if (! str_contains($setting, '.')) {
            $this->error('Name the setting as group.name, for example core.default_locale. Run php artisan capell:settings:get to list them.');

            return self::FAILURE;
        }

        [$group, $property] = explode('.', $setting, 2);

        try {
            $saved = WriteSettingAction::run($group, $property, (string) $this->argument('value'));
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        } catch (Throwable $throwable) {
            // A failed save can carry every sibling setting's value in its message (query bindings), so report
            // only the class and never the message or a chained previous exception.
            $this->error(sprintf('Could not save %s: %s. Nothing was printed from the error because it may contain secret values.', $setting, $throwable::class));

            return self::FAILURE;
        }

        $this->info(sprintf('%s is now %s.', $setting, is_string($saved) ? $saved : json_encode($saved, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));

        return self::SUCCESS;
    }
}

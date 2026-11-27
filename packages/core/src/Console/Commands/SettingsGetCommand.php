<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Settings\ReadSettingsAction;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class SettingsGetCommand extends Command
{
    protected $description = 'Show Capell settings, all groups or one group or setting';

    protected $signature = 'capell:settings:get
        {setting? : A group (for example core) or one setting (for example core.default_locale)}
        {--json : Print JSON}';

    public function handle(): int
    {
        $setting = (string) $this->argument('setting');
        [$group, $property] = str_contains($setting, '.') ? explode('.', $setting, 2) : [$setting, null];

        try {
            $values = ReadSettingsAction::run($group !== '' ? $group : null);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        if ($property !== null) {
            if (! array_key_exists($property, $values[$group])) {
                $this->error(sprintf('The %s settings have no [%s] setting. Settings: %s.', $group, $property, implode(', ', array_keys($values[$group]))));

                return self::FAILURE;
            }

            $this->line((bool) $this->option('json')
                ? json_encode($values[$group][$property], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $this->display($values[$group][$property]));

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ($values as $groupName => $groupValues) {
            $this->table(['Setting', 'Value'], collect($groupValues)
                ->map(fn (mixed $value, string $name): array => [$groupName . '.' . $name, $this->display($value)])
                ->values()
                ->all());
        }

        return self::SUCCESS;
    }

    private function display(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\PublishMigrationsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class PublishCapellMigrationsAction
{
    use AsFake;
    use AsObject;

    public function handle(ProgressReporter $reporter, bool $publishSchema = true, bool $publishSettings = true): void
    {
        if ($publishSchema) {
            $reporter->step('Publishing Capell migrations…');

            $migrationsPath = dirname(__DIR__, 3) . '/database/migrations';

            $this->publish(
                label: 'Capell migrations',
                parameters: [
                    '--items' => CapellCore::getMigrations(),
                    '--path' => $migrationsPath,
                ],
            );

            $reporter->report('✓ Core migrations published');
        }

        if ($publishSettings) {
            $reporter->step('Publishing Capell settings migrations…');

            $settingsPath = dirname(__DIR__, 3) . '/database/settings';

            $this->publish(
                label: 'Capell settings migrations',
                parameters: [
                    '--type' => 'settings',
                    '--items' => CapellCore::getSettingMigrations(),
                    '--path' => $settingsPath,
                ],
            );

            $reporter->report('✓ Settings migrations published');
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function publish(string $label, array $parameters): void
    {
        $type = is_string($parameters['--type'] ?? null) ? $parameters['--type'] : 'migrations';
        $items = is_array($parameters['--items'] ?? null) ? array_values(array_filter($parameters['--items'], is_string(...))) : [];
        $path = is_string($parameters['--path'] ?? null) ? $parameters['--path'] : null;

        $result = PublishMigrationsAction::run($type, $items, $path);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Failed publishing %s.%s',
            $label,
            $result->errors !== [] ? "\nOutput: " . implode("\n", $result->errors) : '',
        ));
    }
}

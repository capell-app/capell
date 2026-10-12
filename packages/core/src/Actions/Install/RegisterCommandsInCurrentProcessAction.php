<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Console\Command\Command;

final class RegisterCommandsInCurrentProcessAction
{
    use AsObject;

    /** @param iterable<class-string<Command>|Command> $commands */
    public function handle(iterable $commands): void
    {
        $registered = Artisan::all();

        foreach ($commands as $command) {
            $instance = is_string($command) ? app()->make($command) : $command;
            $name = $instance->getName();

            if ($name !== null && array_key_exists($name, $registered)) {
                continue;
            }

            Artisan::registerCommand($instance);
            $registered = Artisan::all();
        }
    }
}

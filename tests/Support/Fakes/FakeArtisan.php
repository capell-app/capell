<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

class FakeArtisan
{
    public array $calls = [];

    public array $registeredCommands = [];

    public function __construct(private readonly array $commands = []) {}

    public function all(): array
    {
        return $this->commands;
    }

    public function call(string $command, array $arguments = []): int
    {
        $this->calls[] = [$command, $arguments];

        return 0;
    }

    public function registerCommand(string $command): void
    {
        $this->registeredCommands[] = $command;
    }
}

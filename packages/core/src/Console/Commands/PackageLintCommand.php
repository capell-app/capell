<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class PackageLintCommand extends Command
{
    protected $signature = 'capell:package:lint
        {path? : A package directory, capell.json path, or packages directory to lint}';

    protected $description = 'Lint Capell package manifests, naming, assets, versions, and extension contracts.';

    public function handle(AuditExtensionContractsAction $audit): int
    {
        $results = $audit->handle($this->argument('path'));

        if ($results === []) {
            $this->info('Package lint passed.');

            return CommandAlias::SUCCESS;
        }

        $this->table(
            ['Package', 'Severity', 'Message', 'Manifest', 'Context'],
            array_map(
                static fn (array $result): array => [
                    $result['package'],
                    $result['severity'],
                    $result['message'],
                    $result['manifest_path'],
                    json_encode($result['context'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ],
                $results,
            ),
        );

        foreach ($results as $result) {
            $this->line($result['message']);

            if ($result['context'] !== []) {
                $this->line(json_encode($result['context'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        }

        return collect($results)->contains(
            static fn (array $result): bool => $result['severity'] === 'error',
        )
            ? CommandAlias::FAILURE
            : CommandAlias::SUCCESS;
    }
}

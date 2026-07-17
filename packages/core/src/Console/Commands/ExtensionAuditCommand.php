<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class ExtensionAuditCommand extends Command
{
    protected $signature = 'capell:extension-audit
        {path? : A package directory, capell.json path, or packages directory to audit}';

    protected $description = 'Audit Capell extension manifests against the v3 contract.';

    public function handle(AuditExtensionContractsAction $audit): int
    {
        $results = AuditExtensionContractsAction::run($this->argument('path'));

        if ($results === []) {
            $this->info('No extension contract errors found.');

            return CommandAlias::SUCCESS;
        }

        $this->table(
            ['Package', 'Severity', 'Message', 'Manifest'],
            array_map(
                static fn (array $result): array => [
                    $result['package'],
                    $result['severity'],
                    $result['message'],
                    $result['manifest_path'],
                ],
                $results,
            ),
        );

        foreach ($results as $result) {
            $this->line($result['message']);
            $this->line($result['manifest_path']);
        }

        return collect($results)->contains(
            static fn (array $result): bool => $result['severity'] === 'error',
        )
            ? CommandAlias::FAILURE
            : CommandAlias::SUCCESS;
    }
}

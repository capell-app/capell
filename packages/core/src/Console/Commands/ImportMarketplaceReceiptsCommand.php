<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Marketplace\ApplyMarketplaceIssuanceAction;
use Illuminate\Console\Command;
use JsonException;

final class ImportMarketplaceReceiptsCommand extends Command
{
    protected $signature = 'capell:marketplace:import-receipts {bundle : Path to the bundle written by the Marketplace for this instance}';

    protected $description = 'Import Marketplace installed receipts and the commercial catalogue for an installation without network access.';

    public function handle(): int
    {
        $path = (string) $this->argument('bundle');
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            $this->error((string) __('capell-core::extensions.receipt_import.unreadable', ['path' => $path]));

            return self::FAILURE;
        }

        try {
            $bundle = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $bundle = null;
        }

        if (! is_array($bundle) || ($bundle['bundle_version'] ?? null) !== 1
            || ! is_array($bundle['installed_receipts'] ?? null)
            || (isset($bundle['commercial_catalogue']) && ! is_array($bundle['commercial_catalogue']))) {
            $this->error((string) __('capell-core::extensions.receipt_import.invalid'));

            return self::FAILURE;
        }

        $instanceId = config('capell.extensions.marketplace_instance_id');
        if (! is_string($instanceId) || $instanceId === '' || ($bundle['instance_id'] ?? null) !== $instanceId) {
            $this->error((string) __('capell-core::extensions.receipt_import.wrong_instance'));

            return self::FAILURE;
        }

        $result = ApplyMarketplaceIssuanceAction::run(
            receipts: $bundle['installed_receipts'],
            catalogue: $bundle['commercial_catalogue'] ?? null,
        );

        $this->info((string) __('capell-core::extensions.receipt_import.summary', [
            'accepted' => $result->receiptsAccepted,
            'rejected' => $result->receiptsRejected,
        ]));

        if ($result->catalogueRejected) {
            $this->warn((string) __('capell-core::extensions.receipt_import.catalogue_rejected'));
        }

        return $result->receiptsRejected > 0 || $result->catalogueRejected ? self::FAILURE : self::SUCCESS;
    }
}

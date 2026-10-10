<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Console\Commands\Concerns\HasFrontendAssetsOption;
use Illuminate\Console\Command;
use Override;

final class FrontendAssetsOptionTestCommand extends Command
{
    use HasFrontendAssetsOption;

    protected $signature = 'capell:test-frontend-assets-option';

    protected $description = 'Test frontend asset option resolution.';

    public function __construct(private readonly mixed $assets)
    {
        parent::__construct();
    }

    #[Override]
    public function option($key = null)
    {
        if ($key === 'assets') {
            return $this->assets;
        }

        return parent::option($key);
    }

    public function frontendAssets(): ?array
    {
        return $this->getFrontendAssets();
    }

    public function handle(): int
    {
        $this->line(json_encode($this->frontendAssets(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

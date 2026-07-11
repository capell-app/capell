<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

final readonly class InstallProfileData
{
    /**
     * @param  array<int, string>  $packages
     * @param  array<int, string>  $languages
     * @param  array<int, string>  $sites
     */
    public function __construct(
        public string $key,
        public array $packages = [],
        public ?string $theme = null,
        public ?bool $demo = null,
        public array $languages = [],
        public array $sites = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Pages\Fixtures;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use InvalidArgumentException;

final class SitemapPageFakeSitemapBuilder
{
    /** @var list<array{site_id: int, domain: string, language_id: int, with_edit_url: bool}> */
    public static array $calls = [];

    public static bool $returnsCollection = true;

    public function __construct(
        private readonly Site $site,
        private readonly SiteDomain $domain,
        private readonly Language $language,
        private readonly bool $withEditUrl,
    ) {
        $domain = $this->domain->domain;

        throw_unless(is_string($domain), InvalidArgumentException::class, 'SiteDomain domain must be a string.');

        self::$calls[] = [
            'site_id' => (int) $this->site->getKey(),
            'domain' => $domain,
            'language_id' => (int) $this->language->getKey(),
            'with_edit_url' => $this->withEditUrl,
        ];
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$returnsCollection = true;
    }

    public function build(): mixed
    {
        if (! self::$returnsCollection) {
            return ['invalid sitemap payload'];
        }

        return collect([
            (object) [
                'label' => 'Sitemap: ' . $this->site->name . ' / ' . $this->language->name . ' / ' . $this->domain->domain,
                'url' => $this->domain->full_url,
                'editUrl' => '/admin/pages/' . $this->site->getKey(),
                'children' => collect([
                    (object) [
                        'label' => 'Child: ' . $this->language->code,
                        'url' => $this->domain->full_url . '/child',
                        'editUrl' => '/admin/pages/' . $this->site->getKey() . '/child',
                        'children' => collect(),
                    ],
                ]),
            ],
        ]);
    }
}

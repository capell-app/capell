<?php

declare(strict_types=1);

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

describe('LoadSiteDomainFromUrlAction', function (): void {
    it('matches root domain with null path', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $domain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$domain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://example.com/', $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0])->toBeInstanceOf(SiteDomain::class);
        expect($result[0]->path)->toBeNull();
        expect($result[1])->toBe('/');
    });

    it('matches root domain with "/" path', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $domain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => '/',
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$domain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://example.com/', $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0])->toBeInstanceOf(SiteDomain::class);
        expect($result[0]->path)->toBe('/');
        expect($result[1])->toBe('/');
    });

    it('matches subpath domain', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $domain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => '/foo',
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$domain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://example.com/foo/bar', $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0]->path)->toBe('/foo');
        expect($result[1])->toBe('/bar');
    });

    it('returns null for non-matching domain', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $domain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$domain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://other.com/', $sites);
        expect($result)->toBeNull();
    });

    it('prefers the most specific path match', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $rootDomain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $fooDomain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => '/foo',
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$rootDomain, $fooDomain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://example.com/foo/bar', $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0]->path)->toBe('/foo');
        expect($result[1])->toBe('/bar');
    });

    it('prefers an exact host domain over a null domain fallback', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $fallbackDomain = new SiteDomain([
            'domain' => null,
            'scheme' => 'https',
            'path' => '/foo',
            'status' => true,
        ]);
        $hostDomain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => '/foo',
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$fallbackDomain, $hostDomain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://example.com/foo/bar', $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0])->toBe($hostDomain);
        expect($result[0]->domain)->toBe('example.com');
        expect($result[1])->toBe('/bar');
    });

    it('falls back to a null root domain when exact host domains do not match the path', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $fallbackDomain = new SiteDomain([
            'domain' => null,
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $hostDomain = new SiteDomain([
            'domain' => 'tenant.example.com',
            'scheme' => 'https',
            'path' => '/tenant',
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$fallbackDomain, $hostDomain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://tenant.example.com/', $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0])->toBe($fallbackDomain);
        expect($result[0]->domain)->toBe('tenant.example.com');
        expect($result[1])->toBe('/');
    });

    it('prefers null domains from the exact host site when falling back from a non-matching exact host path', function (): void {
        $globalSite = new Site(['name' => 'Global Site']);
        $globalDomain = new SiteDomain([
            'site_id' => 1,
            'domain' => null,
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $globalSite->setRelation('siteDomains', collect([$globalDomain]));

        $tenantSite = new Site(['name' => 'Tenant Site']);
        $tenantDomain = new SiteDomain([
            'site_id' => 2,
            'domain' => null,
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $hostDomain = new SiteDomain([
            'site_id' => 2,
            'domain' => 'tenant.example.com',
            'scheme' => 'https',
            'path' => '/tenant',
            'status' => true,
        ]);
        $tenantSite->setRelation('siteDomains', collect([$tenantDomain, $hostDomain]));
        $sites = collect([$globalSite, $tenantSite]);

        $result = LoadSiteDomainFromUrlAction::run('https://tenant.example.com/', $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0])->toBe($tenantDomain);
        expect($result[0]->domain)->toBe('tenant.example.com');
        expect($result[1])->toBe('/');
    });

    it('falls back to a null domain matching the request scheme and path', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $fallbackDomain = new SiteDomain([
            'domain' => null,
            'scheme' => 'https',
            'path' => '/foo',
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$fallbackDomain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://tenant.example.com/foo/bar', $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0])->toBe($fallbackDomain);
        expect($result[0]->domain)->toBe('tenant.example.com');
        expect($result[1])->toBe('/bar');
    });

    it('applies the request scheme to hostless domains without a configured scheme', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $fallbackDomain = new SiteDomain([
            'domain' => null,
            'scheme' => null,
            'path' => '/en',
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$fallbackDomain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('http://tenant.example.com/en/about', $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0])->toBe($fallbackDomain);
        expect($result[0]->domain)->toBe('tenant.example.com');
        expect($result[0]->scheme)->toBe('http');
        expect($result[0]->root_url)->toBe('http://tenant.example.com');
        expect($result[1])->toBe('/about');
    });

    it('ignores disabled domains', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $enabled = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $disabled = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => '/foo',
            'status' => false,
        ]);
        $site->setRelation('siteDomains', collect([$enabled, $disabled]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://example.com/foo', $sites);
        expect($result)->toBeNull();
    });

    it('treats /index.php as root', function (): void {
        $site = new Site(['name' => 'Test Site']);
        $domain = new SiteDomain([
            'domain' => 'example.com',
            'scheme' => 'https',
            'path' => null,
            'status' => true,
        ]);
        $site->setRelation('siteDomains', collect([$domain]));
        $sites = collect([$site]);

        $result = LoadSiteDomainFromUrlAction::run('https://example.com/index.php', $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result[0]->path)->toBeNull();
        expect($result[1])->toBe('/');
    });
});

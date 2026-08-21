<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

describe('ResolveSiteDomainAction', function (): void {
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://example.com/'), $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain)->toBeInstanceOf(SiteDomain::class);
        expect($result->siteDomain->path)->toBeNull();
        expect($result->relativePath)->toBe('/');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://example.com/'), $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain)->toBeInstanceOf(SiteDomain::class);
        expect($result->siteDomain->path)->toBe('/');
        expect($result->relativePath)->toBe('/');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://example.com/foo/bar'), $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain->path)->toBe('/foo');
        expect($result->relativePath)->toBe('/bar');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://other.com/'), $sites);
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://example.com/foo/bar'), $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain->path)->toBe('/foo');
        expect($result->relativePath)->toBe('/bar');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://example.com/foo/bar'), $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain)->toBe($hostDomain);
        expect($result->siteDomain->domain)->toBe('example.com');
        expect($result->relativePath)->toBe('/bar');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://tenant.example.com/'), $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain)->toBe($fallbackDomain)
            ->and($result->effectiveOrigin->host)->toBe('tenant.example.com')
            ->and($fallbackDomain->domain)->toBeNull()
            ->and($result->relativePath)->toBe('/');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://tenant.example.com/'), $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain)->toBe($tenantDomain)
            ->and($result->effectiveOrigin->host)->toBe('tenant.example.com')
            ->and($tenantDomain->domain)->toBeNull()
            ->and($result->relativePath)->toBe('/');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://tenant.example.com/foo/bar'), $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain)->toBe($fallbackDomain)
            ->and($result->effectiveOrigin->host)->toBe('tenant.example.com')
            ->and($fallbackDomain->domain)->toBeNull()
            ->and($result->relativePath)->toBe('/bar');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('http://tenant.example.com/en/about'), $sites);

        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain)->toBe($fallbackDomain)
            ->and($result->effectiveOrigin->host)->toBe('tenant.example.com')
            ->and($result->effectiveOrigin->scheme)->toBe('http')
            ->and($result->rootUrl())->toBe('http://tenant.example.com')
            ->and($fallbackDomain->domain)->toBeNull()
            ->and($fallbackDomain->getRawOriginal('scheme'))->toBeNull()
            ->and($result->relativePath)->toBe('/about');
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://example.com/foo'), $sites);
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

        $result = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl('https://example.com/index.php'), $sites);
        expect($result)->not()->toBeNull();
        assert($result !== null);
        expect($result->siteDomain->path)->toBeNull();
        expect($result->relativePath)->toBe('/');
    });
});

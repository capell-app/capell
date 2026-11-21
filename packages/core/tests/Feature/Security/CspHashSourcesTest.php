<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\ResolveCspHashSourcesAction;
use Capell\Core\Contracts\Security\ProvidesCspHashSources;
use Capell\Core\Support\Security\CspHashSource;

it('collects script and style hash sources from tagged providers without duplicates', function (): void {
    $provider = new class implements ProvidesCspHashSources
    {
        #[Override]
        public function scriptHashSources(): array
        {
            return [CspHashSource::forContent('a()'), CspHashSource::forContent('a()')];
        }

        #[Override]
        public function styleHashSources(): array
        {
            return [CspHashSource::forContent('p{}')];
        }
    };
    app()->instance('test.csp.provider', $provider);
    app()->tag(['test.csp.provider'], ProvidesCspHashSources::TAG);
    app()->tag(['test.csp.provider'], ProvidesCspHashSources::TAG);

    $sources = ResolveCspHashSourcesAction::run();

    expect($sources->script)->toBe([CspHashSource::forContent('a()')])
        ->and($sources->style)->toBe([CspHashSource::forContent('p{}')]);
});

it('refuses anything that is not a hash source so a provider cannot widen the policy', function (): void {
    app()->instance('test.csp.widening', new class implements ProvidesCspHashSources
    {
        #[Override]
        public function scriptHashSources(): array
        {
            return ["'unsafe-inline'", 'https://evil.example', "'nonce-abc'", "'sha256-ok+/='", "'sha256-x' 'unsafe-eval'"];
        }

        #[Override]
        public function styleHashSources(): array
        {
            return ["'unsafe-inline'"];
        }
    });
    app()->tag(['test.csp.widening'], ProvidesCspHashSources::TAG);

    $sources = ResolveCspHashSourcesAction::run();

    expect($sources->script)->toBe(["'sha256-ok+/='"])
        ->and($sources->style)->toBe([]);
});

it('keeps resolving when one provider throws', function (): void {
    app()->instance('test.csp.throws', new class implements ProvidesCspHashSources
    {
        #[Override]
        public function scriptHashSources(): array
        {
            throw new RuntimeException('broken');
        }

        #[Override]
        public function styleHashSources(): array
        {
            return [];
        }
    });
    app()->instance('test.csp.fine', new class implements ProvidesCspHashSources
    {
        #[Override]
        public function scriptHashSources(): array
        {
            return [CspHashSource::forContent('fine()')];
        }

        #[Override]
        public function styleHashSources(): array
        {
            return [];
        }
    });
    app()->tag(['test.csp.throws', 'test.csp.fine'], ProvidesCspHashSources::TAG);

    expect(ResolveCspHashSourcesAction::run()->script)->toContain(CspHashSource::forContent('fine()'));
});

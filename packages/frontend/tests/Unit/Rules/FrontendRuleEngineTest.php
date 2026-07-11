<?php

declare(strict_types=1);

use Capell\Frontend\Actions\EvaluateFrontendRuleGroupAction;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\FrontendRuleConditionRegistry;
use Capell\Frontend\Tests\Fixtures\Autoload\FrontendRuleEngineUser;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

it('evaluates nested all any and not rule groups', function (): void {
    $request = Request::create('https://example.test/preview');
    $context = new FrontendRuleContextData($request);
    $rules = [
        'operator' => 'all',
        'rules' => [
            [
                'condition' => 'domain',
                'parameters' => ['hosts' => ['example.test']],
            ],
            [
                'operator' => 'not',
                'rules' => [
                    [
                        'condition' => 'path',
                        'parameters' => ['patterns' => ['admin/*']],
                    ],
                ],
            ],
            [
                'operator' => 'any',
                'rules' => [
                    [
                        'condition' => 'path',
                        'parameters' => ['patterns' => ['docs/*']],
                    ],
                    [
                        'condition' => 'path',
                        'parameters' => ['patterns' => ['preview']],
                    ],
                ],
            ],
        ],
    ];

    expect(EvaluateFrontendRuleGroupAction::run($rules, $context))->toBeTrue();
});

it('evaluates auth state and date windows', function (): void {
    $request = Request::create('https://example.test/preview');
    $request->setUserResolver(fn (): User => new User);

    $rules = [
        'operator' => 'all',
        'rules' => [
            [
                'condition' => 'auth_state',
                'parameters' => ['state' => 'authenticated'],
            ],
            [
                'condition' => 'date_window',
                'parameters' => [
                    'starts_at' => now()->subDay()->toIso8601String(),
                    'ends_at' => now()->addDay()->toIso8601String(),
                ],
            ],
        ],
    ];

    expect(EvaluateFrontendRuleGroupAction::run($rules, new FrontendRuleContextData($request)))->toBeTrue();
});

it('evaluates context locale environment query campaign cookie and session conditions', function (): void {
    app()->setLocale('en');

    $request = Request::create('https://example.test/preview?utm_campaign=launch&ref=docs');
    $request->cookies->set('preview_mode', '1');
    $request->setLaravelSession(resolve(Session::class));
    $request->session()->put('banner_seen', 'yes');

    $context = new FrontendRuleContextData(
        request: $request,
        site: (object) ['key' => 'main'],
        layout: (object) ['slug' => 'marketing'],
        page: (object) ['id' => 42],
        language: (object) ['locale' => 'en'],
    );

    $rules = [
        'operator' => 'all',
        'rules' => [
            ['condition' => 'site', 'parameters' => ['key' => 'main']],
            ['condition' => 'layout', 'parameters' => ['slug' => 'marketing']],
            ['condition' => 'page', 'parameters' => ['id' => '42']],
            ['condition' => 'language', 'parameters' => ['locale' => 'en']],
            ['condition' => 'locale', 'parameters' => ['locale' => 'en']],
            ['condition' => 'environment', 'parameters' => ['environment' => 'testing']],
            ['condition' => 'query_parameter', 'parameters' => ['name' => 'ref', 'value' => 'docs']],
            ['condition' => 'campaign_parameter', 'parameters' => ['name' => 'utm_campaign', 'value' => 'launch']],
            ['condition' => 'cookie', 'parameters' => ['name' => 'preview_mode', 'value' => '1']],
            ['condition' => 'session_flag', 'parameters' => ['name' => 'banner_seen', 'value' => 'yes']],
        ],
    ];

    expect(EvaluateFrontendRuleGroupAction::run($rules, $context))->toBeTrue();
});

it('evaluates role and permission conditions when auth supports them', function (): void {
    Gate::define('view-preview', fn (User $user): bool => true);

    $request = Request::create('https://example.test/preview');
    $request->setUserResolver(fn (): FrontendRuleEngineUser => new FrontendRuleEngineUser);

    $rules = [
        'operator' => 'all',
        'rules' => [
            ['condition' => 'role', 'parameters' => ['role' => 'admin']],
            ['condition' => 'permission', 'parameters' => ['permission' => 'view-preview']],
        ],
    ];

    expect(EvaluateFrontendRuleGroupAction::run($rules, new FrontendRuleContextData($request)))->toBeTrue();
});

it('fails closed for unknown conditions', function (): void {
    $rules = [
        'operator' => 'all',
        'rules' => [
            [
                'condition' => 'missing_condition',
                'parameters' => [],
            ],
        ],
    ];

    expect(resolve(FrontendRuleConditionRegistry::class)->get('missing_condition'))->toBeNull()
        ->and(EvaluateFrontendRuleGroupAction::run($rules, new FrontendRuleContextData(Request::create('/'))))->toBeFalse();
});

it('fails closed for malformed rule groups', function (): void {
    $context = new FrontendRuleContextData(Request::create('/'));

    expect(EvaluateFrontendRuleGroupAction::run([
        'operator' => 'sometimes',
        'rules' => [],
    ], $context))->toBeFalse()
        ->and(EvaluateFrontendRuleGroupAction::run([
            'operator' => 'all',
            'rules' => [],
        ], $context))->toBeFalse()
        ->and(EvaluateFrontendRuleGroupAction::run([
            'operator' => 'any',
            'rules' => [],
        ], $context))->toBeFalse()
        ->and(EvaluateFrontendRuleGroupAction::run([
            'operator' => 'not',
            'rules' => [],
        ], $context))->toBeFalse();
});

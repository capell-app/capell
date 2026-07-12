<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\ValidateExtensionManifestAction;
use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ContributesWorkflowAttention;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionAsset;
use Capell\Core\Contracts\Extensions\RegistersExtensionContentWidget;
use Capell\Core\Contracts\Extensions\RegistersExtensionFrontendComponent;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Illuminate\Support\ServiceProvider;

beforeAll(function (): void {
    if (class_exists('Vendor\\Example\\Providers\\ExampleServiceProvider')) {
        return;
    }

    eval('
        namespace Vendor\\Example\\Providers {
            class ExampleServiceProvider extends \\' . ServiceProvider::class . ' {}
        }

        namespace Vendor\\Example\\Pages {
            class ExamplePage implements \\' . ExtensionContribution::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }
            }
        }

        namespace Vendor\\Example\\Routes {
            class ExampleRoutes implements \\' . RegistersExtensionRoute::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }
            }
        }

        namespace Vendor\\Example\\Settings {
            class ExampleSettings implements \\' . RegistersExtensionSetting::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }
            }
        }

        namespace Vendor\\Example\\Assets {
            class ExampleAssets implements \\' . RegistersExtensionAsset::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }
            }
        }

        namespace Vendor\\Example\\Jobs {
            class ExampleScheduledJob implements \\' . RunsScheduledExtensionJob::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }
            }
        }

        namespace Vendor\\Example\\Health {
            class ExampleTablesHealthCheck implements \\' . ChecksExtensionHealth::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }
            }
        }

        namespace Vendor\\Example\\Workflow {
            class ExampleWorkflowAttention implements \\' . ContributesWorkflowAttention::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }

                public function attentionItems(?\\Illuminate\\Contracts\\Auth\\Authenticatable $user = null): array
                {
                    return [];
                }
            }
        }

        namespace Vendor\\Example\\Components {
            class ExampleFrontendComponent implements \\' . RegistersExtensionFrontendComponent::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.0";
                }
            }
        }

        namespace Vendor\\Example\\Widgets {
            class ExampleContentWidget implements \\' . RegistersExtensionContentWidget::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^4.1";
                }
            }
        }

        namespace Vendor\\Example\\ContentGraph {
            class ExampleContentGraphSource extends \\Illuminate\\Database\\Eloquent\\Model {}

            class ExampleContentGraphExtractor implements \\' . ContentGraphExtractor::class . ' {
                public static function sourceModel(): string
                {
                    return ExampleContentGraphSource::class;
                }

                public function extract(\\Illuminate\\Database\\Eloquent\\Model $model): \\' . ContentGraphEdgeCollectionData::class . '
                {
                    return \\' . ContentGraphEdgeCollectionData::class . '::make();
                }
            }
        }

        namespace Capell\\FirstPartyAddon\\Providers {
            class FirstPartyAddonServiceProvider extends \\' . ServiceProvider::class . ' {}
        }
    ');
});

if (! function_exists('manifestV3Fixture')) {
    function manifestV3Fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/manifest-v3/' . $name . '.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}

function manifestV3ComposerJson(string $name = 'capell-app/example'): array
{
    return [
        'name' => $name,
        'autoload' => [
            'psr-4' => [
                'Vendor\\Example\\' => 'src/',
            ],
        ],
    ];
}

function makeManifestV3Package(array $manifest, array $composerJson): string
{
    $directory = sys_get_temp_dir() . '/capell-manifest-v3-' . bin2hex(random_bytes(6));

    mkdir($directory, recursive: true);
    file_put_contents($directory . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/composer.json', json_encode($composerJson, JSON_THROW_ON_ERROR));

    return $directory;
}

it('accepts a valid manifest v3 package contract', function (): void {
    $validator = new ManifestValidator;

    expect(fn () => $validator->validate(
        manifestV3Fixture('valid-premium-package'),
        composerJson: manifestV3ComposerJson(),
    ))->not->toThrow(InvalidManifestException::class);
});

it('accepts package security contract metadata', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = [
        'riskTier' => 'sensitive',
        'publicSurface' => [
            'routeNames' => ['capell-example.webhook'],
            'auth' => 'public',
            'csrfExemptRoutes' => ['capell-example.webhook'],
            'signedRoutes' => [],
            'tokenizedRoutes' => [],
            'webhookRoutes' => ['capell-example.webhook'],
            'throttledRoutes' => [],
        ],
        'sensitiveData' => [
            'encryptedFields' => ['Capell\\Example\\Models\\Connection::$api_key'],
            'hashedTokenFields' => ['example_tokens.token_hash'],
            'redactedOutputClasses' => ['Capell\\Example\\Actions\\RedactOutputAction'],
            'plaintextJustifications' => [],
        ],
        'publicOutput' => [
            'cacheSafe' => true,
            'forbidAuthoringSurface' => true,
            'forbidSecrets' => true,
            'forbidPublicBladeQueries' => true,
        ],
        'externalHttpClients' => [
            'requiresTimeouts' => true,
            'requiresSecretRedaction' => true,
            'clients' => ['Capell\\Example\\Support\\ProviderClient'],
        ],
        'adminSurface' => [
            'authorization' => 'permissions',
            'permissions' => ['example.manage'],
        ],
    ];

    expect(fn () => $validator->validate(
        $manifest,
        composerJson: manifestV3ComposerJson(),
    ))->not->toThrow(InvalidManifestException::class);
});

it('declares package security metadata in the manifest v3 json schema', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($schema, 'properties.security.properties.publicOutput.properties.cacheSafe.type'))->toBe('boolean')
        ->and(data_get($schema, 'properties.security.properties.publicSurface.properties.routeNames.$ref'))->toBe('#/$defs/stringList');
});

it('rejects malformed package security contract metadata', function (Closure $mutate, string $message): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = [
        'riskTier' => 'sensitive',
        'publicSurface' => [
            'routeNames' => ['capell-example.webhook'],
            'auth' => 'public',
        ],
        'publicOutput' => [
            'cacheSafe' => true,
            'forbidAuthoringSurface' => true,
        ],
    ];
    $mutate($manifest);

    expect(fn () => $validator->validate(
        $manifest,
        composerJson: manifestV3ComposerJson(),
    ))->toThrow(InvalidManifestException::class, $message);
})->with([
    'non-object security' => [
        function (array &$manifest): void {
            $manifest['security'] = 'sensitive';
        },
        'security',
    ],
    'unknown security field' => [
        function (array &$manifest): void {
            $manifest['security']['secrets'] = [];
        },
        'security.secrets',
    ],
    'malformed route list' => [
        function (array &$manifest): void {
            $manifest['security']['publicSurface']['routeNames'] = ['capell-example.webhook', false];
        },
        'routeNames',
    ],
    'malformed public output boolean' => [
        function (array &$manifest): void {
            $manifest['security']['publicOutput']['cacheSafe'] = 'yes';
        },
        'security.publicOutput.cacheSafe',
    ],
]);

it('validates manifests through the action with composer package context', function (): void {
    expect(fn () => ValidateExtensionManifestAction::run(
        manifest: manifestV3Fixture('valid-premium-package'),
        composerJson: manifestV3ComposerJson(),
        packageName: 'capell-app/example',
        discoverySource: 'unit test fixture',
    ))->not->toThrow(InvalidManifestException::class);
});

it('rejects incomplete or malformed manifest v3 contract sections', function (Closure $mutate, string $message): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $mutate($manifest);

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $message);
})->with([
    'unknown root field' => [
        function (array &$manifest): void {
            $manifest['unexpected'] = true;
        },
        'unexpected',
    ],
    'invalid kind' => [
        function (array &$manifest): void {
            $manifest['kind'] = 'application';
        },
        'application',
    ],
    'invalid visibility' => [
        function (array &$manifest): void {
            $manifest['visibility'] = 'private';
        },
        'visibility',
    ],
    'invalid slug' => [
        function (array &$manifest): void {
            $manifest['slug'] = 'Invalid Slug';
        },
        'slug',
    ],
    'invalid surface' => [
        function (array &$manifest): void {
            $manifest['surfaces'] = ['admin', 'portal'];
        },
        'portal',
    ],
    'missing required string' => [
        function (array &$manifest): void {
            $manifest['displayName'] = '';
        },
        'displayName',
    ],
    'missing product' => [
        function (array &$manifest): void {
            unset($manifest['product']);
        },
        'product',
    ],
    'missing dependencies' => [
        function (array &$manifest): void {
            unset($manifest['dependencies']);
        },
        'dependencies',
    ],
    'missing dependency list' => [
        function (array &$manifest): void {
            unset($manifest['dependencies']['requires']);
        },
        'requires',
    ],
    'non-list dependency list' => [
        function (array &$manifest): void {
            $manifest['dependencies']['requires'] = ['vendor/package' => '^1.0'];
        },
        'requires',
    ],
    'non-string optional list entry' => [
        function (array &$manifest): void {
            $manifest['settings'] = [123];
        },
        'settings',
    ],
    'missing providers' => [
        function (array &$manifest): void {
            unset($manifest['providers']);
        },
        'providers',
    ],
    'missing contributes' => [
        function (array &$manifest): void {
            unset($manifest['contributes']);
        },
        'contributes',
    ],
    'non-object contribution' => [
        function (array &$manifest): void {
            $manifest['contributes'] = ['route'];
        },
        'contributes.0',
    ],
    'missing contribution class' => [
        function (array &$manifest): void {
            $manifest['contributes'] = [['type' => 'route']];
        },
        'contributes.0.class',
    ],
    'missing performance' => [
        function (array &$manifest): void {
            unset($manifest['performance']);
        },
        'performance',
    ],
    'missing cache safety' => [
        function (array &$manifest): void {
            unset($manifest['performance']['cacheSafety']);
        },
        'cacheSafety',
    ],
    'invalid invalidation source list' => [
        function (array &$manifest): void {
            $manifest['performance']['cacheSafety']['invalidationSources'] = ['model' => 'App\\Models\\Page'];
        },
        'cacheSafety.invalidationSources',
    ],
    'non-object invalidation source' => [
        function (array &$manifest): void {
            $manifest['performance']['cacheSafety']['invalidationSources'] = ['page'];
        },
        'cacheSafety.invalidationSources.0',
    ],
    'missing health checks' => [
        function (array &$manifest): void {
            unset($manifest['healthChecks']);
        },
        'healthChecks',
    ],
    'non-object health check' => [
        function (array &$manifest): void {
            $manifest['healthChecks'] = ['database'];
        },
        'healthChecks.0',
    ],
    'missing commercial metadata' => [
        function (array &$manifest): void {
            unset($manifest['commercial']);
        },
        'commercial',
    ],
    'invalid private docs proposal flag' => [
        function (array &$manifest): void {
            $manifest['commercial']['privateDocsRequested'] = 'yes';
        },
        'privateDocsRequested',
    ],
    'missing marketplace metadata' => [
        function (array &$manifest): void {
            unset($manifest['marketplace']);
        },
        'marketplace',
    ],
    'invalid marketplace hidden flag' => [
        function (array &$manifest): void {
            $manifest['marketplace']['hidden'] = 'no';
        },
        'marketplace.hidden',
    ],
    'missing marketplace screenshots' => [
        function (array &$manifest): void {
            unset($manifest['marketplace']['screenshots']);
        },
        'marketplace.screenshots',
    ],
    'non-object marketplace screenshot' => [
        function (array &$manifest): void {
            $manifest['marketplace']['screenshots'] = ['screenshot.png'];
        },
        'marketplace.screenshots.0',
    ],
]);

it('rejects third-party packages that spoof trusted Capell platform namespaces', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['name'] = 'vendor/spoofed-platform';
    $manifest['slug'] = 'spoofed-platform';
    $manifest['namespace'] = 'Capell\\Core';
    $manifest['providers']['runtime'] = [CapellServiceProvider::class];
    $manifest['contributes'] = [];
    $manifest['healthChecks'] = [];

    expect(fn () => $validator->validate(
        $manifest,
        composerJson: [
            'name' => 'vendor/spoofed-platform',
            'autoload' => [
                'psr-4' => [
                    'Capell\\Core\\' => 'src/',
                ],
            ],
        ],
    ))->toThrow(InvalidManifestException::class, 'spoofs a trusted Capell platform namespace');
});

it('requires manifest version 3 and rejects v2-only capell-version', function (array $override, string $message): void {
    $validator = new ManifestValidator;
    $manifest = array_replace(manifestV3Fixture('valid-premium-package'), $override);

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $message);
})->with([
    'missing manifest-version' => [['manifest-version' => null], 'manifest-version 3'],
    'manifest v2' => [['manifest-version' => 2], 'manifest-version 3'],
    'capell-version' => [['capell-version' => '^4.0'], 'capell-version'],
]);

it('keeps provider buckets locked to manifest v3 buckets exactly', function (Closure $mutate, string $message): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $mutate($manifest);

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $message);
})->with([
    'missing metadata bucket' => [
        function (array &$manifest): void {
            unset($manifest['providers']['metadata']);
        },
        'metadata',
    ],
    'extra shared bucket' => [
        function (array &$manifest): void {
            $manifest['providers']['shared'] = [];
        },
        'shared',
    ],
]);

it('accepts the optional auth provider bucket', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['providers']['auth'] = ['Vendor\\Example\\Providers\\ExampleServiceProvider'];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('accepts shared-only manifest surfaces for contract packages', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['surfaces'] = ['shared'];
    $manifest['performance']['frontendRenderBudgetMs'] = 0;
    $manifest['performance']['adminQueryBudget'] = 0;
    $manifest['performance']['cacheSafety']['variesBy'] = [];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('limits contribution blueprints to the v3 enum values', function (): void {
    expect(array_map(
        static fn (ExtensionContributionType $type): string => $type->value,
        ExtensionContributionType::cases(),
    ))->toBe([
        'admin-page',
        'admin-resource',
        'admin-action-extender',
        'section',
        'page-type',
        'dashboard-widget',
        'overview-stat',
        'schema-extender',
        'configurator',
        'model',
        'permission',
        'route',
        'setting',
        'page-variation',
        'frontend-component',
        'content-widget',
        'render-hook',
        'asset',
        'migration',
        'scheduled-job',
        'console-command',
        'agent-capability',
        'content-graph',
        'health-check',
        'workflow-attention',
    ]);

    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'][] = ['type' => 'made-up', 'class' => 'Vendor\\Example\\MadeUp'];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'made-up');
});

it('publishes the current extension API version from one typed source', function (): void {
    expect(CapellExtensionApi::CURRENT_VERSION)->toBe('4.1.0');
});

it('exposes a content widget contribution contract', function (): void {
    expect(interface_exists(RegistersExtensionContentWidget::class))->toBeTrue()
        ->and(is_subclass_of(
            RegistersExtensionContentWidget::class,
            ExtensionContribution::class,
        ))->toBeTrue();
});

it('requires content widgets to implement their dedicated contribution contract', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'] = [[
        'type' => 'content-widget',
        'class' => 'Vendor\\Example\\Routes\\ExampleRoutes',
        'key' => 'capell-app.example-widget',
    ]];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, RegistersExtensionContentWidget::class);

    $manifest['contributes'][0]['class'] = 'Vendor\\Example\\Widgets\\ExampleContentWidget';

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('requires unique package-prefixed content widget keys', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'] = [[
        'type' => 'content-widget',
        'class' => 'Vendor\\Example\\Widgets\\ExampleContentWidget',
    ]];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'contributes.0.key');

    $manifest['contributes'][0]['key'] = 'other.example-widget';
    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'package-prefixed');

    $manifest['contributes'][0]['key'] = 'capell-app.example-widget';
    $manifest['contributes'][] = $manifest['contributes'][0];
    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'duplicates content widget key');
});

it('rejects commercial runtime truth spoofing while accepting author proposal fields', function (): void {
    $validator = new ManifestValidator;

    expect(fn () => $validator->validate(
        manifestV3Fixture('invalid-commercial-spoof'),
        composerJson: manifestV3ComposerJson(),
    ))->toThrow(InvalidManifestException::class, 'effectiveLicense');

    expect(fn () => $validator->validate(
        manifestV3Fixture('valid-premium-package'),
        composerJson: manifestV3ComposerJson(),
    ))->not->toThrow(InvalidManifestException::class);
});

it('rejects provider contribution and health check classes outside the package namespace or spoofing Capell namespaces', function (): void {
    $validator = new ManifestValidator;

    expect(fn () => $validator->validate(
        manifestV3Fixture('invalid-namespace-spoof'),
        composerJson: manifestV3ComposerJson(),
    ))->toThrow(InvalidManifestException::class, 'Capell\\Core\\Providers\\CoreServiceProvider');

    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['healthChecks'][0]['class'] = 'Other\\Vendor\\Health\\Check';

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'Other\\Vendor\\Health\\Check');
});

it('allows first-party addon namespaces while protecting platform namespaces', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['name'] = 'capell-app/first-party-addon';
    $manifest['slug'] = 'first-party-addon';
    $manifest['displayName'] = 'First Party Addon';
    $manifest['namespace'] = 'Capell\\FirstPartyAddon';
    $manifest['providers']['runtime'] = [
        'Capell\\FirstPartyAddon\\Providers\\FirstPartyAddonServiceProvider',
    ];
    $manifest['contributes'] = [];
    $manifest['healthChecks'] = [];

    $composerJson = [
        'name' => 'capell-app/first-party-addon',
        'autoload' => [
            'psr-4' => [
                'Capell\\FirstPartyAddon\\' => 'src/',
            ],
        ],
    ];

    expect(fn () => $validator->validate($manifest, composerJson: $composerJson))
        ->not->toThrow(InvalidManifestException::class);
});

it('rejects existing classes that do not implement the expected contribution contract', function (): void {
    $validator = new ManifestValidator;

    expect(fn () => $validator->validate(
        manifestV3Fixture('invalid-wrong-class-type'),
        composerJson: manifestV3ComposerJson(),
    ))->toThrow(InvalidManifestException::class, RegistersExtensionRoute::class);
});

it('requires workflow attention contributions to implement their contract', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'] = [
        [
            'type' => 'workflow-attention',
            'class' => 'Vendor\\Example\\Routes\\ExampleRoutes',
        ],
    ];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, ContributesWorkflowAttention::class);

    $manifest['contributes'][0]['class'] = 'Vendor\\Example\\Workflow\\ExampleWorkflowAttention';

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('accepts content graph contributions', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'] = [
        [
            'type' => 'content-graph',
            'class' => 'Vendor\\Example\\ContentGraph\\ExampleContentGraphExtractor',
        ],
    ];

    expect(ExtensionContributionType::tryFrom('content-graph'))->toBe(ExtensionContributionType::ContentGraph)
        ->and(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('requires content graph contributions to implement their contract', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'] = [
        [
            'type' => 'content-graph',
            'class' => 'Vendor\\Example\\Routes\\ExampleRoutes',
        ],
    ];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, ContentGraphExtractor::class);
});

it('rejects composer package name mismatches with both names and discovery source', function (string $source): void {
    $directory = makeManifestV3Package(
        manifestV3Fixture('valid-premium-package'),
        manifestV3ComposerJson('vendor/mismatched'),
    );

    $loader = new ManifestLoader(new ManifestValidator);

    try {
        $loader->load($directory . '/capell.json', 'vendor/mismatched', $source);
    } catch (InvalidManifestException $invalidManifestException) {
        expect($invalidManifestException->getMessage())
            ->toContain('vendor/mismatched')
            ->toContain('capell-app/example')
            ->toContain($source);

        return;
    }

    $this->fail('Expected manifest package name mismatch to throw.');
})->with([
    'installed Composer package' => 'installed package vendor/mismatched',
    'path repository' => 'path repository /tmp/example',
]);

it('requires explicit cache safety metadata for cacheable frontend surfaces', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    unset($manifest['performance']['cacheSafety']['sensitiveOutput']);

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'sensitiveOutput');

    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['performance']['cacheSafety']['cacheable'] = true;
    $manifest['performance']['cacheSafety']['invalidationSources'] = [];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'invalidationSources');
});

it('requires usable marketplace screenshot alt text and captions', function (string $field): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['marketplace']['screenshots'][0][$field] = 'short';

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $field);
})->with(['alt', 'caption']);

<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\ValidateExtensionManifestAction;
use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ContributesWorkflowAttention;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionAsset;
use Capell\Core\Contracts\Extensions\RegistersExtensionBuilderBlock;
use Capell\Core\Contracts\Extensions\RegistersExtensionContentWidget;
use Capell\Core\Contracts\Extensions\RegistersExtensionFrontendComponent;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Enums\CapabilityContract;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\Manifest\ManifestVocabulary;
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
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Example\\Routes {
            class ExampleRoutes implements \\' . RegistersExtensionRoute::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Example\\Settings {
            class ExampleSettings implements \\' . RegistersExtensionSetting::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Example\\Assets {
            class ExampleAssets implements \\' . RegistersExtensionAsset::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Example\\Jobs {
            class ExampleScheduledJob implements \\' . RunsScheduledExtensionJob::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Example\\Health {
            class ExampleTablesHealthCheck implements \\' . ChecksExtensionHealth::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Example\\Workflow {
            class ExampleWorkflowAttention implements \\' . ContributesWorkflowAttention::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.0";
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
                    return "^1.0";
                }
            }
        }

        namespace Vendor\\Example\\Widgets {
            class ExampleBuilderBlock implements \\' . RegistersExtensionBuilderBlock::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.1";
                }
            }

            class ExampleContentWidget implements \\' . RegistersExtensionContentWidget::class . ' {
                public static function compatibleCapellApiVersion(): string
                {
                    return "^1.1";
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

it('validates structure without autoloading declared package classes', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['providers']['runtime'] = ['Vendor\\Example\\UnloadedProvider'];
    $autoloaded = [];
    $loader = static function (string $class) use (&$autoloaded): void {
        $autoloaded[] = $class;
    };
    spl_autoload_register($loader, prepend: true);

    try {
        $validator = new ManifestValidator;
        $validator->validateStructure($manifest, composerJson: manifestV3ComposerJson());
        expect($autoloaded)->not->toContain('Vendor\\Example\\UnloadedProvider');
        expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
            ->toThrow(InvalidManifestException::class, 'cannot be resolved');
    } finally {
        spl_autoload_unregister($loader);
    }
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

it('accepts the published form builder agent tool declaration shape', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['agent_tools'] = [[
        'name' => 'form.submit',
        'description' => 'Submit this public form after user confirmation.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['submitted', 'pending']],
                'cancelled' => ['type' => 'boolean'],
            ],
            'required' => ['status'],
            'additionalProperties' => false,
        ],
        'effect' => 'write',
        'binding' => ['type' => 'form', 'target' => 'capell-form'],
    ]];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('rejects malformed agent tool declarations', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['agent_tools'] = [[
        'name' => 'form.submit',
        'description' => 'Submit this public form after user confirmation.',
        'inputSchema' => ['type' => 'object'],
        'outputSchema' => ['type' => 'object'],
        'effect' => 'execute',
        'binding' => ['type' => 'form', 'target' => 'capell-form'],
    ]];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'agent_tools.0');
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

it('declares manually listed external HTTP clients in the manifest v3 json schema', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($schema, 'properties.security.properties.externalHttpClients.additionalProperties'))->toBeFalse()
        ->and(data_get($schema, 'properties.security.properties.externalHttpClients.properties.manualClients.$ref'))->toBe('#/$defs/stringList')
        ->and(data_get($schema, 'properties.security.properties.externalHttpClients.properties.clients.$ref'))->toBe('#/$defs/stringList');
});

it('accepts manually listed external HTTP clients beside the detected ones', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = [
        'externalHttpClients' => [
            'requiresTimeouts' => true,
            'requiresSecretRedaction' => true,
            'clients' => ['Vendor\\Sdk\\Client'],
            'manualClients' => ['Vendor\\Sdk\\Client'],
        ],
    ];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('declares hand-declared signed and tokenized routes in the manifest v3 json schema', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($schema, 'properties.security.properties.manual.additionalProperties'))->toBeFalse()
        ->and(data_get($schema, 'properties.security.properties.manual.properties.signedRoutes.$ref'))->toBe('#/$defs/stringList')
        ->and(data_get($schema, 'properties.security.properties.manual.properties.tokenizedRoutes.$ref'))->toBe('#/$defs/stringList')
        ->and(data_get($schema, 'properties.security.properties.manual.properties.description.type'))->toBe('string');
});

it('accepts hand-declared signed and tokenized routes', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = [
        'manual' => [
            'description' => 'Portal links carry a bearer token in the uri.',
            'signedRoutes' => ['GET /portal/{token} (capell-example.portal)'],
            'tokenizedRoutes' => ['POST /hook (capell-example.hook)'],
        ],
    ];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('rejects a malformed hand-declared route declaration', function (mixed $manual, string $field): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = ['manual' => $manual];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $field);
})->with([
    'not an object' => ['GET /x', 'security.manual'],
    'unknown field' => [['webhookRoutes' => []], 'security.manual.webhookRoutes'],
    'signed routes not a list' => [['signedRoutes' => 'GET /x'], 'signedRoutes'],
    'tokenized route not a string' => [['tokenizedRoutes' => ['GET /x', 3]], 'tokenizedRoutes'],
    'description not a string' => [['description' => ['x']], 'security.manual.description'],
]);

it('validates screenshot paths in the published schema without depending on its regex spelling', function (string $path, bool $safe): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $pattern = data_get($schema, 'properties.marketplace.properties.screenshots.items.properties.path.pattern');
    throw_unless(is_string($pattern), RuntimeException::class, 'Screenshot paths need a schema constraint.');
    expect(preg_match('~' . str_replace('~', '\~', $pattern) . '~u', $path) === 1)->toBe($safe);
})->with([
    'relative image' => ['docs/screenshots/admin.webp', true],
    'root image' => ['admin.webp', true],
    'absolute path' => ['/tmp/admin.webp', false],
    'drive path' => ['C:/admin.webp', false],
    'parent path' => ['docs/../admin.webp', false],
    'current path' => ['./admin.webp', false],
    'empty segment' => ['docs//admin.webp', false],
    'windows separator' => ['docs\\admin.webp', false],
    'directory' => ['docs/', false],
    'empty path' => ['', false],
]);

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
    'malformed manual HTTP clients' => [
        function (array &$manifest): void {
            $manifest['security']['externalHttpClients']['manualClients'] = 'Vendor\\Sdk\\Client';
        },
        'manualClients',
    ],
    'manual HTTP client that is not a string' => [
        function (array &$manifest): void {
            $manifest['security']['externalHttpClients']['manualClients'] = ['Vendor\\Sdk\\Client', 3];
        },
        'manualClients',
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
    'marketplace screenshot traversing outside the package' => [
        function (array &$manifest): void {
            $manifest['marketplace']['screenshots'][0]['path'] = '../another-package/docs/screenshots/example.png';
        },
        'marketplace.screenshots.0.path',
    ],
    'marketplace screenshot directory without a filename' => [
        function (array &$manifest): void {
            $manifest['marketplace']['screenshots'][0]['path'] = 'docs/screenshots/';
        },
        'marketplace.screenshots.0.path',
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
    'capell-version' => [['capell-version' => '^1.0'], 'capell-version'],
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
        'public-render-data',
        'asset',
        'migration',
        'scheduled-job',
        'console-command',
        'agent-capability',
        'content-graph',
        'health-check',
        'workflow-attention',
        'outbound-event',
        'blueprint-subject',
        'builder-block',
    ]);

    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'][] = ['type' => 'made-up', 'class' => 'Vendor\\Example\\MadeUp'];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'made-up');
});

it('publishes the current extension API version from one typed source', function (): void {
    expect(CapellExtensionApi::CURRENT_VERSION)->toBe('1.1.0');
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

it('accepts builder blocks only when they implement their dedicated contribution contract', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributes'] = [[
        'type' => 'builder-block',
        'class' => 'Vendor\\Example\\Routes\\ExampleRoutes',
    ]];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, RegistersExtensionBuilderBlock::class);

    $manifest['contributes'][0]['class'] = 'Vendor\\Example\\Widgets\\ExampleBuilderBlock';

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

it('preserves structured runtime event listener traceability', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributionTraceability'] = [
        'runtimeIntegrations' => [
            'eventListeners' => [
                ['event' => 'eloquent.created: App\\Models\\Campaign', 'listener' => 'Vendor\\CampaignStudio\\Listeners\\CampaignListener'],
            ],
        ],
    ];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class)
        ->and(CapellManifestData::fromArray($manifest)->contributionTraceability?->runtimeIntegrations['eventListeners'])
        ->toBe($manifest['contributionTraceability']['runtimeIntegrations']['eventListeners']);
});

it('rejects malformed structured runtime event listener traceability', function (array $listeners): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['contributionTraceability'] = ['runtimeIntegrations' => ['eventListeners' => $listeners]];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'string list or event listener list');
})->with([
    'empty string' => [['event' => '', 'listener' => 'Vendor\\Listener']],
    'extra field' => [['event' => 'created', 'listener' => 'Vendor\\Listener', 'extra' => true]],
    'mixed entries' => [['created', ['event' => 'created', 'listener' => 'Vendor\\Listener']]],
]);

it('rejects unknown traceability fields and malformed contribution identity fields', function (array $changes): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest = [...$manifest, ...$changes];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class);
})->with([
    'unknown traceability field' => [['contributionTraceability' => ['unexpected' => true]]],
    'invalid contribution class' => [['contributes' => [[
        'type' => 'asset',
        'class' => [],
    ]]]],
    'empty contribution class' => [['contributes' => [[
        'type' => 'asset',
        'class' => '',
    ]]]],
]);

it('names the declared manifest version and the field when the version is unsupported', function (): void {
    $validator = new ManifestValidator;
    $manifest = array_replace(manifestV3Fixture('valid-premium-package'), ['manifest-version' => 2]);

    try {
        $validator->validate($manifest, composerJson: manifestV3ComposerJson());
    } catch (InvalidManifestException $invalidManifestException) {
        expect($invalidManifestException->getMessage())->toBe('Capell manifest declares manifest-version 2, but Capell reads manifest-version 3 only.')
            ->and($invalidManifestException->field)->toBe('manifest-version');

        return;
    }

    test()->fail('A manifest-version 2 manifest must be rejected.');
});

it('lists the real surfaces when a surface is unknown', function (): void {
    $validator = new ManifestValidator;
    $manifest = array_replace(manifestV3Fixture('valid-premium-package'), ['surfaces' => ['frontend', 'backoffice']]);

    try {
        $validator->validate($manifest, composerJson: manifestV3ComposerJson());
    } catch (InvalidManifestException $invalidManifestException) {
        expect($invalidManifestException->getMessage())->toBe("Capell manifest has invalid surface: 'backoffice'. Must be one of: content, admin, frontend, workflow, delivery, operations, integrations, marketplace, console, shared")
            ->and($invalidManifestException->field)->toBe('surfaces');

        return;
    }

    test()->fail('An unknown surface must be rejected.');
});

it('names the unknown and missing provider buckets', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $providers = $manifest['providers'];
    unset($providers['frontend']);
    $providers['web'] = [];
    $manifest['providers'] = $providers;

    try {
        $validator->validate($manifest, composerJson: manifestV3ComposerJson());
    } catch (InvalidManifestException $invalidManifestException) {
        expect($invalidManifestException->getMessage())->toBe("Capell manifest has invalid providers: unknown bucket 'web'; missing bucket 'frontend'. Required buckets: metadata, install, runtime, admin, frontend. Allowed buckets: metadata, install, runtime, auth, admin, frontend")
            ->and($invalidManifestException->field)->toBe('providers');

        return;
    }

    test()->fail('Unknown and missing provider buckets must be rejected.');
});

it('reports structural problems keyed by field without loading classes', function (): void {
    $validator = new ManifestValidator;
    $valid = manifestV3Fixture('valid-premium-package');

    expect($validator->structureProblems($valid, composerJson: manifestV3ComposerJson()))->toBe([]);

    $withoutPerformance = $valid;
    unset($withoutPerformance['performance']);

    expect($validator->structureProblems($withoutPerformance, composerJson: manifestV3ComposerJson()))
        ->toBe(['performance' => 'Capell manifest is missing required field: performance']);

    $spoofed = manifestV3Fixture('invalid-commercial-spoof');
    $problems = $validator->structureProblems($spoofed, composerJson: manifestV3ComposerJson());

    expect(array_keys($problems))->toBe(['commercial.effectiveLicense'])
        ->and($problems['commercial.effectiveLicense'])->toContain('author proposal metadata only');
});

it('requires the performance budgets the schema declares', function (string $budget, mixed $value): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');

    if ($value === null) {
        unset($manifest['performance'][$budget]);
    } else {
        $manifest['performance'][$budget] = $value;
    }

    expect($validator->structureProblems($manifest, composerJson: manifestV3ComposerJson()))
        ->toBe(['performance.' . $budget => 'Capell manifest has invalid field performance.' . $budget . ': must be an integer budget of zero or more']);
})->with([
    'frontendRenderBudgetMs missing' => ['frontendRenderBudgetMs', null],
    'adminQueryBudget missing' => ['adminQueryBudget', null],
    'frontendRenderBudgetMs negative' => ['frontendRenderBudgetMs', -1],
    'adminQueryBudget string' => ['adminQueryBudget', '20'],
]);

it('judges structure without composer data but still refuses platform namespace spoofing', function (): void {
    $validator = new ManifestValidator;

    expect($validator->structureProblems(manifestV3Fixture('valid-premium-package')))->toBe([])
        ->and(array_keys($validator->structureProblems(manifestV3Fixture('invalid-namespace-spoof'))))->toBe(['class'])
        ->and($validator->structureProblems(manifestV3Fixture('valid-premium-package'), composerJson: ['autoload' => ['psr-4' => ['Other\\Vendor\\' => 'src/']]]))
        ->toHaveKey('class');
});

it('accepts optional outcome copy and FAQ entries in the marketplace section', function (): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['marketplace']['problem'] = 'Editors retype the same notice on every page.';
    $manifest['marketplace']['outcome'] = 'One notice, edited once, shown wherever it applies.';
    $manifest['marketplace']['faq'] = [
        ['question' => 'Does it work with every theme?', 'answer' => 'Yes, it renders through the theme notice slot.'],
    ];

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);
});

it('rejects outcome copy that would render blank or malformed', function (array $marketplace, string $field): void {
    $validator = new ManifestValidator;
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['marketplace'] = array_replace($manifest['marketplace'], $marketplace);

    expect(fn () => $validator->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, 'Capell manifest has invalid field ' . $field . ':');
})->with([
    'short problem' => [['problem' => 'Too short'], 'marketplace.problem'],
    'non-string outcome' => [['outcome' => ['not', 'text']], 'marketplace.outcome'],
    'faq not a list' => [['faq' => ['question' => 'x']], 'marketplace.faq'],
    'faq entry not an object' => [['faq' => ['just a string']], 'marketplace.faq.0'],
    'faq entry without answer' => [['faq' => [['question' => 'Does it work with every theme?']]], 'marketplace.faq.0.answer'],
    'faq entry with stray key' => [['faq' => [['question' => 'Does it work with every theme?', 'answer' => 'Yes, through the theme notice slot.', 'link' => 'x']]], 'marketplace.faq.0.link'],
]);

it('accepts and reads the capability contracts a package provides and consumes', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['capabilityContracts'] = [
        'provides' => ['form-submissions' => 1, 'form-catalogue' => 1],
        'consumes' => ['campaign-conversions' => 1],
    ];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class);

    $contracts = CapellManifestData::fromArray($manifest)->capabilityContracts;

    expect($contracts?->provides(CapabilityContract::FormSubmissions))->toBeTrue()
        ->and($contracts?->provides(CapabilityContract::CampaignConversions))->toBeFalse()
        ->and($contracts?->consumes(CapabilityContract::CampaignConversions))->toBeTrue()
        ->and(CapellManifestData::fromArray($manifest)->toArray()['capabilityContracts'])->toBe([
            'provides' => ['form-catalogue' => 1, 'form-submissions' => 1],
            'consumes' => ['campaign-conversions' => 1],
        ]);
});

it('rejects capability contract declarations Core does not define', function (mixed $section, string $field): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['capabilityContracts'] = $section;

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $field);
})->with([
    'a list of strings' => [['form-submissions'], 'capabilityContracts'],
    'an unknown section' => [['implements' => ['form-submissions' => 1]], 'capabilityContracts.implements'],
    'a package class name' => [['consumes' => ['Capell\\FormBuilder\\Models\\Form' => 1]], 'capabilityContracts.consumes.Capell\\FormBuilder\\Models\\Form'],
    'an unknown capability' => [['provides' => ['payments' => 1]], 'capabilityContracts.provides.payments'],
    'a future major version' => [['provides' => ['form-submissions' => 2]], 'capabilityContracts.provides.form-submissions'],
    'a string version' => [['provides' => ['form-submissions' => '1']], 'capabilityContracts.provides.form-submissions'],
    'a version list' => [['provides' => [1]], 'capabilityContracts.provides'],
]);

it('declares capability contracts with the Core keys in the manifest v3 json schema', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($schema, '$defs.capabilityContractVersions.propertyNames.enum'))
        ->toBe(array_map(static fn (CapabilityContract $capability): string => $capability->value, CapabilityContract::cases()))
        ->and(data_get($schema, 'properties.capabilityContracts.properties.provides.$ref'))->toBe('#/$defs/capabilityContractVersions');
});

it('accepts and reads the consent purposes a package asks about', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['privacy'] = ['purposes' => ['youtube' => 'marketing', 'openstreetmap' => 'functional']];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class)
        ->and(CapellManifestData::fromArray($manifest)->privacyPurposes)->toBe(['openstreetmap' => 'functional', 'youtube' => 'marketing'])
        ->and(CapellManifestData::fromArray($manifest)->toArray()['privacy'])->toBe(['purposes' => ['openstreetmap' => 'functional', 'youtube' => 'marketing']])
        ->and(CapellManifestData::fromArray(manifestV3Fixture('valid-premium-package'))->toArray())->not->toHaveKey('privacy');
});

it('rejects consent purpose declarations that could skip or confuse consent', function (mixed $section, string $field): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['privacy'] = $section;

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $field);
})->with([
    'a list' => [['youtube'], 'privacy'],
    'an unknown section' => [['cookies' => []], 'privacy.cookies'],
    'a purposes list' => [['purposes' => ['youtube']], 'privacy.purposes'],
    'an essential purpose, which would never ask' => [['purposes' => ['youtube' => 'essential']], 'privacy.purposes.youtube'],
    'an unknown category' => [['purposes' => ['youtube' => 'ads']], 'privacy.purposes.youtube'],
    'a category name as a purpose' => [['purposes' => ['marketing' => 'functional']], 'privacy.purposes.marketing'],
    'a malformed purpose key' => [['purposes' => ['YouTube Video' => 'marketing']], 'privacy.purposes.YouTube Video'],
]);

it('declares consent purposes in the manifest v3 json schema with the categories a package may use', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($schema, 'properties.privacy.properties.purposes.additionalProperties.enum'))
        ->toBe(['functional', 'preferences', 'analytics', 'marketing']);
});

it('accepts every documented product and commercial value and rejects any other', function (string $path, string $value, bool $accepted): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    data_set($manifest, $path, $value);
    $problems = (new ManifestValidator)->structureProblems($manifest, manifestV3ComposerJson());

    expect($problems === [])->toBe($accepted);

    if (! $accepted) {
        expect(array_keys($problems))->toBe([$path]);
    }
})->with(function (): Generator {
    $vocabulary = [
        'product.tier' => ManifestVocabulary::PRODUCT_TIERS,
        'commercial.proposedLicense' => ManifestVocabulary::PROPOSED_LICENSES,
        'commercial.requestedCertification' => ManifestVocabulary::REQUESTED_CERTIFICATIONS,
        'commercial.supportPolicy' => ManifestVocabulary::SUPPORT_POLICIES,
    ];

    foreach ($vocabulary as $path => $values) {
        foreach ($values as $value) {
            yield $path . ' ' . $value => [$path, $value, true];
        }

        yield $path . ' invented' => [$path, 'invented', false];
    }
});

it('keeps the manifest schema enums identical to the validator vocabulary', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($schema, 'properties.product.properties.tier.enum'))->toBe(ManifestVocabulary::PRODUCT_TIERS)
        ->and(data_get($schema, 'properties.commercial.properties.proposedLicense.enum'))->toBe([...ManifestVocabulary::PROPOSED_LICENSES, null])
        ->and(data_get($schema, 'properties.commercial.properties.requestedCertification.enum'))->toBe([...ManifestVocabulary::REQUESTED_CERTIFICATIONS, null])
        ->and(data_get($schema, 'properties.commercial.properties.supportPolicy.enum'))->toBe([...ManifestVocabulary::SUPPORT_POLICIES, null])
        ->and(data_get($schema, 'properties.$schema.type'))->toBe('string');
});

it('accepts model, settings and event cache invalidation sources and rejects malformed ones', function (array $sources, ?string $field): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['performance']['cacheSafety']['cacheable'] = true;
    $manifest['performance']['cacheSafety']['invalidationSources'] = $sources;
    $problems = (new ManifestValidator)->structureProblems($manifest, manifestV3ComposerJson());

    expect($field === null ? $problems : array_keys($problems))->toBe($field === null ? [] : [$field]);
})->with([
    'model with events' => [[['model' => 'Vendor\\Example\\Models\\Item', 'events' => ['saved']]], null],
    'settings class' => [[['settings' => 'Vendor\\Example\\Settings\\ExampleSettings']], null],
    'event class' => [[['event' => 'Vendor\\Example\\Events\\ItemChanged']], null],
    'all three shapes' => [[['model' => 'Vendor\\Example\\Models\\Item', 'events' => []], ['settings' => 'S'], ['event' => 'E']], null],
    'empty settings class' => [[['settings' => '']], 'settings'],
    'settings beside an event' => [[['settings' => 'S', 'event' => 'E']], 'cacheSafety.invalidationSources.0'],
    'settings with a stray key' => [[['settings' => 'S', 'note' => 'x']], 'cacheSafety.invalidationSources.0'],
    'unknown shape' => [[['table' => 'items']], 'cacheSafety.invalidationSources.0'],
    'model without events' => [[['model' => 'Vendor\\Example\\Models\\Item']], 'events'],
]);

it('declares the settings and event invalidation sources in the manifest v3 json schema', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $shapes = array_map(
        static fn (array $shape): array => array_keys($shape['properties']),
        data_get($schema, 'properties.performance.properties.cacheSafety.properties.invalidationSources.items.oneOf'),
    );

    expect($shapes)->toBe([['model', 'events'], ['settings'], ['event']]);
});

it('ignores the schema pointer a manifest may start with', function (): void {
    $manifest = ['$schema' => 'https://capell.app/schema/capell-manifest-v3.schema.json', ...manifestV3Fixture('valid-premium-package')];

    expect((new ManifestValidator)->structureProblems($manifest, manifestV3ComposerJson()))->toBe([]);
});

it('runs every registered manifest section validator', function (string $section, string $field): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest[$section] = 'not an object';

    expect(array_keys((new ManifestValidator)->structureProblems($manifest, manifestV3ComposerJson())))->toBe([$field]);
})->with([
    'security' => ['security', 'security'],
    'performance' => ['performance', 'performance'],
    'commercial' => ['commercial', 'commercial'],
    'marketplace' => ['marketplace', 'marketplace'],
    'capability contracts' => ['capabilityContracts', 'capabilityContracts'],
    'privacy' => ['privacy', 'privacy'],
]);

it('accepts declared public endpoint throttles that cover every throttled route', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = [
        'publicSurface' => ['throttledRoutes' => ['capell-example.submit']],
        'throttles' => [
            'capell-example-submit' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => ['capell-example.submit']],
        ],
    ];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))->not->toThrow(InvalidManifestException::class);
});

it('keeps accepting throttled routes from packages that have not declared throttles yet', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = ['publicSurface' => ['throttledRoutes' => ['capell-example.submit']]];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))->not->toThrow(InvalidManifestException::class);
});

it('rejects malformed or incomplete public endpoint throttle declarations', function (array $throttles, string $message): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['security'] = [
        'publicSurface' => ['throttledRoutes' => ['capell-example.submit']],
        'throttles' => $throttles,
    ];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $message);
})->with([
    'throttled route left uncovered' => [['capell-example-other' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => ['capell-example.other']]], 'capell-example.submit'],
    'purpose outside the naming convention' => [['submit' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => ['capell-example.submit']]], 'security.throttles.submit'],
    'zero attempts' => [['capell-example-submit' => ['maxAttempts' => 0, 'decaySeconds' => 60, 'routes' => ['capell-example.submit']]], 'maxAttempts'],
    'string window' => [['capell-example-submit' => ['maxAttempts' => 5, 'decaySeconds' => '60', 'routes' => ['capell-example.submit']]], 'decaySeconds'],
    'missing routes' => [['capell-example-submit' => ['maxAttempts' => 5, 'decaySeconds' => 60]], 'routes'],
    'unknown field' => [['capell-example-submit' => ['maxAttempts' => 5, 'decaySeconds' => 60, 'routes' => ['capell-example.submit'], 'key' => 'email']], 'security.throttles.capell-example-submit.key'],
    'not an object' => [['capell-example-submit' => 5], 'security.throttles.capell-example-submit'],
]);

it('declares public endpoint throttles in the manifest v3 json schema', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($schema, 'properties.security.properties.throttles.additionalProperties.required'))->toBe(['maxAttempts', 'decaySeconds', 'routes'])
        ->and(data_get($schema, 'properties.security.properties.throttles.additionalProperties.additionalProperties'))->toBeFalse();
});

it('accepts and reads package roles, role priority and install metadata', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');
    $manifest['roles'] = ['default-theme', 'workflow-final'];
    $manifest['rolePriority'] = 20;
    $manifest['install'] = [
        'tips' => [['variant' => 'blog', 'priority' => 5]],
        'legacyTables' => [['name' => 'tags', 'configKey' => 'tags.table', 'migration' => 'create_tags_table']],
    ];

    $data = CapellManifestData::fromArray($manifest);

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class)
        ->and($data->roles)->toBe([PackageRole::DefaultTheme, PackageRole::WorkflowFinal])
        ->and($data->rolePriority)->toBe(20)
        ->and($data->installTips[0]->variant)->toBe('blog')
        ->and($data->installTips[0]->priority)->toBe(5)
        ->and($data->legacyTables[0]->configKey)->toBe('tags.table')
        ->and($data->toArray())->toMatchArray([
            'roles' => ['default-theme', 'workflow-final'],
            'rolePriority' => 20,
            'install' => $manifest['install'],
        ]);
});

it('leaves roles and install metadata empty and out of the output when a manifest declares none', function (): void {
    $manifest = manifestV3Fixture('valid-premium-package');

    $data = CapellManifestData::fromArray($manifest);

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->not->toThrow(InvalidManifestException::class)
        ->and($data->roles)->toBe([])
        ->and($data->rolePriority)->toBe(0)
        ->and($data->installTips)->toBe([])
        ->and($data->legacyTables)->toBe([])
        ->and($data->toArray())->not->toHaveKeys(['roles', 'rolePriority', 'install']);
});

it('rejects invalid role and install declarations', function (array $fields, string $field): void {
    $manifest = [...manifestV3Fixture('valid-premium-package'), ...$fields];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: manifestV3ComposerJson()))
        ->toThrow(InvalidManifestException::class, $field);
})->with([
    'roles as a string' => [['roles' => 'default-theme'], 'roles'],
    'an unknown role' => [['roles' => ['made-up']], 'roles'],
    'a non-string role' => [['roles' => [1]], 'roles'],
    'a non-integer priority' => [['rolePriority' => '5'], 'rolePriority'],
    'install as a list' => [['install' => ['tips']], 'install'],
    'an unknown install field' => [['install' => ['hints' => []]], 'install.hints'],
    'tips as an object' => [['install' => ['tips' => ['variant' => 'blog']]], 'install.tips'],
    'a tip without a priority' => [['install' => ['tips' => [['variant' => 'blog']]]], 'install.tips.0.priority'],
    'a tip with a string priority' => [['install' => ['tips' => [['variant' => 'blog', 'priority' => '1']]]], 'install.tips.0.priority'],
    'a tip with an empty variant' => [['install' => ['tips' => [['variant' => '', 'priority' => 1]]]], 'install.tips.0.variant'],
    'a tip with an unknown field' => [['install' => ['tips' => [['variant' => 'a', 'priority' => 1, 'x' => 1]]]], 'install.tips.0.x'],
    'a legacy table without a migration' => [['install' => ['legacyTables' => [['name' => 'tags', 'configKey' => 'tags.table']]]], 'install.legacyTables.0.migration'],
    'a legacy table that is a list' => [['install' => ['legacyTables' => [['tags']]]], 'install.legacyTables.0'],
]);

it('declares roles and install metadata in the manifest v3 json schema', function (): void {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/capell-manifest-v3.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($schema, 'properties.roles.items.enum'))->toBe(PackageRole::values())
        ->and(data_get($schema, 'properties.rolePriority.type'))->toBe('integer')
        ->and(data_get($schema, 'properties.install.properties.tips.items.required'))->toBe(['variant', 'priority'])
        ->and(data_get($schema, 'properties.install.properties.legacyTables.items.required'))->toBe(['name', 'configKey', 'migration']);
});

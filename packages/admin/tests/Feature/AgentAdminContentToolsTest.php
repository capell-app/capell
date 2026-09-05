<?php

declare(strict_types=1);

use Capell\Admin\Actions\Agent\UpdateAgentSettingsAction;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Support\Agent\AgentAdminAuthorization;
use Capell\Admin\Support\Agent\AgentAdminConfirmationStore;
use Capell\Admin\Support\Agent\AgentAdminToolInvocationService;
use Capell\Admin\Support\Agent\AgentAdminToolRegistry;
use Capell\Admin\Support\Agent\AgentPageDraftSaveTool;
use Capell\Admin\Support\Agent\AgentPagePublicationTool;
use Capell\Admin\Support\Agent\AgentPageReadinessTool;
use Capell\Admin\Support\Agent\AgentSettingsWriteTool;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Translation;
use Capell\Core\Settings\CoreSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;

it('saves an editor scratch draft without changing the canonical page', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create();
    $translation = Translation::factory()->translatable($page)->language(Language::factory()->create())->create([
        'title' => 'Before',
        'content' => 'Original',
    ]);
    $invocation = new AgentAdminToolInvocationData(
        tool: 'admin.page.draft.save',
        payload: [
            'page_id' => $page->id,
            'fields' => ['name' => 'Updated page'],
            'translations' => [['id' => $translation->id, 'title' => 'After', 'content' => 'Updated']],
        ],
        siteId: $page->site_id,
        user: $user,
    );
    $tool = new AgentPageDraftSaveTool(new AgentAdminAuthorization);

    $tool->authorize($invocation);

    expect($tool->preview($invocation)->data['after']['translations'][0]['title'])->toBe('After');

    $tool->execute($invocation);

    expect($translation->fresh()?->title)->toBe('Before')
        ->and($translation->fresh()?->content)->toBe('Original')
        ->and($page->fresh()?->name)->not->toBe('Updated page')
        ->and(EditorScratchDraft::query()->sole()->payload)->toMatchArray([
            'fields' => ['name' => 'Updated page'],
            'translations' => [['id' => $translation->id, 'title' => 'After', 'content' => 'Updated']],
        ]);
});

it('publishes through the existing Action only after confirmation', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create();
    $tool = new AgentPagePublicationTool(new AgentAdminAuthorization, PublicationTransition::PublishNow);
    $registry = new AgentAdminToolRegistry;
    $registry->register($tool);

    $service = new AgentAdminToolInvocationService($registry, new AgentAdminConfirmationStore);
    $beforeState = $page->publishVisibilityState();

    $preview = $service->invoke(
        'admin.page.publish',
        ['page_id' => $page->id],
        $user,
        $page->site_id,
        sessionId: 'agent-session',
    );

    expect($preview->mode)->toBe('confirmation_required')
        ->and($preview->confirmationToken)->toHaveLength(64)
        ->and($page->fresh()?->publishVisibilityState())->toBe($beforeState);

    $executed = $service->invoke(
        'admin.page.publish',
        [],
        $user,
        $page->site_id,
        $preview->confirmationToken,
        'agent-session',
    );

    expect($executed->mode)->toBe('executed')
        ->and($executed->ok)->toBeTrue()
        ->and($page->fresh()?->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});

it('reports per-page readiness through the existing completeness Action', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create();
    $invocation = new AgentAdminToolInvocationData(
        tool: 'admin.page.agent_readiness.read',
        payload: ['page_id' => $page->id],
        siteId: $page->site_id,
        user: $user,
    );
    $tool = new AgentPageReadinessTool(new AgentAdminAuthorization);

    $tool->authorize($invocation);

    $result = $tool->execute($invocation);

    expect($result->ok)->toBeTrue()
        ->and($result->data['page_id'])->toBe($page->id)
        ->and($result->data['is_agent_complete'])->toBeTrue();
});

it('updates only registered non-secret settings after confirmation', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $siteId = Page::factory()->create()->site_id;
    $settings = resolve(CoreSettings::class);
    $before = $settings->default_locale;
    $registry = new AgentAdminToolRegistry;
    $service = new AgentAdminToolInvocationService($registry, new AgentAdminConfirmationStore);

    try {
        $preview = $service->invoke(
            'admin.settings.write',
            ['group' => 'core', 'values' => ['default_locale' => 'fr']],
            $user,
            $siteId,
            sessionId: 'settings-session',
        );

        expect($preview->mode)->toBe('confirmation_required');

        $executed = $service->invoke(
            'admin.settings.write',
            [],
            $user,
            $siteId,
            $preview->confirmationToken,
            'settings-session',
        );

        expect($executed->ok)->toBeTrue()
            ->and(resolve(CoreSettings::class)->refresh()->default_locale)->toBe('fr');
    } finally {
        $settings->default_locale = $before;
        $settings->save();
    }
});

it('keeps process-wide settings unavailable to a site-scoped administrator', function (): void {
    $siteId = Page::factory()->create()->site_id;
    $user = ScopedAdminUser::make(collect([$siteId]));
    $invocation = new AgentAdminToolInvocationData(
        tool: 'admin.settings.write',
        payload: [],
        siteId: $siteId,
        user: $user,
    );

    expect(new AgentSettingsWriteTool(new AgentAdminAuthorization, new UpdateAgentSettingsAction)
        ->isAvailable($invocation))->toBeFalse();
});

it('creates a site taxonomy through the canonical structure Action after confirmation', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $siteId = Page::factory()->create()->site_id;
    $registry = new AgentAdminToolRegistry;
    $service = new AgentAdminToolInvocationService($registry, new AgentAdminConfirmationStore);

    $preview = $service->invoke(
        'admin.structure.write',
        ['resource' => 'taxonomy', 'operation' => 'create', 'data' => ['key' => 'brands', 'name' => 'Brands']],
        $user,
        $siteId,
        sessionId: 'structure-session',
    );

    $executed = $service->invoke(
        'admin.structure.write',
        [],
        $user,
        $siteId,
        $preview->confirmationToken,
        'structure-session',
    );

    expect($executed->ok)->toBeTrue()
        ->and(Taxonomy::query()->where('site_id', $siteId)->where('key', 'brands')->exists())->toBeTrue()
        ->and(PropertySet::query()->where('key', 'brands')->exists())->toBeFalse();
});

it('creates and updates a blueprint through supported fields and the existing update Action', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $siteId = Page::factory()->create()->site_id;
    $registry = new AgentAdminToolRegistry;
    $service = new AgentAdminToolInvocationService($registry, new AgentAdminConfirmationStore);

    $createPreview = $service->invoke(
        'admin.blueprint.write',
        [
            'operation' => 'create',
            'data' => ['type' => 'page', 'key' => 'agent-page', 'name' => 'Agent page', 'order' => 42, 'status' => false, 'default' => true],
        ],
        $user,
        $siteId,
        sessionId: 'blueprint-session',
    );
    $created = $service->invoke(
        'admin.blueprint.write',
        [],
        $user,
        $siteId,
        $createPreview->confirmationToken,
        'blueprint-session',
    );
    $blueprintId = (int) $created->data['id'];

    $updatePreview = $service->invoke(
        'admin.blueprint.write',
        ['operation' => 'update', 'id' => $blueprintId, 'data' => ['key' => 'agent-page', 'name' => 'Updated agent page']],
        $user,
        $siteId,
        sessionId: 'blueprint-session-2',
    );
    $updated = $service->invoke(
        'admin.blueprint.write',
        [],
        $user,
        $siteId,
        $updatePreview->confirmationToken,
        'blueprint-session-2',
    );

    expect($created->ok)->toBeTrue()
        ->and($updated->ok)->toBeTrue()
        ->and(Blueprint::query()->findOrFail($blueprintId)->name)->toBe('Updated agent page')
        ->and(Blueprint::query()->findOrFail($blueprintId)->order)->toBe(42)
        ->and(Blueprint::query()->findOrFail($blueprintId)->status)->toBeFalse()
        ->and(Blueprint::query()->findOrFail($blueprintId)->default)->toBeTrue();
});

it('reads supported blueprint fields without exposing blueprint internals', function (): void {
    $user = test()->actingAsAdmin()->authenticatedUser();
    $siteId = Page::factory()->create()->site_id;
    $blueprint = Blueprint::factory()->page()->createOne([
        'name' => 'Readable page',
        'key' => 'readable-page',
    ]);
    $registry = new AgentAdminToolRegistry;
    $service = new AgentAdminToolInvocationService($registry, new AgentAdminConfirmationStore);

    $result = $service->invoke(
        'admin.blueprint.read',
        ['id' => $blueprint->id],
        $user,
        $siteId,
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['blueprints'])->toHaveCount(1)
        ->and($result->data['blueprints'][0])->toMatchArray([
            'id' => $blueprint->id,
            'type' => 'page',
            'key' => 'readable-page',
            'name' => 'Readable page',
        ])
        ->and($result->data['blueprints'][0])->not->toHaveKey('meta');
});

it('rejects structure writes from an actor who can only view the site', function (): void {
    $siteId = Page::factory()->create()->site_id;
    $user = test()->createUser();
    $user->assignedSiteIds = collect([$siteId]);

    $service = new AgentAdminToolInvocationService(new AgentAdminToolRegistry, new AgentAdminConfirmationStore);

    expect(fn (): AgentAdminToolResultData => $service->invoke(
        'admin.structure.write',
        ['resource' => 'taxonomy', 'operation' => 'create', 'data' => ['key' => 'forbidden', 'name' => 'Forbidden']],
        $user,
        $siteId,
        sessionId: 'viewer-session',
    ))->toThrow(AuthorizationException::class);
    expect(Taxonomy::query()->where('key', 'forbidden')->exists())->toBeFalse();
});

it('rejects global property-set writes from a site administrator', function (): void {
    $siteId = Page::factory()->create()->site_id;
    $user = test()->createUser();
    $user->assignedSiteIds = collect([$siteId]);

    $permission = Permission::findOrCreate(CapellPermission::UpdateOwnSite->name(), 'web');
    $user->givePermissionTo($permission);
    $service = new AgentAdminToolInvocationService(new AgentAdminToolRegistry, new AgentAdminConfirmationStore);

    expect(fn (): AgentAdminToolResultData => $service->invoke(
        'admin.structure.write',
        ['resource' => 'property_set', 'operation' => 'create', 'data' => ['key' => 'forbidden', 'name' => 'Forbidden']],
        $user,
        $siteId,
        sessionId: 'site-admin-session',
    ))->toThrow(AuthorizationException::class);
    expect(PropertySet::query()->where('key', 'forbidden')->exists())->toBeFalse();
});

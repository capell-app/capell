<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Admin\Support\Agent\AgentAdminConfirmationStore;
use Capell\Admin\Support\Agent\AgentAdminToolInvocationService;
use Capell\Admin\Support\Agent\AgentAdminToolRegistry;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

afterEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

it('requires the existing admin session for tool discovery', function (): void {
    test()->getJson(route('capell-admin.agent.tools'))
        ->assertUnauthorized();
});

it('only discovers tools for a site and permissions assigned to the current session', function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;

    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $user = test()->createUser();
    $panelRole = Role::findOrCreate(Utils::getPanelUserRoleName(), 'web');
    $user->assignedSiteIds = collect([$assignedSite->getKey()]);

    $permission = Permission::findOrCreate('View:Page', 'web');
    $role = Role::findOrCreate('agent-page-viewer', 'web');
    $role->givePermissionTo($permission);
    DB::table('model_has_roles')->insert([
        'role_id' => $panelRole->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $assignedSite->getKey(),
    ]);
    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $assignedSite->getKey(),
    ]);

    test()->actingAs($user);
    $this->withSession(['capell.current_site_id' => $assignedSite->getKey()]);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($assignedSite->getKey());

    test()->getJson(route('capell-admin.agent.tools'))
        ->assertOk()
        ->assertJsonFragment(['name' => 'admin.page.properties.read'])
        ->assertJsonMissing(['name' => 'admin.page.properties.write']);

    $this->withSession(['capell.current_site_id' => $otherSite->getKey()])
        ->getJson(route('capell-admin.agent.tools'))
        ->assertForbidden();
});

it('takes the authenticated user and session from the request rather than the tool payload', function (): void {
    $site = Site::factory()->createOne();
    $user = test()->actingAsAdmin()->authenticatedUser();
    $this->withSession(['capell.current_site_id' => $site->getKey()]);
    $captured = new stdClass;
    $tool = new readonly class($captured) implements AgentAdminTool
    {
        public function __construct(private stdClass $captured) {}

        public function definition(): AgentToolDefinitionData
        {
            return new AgentToolDefinitionData(
                name: 'test.admin.read',
                description: 'Test admin read.',
                inputSchema: ['type' => 'object'],
                outputSchema: ['type' => 'object'],
                effect: AgentToolEffect::Read,
                binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, '/test'),
            );
        }

        public function isAvailable(AgentAdminToolInvocationData $invocation): bool
        {
            return true;
        }

        public function authorize(AgentAdminToolInvocationData $invocation): void
        {
            $this->captured->invocation = $invocation;
        }

        public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
        {
            return $this->execute($invocation);
        }

        public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
        {
            return new AgentAdminToolResultData(
                ok: true,
                mode: 'executed',
                tool: $invocation->tool,
            );
        }
    };
    $registry = new AgentAdminToolRegistry;
    $registry->register($tool);

    app()->instance(
        AgentAdminToolInvocationService::class,
        new AgentAdminToolInvocationService($registry, new AgentAdminConfirmationStore),
    );

    $this->postJson(route('capell-admin.agent.tools.invoke'), [
        'site_id' => 999999,
        'tool' => 'test.admin.read',
        'payload' => [
            'user_id' => 999999,
            'session_id' => 'forged-session',
        ],
        'confirmation_token' => null,
    ])->assertStatus(422);

    $response = $this->postJson(route('capell-admin.agent.tools.invoke'), [
        'tool' => 'test.admin.read',
        'payload' => [
            'user_id' => 999999,
            'session_id' => 'forged-session',
        ],
        'confirmation_token' => null,
    ]);

    $response->assertOk();

    expect($captured->invocation->tool)->toBe('test.admin.read')
        ->and($captured->invocation->payload['user_id'])->toBe(999999)
        ->and($captured->invocation->user)->toBe($user)
        ->and($captured->invocation->siteId)->toBe($site->getKey())
        ->and($captured->invocation->sessionId)->not->toBe('forged-session');
});

it('does not emit the admin tool schema in public HTML', function (): void {
    test()->get('/')->assertDontSee('capellAgentSchema', false);
});

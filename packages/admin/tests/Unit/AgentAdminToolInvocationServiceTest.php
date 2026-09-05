<?php

declare(strict_types=1);

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

test('admin writes require one-use explicit confirmation and re-run authorisation', function (): void {
    $calls = new stdClass;
    $calls->authorise = 0;
    $calls->preview = 0;
    $calls->execute = 0;
    $calls->payload = null;
    $calls->previewVersion = 1;

    $tool = new readonly class($calls) implements AgentAdminTool
    {
        public function __construct(private stdClass $calls) {}

        public function definition(): AgentToolDefinitionData
        {
            return new AgentToolDefinitionData(
                name: 'test.admin.write',
                description: 'Test admin write.',
                inputSchema: ['type' => 'object'],
                outputSchema: ['type' => 'object'],
                effect: AgentToolEffect::Write,
                binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, '/test'),
            );
        }

        public function isAvailable(AgentAdminToolInvocationData $invocation): bool
        {
            return true;
        }

        public function authorize(AgentAdminToolInvocationData $invocation): void
        {
            $this->calls->authorise++;
            $this->calls->payload = $invocation->payload;
        }

        public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
        {
            $this->calls->preview++;

            return new AgentAdminToolResultData(
                ok: true,
                mode: 'preview',
                tool: $invocation->tool,
                data: [
                    'value' => $invocation->payload['value'] ?? null,
                    'version' => $this->calls->previewVersion,
                ],
            );
        }

        public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
        {
            $this->calls->execute++;

            return new AgentAdminToolResultData(
                ok: true,
                mode: 'executed',
                tool: $invocation->tool,
                data: ['value' => $invocation->payload['value'] ?? null],
            );
        }
    };

    $registry = new AgentAdminToolRegistry;
    $registry->register($tool);

    $service = new AgentAdminToolInvocationService($registry, new AgentAdminConfirmationStore);
    $user = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 7;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    $preview = $service->invoke('test.admin.write', ['value' => 'draft'], $user, 11, sessionId: 'session-a');

    expect($preview->mode)->toBe('confirmation_required')
        ->and($preview->confirmationToken)->toHaveLength(64)
        ->and($calls->authorise)->toBe(1)
        ->and($calls->preview)->toBe(1)
        ->and($calls->execute)->toBe(0);

    $executed = $service->invoke('test.admin.write', [], $user, 11, $preview->confirmationToken, 'session-a');

    expect($executed->mode)->toBe('executed')
        ->and($calls->authorise)->toBe(2)
        ->and($calls->preview)->toBe(2)
        ->and($calls->execute)->toBe(1)
        ->and($calls->payload)->toBe(['value' => 'draft']);

    $stale = $service->invoke('test.admin.write', ['value' => 'stale'], $user, 11, sessionId: 'session-a');
    $calls->previewVersion = 2;

    expect(fn (): AgentAdminToolResultData => $service->invoke('test.admin.write', [], $user, 11, $stale->confirmationToken, 'session-a'))
        ->toThrow(AuthorizationException::class);

    $sessionBound = $service->invoke('test.admin.write', ['value' => 'session-bound'], $user, 11, sessionId: 'session-a');

    expect(fn (): AgentAdminToolResultData => $service->invoke('test.admin.write', [], $user, 11, $sessionBound->confirmationToken, 'session-b'))
        ->toThrow(AuthorizationException::class);

    expect($service->invoke('test.admin.write', [], $user, 11, $sessionBound->confirmationToken, 'session-a')->mode)
        ->toBe('executed');

    expect(fn (): AgentAdminToolResultData => $service->invoke('test.admin.write', [], $user, 11, $preview->confirmationToken))
        ->toThrow(AuthorizationException::class);
});

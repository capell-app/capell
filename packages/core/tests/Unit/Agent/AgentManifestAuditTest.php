<?php

declare(strict_types=1);

use Capell\Core\Actions\Agent\AuditAgentToolManifestAction;

function auditableAgentTool(): array
{
    return [
        'name' => 'contact.send', 'description' => 'Submit the contact form.',
        'inputSchema' => ['type' => 'object'], 'outputSchema' => ['type' => 'object'],
        'effect' => 'write', 'binding' => ['type' => 'form', 'target' => 'contact'],
    ];
}

it('requires positive validated declarations for a readiness verdict', function (): void {
    expect(AuditAgentToolManifestAction::run([])->isReady())->toBeFalse();
    $audit = AuditAgentToolManifestAction::run(['agent_tools' => [auditableAgentTool()]]);
    expect($audit->isReady())->toBeTrue()
        ->and($audit->validatedCount)->toBe(1)
        ->and($audit->errors)->toBe([]);
});

it('never promotes a waiver or an old authenticated capability to public readiness', function (): void {
    $surface = ['contributes' => [['type' => 'route', 'surface' => 'frontend']]];
    $missing = AuditAgentToolManifestAction::run($surface);
    $waived = AuditAgentToolManifestAction::run($surface + ['agent_tools' => ['none' => true, 'reason' => 'No public actions.']]);
    expect($missing->isDeclarationMissing())->toBeTrue()
        ->and($waived->isDeclarationMissing())->toBeFalse()
        ->and($waived->isReady())->toBeFalse()
        ->and(AuditAgentToolManifestAction::run(['contributes' => [[
            'type' => 'agent-capability', 'context' => 'admin', ...auditableAgentTool(),
        ]]])->isReady())->toBeFalse();
});

it('rejects invalid or duplicated declarations instead of trusting a ready flag', function (): void {
    $invalid = auditableAgentTool();
    $invalid['effect'] = 'execute-arbitrary-code';
    $audit = AuditAgentToolManifestAction::run(['agent_ready' => true, 'agent_tools' => [$invalid]]);
    expect($audit->isReady())->toBeFalse()->and($audit->errors)->not->toBeEmpty()
        ->and(AuditAgentToolManifestAction::run(['agent_tools' => [auditableAgentTool(), auditableAgentTool()]])->isReady())->toBeFalse();
});

it('does not attest executable class-only declarations from a catalogue snapshot', function (): void {
    $audit = AuditAgentToolManifestAction::run(['contributes' => [[
        'type' => 'agent-capability', 'context' => 'public', 'surface' => 'frontend',
        'class' => 'UnknownVendor\\Tool', 'key' => 'contact.send',
    ]]]);
    expect($audit->declaredCount)->toBe(1)
        ->and($audit->validatedCount)->toBe(0)
        ->and($audit->isReady())->toBeFalse();
});

it('does not award readiness when public declarations coexist with a waiver', function (): void {
    $audit = AuditAgentToolManifestAction::run([
        'agent_tools' => 'none', 'agent_tools_reason' => 'No supported public actions.',
        'contributes' => [[
            'type' => 'agent-capability', 'context' => 'public', 'surface' => 'frontend',
            ...auditableAgentTool(),
        ]],
    ]);
    expect($audit->validatedCount)->toBe(1)->and($audit->isReady())->toBeFalse();
});

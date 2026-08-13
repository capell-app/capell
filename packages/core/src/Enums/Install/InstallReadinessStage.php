<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Install;

enum InstallReadinessStage: string
{
    case Boot = 'boot';
    case Plan = 'plan';
    case Apply = 'apply';
    case Step = 'step';
    case Handoff = 'handoff';
}

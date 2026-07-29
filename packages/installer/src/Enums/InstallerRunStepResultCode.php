<?php

declare(strict_types=1);

namespace Capell\Installer\Enums;

enum InstallerRunStepResultCode: string
{
    case Complete = 'complete';
    case ExecutionFailed = 'execution-failed';
    case OutOfSequence = 'out-of-sequence';
    case PlanNotFound = 'plan-not-found';
    case PreflightFailed = 'preflight-failed';
    case Running = 'running';
    case SessionNotFound = 'session-not-found';
}

<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Diagnostics;

/**
 * What a doctor check means for the install: clean, a problem that leaves the
 * site working, or a problem that stops it from working.
 */
enum DoctorCheckOutcome: string
{
    case Passed = 'passed';
    case Warning = 'warning';
    case Failed = 'failed';
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum CrossOrigin: string
{
    case Anonymous = 'anonymous';
    case UseCredentials = 'use-credentials';
}

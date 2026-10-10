<?php

declare(strict_types=1);

use Capell\Tests\Support\Psr4TestDeclarationScanner;

require_once dirname(__DIR__) . '/tests/Support/Psr4TestDeclarationScanner.php';

$violations = new Psr4TestDeclarationScanner()->scan(dirname(__DIR__));

foreach ($violations as $violation) {
    printf("%s:%d:%s (expected: %s)\n", $violation['file'], $violation['line'], $violation['class'], implode(', ', $violation['expected']) ?: 'no matching namespace');
}

printf("PSR-4 violations: %d\n", count($violations));
exit($violations === [] ? 0 : 1);

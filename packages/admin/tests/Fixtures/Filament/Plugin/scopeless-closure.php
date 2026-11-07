<?php

declare(strict_types=1);

/** A closure declared outside any class, as in a package's route file. */
function capell_runtime_scopeless_closure(): Closure
{
    return static fn (): string => 'cached closure served';
}

<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;

class MissingMorphedModelException extends Exception
{
    public function __construct(string $type, ?string $suggestion = null)
    {
        $message = 'Unable to find morph model for type: ' . $type;
        if ($suggestion !== null && $suggestion !== '') {
            $message .= '; did you mean: ' . $suggestion;
        }

        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Exceptions;

use Exception;
use Throwable;

class WidgetLibraryException extends Exception
{
    public function __construct(string $message = '', private readonly mixed $widgets = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the exception's widget information for error renderers.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return ['widgets' => $this->widgets];
    }
}

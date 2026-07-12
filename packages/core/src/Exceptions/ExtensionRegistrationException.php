<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Data\PageTypeData;
use RuntimeException;
use Throwable;

class ExtensionRegistrationException extends RuntimeException
{
    public function __construct(
        string $class,
        string $expectedInterface,
        string $docUrl = 'https://github.com/capell-app/capell/blob/4.x/docs/extending-capell.md',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                "Failed to register extension class [%s]. Expected it to implement or extend [%s].\nSee: %s",
                $class,
                $expectedInterface,
                $docUrl,
            ),
            0,
            $previous,
        );
    }

    public static function forPageType(string $class, ?Throwable $previous = null): self
    {
        return new self(
            $class,
            PageTypeData::class,
            'https://github.com/capell-app/capell/blob/4.x/docs/extending-capell.md#page-types',
            $previous,
        );
    }

    public static function forSchema(string $class, ?Throwable $previous = null): self
    {
        return new self(
            $class,
            'Capell\Core\Contracts\SchemaInterface',
            'https://github.com/capell-app/capell/blob/4.x/docs/extending-capell.md#schemas',
            $previous,
        );
    }

    public static function forWidget(string $class, ?Throwable $previous = null): self
    {
        return new self(
            $class,
            'Capell\Core\Contracts\WidgetInterface',
            'https://github.com/capell-app/capell/blob/4.x/docs/extending-capell.md#widgets',
            $previous,
        );
    }
}

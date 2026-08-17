<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

class LoggingCapellChannelPatch extends AbstractConfigArrayPatch
{
    public function id(): string
    {
        return 'logging-capell-channel-patch';
    }

    public function group(): string
    {
        return 'config';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.logging_capell_channel_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.logging_capell_channel_patch_description');
    }

    public function docUrl(): ?string
    {
        return null;
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    protected function relativeConfigFilePath(): string
    {
        return 'config/logging.php';
    }

    protected function configArrayPath(): string
    {
        return 'channels.capell';
    }

    protected function buildConfigValue(): Array_
    {
        $items = [];

        // 'driver' => 'single'
        $items[] = new ArrayItem(
            new String_('single'),
            new String_('driver'),
            false,
            [],
        );

        // 'path' => storage_path('logs/capell.log')
        $storagePathCall = new FuncCall(
            new Name('storage_path'),
            [new Arg(new String_('logs/capell.log'))],
        );
        $items[] = new ArrayItem(
            $storagePathCall,
            new String_('path'),
            false,
            [],
        );

        // 'level' => 'debug'
        $items[] = new ArrayItem(
            new String_('debug'),
            new String_('level'),
            false,
            [],
        );

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    protected function isCanonicalValue(Expr $value): bool
    {
        if (! $value instanceof Array_) {
            return false;
        }

        $driver = $this->arrayItemValue($value, 'driver');
        $path = $this->arrayItemValue($value, 'path');
        $level = $this->arrayItemValue($value, 'level');

        return $driver instanceof String_
            && $driver->value === 'single'
            && $this->isFunctionCallWithStringArgument($path, 'storage_path', 'logs/capell.log')
            && $level instanceof String_
            && $level->value === 'debug';
    }
}

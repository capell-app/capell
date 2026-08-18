<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

class FilesystemsPageCacheDiskPatch extends AbstractConfigArrayPatch
{
    public function id(): string
    {
        return 'filesystems-page-cache-disk-patch';
    }

    public function group(): string
    {
        return 'config';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.filesystems_page_cache_disk_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.filesystems_page_cache_disk_patch_description');
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
        return 'config/filesystems.php';
    }

    protected function configArrayPath(): string
    {
        return 'disks.page_cache';
    }

    protected function buildConfigValue(): Array_
    {
        $items = [];

        // 'driver' => 'local'
        $items[] = new ArrayItem(
            new String_('local'),
            new String_('driver'),
            false,
            [],
        );

        // 'root' => public_path('page-cache')
        $publicPathCall = new FuncCall(
            new Name('public_path'),
            [new Arg(new String_('page-cache'))],
        );
        $items[] = new ArrayItem(
            $publicPathCall,
            new String_('root'),
            false,
            [],
        );

        // 'throw' => false
        $items[] = new ArrayItem(
            new ConstFetch(new Name('false')),
            new String_('throw'),
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
        $root = $this->arrayItemValue($value, 'root');
        $throw = $this->arrayItemValue($value, 'throw');

        return $driver instanceof String_
            && $driver->value === 'local'
            && $this->isFunctionCallWithStringArgument($root, 'public_path', 'page-cache')
            && $throw instanceof ConstFetch
            && strtolower($throw->name->toString()) === 'false';
    }
}

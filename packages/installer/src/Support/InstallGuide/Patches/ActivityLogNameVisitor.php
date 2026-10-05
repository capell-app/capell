<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Activity\LogOptions;
use Capell\Core\Support\Activity\LogsActivity;
use Override;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeVisitorAbstract;

/** Adapt resolved vendor references and their imports without changing aliases. */
final class ActivityLogNameVisitor extends NodeVisitorAbstract
{
    private const array REPLACEMENTS = [
        'spatie\\activitylog\\traits\\logsactivity' => LogsActivity::class,
        'spatie\\activitylog\\models\\concerns\\logsactivity' => LogsActivity::class,
        'spatie\\activitylog\\logoptions' => LogOptions::class,
        'spatie\\activitylog\\support\\logoptions' => LogOptions::class,
    ];

    #[Override]
    public function leaveNode(Node $node): Node|array|null
    {
        if ($node instanceof Name) {
            $resolved = $node->getAttribute('resolvedName');
            $replacement = $resolved instanceof Name ? $this->replacement($resolved->toString()) : null;

            return $replacement !== null ? new FullyQualified($replacement, $node->getAttributes()) : null;
        }

        if ($node instanceof Use_ && $node->type === Use_::TYPE_NORMAL) {
            foreach ($node->uses as $use) {
                $replacement = $this->replacement($use->name->toString());
                if ($replacement !== null) {
                    $use->name = new Name($replacement);
                }
            }
        }

        if ($node instanceof GroupUse) {
            $imports = [];
            foreach ($node->uses as $index => $use) {
                if (($node->type | $use->type) !== Use_::TYPE_NORMAL) {
                    continue;
                }

                $replacement = $this->replacement($node->prefix->toString() . '\\' . $use->name->toString());
                if ($replacement !== null) {
                    $imports[] = new Use_([new UseItem(new Name($replacement), $use->alias)]);
                    unset($node->uses[$index]);
                }
            }

            if ($imports !== []) {
                $node->uses = array_values($node->uses);

                return $node->uses === [] ? $imports : [$node, ...$imports];
            }
        }

        return null;
    }

    private function replacement(string $name): ?string
    {
        return self::REPLACEMENTS[strtolower($name)] ?? null;
    }
}

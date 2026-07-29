<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Database;

enum DatabaseCapability: string
{
    case PrefixIndex = 'prefix-index';
    case GeneratedColumn = 'generated-column';
    case StoredGeneratedColumn = 'stored-generated-column';
    case HashGeneratedColumn = 'hash-generated-column';
    case JsonPathIndex = 'json-path-index';
    case ForeignKeyDrop = 'foreign-key-drop';
    case GeneratedColumnInspection = 'generated-column-inspection';
}

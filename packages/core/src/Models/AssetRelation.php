<?php

declare(strict_types=1);

namespace Capell\Core\Models;

/**
 * @deprecated Use AssetAttachment.
 */
class AssetRelation extends AssetAttachment
{
    protected $table = 'asset_attachments';
}

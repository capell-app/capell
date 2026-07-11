<?php

declare(strict_types=1);

namespace Capell\Core\Data\Makers;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class MakerPreviewData extends Data
{
    /**
     * @param  Collection<int, MakerFileData>|DataCollection<int, MakerFileData>  $files
     * @param  Collection<int, MakerDatabaseRecordData>|DataCollection<int, MakerDatabaseRecordData>  $databaseRecords
     * @param  Collection<int, string>  $commands
     * @param  Collection<int, string>  $notes
     */
    public function __construct(
        public string $maker,
        public Collection|DataCollection $files,
        public Collection|DataCollection $databaseRecords,
        public Collection $commands,
        public Collection $notes,
    ) {}
}

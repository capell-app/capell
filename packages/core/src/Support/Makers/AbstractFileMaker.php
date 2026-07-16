<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers;

use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Data\Makers\MakerFileData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

abstract class AbstractFileMaker implements Maker
{
    abstract protected function buildPreview(MakerInputData $input): MakerPreviewData;

    public function preview(MakerInputData $input): MakerPreviewData
    {
        return $this->buildPreview($input);
    }

    public function run(MakerInputData $input): MakerResultData
    {
        $preview = $this->preview($input);
        $writtenFiles = collect();

        foreach ($preview->files as $file) {
            if ($file->contents === null) {
                $writtenFiles->push($file);

                continue;
            }

            resolve(Filesystem::class)->ensureDirectoryExists(dirname($file->path));
            resolve(Filesystem::class)->put($file->path, $file->contents);

            $writtenFiles->push(new MakerFileData(
                path: $file->path,
                operation: $file->operation,
                exists: true,
                writable: is_writable($file->path),
                contents: $file->contents,
            ));
        }

        return new MakerResultData(
            maker: $preview->maker,
            files: $writtenFiles,
            databaseRecords: $preview->databaseRecords,
            commands: $preview->commands,
            notes: $preview->notes,
            successful: true,
            warnings: collect(),
        );
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function renderStub(string $stubPath, array $replacements): string
    {
        $contents = (string) file_get_contents($stubPath);

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ ' . $key . ' }}', $value, $contents);
        }

        return $contents;
    }

    protected function fileData(string $path, string $contents, bool $force): MakerFileData
    {
        $exists = resolve(Filesystem::class)->exists($path);

        return new MakerFileData(
            path: $path,
            operation: $exists && $force ? 'overwrite' : 'create',
            exists: $exists,
            writable: true,
            contents: $contents,
        );
    }

    protected function studlyName(MakerInputData $input, string $suffix = ''): string
    {
        $name = Str::studly((string) ($input->values['name'] ?? ''));

        if ($suffix !== '' && ! str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        return $name;
    }

    protected function kebabName(MakerInputData $input, string $suffix = ''): string
    {
        return Str::kebab(Str::remove($suffix, $this->studlyName($input)));
    }

    /** @phpstan-ignore missingType.generics (Maker extension collections intentionally carry heterogeneous preview values.) */
    protected function previewData(MakerInputData $input, Collection $files, Collection $commands, Collection $notes): MakerPreviewData
    {
        return new MakerPreviewData(
            maker: $input->maker,
            files: $files,
            databaseRecords: collect(),
            commands: $commands->values(),
            notes: $notes->values(),
        );
    }
}

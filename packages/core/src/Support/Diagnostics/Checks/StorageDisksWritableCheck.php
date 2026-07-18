<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class StorageDisksWritableCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.storage.writable';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $assetsDisk = config('capell.assets.disk', 'local');
        $diskNames = array_values(array_unique(array_filter([is_string($assetsDisk) ? $assetsDisk : null], fn (mixed $value): bool => $value !== null)));
        $failed = [];
        $skipped = [];

        foreach ($diskNames as $diskName) {
            $config = config('filesystems.disks', []);
            if (! isset($config[$diskName])) {
                $skipped[] = $diskName;

                continue;
            }

            try {
                $testFile = '.capell-doctor-probe-' . bin2hex(random_bytes(8));
                $disk = Storage::disk($diskName);
                $disk->put($testFile, '');
                $disk->delete($testFile);
            } catch (Throwable) {
                $failed[] = $diskName;
            }
        }

        if ($failed !== []) {
            return new DoctorCheckResultData('Storage disks are writable', false, sprintf('Disk(s) not writable: %s.', implode(', ', $failed)), 'Check storage configuration and filesystem permissions.');
        }

        $checked = array_diff($diskNames, $skipped);

        return $skipped !== []
            ? new DoctorCheckResultData('Storage disks are writable', true, sprintf('Disks checked (some not configured in filesystems.disks): %s.', $checked !== [] ? implode(', ', $checked) : 'none'))
            : new DoctorCheckResultData('Storage disks are writable', true, 'All configured storage disks are writable.');
    }
}

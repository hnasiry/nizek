<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Support;

use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

final class StockImportFileResolver
{
    public function __construct(private readonly FilesystemManager $filesystemManager) {}

    public function resolve(string $storageDisk, string $storedPath): ResolvedStockImportFile
    {
        $storage = $this->filesystemManager->disk($storageDisk);
        if ($storage->getAdapter() instanceof LocalFilesystemAdapter) {
            $localPath = $storage->path($storedPath);

            if (! is_string($localPath) || $localPath === '') {
                throw new RuntimeException('Unable to resolve local path for stock import file.');
            }

            return new ResolvedStockImportFile($localPath);
        }

        $temporaryHandle = tmpfile();

        if ($temporaryHandle === false) {
            throw new RuntimeException('Unable to create temporary file for stock import.');
        }

        $meta = stream_get_meta_data($temporaryHandle);
        $temporaryPath = $meta['uri'] ?? null;

        if (! is_string($temporaryPath) || $temporaryPath === '') {
            fclose($temporaryHandle);

            throw new RuntimeException('Unable to discover temporary file path for stock import.');
        }

        $sourceStream = $storage->readStream($storedPath);

        if (! is_resource($sourceStream)) {
            fclose($temporaryHandle);

            throw new RuntimeException('Unable to read stock import stream from storage.');
        }

        try {
            if (stream_copy_to_stream($sourceStream, $temporaryHandle) === false) {
                throw new RuntimeException('Unable to copy stock import stream to temporary file.');
            }
        } finally {
            fclose($sourceStream);
        }

        return new ResolvedStockImportFile($temporaryPath, $temporaryHandle);
    }
}

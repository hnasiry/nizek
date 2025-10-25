<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Actions;

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\StockImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CreateStockImportFromUpload
{
    public function handle(int $companyId, UploadedFile $file): StockImport
    {
        $disk = config('stocks.import.disk', 'local');
        $importId = (string) Str::ulid();
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'xlsx';
        $storedFilename = sprintf('%s.%s', $importId, $extension);

        try {
            $storedPath = $file->storeAs('imports', $storedFilename, $disk);
        } catch (Throwable $exception) {
            Log::error('Unable to store uploaded stock import file.', [
                'company_id' => $companyId,
                'disk' => $disk,
                'exception' => $exception,
            ]);

            throw new RuntimeException('Unable to store the uploaded file.', 0, $exception);
        }

        if ($storedPath === false) {
            throw new RuntimeException('Unable to store the uploaded file.');
        }

        $originalName = $file->getClientOriginalName() ?: $storedFilename;

        try {
            return DB::transaction(function () use ($companyId, $disk, $importId, $originalName, $storedPath): StockImport {
                return StockImport::query()->create([
                    'id' => $importId,
                    'company_id' => $companyId,
                    'original_filename' => $originalName,
                    'stored_path' => $storedPath,
                    'disk' => $disk,
                    'status' => StockImportStatus::Pending,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);

            Log::error('Unable to create stock import record.', [
                'company_id' => $companyId,
                'stored_path' => $storedPath,
                'exception' => $exception,
            ]);

            throw new RuntimeException('Unable to create the stock import record.', 0, $exception);
        }
    }
}
